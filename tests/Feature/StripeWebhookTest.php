<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\StripeEvent;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Stripe webhooks, end to end through the real StripePaymentService.
 *
 * Nothing here reaches Stripe: the handled events carry everything needed in
 * their payload, and the signature is computed with a test secret exactly as
 * Stripe computes it (HMAC-SHA256 of "timestamp.payload").
 */
class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_test_secret_for_phpunit';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret' => 'sk_test_phpunit_not_real',
            'services.stripe.webhook_secret' => self::SECRET,
        ]);
    }

    private function invoice(array $overrides = []): Invoice
    {
        return Invoice::create([
            'user_id' => User::factory()->create()->id,
            'reference' => 'INV-WH-'.fake()->unique()->numerify('####'),
            'amount_minor' => 25000,
            'currency' => 'USD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(15),
            'status' => 'Unpaid',
            ...$overrides,
        ]);
    }

    /** @param  array<string, mixed>  $object */
    private function deliver(string $type, array $object, ?string $eventId = null, ?string $secret = null, ?int $timestamp = null): TestResponse
    {
        $payload = json_encode([
            'id' => $eventId ?? 'evt_'.fake()->unique()->bothify('????????'),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        $timestamp ??= time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", $secret ?? self::SECRET);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);
    }

    /** @return array<string, mixed> */
    private function paidSession(Invoice $invoice, array $overrides = []): array
    {
        return [
            'id' => 'cs_test_'.$invoice->id,
            'object' => 'checkout.session',
            'client_reference_id' => (string) $invoice->id,
            'payment_status' => 'paid',
            'payment_intent' => 'pi_test_'.$invoice->id,
            'amount_total' => $invoice->amount_minor,
            'currency' => strtolower($invoice->currency),
            ...$overrides,
        ];
    }

    /** @return array<string, mixed> */
    private function refundedCharge(string $paymentIntent, int $amount, int $amountRefunded): array
    {
        return [
            'id' => 'ch_'.$paymentIntent,
            'object' => 'charge',
            'payment_intent' => $paymentIntent,
            'amount' => $amount,
            'amount_refunded' => $amountRefunded,
            'currency' => 'usd',
        ];
    }

    // ── Verification ──

    public function test_the_endpoint_does_not_exist_without_a_signing_secret(): void
    {
        config(['services.stripe.webhook_secret' => null]);
        $invoice = $this->invoice();

        $this->deliver('checkout.session.completed', $this->paidSession($invoice))->assertNotFound();

        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_a_forged_signature_is_rejected_and_changes_nothing(): void
    {
        $invoice = $this->invoice();

        $this->deliver('checkout.session.completed', $this->paidSession($invoice), secret: 'whsec_attacker')
            ->assertStatus(400);

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertDatabaseCount('stripe_events', 0);
    }

    public function test_a_replayed_old_delivery_is_rejected(): void
    {
        $invoice = $this->invoice();

        $this->deliver('checkout.session.completed', $this->paidSession($invoice), timestamp: time() - 3600)
            ->assertStatus(400);

        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    // ── Settlement ──

    public function test_a_completed_checkout_settles_the_invoice_and_records_the_payment(): void
    {
        $invoice = $this->invoice();

        $this->deliver('checkout.session.completed', $this->paidSession($invoice))
            ->assertOk()
            ->assertJson(['outcome' => 'settled']);

        $invoice->refresh();
        $this->assertSame('Paid', $invoice->status);
        $this->assertNotNull($invoice->paid_at);
        $this->assertSame('pi_test_'.$invoice->id, $invoice->stripe_payment_intent);

        $payment = Transaction::sole();
        $this->assertSame('stripe:pi_test_'.$invoice->id, $payment->reference);
        $this->assertSame('completed', $payment->status);
        $this->assertSame(25000, $payment->amount_minor);
        $this->assertSame('stripe_card', $payment->payment_channel);
    }

    public function test_a_retried_delivery_of_the_same_event_records_the_payment_once(): void
    {
        $invoice = $this->invoice();

        $this->deliver('checkout.session.completed', $this->paidSession($invoice), 'evt_same')->assertOk();
        $this->deliver('checkout.session.completed', $this->paidSession($invoice), 'evt_same')
            ->assertOk()
            ->assertJson(['outcome' => 'duplicate']);

        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('stripe_events', 1);
    }

    public function test_two_different_events_for_one_payment_record_it_once(): void
    {
        $invoice = $this->invoice();

        $this->deliver('checkout.session.completed', $this->paidSession($invoice))->assertOk();
        $this->deliver('checkout.session.async_payment_succeeded', $this->paidSession($invoice))
            ->assertOk()
            ->assertJson(['outcome' => 'duplicate']);

        $this->assertDatabaseCount('transactions', 1);
        $this->assertSame(25000, $invoice->fresh()->amountPaidMinor());
    }

    public function test_an_unpaid_session_changes_nothing(): void
    {
        $invoice = $this->invoice();

        $this->deliver('checkout.session.completed', $this->paidSession($invoice, ['payment_status' => 'unpaid']))
            ->assertOk()
            ->assertJson(['outcome' => 'unpaid']);

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_session_in_another_currency_does_not_settle_the_invoice(): void
    {
        $invoice = $this->invoice(['currency' => 'JMD']);

        $this->deliver('checkout.session.completed', $this->paidSession($invoice, ['currency' => 'usd']))
            ->assertOk()
            ->assertJson(['outcome' => 'mismatch']);

        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_paying_an_invoice_already_settled_at_the_office_is_recorded_for_refund(): void
    {
        $invoice = $this->invoice();
        $invoice->markPaid();
        $paidAt = $invoice->fresh()->paid_at;

        $this->deliver('checkout.session.completed', $this->paidSession($invoice))
            ->assertOk()
            ->assertJson(['outcome' => 'overpaid']);

        $invoice->refresh();
        $this->assertSame('Paid', $invoice->status);
        $this->assertEquals($paidAt, $invoice->paid_at);
        $this->assertNull($invoice->stripe_payment_intent);
        $this->assertSame('completed', Transaction::sole()->status);
    }

    public function test_unhandled_event_types_are_acknowledged(): void
    {
        $this->deliver('customer.created', ['id' => 'cus_1', 'object' => 'customer'])
            ->assertOk()
            ->assertJson(['outcome' => 'ignored']);
    }

    // ── Refunds ──

    public function test_a_full_refund_reopens_the_invoice_it_settled(): void
    {
        $invoice = $this->invoice();
        $this->deliver('checkout.session.completed', $this->paidSession($invoice))->assertOk();

        $this->deliver('charge.refunded', $this->refundedCharge('pi_test_'.$invoice->id, 25000, 25000))
            ->assertOk()
            ->assertJson(['outcome' => 'refunded']);

        $invoice->refresh();
        $this->assertSame('Unpaid', $invoice->status);
        $this->assertNull($invoice->paid_at);
        $this->assertSame(0, $invoice->amountPaidMinor());

        $refund = Transaction::where('status', 'refunded')->sole();
        $this->assertSame(25000, $refund->amount_minor);
    }

    public function test_partial_refunds_are_recorded_step_by_step_and_retries_add_nothing(): void
    {
        $invoice = $this->invoice();
        $paymentIntent = 'pi_test_'.$invoice->id;
        $this->deliver('checkout.session.completed', $this->paidSession($invoice))->assertOk();

        $this->deliver('charge.refunded', $this->refundedCharge($paymentIntent, 25000, 5000), 'evt_refund_1')->assertOk();
        $this->assertSame('Partially Paid', $invoice->fresh()->status);

        // Stripe retries the first refund event, then sends the second refund.
        $this->deliver('charge.refunded', $this->refundedCharge($paymentIntent, 25000, 5000), 'evt_refund_1')
            ->assertJson(['outcome' => 'duplicate']);
        $this->deliver('charge.refunded', $this->refundedCharge($paymentIntent, 25000, 12000))->assertOk();

        // A second, distinct event reporting the same cumulative total.
        $this->deliver('charge.refunded', $this->refundedCharge($paymentIntent, 25000, 12000))
            ->assertJson(['outcome' => 'duplicate']);

        $refunds = Transaction::where('status', 'refunded')->orderBy('id')->pluck('amount_minor')->all();
        $this->assertSame([5000, 7000], $refunds);
        $this->assertSame(13000, $invoice->fresh()->amountPaidMinor());
        $this->assertSame('Partially Paid', $invoice->fresh()->status);
    }

    public function test_refunding_a_duplicate_payment_leaves_the_invoice_paid(): void
    {
        $invoice = $this->invoice();
        $invoice->markPaid();
        $this->deliver('checkout.session.completed', $this->paidSession($invoice))->assertJson(['outcome' => 'overpaid']);

        $this->deliver('charge.refunded', $this->refundedCharge('pi_test_'.$invoice->id, 25000, 25000))
            ->assertJson(['outcome' => 'refunded']);

        $this->assertSame('Paid', $invoice->fresh()->status);
    }

    public function test_a_refund_for_a_payment_this_app_never_saw_is_acknowledged_and_ignored(): void
    {
        $this->deliver('charge.refunded', $this->refundedCharge('pi_unknown', 1000, 1000))
            ->assertOk()
            ->assertJson(['outcome' => 'unknown_invoice']);

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_the_event_is_recorded_so_its_id_cannot_be_applied_again(): void
    {
        $invoice = $this->invoice();

        $this->deliver('checkout.session.completed', $this->paidSession($invoice), 'evt_recorded')->assertOk();

        $this->assertSame('checkout.session.completed', StripeEvent::where('event_id', 'evt_recorded')->value('type'));
    }
}
