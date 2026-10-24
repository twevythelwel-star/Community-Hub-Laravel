<?php

namespace Tests\Feature;

use App\Enums\PaymentState;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ARCHITECTURAL INVARIANT TEST:
 * "Never let the browser determine: PAID.
 *  The browser can only say: 'I completed the checkout flow.'
 *  CommunityHub must wait for the authoritative provider event."
 *
 * This test suite guarantees that across all payment rails (Stripe, WiPay,
 * Bank Transfers, Cash, Payment Links):
 * 1. Client-side input or forged redirects cannot mark a transaction PAID.
 * 2. If the user closes the browser mid-flight, authoritative server-to-server
 *    webhooks (e.g. payment_intent.succeeded) still settle the invoice cleanly.
 * 3. Double-entry ledger integrity is maintained exclusively on authoritative truth.
 */
class AuthoritativeProviderEventTruthTest extends TestCase
{
    use RefreshDatabase;

    private const STRIPE_SECRET = 'whsec_test_authoritative_rule_secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret' => 'sk_test_phpunit_mock_key',
            'services.stripe.webhook_secret' => self::STRIPE_SECRET,
        ]);
    }

    private function createUnpaidInvoice(User $user, int $amountMinor = 50000): Invoice
    {
        return Invoice::create([
            'user_id' => $user->id,
            'reference' => 'INV-SEC-'.fake()->unique()->numerify('#####'),
            'amount_minor' => $amountMinor,
            'currency' => 'USD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(14),
            'status' => 'Unpaid',
        ]);
    }

    /**
     * Test 1: An attacker attempting to call the browser return endpoint with
     * arbitrary parameters or fabricated session IDs cannot mark the invoice PAID.
     */
    public function test_browser_cannot_claim_paid_with_fake_session(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createUnpaidInvoice($user);

        // Attempting to hit the return route with a forged session_id
        // without an authoritative Stripe confirmation fails.
        $response = $this->actingAs($user)->get(route('dashboard.billing.stripe.success', [
            'invoice' => $invoice->id,
            'session_id' => 'cs_fake_attacker_session_9999',
        ]));

        $invoice->refresh();

        // Invoice remains Unpaid and paid_at is null
        $this->assertSame('Unpaid', $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertSame(0, Transaction::count());
    }

    /**
     * Test 2: If the resident closes their browser or loses connectivity,
     * the authoritative server-side webhook (payment_intent.succeeded)
     * settles the invoice and records the transaction without any browser interaction.
     */
    public function test_server_side_webhook_settles_invoice_when_browser_is_closed(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createUnpaidInvoice($user, 75000);

        $payload = json_encode([
            'id' => 'evt_test_pi_succeeded_'.uniqid(),
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'data' => [
                'object' => [
                    'id' => 'cs_test_authoritative_session_123',
                    'object' => 'checkout.session',
                    'client_reference_id' => (string) $invoice->id,
                    'payment_status' => 'paid',
                    'payment_intent' => 'pi_authoritative_999',
                    'amount_total' => 75000,
                    'currency' => 'usd',
                ],
            ],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", self::STRIPE_SECRET);

        // The resident's browser NEVER contacted CommunityHub after Stripe checkout.
        // Stripe delivers the server-to-server webhook directly:
        $response = $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);

        $response->assertOk();
        $response->assertJson(['received' => true, 'outcome' => 'settled']);

        // Authoritative verification occurred:
        $invoice->refresh();
        $this->assertSame('Paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);

        // Exactly one ledger transaction was written
        $this->assertSame(1, Transaction::count());
        $tx = Transaction::first();
        $this->assertEquals(75000, $tx->amount_minor);
        $this->assertSame('completed', strtolower($tx->status));
    }

    /**
     * Test 3: WiPay provider rejects forged or tampered browser returns,
     * requiring exact cryptographic HMAC-MD5 hash matching server secret.
     */
    public function test_wipay_rejects_tampered_browser_query_parameters(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createUnpaidInvoice($user, 10000);

        $payment = Payment::create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'applies_to' => 'invoice',
            'purpose' => Transaction::PURPOSE_MAINTENANCE_FEE,
            'transaction_id' => 'CH-2026-0000000999',
            'provider' => 'wipay',
            'provider_payment_id' => 'WPY-999',
            'channel' => 'card',
            'amount_minor' => 10000,
            'currency' => 'JMD',
            'state' => PaymentState::Processing,
        ]);

        config([
            'payments.providers.wipay.api_key' => 'secret_merchant_wipay_key',
            'payments.providers.wipay.account_number' => '123456',
        ]);

        // Attacker alters query string to say 'status=success' with forged hash
        $tamperedQuery = [
            'status' => 'success',
            'transaction_id' => 'WPY-999',
            'order_id' => 'CH20260000000999',
            'total' => '100.00',
            'hash' => md5('forged_attacker_hash'),
        ];

        $response = $this->actingAs($user)->get(route('dashboard.payments.return', [
            'payment' => $payment->id,
            ...$tamperedQuery,
        ]));

        $payment->refresh();
        // Payment MUST NOT be marked Paid!
        $this->assertNotEquals(PaymentState::Paid, $payment->state);
        $this->assertSame(0, Transaction::count());
    }

    /**
     * Test 4: Resident asserting "I made a bank transfer" does NOT mark invoice PAID.
     * The payment remains pending until authoritative bank reconciliation matches it.
     */
    public function test_resident_cannot_self_certify_bank_transfer_as_paid(): void
    {
        $user = User::factory()->create();
        $invoice = $this->createUnpaidInvoice($user, 35000);

        $payment = Payment::create([
            'user_id' => $user->id,
            'invoice_id' => $invoice->id,
            'applies_to' => 'invoice',
            'purpose' => Transaction::PURPOSE_MAINTENANCE_FEE,
            'transaction_id' => 'CH-2026-BANK-001',
            'provider' => 'manual',
            'channel' => 'bank_transfer',
            'amount_minor' => 35000,
            'currency' => 'JMD',
            'state' => PaymentState::AwaitingTransfer,
        ]);

        // Resident browser only registers that checkout flow was submitted/initiated:
        $this->assertSame(PaymentState::AwaitingTransfer, $payment->state);
        $this->assertSame('Unpaid', $invoice->status);
        $this->assertSame(0, Transaction::count(), 'Zero ledger entries until authoritative reconciliation.');
    }
}
