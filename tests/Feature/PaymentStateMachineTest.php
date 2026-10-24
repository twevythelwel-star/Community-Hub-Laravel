<?php

namespace Tests\Feature;

use App\Enums\PaymentState;
use App\Exceptions\IllegalPaymentTransition;
use App\Models\Account;
use App\Models\Invoice;
use App\Models\LedgerEntry;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Payments\PaymentOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The payment state machine (App\Enums\PaymentState), enforced by
 * Payment::transitionTo() and driven by Stripe's server-side events.
 */
class PaymentStateMachineTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_state_machine_tests';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.stripe.secret' => 'sk_test_phpunit_not_real',
            'services.stripe.webhook_secret' => self::SECRET,
        ]);
    }

    private function invoice(int $amountMinor = 25_000): Invoice
    {
        return Invoice::create([
            'user_id' => User::factory()->create(['lot' => 'Lot 12'])->id,
            'reference' => 'INV-SM-'.fake()->unique()->numerify('####'),
            'amount_minor' => $amountMinor,
            'currency' => 'USD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(10),
            'status' => 'Unpaid',
        ]);
    }

    /** A card payment sent to Checkout, as createCheckoutSession() leaves it. */
    private function cardPayment(Invoice $invoice): Payment
    {
        $payment = app(PaymentOrchestratorService::class)->startPayment([
            'user' => $invoice->user,
            'channel' => 'card',
            'invoice' => $invoice,
            'amount_minor' => $invoice->amount_minor,
            'currency' => $invoice->currency,
        ]);
        $payment->update(['provider_session_id' => "cs_test_sm_{$payment->id}"]);

        return $payment;
    }

    /** @param  array<string, mixed>  $object */
    private function deliver(string $type, array $object, ?string $eventId = null): TestResponse
    {
        $payload = json_encode([
            'id' => $eventId ?? 'evt_'.fake()->unique()->bothify('????????'),
            'object' => 'event',
            'type' => $type,
            'data' => ['object' => $object],
        ]);

        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", self::SECRET);

        return $this->call('POST', '/api/webhooks/stripe', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        ], $payload);
    }

    /** @return array<string, mixed> */
    private function intent(Payment $payment, string $status, array $extra = []): array
    {
        return [
            'id' => "pi_sm_{$payment->id}",
            'object' => 'payment_intent',
            'status' => $status,
            'amount' => $payment->amount_minor,
            'currency' => 'usd',
            'metadata' => ['payment_id' => (string) $payment->id, 'invoice_id' => (string) $payment->invoice_id],
            ...$extra,
        ];
    }

    /** @return array<string, mixed> */
    private function paidSession(Payment $payment): array
    {
        return [
            'id' => $payment->provider_session_id,
            'object' => 'checkout.session',
            'client_reference_id' => (string) $payment->invoice_id,
            'payment_status' => 'paid',
            'payment_intent' => "pi_sm_{$payment->id}",
            'amount_total' => $payment->amount_minor,
            'currency' => 'usd',
            'metadata' => ['purpose' => 'invoice', 'invoice_id' => (string) $payment->invoice_id, 'payment_id' => (string) $payment->id],
        ];
    }

    // ── The machine itself ──

    public function test_every_transition_not_in_the_table_is_refused(): void
    {
        foreach (PaymentState::cases() as $from) {
            foreach (PaymentState::cases() as $to) {
                if ($from === $to) {
                    continue;
                }

                $payment = Payment::factory()->inState($from)->create();

                if ($from->canTransitionTo($to)) {
                    $payment->transitionTo($to);
                    $this->assertSame($to, $payment->fresh()->state, "{$from->value} → {$to->value}");
                } else {
                    try {
                        $payment->transitionTo($to);
                        $this->fail("{$from->value} → {$to->value} was allowed.");
                    } catch (IllegalPaymentTransition) {
                        $this->assertSame($from, $payment->fresh()->state);
                    }
                }
            }
        }
    }

    public function test_terminal_states_have_no_way_out(): void
    {
        foreach ([PaymentState::Expired, PaymentState::Rejected, PaymentState::Refunded, PaymentState::ChargedBack] as $state) {
            $this->assertTrue($state->isTerminal(), $state->value);
        }

        $this->assertFalse(PaymentState::Paid->isTerminal(), 'A paid payment can still be refunded or disputed.');
    }

    public function test_every_step_is_recorded_with_who_or_what_caused_it(): void
    {
        $actor = User::factory()->create();
        $payment = Payment::factory()->inState(PaymentState::AwaitingTransfer)->create();

        $payment->transitionTo(PaymentState::Received, $actor, 'admin', 'Seen on the statement');

        $step = $payment->transitions()->latest('id')->first();
        $this->assertSame(PaymentState::AwaitingTransfer, $step->from_state);
        $this->assertSame(PaymentState::Received, $step->to_state);
        $this->assertSame($actor->id, $step->actor_id);
        $this->assertSame('admin', $step->source);
        $this->assertSame('Seen on the statement', $step->note);
    }

    public function test_the_ledger_is_not_where_a_payment_s_state_lives(): void
    {
        $payment = $this->cardPayment($this->invoice());

        $this->assertSame(PaymentState::Created, $payment->state);
        $this->assertSame(0, Transaction::count(), 'A card attempt used to be a pending ledger row an admin could confirm.');
    }

    // ── Driven by Stripe, never by the browser ──

    public function test_authentication_then_processing_then_payment(): void
    {
        $invoice = $this->invoice();
        $payment = $this->cardPayment($invoice);

        $this->deliver('payment_intent.requires_action', $this->intent($payment, 'requires_action'), 'evt_sm_3ds')
            ->assertOk()->assertJson(['outcome' => 'requires_action']);
        $this->assertSame(PaymentState::RequiresAction, $payment->fresh()->state);

        $this->deliver('payment_intent.processing', $this->intent($payment, 'processing'))->assertOk();
        $this->assertSame(PaymentState::Processing, $payment->fresh()->state);

        $this->deliver('checkout.session.completed', $this->paidSession($payment))->assertOk()->assertJson(['outcome' => 'settled']);

        $payment->refresh();
        $this->assertSame(PaymentState::Paid, $payment->state);
        $this->assertSame('Paid', $invoice->fresh()->status);
        $this->assertSame($payment->transaction_id, $payment->ledgerPayment()->transaction_id);
        $this->assertSame('stripe:evt_sm_3ds', $payment->transitions()->where('to_state', 'requires_action')->value('source'));
        $this->assertSame(
            ['created', 'requires_action', 'processing', 'succeeded', 'paid'],
            $payment->transitions->map(fn ($t) => $t->to_state->value)->all(),
        );
    }

    public function test_a_declined_card_retried_on_the_same_checkout_can_still_succeed(): void
    {
        $invoice = $this->invoice();
        $payment = $this->cardPayment($invoice);

        $this->deliver('payment_intent.payment_failed', $this->intent($payment, 'requires_payment_method', [
            'last_payment_error' => ['message' => 'Your card was declined.'],
        ]))->assertOk();
        $this->assertSame(PaymentState::Failed, $payment->fresh()->state);
        $this->assertSame('Unpaid', $invoice->fresh()->status);

        $this->deliver('checkout.session.completed', $this->paidSession($payment))->assertOk()->assertJson(['outcome' => 'settled']);

        $this->assertSame(PaymentState::Paid, $payment->fresh()->state);
        $this->assertSame('Paid', $invoice->fresh()->status);
    }

    public function test_a_late_event_is_acknowledged_without_moving_the_payment_back(): void
    {
        $payment = $this->cardPayment($this->invoice());
        $this->deliver('checkout.session.completed', $this->paidSession($payment))->assertOk();

        // Stripe does not promise order. A 500 here would be retried for days.
        $this->deliver('payment_intent.processing', $this->intent($payment, 'processing'))
            ->assertOk()->assertJson(['outcome' => 'stale']);

        $this->assertSame(PaymentState::Paid, $payment->fresh()->state);
    }

    public function test_an_expired_checkout_closes_the_payment(): void
    {
        $invoice = $this->invoice();
        $payment = $this->cardPayment($invoice);

        $this->deliver('checkout.session.expired', [
            'id' => $payment->provider_session_id,
            'object' => 'checkout.session',
            'client_reference_id' => (string) $invoice->id,
            'amount_total' => $payment->amount_minor,
            'currency' => 'usd',
            'metadata' => ['payment_id' => (string) $payment->id],
        ])->assertOk();

        $this->assertSame(PaymentState::Expired, $payment->fresh()->state);
        $this->assertTrue($payment->fresh()->state->isTerminal());
    }

    public function test_money_for_an_invoice_already_settled_is_recorded_but_not_applied(): void
    {
        $invoice = $this->invoice();
        $payment = $this->cardPayment($invoice);
        $invoice->markPaid();

        $this->deliver('checkout.session.completed', $this->paidSession($payment))->assertOk()->assertJson(['outcome' => 'overpaid']);

        $payment->refresh();
        $this->assertSame(PaymentState::Succeeded, $payment->state, 'Received by Stripe, not applied: left for a refund.');
        $this->assertSame(Transaction::STATUS_COMPLETED, $payment->ledgerPayment()->status);
    }

    // ── After payment: refunds and disputes, in both ledgers ──

    public function test_refunds_move_the_payment_and_reverse_the_double_entry_ledger(): void
    {
        $invoice = $this->invoice(20_000);
        $payment = $this->cardPayment($invoice);
        $this->deliver('checkout.session.completed', $this->paidSession($payment))->assertOk();

        $charge = fn (int $refunded) => [
            'id' => "ch_sm_{$payment->id}",
            'object' => 'charge',
            'payment_intent' => "pi_sm_{$payment->id}",
            'amount' => 20_000,
            'amount_refunded' => $refunded,
            'currency' => 'usd',
        ];

        $this->deliver('charge.refunded', $charge(5_000))->assertOk();
        $this->assertSame(PaymentState::PartiallyRefunded, $payment->fresh()->state);

        $this->deliver('charge.refunded', $charge(20_000))->assertOk();
        $this->assertSame(PaymentState::Refunded, $payment->fresh()->state);

        // Every refund row has mirrored entries, and the books still balance.
        $refundRows = Transaction::where('status', 'refunded')->where('payment_id', $payment->id)->get();
        $this->assertCount(2, $refundRows);
        foreach ($refundRows as $row) {
            $this->assertSame($row->amount_minor * 2, (int) LedgerEntry::where('transaction_id', $row->id)->sum('amount_minor'));
        }

        $this->assertSame(
            (int) LedgerEntry::where('entry_type', LedgerEntry::TYPE_DEBIT)->sum('amount_minor'),
            (int) LedgerEntry::where('entry_type', LedgerEntry::TYPE_CREDIT)->sum('amount_minor'),
        );
        $this->assertSame(0, (int) Account::where('code', Account::CODE_STRIPE_CLEARING)->value('balance_minor'), 'Fully refunded: nothing left in clearing.');
    }

    public function test_a_lost_dispute_is_a_chargeback_and_is_reversed(): void
    {
        $invoice = $this->invoice();
        $payment = $this->cardPayment($invoice);
        $this->deliver('checkout.session.completed', $this->paidSession($payment))->assertOk();

        $dispute = fn (string $status) => [
            'id' => 'dp_sm_1',
            'object' => 'dispute',
            'payment_intent' => "pi_sm_{$payment->id}",
            'amount' => $invoice->amount_minor,
            'currency' => 'usd',
            'status' => $status,
            'reason' => 'fraudulent',
        ];

        $this->deliver('charge.dispute.created', $dispute('needs_response'))->assertOk();
        $this->assertSame(PaymentState::Disputed, $payment->fresh()->state);

        $this->deliver('charge.dispute.closed', $dispute('lost'))->assertOk();
        $this->assertSame(PaymentState::ChargedBack, $payment->fresh()->state);

        $chargeback = Transaction::where('reference', 'stripe-dispute:dp_sm_1:lost')->sole();
        $this->assertSame($payment->id, $chargeback->payment_id);
        $this->assertSame(2, LedgerEntry::where('transaction_id', $chargeback->id)->count());
    }

    public function test_a_won_dispute_returns_the_payment_to_paid(): void
    {
        $payment = $this->cardPayment($this->invoice());
        $this->deliver('checkout.session.completed', $this->paidSession($payment))->assertOk();

        $dispute = fn (string $status) => [
            'id' => 'dp_sm_2', 'object' => 'dispute', 'payment_intent' => "pi_sm_{$payment->id}",
            'amount' => $payment->amount_minor, 'currency' => 'usd', 'status' => $status, 'reason' => 'general',
        ];

        $this->deliver('charge.dispute.created', $dispute('needs_response'))->assertOk();
        $this->deliver('charge.dispute.closed', $dispute('won'))->assertOk();

        $this->assertSame(PaymentState::Paid, $payment->fresh()->state);
    }

    public function test_office_channels_are_not_booked_to_stripe_clearing(): void
    {
        $user = User::factory()->create();
        $tx = Transaction::create([
            'user_id' => $user->id,
            'amount_minor' => 10_000,
            'currency' => 'USD',
            'payment_channel' => 'apple_pay',
            'status' => Transaction::STATUS_COMPLETED,
        ]);

        $this->assertSame('office', $tx->provider);

        app(LedgerService::class)->postTransaction($tx);

        $accounts = LedgerEntry::with('account')->where('transaction_id', $tx->id)->get()->pluck('account.code');

        $this->assertCount(2, $accounts);
        $this->assertNotContains(Account::CODE_STRIPE_CLEARING, $accounts, 'Stripe never held Apple Pay money.');
    }
}
