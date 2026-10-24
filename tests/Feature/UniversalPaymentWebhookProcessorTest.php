<?php

namespace Tests\Feature;

use App\Enums\PaymentState;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Models\PaymentReceipt;
use App\Models\PaymentRefund;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Payments\UniversalPaymentWebhookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Universal Multi-Provider Webhook Processor Test Suite
 * Route: POST /api/webhooks/payments/{provider}
 *
 * Verifies the complete 14-step verified processing pipeline:
 * Incoming -> Authenticate -> Verify Sig -> Check Idempotent -> Find Tx
 * -> Verify (Amount, Currency, Merchant, State) -> Process Event -> Ledger -> Invoice -> Receipt -> Notification
 */
class UniversalPaymentWebhookProcessorTest extends TestCase
{
    use RefreshDatabase;

    private const WIPAY_API_KEY = 'test_wipay_secret_api_key_123';

    private const GENERIC_SECRET = 'test_generic_webhook_secret_xyz';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.providers.wipay.api_key' => self::WIPAY_API_KEY,
            'payments.providers.wipay.account_number' => 'ACC-998877',
            'payments.providers.generic.webhook_secret' => self::GENERIC_SECRET,
            'payments.providers.generic.account_number' => 'GEN-112233',
        ]);
    }

    private function createUnpaidInvoice(User $user, int $amountMinor = 25000): Invoice
    {
        return Invoice::create([
            'user_id' => $user->id,
            'reference' => 'INV-WH-'.fake()->unique()->numerify('#####'),
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(10),
            'status' => 'Unpaid',
        ]);
    }

    private function createPaymentAttempt(Invoice $invoice, string $provider = 'wipay', int $amountMinor = 25000, string $channel = 'card'): Payment
    {
        return Payment::create([
            'user_id' => $invoice->user_id,
            'invoice_id' => $invoice->id,
            'applies_to' => 'invoice',
            'purpose' => Transaction::PURPOSE_MAINTENANCE_FEE,
            'transaction_id' => 'CH-2026-'.fake()->unique()->numerify('##########'),
            'provider' => $provider,
            'provider_payment_id' => 'PROV-TX-'.fake()->unique()->numerify('####'),
            'channel' => $channel,
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'state' => PaymentState::Processing,
        ]);
    }

    /**
     * Helper to deliver signed webhook to POST /api/webhooks/payments/{provider}
     */
    private function deliverWebhook(string $provider, array $payload, ?string $signature = null, ?string $secret = null): TestResponse
    {
        $raw = json_encode($payload);
        $sec = $secret ?? self::WIPAY_API_KEY;
        $sig = $signature ?? hash_hmac('sha256', $raw, $sec);

        return $this->call('POST', "/api/webhooks/payments/{$provider}", [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SIGNATURE' => $sig,
            'HTTP_X_WIPAY_SIGNATURE' => $sig,
        ], $raw);
    }

    /**
     * Test 1: Full 14-Step Pipeline Execution for Successful Payment
     */
    public function test_full_pipeline_processes_payment_success(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createUnpaidInvoice($user, 45000);
        $payment = $this->createPaymentAttempt($invoice, 'wipay', 45000);

        $eventId = 'evt_wipay_success_001';
        $payload = [
            'event_id' => $eventId,
            'type' => 'approved',
            'provider_payment_id' => $payment->provider_payment_id,
            'order_id' => str_replace('-', '', $payment->transaction_id),
            'amount_minor' => 45000,
            'currency' => 'JMD',
            'account_number' => 'ACC-998877',
            'status' => 'success',
            'message' => 'Approved by card processor',
        ];

        $response = $this->deliverWebhook('wipay', $payload);

        $response->assertOk();
        $response->assertJson([
            'received' => true,
            'outcome' => 'processed',
            'event_id' => $eventId,
            'canonical_event' => UniversalPaymentWebhookService::EVENT_SUCCESS,
        ]);

        // Verify Database State Machine Transitions:
        $payment->refresh();
        $this->assertSame(PaymentState::Paid, $payment->state);

        // Verify Invoice Settled:
        $invoice->refresh();
        $this->assertSame('Paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);

        // Verify General Ledger double-entry posted:
        $this->assertSame(1, Transaction::count());
        $tx = Transaction::first();
        $this->assertSame('completed', strtolower($tx->status));
        $this->assertEquals(45000, $tx->amount_minor);

        // Verify PaymentReceipt Generated:
        $this->assertSame(1, PaymentReceipt::count());
        $receipt = PaymentReceipt::first();
        $this->assertSame($tx->id, $receipt->transaction_id);
        $this->assertEquals(45000, $receipt->amount_minor);
        $this->assertSame('JMD', $receipt->currency);
        $this->assertStringStartsWith('REC-2026-', $receipt->receipt_number);

        // Verify PaymentEvent Audit Log:
        $event = PaymentEvent::where('event_id', $eventId)->first();
        $this->assertNotNull($event);
        $this->assertSame('processed', $event->status);
        $this->assertSame($tx->id, $event->transaction_id);
    }

    /**
     * Test 2: Idempotency Guard - Duplicate Deliveries Stop Immediately with 200 OK
     */
    public function test_idempotent_duplicate_webhook_stops_without_reprocessing(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createUnpaidInvoice($user, 30000);
        $payment = $this->createPaymentAttempt($invoice, 'wipay', 30000);

        $eventId = 'evt_wipay_duplicate_test';
        $payload = [
            'event_id' => $eventId,
            'type' => 'approved',
            'provider_payment_id' => $payment->provider_payment_id,
            'order_id' => str_replace('-', '', $payment->transaction_id),
            'amount_minor' => 30000,
            'currency' => 'JMD',
            'account_number' => 'ACC-998877',
        ];

        // First delivery: processes
        $res1 = $this->deliverWebhook('wipay', $payload);
        $res1->assertOk()->assertJson(['outcome' => 'processed']);

        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, PaymentReceipt::count());

        // Second delivery of same event ID: returns duplicate, stops immediately
        $res2 = $this->deliverWebhook('wipay', $payload);
        $res2->assertOk()->assertJson([
            'received' => true,
            'outcome' => 'duplicate',
            'event_id' => $eventId,
        ]);

        // Zero duplicate transactions or receipts:
        $this->assertSame(1, Transaction::count());
        $this->assertSame(1, PaymentReceipt::count());
    }

    /**
     * Test 3: Forged or Tampered Signature is Rejected with 400 Bad Request
     */
    public function test_tampered_signature_is_rejected_with_400(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createUnpaidInvoice($user, 20000);
        $payment = $this->createPaymentAttempt($invoice, 'wipay', 20000);

        $payload = [
            'event_id' => 'evt_forged_999',
            'type' => 'approved',
            'provider_payment_id' => $payment->provider_payment_id,
            'amount_minor' => 20000,
            'currency' => 'JMD',
        ];

        // Deliver with forged signature:
        $response = $this->deliverWebhook('wipay', $payload, 'bad_signature_hash');

        $response->assertStatus(400);
        $response->assertJsonStructure(['error']);

        // Zero database mutations:
        $payment->refresh();
        $this->assertSame(PaymentState::Processing, $payment->state);
        $this->assertSame(0, Transaction::count());
    }

    /**
     * Test 4: Amount Mismatch on Webhook Payload is Refused
     */
    public function test_amount_mismatch_is_rejected(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createUnpaidInvoice($user, 50000);
        $payment = $this->createPaymentAttempt($invoice, 'wipay', 50000);

        // Attacker claims payment of only 1,000 minor units
        $payload = [
            'event_id' => 'evt_mismatch_001',
            'type' => 'approved',
            'provider_payment_id' => $payment->provider_payment_id,
            'amount_minor' => 1000,
            'currency' => 'JMD',
            'account_number' => 'ACC-998877',
        ];

        $response = $this->deliverWebhook('wipay', $payload);

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'Amount mismatch: expected [50000], received [1000].']);

        // Payment remains unpaid
        $payment->refresh();
        $this->assertSame(PaymentState::Processing, $payment->state);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    /**
     * Test 5: Currency Mismatch on Webhook Payload is Refused
     */
    public function test_currency_mismatch_is_rejected(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createUnpaidInvoice($user, 50000);
        $payment = $this->createPaymentAttempt($invoice, 'wipay', 50000);

        $payload = [
            'event_id' => 'evt_curr_mismatch_001',
            'type' => 'approved',
            'provider_payment_id' => $payment->provider_payment_id,
            'amount_minor' => 50000,
            'currency' => 'EUR', // Expected JMD!
            'account_number' => 'ACC-998877',
        ];

        $response = $this->deliverWebhook('wipay', $payload);

        $response->assertStatus(400);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    /**
     * Test 6: Provider Event Mapping for Payment Failure
     */
    public function test_failure_event_transitions_payment_to_failed(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createUnpaidInvoice($user, 15000);
        $payment = $this->createPaymentAttempt($invoice, 'wipay', 15000);

        $eventId = 'evt_failed_001';
        $payload = [
            'event_id' => $eventId,
            'type' => 'declined',
            'provider_payment_id' => $payment->provider_payment_id,
            'amount_minor' => 15000,
            'currency' => 'JMD',
            'account_number' => 'ACC-998877',
            'failure_reason' => 'Insufficient funds in payer card account',
        ];

        $response = $this->deliverWebhook('wipay', $payload);

        $response->assertOk();
        $payment->refresh();
        $this->assertSame(PaymentState::Failed, $payment->state);
        $this->assertSame('Insufficient funds in payer card account', $payment->failure_reason);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    /**
     * Test 7: Provider Event Mapping for Refunds & Disputes
     */
    public function test_refund_event_updates_ledger_and_records_payment_refund(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createUnpaidInvoice($user, 25000);
        $payment = $this->createPaymentAttempt($invoice, 'wipay', 25000);

        // Settle payment first
        $eventId1 = 'evt_pay_first';
        $this->deliverWebhook('wipay', [
            'event_id' => $eventId1,
            'type' => 'approved',
            'provider_payment_id' => $payment->provider_payment_id,
            'amount_minor' => 25000,
            'currency' => 'JMD',
            'account_number' => 'ACC-998877',
        ])->assertOk();

        $this->assertSame(PaymentState::Paid, $payment->fresh()->state);

        // Deliver refund event
        $refundEventId = 'evt_refund_999';
        $this->deliverWebhook('wipay', [
            'event_id' => $refundEventId,
            'type' => 'refunded',
            'provider_payment_id' => $payment->provider_payment_id,
            'amount_minor' => 25000,
            'currency' => 'JMD',
            'account_number' => 'ACC-998877',
        ])->assertOk();

        $payment->refresh();
        $this->assertSame(PaymentState::Refunded, $payment->state);

        // Verify PaymentRefund created:
        $refund = PaymentRefund::where('provider_refund_id', $refundEventId)->first();
        $this->assertNotNull($refund);
        $this->assertEquals(25000, $refund->amount_minor);
        $this->assertSame('succeeded', $refund->status);
    }

    /**
     * Test 8: Universal Endpoint Handles Stripe Webhook Delegation with Canonical Event Recording
     */
    public function test_stripe_webhook_delegation_via_universal_endpoint(): void
    {
        config([
            'services.stripe.secret' => 'sk_test_mock_stripe_key',
            'services.stripe.webhook_secret' => 'whsec_test_universal_stripe_secret',
        ]);

        $user = User::factory()->create();
        $invoice = $this->createUnpaidInvoice($user, 35000);

        $eventId = 'evt_stripe_universal_test_001';
        $payload = json_encode([
            'id' => $eventId,
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_universal_session',
                    'object' => 'checkout.session',
                    'client_reference_id' => (string) $invoice->id,
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_universal_stripe_123',
                    'amount_total' => 35000,
                    'currency' => 'jmd',
                ],
            ],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test_universal_stripe_secret');

        $response = $this->call('POST', '/api/webhooks/payments/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);

        $response->assertOk();
        $response->assertJson(['received' => true, 'outcome' => 'settled', 'event_id' => $eventId]);

        $invoice->refresh();
        $this->assertSame('Paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);

        // Verify PaymentEvent recorded
        $event = PaymentEvent::where('provider', 'stripe')->where('event_id', $eventId)->first();
        $this->assertNotNull($event);
        $this->assertSame('checkout.session.completed', $event->event_type);
        $this->assertSame('processed', $event->status);
    }

    public function test_one_providers_event_cannot_settle_another_providers_payment(): void
    {
        config(['payments.providers.paypal.webhook_secret' => 'paypal_secret_for_test']);

        $invoice = $this->createUnpaidInvoice(User::factory()->create(), 25000);
        $payment = $this->createPaymentAttempt($invoice, 'wipay', 25000);

        $raw = json_encode([
            'id' => 'WH-PAYPAL-1',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'order_id' => $payment->transaction_id,
            'amount_minor' => 25000,
            'currency' => 'JMD',
        ]);

        $this->call('POST', '/api/webhooks/payments/paypal', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => hash_hmac('sha256', $raw, 'paypal_secret_for_test'),
        ], $raw)->assertOk()->assertJson(['outcome' => 'unmatched']);

        $this->assertSame(PaymentState::Processing, $payment->fresh()->state);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_paypal_has_no_built_in_fallback_secret(): void
    {
        $invoice = $this->createUnpaidInvoice(User::factory()->create(), 25000);
        $payment = $this->createPaymentAttempt($invoice, 'paypal', 25000);

        // Signed with the secret the endpoint used to fall back to.
        $raw = json_encode([
            'id' => 'WH-PAYPAL-FORGED',
            'event_type' => 'PAYMENT.CAPTURE.COMPLETED',
            'provider_payment_id' => $payment->provider_payment_id,
            'amount_minor' => 25000,
            'currency' => 'JMD',
        ]);

        $this->call('POST', '/api/webhooks/payments/paypal', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PAYPAL_TRANSMISSION_SIG' => hash_hmac('sha256', $raw, 'test_paypal_secret'),
        ], $raw)->assertStatus(400);

        $this->assertSame(PaymentState::Processing, $payment->fresh()->state);
    }

    public function test_a_success_event_without_an_amount_is_rejected(): void
    {
        $invoice = $this->createUnpaidInvoice(User::factory()->create(), 25000);
        $payment = $this->createPaymentAttempt($invoice, 'wipay', 25000);

        $this->deliverWebhook('wipay', [
            'event_id' => 'evt_no_amount',
            'type' => 'approved',
            'provider_payment_id' => $payment->provider_payment_id,
            'currency' => 'JMD',
        ])->assertStatus(400);

        $this->assertSame(PaymentState::Processing, $payment->fresh()->state);
    }

    public function test_an_invoice_number_as_order_id_matches_nothing(): void
    {
        $invoice = $this->createUnpaidInvoice(User::factory()->create(), 25000);
        $payment = $this->createPaymentAttempt($invoice, 'wipay', 25000);

        $this->deliverWebhook('wipay', [
            'event_id' => 'evt_invoice_id',
            'type' => 'approved',
            'order_id' => (string) $invoice->id,
            'amount_minor' => 25000,
            'currency' => 'JMD',
        ])->assertOk()->assertJson(['outcome' => 'unmatched']);

        $this->assertSame(PaymentState::Processing, $payment->fresh()->state);
    }

    public function test_office_channels_cannot_be_confirmed_by_webhook(): void
    {
        $this->deliverWebhook('cash', ['event_id' => 'evt_cash', 'type' => 'paid'])->assertStatus(400);
    }

    public function test_a_partial_refund_event_books_only_the_refunded_amount(): void
    {
        $invoice = $this->createUnpaidInvoice(User::factory()->create(), 25000);
        $payment = $this->createPaymentAttempt($invoice, 'wipay', 25000);

        $this->deliverWebhook('wipay', [
            'event_id' => 'evt_pay_partial',
            'type' => 'approved',
            'provider_payment_id' => $payment->provider_payment_id,
            'amount_minor' => 25000,
            'currency' => 'JMD',
        ])->assertOk();

        $this->deliverWebhook('wipay', [
            'event_id' => 'evt_refund_partial',
            'type' => 'refunded',
            'provider_payment_id' => $payment->provider_payment_id,
            'amount_minor' => 5000,
            'currency' => 'JMD',
        ])->assertOk();

        $this->assertSame(5000, (int) PaymentRefund::sole()->amount_minor);
        $this->assertSame(PaymentState::PartiallyRefunded, $payment->fresh()->state);
    }
}
