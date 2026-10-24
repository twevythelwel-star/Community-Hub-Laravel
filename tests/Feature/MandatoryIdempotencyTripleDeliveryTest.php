<?php

namespace Tests\Feature;

use App\Enums\PaymentState;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentReceipt;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * MANDATORY IDEMPOTENCY TEST SUITE
 *
 * Verifies the foundational payment security invariant:
 * "Suppose the provider sends: payment.succeeded three times.
 *  CommunityHub must produce:
 *    1 transaction
 *    1 ledger posting
 *    1 receipt
 *  —not:
 *    3 transactions
 *    3 ledger entries
 *    3 receipts
 *
 *  Therefore:
 *    provider_event_id UNIQUE
 *    and:
 *    idempotency_key UNIQUE
 *  are required database constraints."
 */
class MandatoryIdempotencyTripleDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const PROVIDER_SECRET = 'test_idempotency_secret_key_999';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.providers.wipay.api_key' => self::PROVIDER_SECRET,
            'payments.providers.wipay.account_number' => 'ACC-ESTATE-01',
        ]);
    }

    private function createUnpaidInvoice(User $user, int $amountMinor = 75000): Invoice
    {
        return Invoice::create([
            'user_id' => $user->id,
            'reference' => 'INV-IDEM-'.fake()->unique()->numerify('#####'),
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(14),
            'status' => 'Unpaid',
        ]);
    }

    private function createPaymentAttempt(Invoice $invoice, int $amountMinor = 75000): Payment
    {
        return Payment::create([
            'user_id' => $invoice->user_id,
            'invoice_id' => $invoice->id,
            'applies_to' => 'invoice',
            'purpose' => Transaction::PURPOSE_MAINTENANCE_FEE,
            'transaction_id' => 'CH-2026-'.fake()->unique()->numerify('##########'),
            'idempotency_key' => 'idem_pay_attempt_'.uniqid(),
            'provider' => 'wipay',
            'provider_payment_id' => 'WPY-TRIPLE-'.uniqid(),
            'channel' => 'card',
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'state' => PaymentState::Processing,
        ]);
    }

    private function deliverWebhook(array $payload): TestResponse
    {
        $raw = json_encode($payload);
        $sig = hash_hmac('sha256', $raw, self::PROVIDER_SECRET);

        return $this->call('POST', '/api/webhooks/payments/wipay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $sig,
            'HTTP_X_WIPAY_SIGNATURE' => $sig,
        ], $raw);
    }

    /**
     * Test 1: Triple Delivery of payment.succeeded produces EXACTLY 1 transaction,
     * exactly 1 ledger posting, and exactly 1 receipt.
     */
    public function test_triple_delivery_of_payment_succeeded_produces_exactly_one_record_of_each(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createUnpaidInvoice($user, 75000);
        $payment = $this->createPaymentAttempt($invoice, 75000);

        $providerEventId = 'evt_payment_succeeded_triple_delivery_777';
        $payload = [
            'event_id' => $providerEventId,
            'type' => 'approved', // Maps to PAYMENT_SUCCESS
            'provider_payment_id' => $payment->provider_payment_id,
            'order_id' => str_replace('-', '', $payment->transaction_id),
            'amount_minor' => 75000,
            'currency' => 'JMD',
            'account_number' => 'ACC-ESTATE-01',
            'status' => 'success',
            'message' => 'Payment settled by acquirer',
        ];

        // ── Delivery #1 (Initial Event) ──
        $response1 = $this->deliverWebhook($payload);
        $response1->assertOk();
        $response1->assertJson(['received' => true, 'outcome' => 'processed', 'event_id' => $providerEventId]);

        // ── Delivery #2 (Provider Retry #1) ──
        $response2 = $this->deliverWebhook($payload);
        $response2->assertOk();
        $response2->assertJson(['received' => true, 'outcome' => 'duplicate', 'event_id' => $providerEventId]);

        // ── Delivery #3 (Provider Retry #2) ──
        $response3 = $this->deliverWebhook($payload);
        $response3->assertOk();
        $response3->assertJson(['received' => true, 'outcome' => 'duplicate', 'event_id' => $providerEventId]);

        // ── VERIFY EXACTLY 1 OF EACH ENTITY (NEVER 3) ──

        // 1. Exactly ONE Transaction
        $this->assertSame(1, Transaction::count(), 'Idempotency violation: more than 1 transaction created!');
        $tx = Transaction::first();
        $this->assertSame($providerEventId, $tx->provider_event_id);
        $this->assertSame('completed', strtolower($tx->status));
        $this->assertEquals(75000, $tx->amount_minor);

        // 2. Exactly ONE Ledger Posting (balanced double-entry DR 1010 + CR 4010)
        $ledgerRows = LedgerEntry::where('transaction_id', $tx->id)->get();
        // A single posting has 2 balanced lines (1 Debit + 1 Credit). If 3 postings ran, it would have 6 rows!
        $this->assertSame(2, $ledgerRows->count(), 'Idempotency violation: more than 1 ledger posting recorded!');
        $totalDebit = $ledgerRows->where('entry_type', 'debit')->sum('amount_minor');
        $totalCredit = $ledgerRows->where('entry_type', 'credit')->sum('amount_minor');
        $this->assertEquals(75000, $totalDebit);
        $this->assertEquals(75000, $totalCredit);

        // 3. Exactly ONE Receipt
        $this->assertSame(1, PaymentReceipt::count(), 'Idempotency violation: more than 1 receipt generated!');
        $receipt = PaymentReceipt::first();
        $this->assertSame($tx->id, $receipt->transaction_id);
        $this->assertEquals(75000, $receipt->amount_minor);

        // 4. Exactly ONE PaymentEvent in processed state
        $this->assertSame(1, PaymentEvent::where('status', 'processed')->count());

        // 5. Invoice remains Paid once
        $invoice->refresh();
        $this->assertSame('Paid', $invoice->status);
    }

    /**
     * Test 2: Database UNIQUE Constraint on transactions.provider_event_id
     * guarantees the database itself physically rejects duplicate transactions.
     */
    public function test_transactions_table_enforces_unique_provider_event_id_constraint(): void
    {
        $user = User::factory()->create();

        // Insert first transaction
        Transaction::create([
            'transaction_id' => 'CH-2026-0000001111',
            'user_id' => $user->id,
            'amount_minor' => 10000,
            'currency' => 'JMD',
            'payment_channel' => 'card',
            'status' => 'completed',
            'provider' => 'wipay',
            'provider_event_id' => 'evt_unique_constraint_test_1',
            'idempotency_key' => 'idem_key_test_1',
        ]);

        $this->assertSame(1, Transaction::count());

        // Attempting to insert a second transaction with the same provider_event_id MUST throw QueryException
        $this->expectException(QueryException::class);

        Transaction::create([
            'transaction_id' => 'CH-2026-0000001112',
            'user_id' => $user->id,
            'amount_minor' => 10000,
            'currency' => 'JMD',
            'payment_channel' => 'card',
            'status' => 'completed',
            'provider' => 'wipay',
            'provider_event_id' => 'evt_unique_constraint_test_1', // Duplicate!
            'idempotency_key' => 'idem_key_test_2',
        ]);
    }

    /**
     * Test 3: Database UNIQUE Constraint on transactions.idempotency_key
     * guarantees the database physically rejects duplicate transactions by idempotency key.
     */
    public function test_transactions_table_enforces_unique_idempotency_key_constraint(): void
    {
        $user = User::factory()->create();

        // Insert first transaction
        Transaction::create([
            'transaction_id' => 'CH-2026-0000002221',
            'user_id' => $user->id,
            'amount_minor' => 20000,
            'currency' => 'JMD',
            'payment_channel' => 'card',
            'status' => 'completed',
            'provider' => 'wipay',
            'provider_event_id' => 'evt_unique_constraint_test_2a',
            'idempotency_key' => 'idem_shared_key_100',
        ]);

        $this->assertSame(1, Transaction::count());

        // Attempting to insert a second transaction with the same idempotency_key MUST throw QueryException
        $this->expectException(QueryException::class);

        Transaction::create([
            'transaction_id' => 'CH-2026-0000002222',
            'user_id' => $user->id,
            'amount_minor' => 20000,
            'currency' => 'JMD',
            'payment_channel' => 'card',
            'status' => 'completed',
            'provider' => 'wipay',
            'provider_event_id' => 'evt_unique_constraint_test_2b',
            'idempotency_key' => 'idem_shared_key_100', // Duplicate!
        ]);
    }

    /**
     * Test 4: Database UNIQUE Constraint on payment_receipts.transaction_id
     * physically guarantees exactly 1 receipt per transaction at storage level.
     */
    public function test_payment_receipts_enforces_unique_transaction_id_constraint(): void
    {
        $user = User::factory()->create();

        $tx = Transaction::create([
            'transaction_id' => 'CH-2026-0000003333',
            'user_id' => $user->id,
            'amount_minor' => 30000,
            'currency' => 'JMD',
            'payment_channel' => 'card',
            'status' => 'completed',
            'provider' => 'wipay',
            'provider_event_id' => 'evt_receipt_test_1',
            'idempotency_key' => 'idem_receipt_test_1',
        ]);

        PaymentReceipt::create([
            'transaction_id' => $tx->id,
            'receipt_number' => 'REC-2026-00001',
            'amount_minor' => 30000,
            'currency' => 'JMD',
            'payer_name' => $user->name,
            'issued_at' => now(),
        ]);

        $this->assertSame(1, PaymentReceipt::count());

        // Attempting to insert a second receipt for the same transaction MUST throw QueryException
        $this->expectException(QueryException::class);

        PaymentReceipt::create([
            'transaction_id' => $tx->id, // Duplicate!
            'receipt_number' => 'REC-2026-00002',
            'amount_minor' => 30000,
            'currency' => 'JMD',
            'payer_name' => $user->name,
            'issued_at' => now(),
        ]);
    }
}
