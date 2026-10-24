<?php

namespace Tests\Feature;

use App\Enums\PaymentState;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\StripePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Mockery\MockInterface;
use Tests\TestCase;

/**
 * A payment may only be settled on evidence the payer cannot make up.
 *
 * Two paths settled statements without it: the universal WiPay webhook took
 * every field but two of a WiPay result on trust, so one genuine hash could
 * pay anybody's statement; and POST /dashboard/billing/card-pay marked a
 * statement Paid without any card being charged.
 */
class PaymentSettlementForgeryTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'wipay_server_only_key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payments.providers.wipay.api_key' => self::API_KEY,
            'payments.providers.wipay.account_number' => '1234567890',
            'payments.providers.wipay.country_code' => 'JM',
            'payments.providers.wipay.environment' => 'sandbox',
        ]);
    }

    private function unpaidInvoice(User $user, int $amountMinor = 75000): Invoice
    {
        return Invoice::create([
            'user_id' => $user->id,
            'reference' => 'INV-FORGE-'.fake()->unique()->numerify('#####'),
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(14),
            'status' => 'Unpaid',
        ]);
    }

    private function wipayPayment(Invoice $invoice, string $wipayTransactionId, string $transactionId): Payment
    {
        return Payment::create([
            'user_id' => $invoice->user_id,
            'invoice_id' => $invoice->id,
            'applies_to' => 'invoice',
            'purpose' => Transaction::PURPOSE_MAINTENANCE_FEE,
            'transaction_id' => $transactionId,
            'idempotency_key' => 'idem_'.uniqid(),
            'provider' => 'wipay',
            'provider_payment_id' => $wipayTransactionId,
            'channel' => 'card',
            'amount_minor' => $invoice->amount_minor,
            'currency' => 'JMD',
            'state' => PaymentState::Processing,
        ]);
    }

    /** What WiPay puts in the payer's browser after a successful payment. */
    private function genuineReturn(Payment $payment): array
    {
        $total = number_format($payment->amount_minor / 100, 2, '.', '');

        return [
            'status' => 'success',
            'transaction_id' => $payment->provider_payment_id,
            'order_id' => str_replace('-', '', $payment->transaction_id),
            'total' => $total,
            'currency' => 'JMD',
            'hash' => md5($payment->provider_payment_id.$total.self::API_KEY),
        ];
    }

    private function postToWebhook(array $fields): TestResponse
    {
        return $this->post('/api/webhooks/payments/wipay', $fields);
    }

    // ── Universal WiPay webhook ─────────────────────────────────────

    public function test_a_genuine_wipay_result_settles_its_own_payment(): void
    {
        $invoice = $this->unpaidInvoice(User::factory()->create());
        $payment = $this->wipayPayment($invoice, 'WPY-OWN-1', 'CH-2026-0000000101');

        $this->postToWebhook($this->genuineReturn($payment))
            ->assertOk()
            ->assertJson(['outcome' => 'processed', 'event_id' => 'wipay:WPY-OWN-1']);

        $this->assertSame(PaymentState::Paid, $payment->fresh()->state);
        $this->assertSame('Paid', $invoice->fresh()->status);
        $this->assertSame(1, Transaction::count());
    }

    public function test_delivering_the_same_result_again_records_the_money_once(): void
    {
        $invoice = $this->unpaidInvoice(User::factory()->create());
        $payment = $this->wipayPayment($invoice, 'WPY-TWICE-1', 'CH-2026-0000000102');

        $this->postToWebhook($this->genuineReturn($payment))->assertOk();
        $this->postToWebhook($this->genuineReturn($payment))->assertOk()->assertJson(['outcome' => 'duplicate']);

        $this->assertSame(1, Transaction::count());
    }

    public function test_one_genuine_hash_cannot_settle_an_unrelated_payment(): void
    {
        $attackerInvoice = $this->unpaidInvoice(User::factory()->create(), 100);
        $attackerPayment = $this->wipayPayment($attackerInvoice, 'WPY-ATTACKER-1', 'CH-2026-0000000041');

        $victimInvoice = $this->unpaidInvoice(User::factory()->create(), 75000);
        $victimPayment = $this->wipayPayment($victimInvoice, 'WPY-VICTIM-1', 'CH-2026-0000000042');

        // The attacker's own genuine $1.00 result, with every unsigned field rewritten.
        $this->postToWebhook([
            ...$this->genuineReturn($attackerPayment),
            'provider_payment_id' => 'no-such-id',
            'order_id' => 'CH20260000000042',
            'amount_minor' => 75000,
            'id' => 'evt_forged_'.uniqid(),
            'type' => 'approved',
        ])->assertStatus(400);

        $this->assertSame(PaymentState::Processing, $victimPayment->fresh()->state);
        $this->assertSame('Unpaid', $victimInvoice->fresh()->status);
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_genuine_hash_replayed_against_a_payment_of_the_same_amount_is_refused(): void
    {
        $first = $this->wipayPayment($this->unpaidInvoice(User::factory()->create()), 'WPY-SAME-1', 'CH-2026-0000000201');
        $otherInvoice = $this->unpaidInvoice(User::factory()->create());
        $other = $this->wipayPayment($otherInvoice, 'WPY-SAME-2', 'CH-2026-0000000202');

        $this->postToWebhook([
            ...$this->genuineReturn($first),
            'order_id' => str_replace('-', '', $other->transaction_id),
        ])->assertStatus(400);

        $this->assertSame(PaymentState::Processing, $other->fresh()->state);
        $this->assertSame('Unpaid', $otherInvoice->fresh()->status);
    }

    public function test_a_forged_hash_changes_nothing(): void
    {
        $invoice = $this->unpaidInvoice(User::factory()->create());
        $payment = $this->wipayPayment($invoice, 'WPY-FORGED-1', 'CH-2026-0000000301');

        $this->postToWebhook([...$this->genuineReturn($payment), 'hash' => md5('forged')])->assertStatus(400);

        $this->assertSame(PaymentState::Processing, $payment->fresh()->state);
        $this->assertSame(0, Transaction::count());
    }

    public function test_an_unsigned_failure_cannot_fail_someone_elses_payment(): void
    {
        $invoice = $this->unpaidInvoice(User::factory()->create());
        $payment = $this->wipayPayment($invoice, 'WPY-FAIL-1', 'CH-2026-0000000401');

        $this->postToWebhook([
            'status' => 'failed',
            'transaction_id' => 'WPY-FAIL-1',
            'order_id' => 'CH20260000000401',
            'total' => '750.00',
        ])->assertStatus(400);

        $this->assertSame(PaymentState::Processing, $payment->fresh()->state);
    }

    public function test_an_hmac_signature_for_a_different_body_is_refused(): void
    {
        $invoice = $this->unpaidInvoice(User::factory()->create());
        $payment = $this->wipayPayment($invoice, 'WPY-HMAC-1', 'CH-2026-0000000501');

        $signed = json_encode(['type' => 'approved', 'provider_payment_id' => 'WPY-HMAC-1', 'amount_minor' => 100]);
        $sent = json_encode(['type' => 'approved', 'provider_payment_id' => 'WPY-HMAC-1', 'amount_minor' => 75000, 'currency' => 'JMD']);

        $this->call('POST', '/api/webhooks/payments/wipay', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WIPAY_SIGNATURE' => hash_hmac('sha256', $signed, self::API_KEY),
        ], $sent)->assertStatus(400);

        $this->assertSame(PaymentState::Processing, $payment->fresh()->state);
    }

    // ── POST /dashboard/billing/card-pay ────────────────────────────

    public function test_card_pay_never_settles_a_statement_by_itself(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);
        $invoice = $this->unpaidInvoice($homeowner);

        $this->actingAs($homeowner)->postJson('/dashboard/billing/card-pay', [
            'invoice_id' => $invoice->id,
            'amount' => 750.00,
            'last_four' => '4242',
            'brand' => 'Visa',
            'payment_intent_id' => 'pi_made_up_by_the_browser',
            'save_card' => true,
        ])->assertStatus(503);

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame(0, Transaction::count());
        $this->assertFalse(Payment::query()->whereIn('state', [PaymentState::Succeeded, PaymentState::Paid])->exists());
        $this->assertSame(0, $homeowner->paymentMethods()->count());
    }

    public function test_card_pay_hands_the_resident_to_stripe_checkout(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);
        $invoice = $this->unpaidInvoice($homeowner);

        $this->mock(StripePaymentService::class, function (MockInterface $stripe) use ($invoice): void {
            $stripe->shouldReceive('isLive')->andReturnTrue();
            $stripe->shouldReceive('createCheckoutSession')
                ->once()
                ->withArgs(fn (Invoice $for, string $success, string $cancel, int $amountMinor) => $for->is($invoice) && $amountMinor === 50000)
                ->andReturn('https://checkout.stripe.com/c/pay/cs_test_123');
        });

        $this->actingAs($homeowner)->postJson('/dashboard/billing/card-pay', [
            'invoice_id' => $invoice->id,
            'amount' => 500.00,
        ])->assertOk()->assertExactJson(['checkout_url' => 'https://checkout.stripe.com/c/pay/cs_test_123']);

        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame(0, Transaction::count());
    }

    public function test_card_pay_refuses_another_households_statement(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);
        $neighbourInvoice = $this->unpaidInvoice(User::factory()->create(['role' => UserRole::Homeowner]));

        $this->actingAs($homeowner)->postJson('/dashboard/billing/card-pay', [
            'invoice_id' => $neighbourInvoice->id,
            'amount' => 750.00,
        ])->assertStatus(422)->assertJsonValidationErrors('invoice_id');

        $this->assertSame('Unpaid', $neighbourInvoice->fresh()->status);
    }

    public function test_card_pay_refuses_more_than_is_owed(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);
        $invoice = $this->unpaidInvoice($homeowner);

        $this->actingAs($homeowner)->postJson('/dashboard/billing/card-pay', [
            'invoice_id' => $invoice->id,
            'amount' => 751.00,
        ])->assertStatus(422)->assertJsonValidationErrors('amount');
    }
}
