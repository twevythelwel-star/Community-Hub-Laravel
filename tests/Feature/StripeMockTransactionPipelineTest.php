<?php

namespace Tests\Feature;

use App\Enums\PaymentState;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\PaymentOrchestratorService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * STRIPE TEST MODE END-TO-END FINANCIAL WORKFLOW SUITE
 *
 * Proves the 4 Core Enterprise Financial Invariants:
 * 1. Test 1 — Successful payment: Full workflow settles invoice, posts balanced ledger (+75,000), generates receipt, sends Twilio alert.
 * 2. Test 2 — Declined payment: Stripe test decline (payment_intent.payment_failed) marks transaction FAILED, leaves invoice UNPAID, zero ledger posting.
 * 3. Test 3 — Duplicate webhook (Idempotency): Re-sending the identical Stripe event does NOT create a second ledger entry (exact 1 ledger row, exact 1 receipt).
 * 4. Test 4 — Refund: Succeeded payment followed by charge.refunded triggers ledger reversal, updates payment to REFUNDED, and re-opens the invoice.
 */
class StripeMockTransactionPipelineTest extends TestCase
{
    use RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_test_mock_pipeline_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret' => 'sk_test_phpunit_mock_key',
            'services.stripe.webhook_secret' => self::WEBHOOK_SECRET,
        ]);
    }

    private function deliverWebhook(string $type, array $object, ?string $eventId = null): TestResponse
    {
        $payload = [
            'id' => $eventId ?? 'evt_test_'.Str::random(24),
            'object' => 'event',
            'type' => $type,
            'data' => [
                'object' => $object,
            ],
        ];

        $payloadJson = json_encode($payload);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payloadJson}", self::WEBHOOK_SECRET);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payloadJson);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 1 — Successful payment
    // ─────────────────────────────────────────────────────────────────────────
    public function test_1_successful_payment(): void
    {
        $homeowner = User::factory()->create([
            'name' => 'Alexander Wright',
            'phone' => '+18765550199',
            'lot' => 'Lot 42B',
        ]);

        $amountMinor = 7500000; // JMD $75,000.00
        $invoice = Invoice::create([
            'user_id' => $homeowner->id,
            'reference' => 'INV-4821',
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(14),
            'status' => 'Unpaid',
            'description' => 'CommunityHub Invoice INV-4821',
        ]);

        $orchestrator = app(PaymentOrchestratorService::class);
        $payment = $orchestrator->startPayment([
            'user' => $homeowner,
            'channel' => 'card',
            'invoice' => $invoice,
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'purpose' => 'CommunityHub Invoice INV-4821',
            'source' => 'stripe:test_mode',
        ]);

        $this->assertSame(PaymentState::Created, $payment->state);

        $smsMock = Mockery::mock(SmsService::class);
        $smsMock->shouldReceive('isConfigured')->andReturn(true);
        $smsMock->shouldReceive('send')->once()->andReturn('SM_TEST_TWILIO_SID_12345');
        $this->app->instance(SmsService::class, $smsMock);

        $metadata = $payment->toProcessorMetadata();

        // Verify the 6 Mandatory Non-Custodial Metadata Identifiers
        $this->assertSame($payment->transaction_id, $metadata['communityhub_transaction_id']);
        $this->assertSame(Transaction::formatUserCode($homeowner), $metadata['user_id']);
        $this->assertSame(Transaction::formatPropertyCode($homeowner, $invoice), $metadata['property_id']);
        $this->assertSame('COMM-001', $metadata['community_id']);
        $this->assertSame('INV-4821', $metadata['invoice_id']);
        $this->assertSame('HOA_ASSESSMENT', $metadata['payment_type']);

        $paymentIntentId = 'pi_test_'.Str::random(24);

        $piData = [
            'id' => $paymentIntentId,
            'object' => 'payment_intent',
            'amount' => $amountMinor,
            'currency' => 'jmd',
            'status' => 'succeeded',
            'payment_method' => 'pm_card_visa',
            'description' => 'CommunityHub Invoice INV-4821',
            'metadata' => $metadata,
        ];

        $response = $this->deliverWebhook('payment_intent.succeeded', $piData);
        $response->assertOk()->assertJson(['received' => true, 'outcome' => 'settled']);

        $payment->refresh();
        $invoice->refresh();

        // 1. Transaction = PAID
        $this->assertSame(PaymentState::Paid, $payment->state);

        // 2. Invoice = PAID
        $this->assertSame('Paid', $invoice->status);
        $this->assertSame(0, $invoice->balanceRemainingMinor());

        // 3. Ledger updated (+75,000)
        $ledgerRow = Transaction::where('payment_id', $payment->id)->first();
        $this->assertNotNull($ledgerRow);
        $this->assertSame('completed', strtolower($ledgerRow->status));
        $this->assertSame(7500000, $ledgerRow->amount_minor);

        // 4. Receipt generated
        $receipt = PaymentReceipt::where('transaction_id', $ledgerRow->id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame(7500000, $receipt->amount_minor);
        $this->assertSame('Alexander Wright', $receipt->payer_name);

        // 5. Public Slip
        $slip = $payment->toSlip();
        $this->assertSame('PAID', $slip['status']);
        $this->assertSame('75000.00', $slip['amount']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 2 — Declined payment
    // ─────────────────────────────────────────────────────────────────────────
    public function test_2_declined_payment_leaves_invoice_unpaid(): void
    {
        $homeowner = User::factory()->create([
            'name' => 'Alexander Wright',
            'phone' => '+18765550199',
            'lot' => 'Lot 42B',
        ]);

        $amountMinor = 7500000;
        $invoice = Invoice::create([
            'user_id' => $homeowner->id,
            'reference' => 'INV-DECLINED-75000',
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(14),
            'status' => 'Unpaid',
            'description' => 'HOA Assessment',
        ]);

        $orchestrator = app(PaymentOrchestratorService::class);
        $payment = $orchestrator->startPayment([
            'user' => $homeowner,
            'channel' => 'card',
            'invoice' => $invoice,
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'purpose' => 'HOA Assessment',
            'source' => 'stripe:test_mode',
        ]);

        $paymentIntentId = 'pi_test_declined_'.Str::random(16);

        $piDeclinedData = [
            'id' => $paymentIntentId,
            'object' => 'payment_intent',
            'amount' => $amountMinor,
            'currency' => 'jmd',
            'status' => 'requires_payment_method',
            'last_payment_error' => [
                'message' => 'Your card was declined. Your card has insufficient funds.',
                'code' => 'card_declined',
                'decline_code' => 'insufficient_funds',
            ],
            'metadata' => $payment->toProcessorMetadata(),
        ];

        // Deliver Stripe decline webhook
        $response = $this->deliverWebhook('payment_intent.payment_failed', $piDeclinedData);
        $response->assertOk();

        $payment->refresh();
        $invoice->refresh();

        // 1. Transaction FAILED
        $this->assertSame(PaymentState::Failed, $payment->state);
        $this->assertSame('Your card was declined. Your card has insufficient funds.', $payment->failure_reason);

        // 2. Invoice remains UNPAID
        $this->assertSame('Unpaid', $invoice->status);
        $this->assertSame(7500000, $invoice->balanceRemainingMinor());
        $this->assertNull($invoice->paid_at);

        // 3. No double-entry ledger payment posted
        $completedTxs = Transaction::where('payment_id', $payment->id)
            ->where('status', Transaction::STATUS_COMPLETED)
            ->count();
        $this->assertSame(0, $completedTxs);

        // 4. User can retry (Invoice balance remains full amount)
        $this->assertSame(7500000, $invoice->fresh()->balanceRemainingMinor());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 3 — Duplicate webhook (Idempotency)
    // ─────────────────────────────────────────────────────────────────────────
    public function test_3_duplicate_webhook_idempotency_prevents_double_ledger(): void
    {
        $homeowner = User::factory()->create(['name' => 'Alexander Wright', 'phone' => '+18765550199', 'lot' => 'Lot 42B']);
        $amountMinor = 7500000;
        $invoice = Invoice::create([
            'user_id' => $homeowner->id,
            'reference' => 'INV-IDEM-75000',
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(14),
            'status' => 'Unpaid',
            'description' => 'HOA Assessment',
        ]);

        $orchestrator = app(PaymentOrchestratorService::class);
        $payment = $orchestrator->startPayment([
            'user' => $homeowner,
            'channel' => 'card',
            'invoice' => $invoice,
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'purpose' => 'HOA Assessment',
            'source' => 'stripe:test_mode',
        ]);

        $paymentIntentId = 'pi_test_idem_'.Str::random(16);
        $eventId = 'evt_test_fixed_event_id_'.Str::random(12);

        $piData = [
            'id' => $paymentIntentId,
            'object' => 'payment_intent',
            'amount' => $amountMinor,
            'currency' => 'jmd',
            'status' => 'succeeded',
            'metadata' => $payment->toProcessorMetadata(),
        ];

        // Webhook #1: PAID
        $resp1 = $this->deliverWebhook('payment_intent.succeeded', $piData, $eventId);
        $resp1->assertOk()->assertJson(['received' => true, 'outcome' => 'settled']);

        $this->assertSame(1, Transaction::where('payment_id', $payment->id)->where('status', Transaction::STATUS_COMPLETED)->count());
        $this->assertSame(1, PaymentReceipt::count());

        // Webhook #2: Same Stripe event delivered again
        $resp2 = $this->deliverWebhook('payment_intent.succeeded', $piData, $eventId);
        $resp2->assertOk()->assertJson(['received' => true, 'outcome' => 'duplicate']);

        // CRITICAL INVARIANT: Exact 1 transaction, exact 1 receipt
        $this->assertSame(1, Transaction::where('payment_id', $payment->id)->where('status', Transaction::STATUS_COMPLETED)->count());
        $this->assertSame(1, PaymentReceipt::count());
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Test 4 — Refund
    // ─────────────────────────────────────────────────────────────────────────
    public function test_4_refund_reverses_ledger_and_updates_status(): void
    {
        $homeowner = User::factory()->create(['name' => 'Alexander Wright', 'phone' => '+18765550199', 'lot' => 'Lot 42B']);
        $amountMinor = 7500000;
        $invoice = Invoice::create([
            'user_id' => $homeowner->id,
            'reference' => 'INV-REFUND-75000',
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(14),
            'status' => 'Unpaid',
            'description' => 'HOA Assessment',
        ]);

        $orchestrator = app(PaymentOrchestratorService::class);
        $payment = $orchestrator->startPayment([
            'user' => $homeowner,
            'channel' => 'card',
            'invoice' => $invoice,
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'purpose' => 'HOA Assessment',
            'source' => 'stripe:test_mode',
        ]);

        $paymentIntentId = 'pi_test_refund_'.Str::random(16);

        // 1. Settle initial payment
        $piData = [
            'id' => $paymentIntentId,
            'object' => 'payment_intent',
            'amount' => $amountMinor,
            'currency' => 'jmd',
            'status' => 'succeeded',
            'metadata' => $payment->toProcessorMetadata(),
        ];

        $resp = $this->deliverWebhook('payment_intent.succeeded', $piData);
        $resp->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentState::Paid, $payment->state);
        $this->assertSame('Paid', $invoice->fresh()->status);

        // 2. Deliver charge.refunded webhook from Stripe
        $chargeId = 'ch_test_'.Str::random(16);
        $chargeData = [
            'id' => $chargeId,
            'object' => 'charge',
            'payment_intent' => $paymentIntentId,
            'amount' => $amountMinor,
            'amount_refunded' => $amountMinor,
            'currency' => 'jmd',
            'refunded' => true,
        ];

        $refundResp = $this->deliverWebhook('charge.refunded', $chargeData);
        $refundResp->assertOk();

        // 3. Verify Refunded Invariants
        $payment->refresh();
        $invoice->refresh();

        // Payment = REFUNDED
        $this->assertSame(PaymentState::Refunded, $payment->state);

        // Ledger reversal posted
        $reversalTx = Transaction::where('payment_id', $payment->id)
            ->where('status', 'refunded')
            ->first();
        $this->assertNotNull($reversalTx);
        $this->assertSame(-7500000, $reversalTx->net_amount_minor);

        // Invoice reopened as Unpaid
        $this->assertSame('Unpaid', $invoice->status);
        $this->assertSame(7500000, $invoice->balanceRemainingMinor());
    }
}
