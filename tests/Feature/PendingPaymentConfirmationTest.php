<?php

namespace Tests\Feature;

use App\Enums\PaymentState;
use App\Enums\UserRole;
use App\Models\BankReconciliation;
use App\Models\Donation;
use App\Models\Fundraiser;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Payments by channels the app cannot see.
 *
 * Bank wire, cash, QR, NFC, Apple/Google/Samsung Pay, Zelle and Cash App each
 * had a driver that reported success for whatever amount was posted, so a
 * resident could mark their own invoice Paid by choosing one of them.
 *
 * Such a payment is now AwaitingTransfer and nothing else. One administrator
 * logs the money as Received; a different administrator verifies it in a bank
 * reconciliation; only then is it Paid and on the ledger.
 */
class PendingPaymentConfirmationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercises actions behind password.confirm; the prompt itself is PasswordConfirmationTest's.
        $this->confirmPassword();
    }

    private function resident(): User
    {
        return User::factory()->role(UserRole::Homeowner)->create();
    }

    private function admin(): User
    {
        return User::factory()->role(UserRole::Admin)->create();
    }

    private function invoiceFor(User $user, int $amountMinor = 10_000_00): Invoice
    {
        return Invoice::create([
            'user_id' => $user->id,
            'reference' => 'INV-PEND-'.fake()->unique()->numerify('####'),
            'amount_minor' => $amountMinor,
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(10),
            'status' => 'Unpaid',
        ]);
    }

    private function fundraiser(): Fundraiser
    {
        return Fundraiser::create([
            'title' => 'Clubhouse Roof',
            'description' => 'Replace the leaking roof.',
            'goal_minor' => 1_000_000_00,
            'goal_currency' => 'JMD',
            'status' => 'Active',
            'created_by' => $this->admin()->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function pay(User $user, Invoice $invoice, array $overrides = []): Payment
    {
        $this->actingAs($user)
            ->post(route('dashboard.billing.pay'), [
                'channel' => 'bank_wire',
                'amount' => $invoice->amount_minor / 100,
                'invoice_id' => $invoice->id,
                ...$overrides,
            ])
            ->assertSessionHasNoErrors();

        return Payment::latest('id')->firstOrFail();
    }

    private function receive(Payment $payment, User $admin): void
    {
        $this->actingAs($admin)
            ->post(route('dashboard.billing.payments.receive', $payment), ['bank_reference' => 'NCB-'.$payment->id])
            ->assertSessionHasNoErrors();
    }

    /** @param  list<int>  $paymentIds */
    private function reconcile(User $admin, array $paymentIds, float $statementBalance = 0): TestResponse
    {
        return $this->actingAs($admin)->post(route('dashboard.billing.reconciliations.store'), [
            'bank_statement_date' => now()->toDateString(),
            'statement_balance' => $statementBalance,
            'verify_payment_ids' => $paymentIds,
        ]);
    }

    // ── Submission ──

    public function test_an_office_channel_payment_awaits_transfer_and_touches_nothing(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoiceFor($resident);

        $payment = $this->pay($resident, $invoice);

        $this->assertSame(PaymentState::AwaitingTransfer, $payment->state);
        $this->assertMatchesRegularExpression('/^CH-\d{4}-\d{10}$/', $payment->transaction_id);
        $this->assertSame(0, Transaction::count(), 'Nothing reaches the ledger before money has moved.');
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_every_channel_the_app_cannot_see_waits_for_the_office(): void
    {
        $resident = $this->resident();

        // (NFC is not here: a tap is taken on a staff-run card reader, not
        // reported to the office; see StripeTerminalInPersonTest.)
        foreach (['bank_wire', 'cash_office', 'qr_code', 'zelle', 'cash_app'] as $channel) {
            $invoice = $this->invoiceFor($resident);

            $payment = $this->pay($resident, $invoice, ['channel' => $channel]);

            $this->assertSame(PaymentState::AwaitingTransfer, $payment->state, $channel);
            $this->assertSame('office', $payment->provider, $channel);
            $this->assertSame('Unpaid', $invoice->fresh()->status, "{$channel} settled the invoice on its own.");
        }

        $this->assertSame(0, Transaction::count());
    }

    // ── Received, then verified by someone else ──

    public function test_logging_receipt_does_not_settle_the_invoice(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoiceFor($resident);
        $payment = $this->pay($resident, $invoice);

        $this->receive($payment, $clerk = $this->admin());

        $payment->refresh();
        $this->assertSame(PaymentState::Received, $payment->state);
        $this->assertSame($clerk->id, $payment->received_by);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_second_administrator_verifies_it_in_a_reconciliation_and_it_is_paid(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoiceFor($resident);
        $payment = $this->pay($resident, $invoice);
        $this->receive($payment, $this->admin());

        $this->reconcile($auditor = $this->admin(), [$payment->id], 10_000.00)->assertSessionHasNoErrors();

        $payment->refresh();
        $this->assertSame(PaymentState::Paid, $payment->state);
        $this->assertSame($auditor->id, $payment->verified_by);
        $this->assertNotNull($payment->bank_reconciliation_id);
        $this->assertSame('Paid', $invoice->fresh()->status);

        $row = $payment->ledgerPayment();
        $this->assertSame(Transaction::STATUS_COMPLETED, $row->status);
        $this->assertSame($payment->transaction_id, $row->transaction_id, 'The ledger row carries the payment\'s number.');

        // The reconciliation counted the money it verified.
        $this->assertSame('Reconciled', BankReconciliation::sole()->status);

        $this->assertSame(
            ['created', 'awaiting_transfer', 'received', 'verified', 'paid'],
            $payment->transitions->map(fn ($t) => $t->to_state->value)->all(),
        );
    }

    public function test_the_administrator_who_logged_receipt_cannot_verify_it(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoiceFor($resident);
        $payment = $this->pay($resident, $invoice);
        $this->receive($payment, $clerk = $this->admin());

        $this->reconcile($clerk, [$payment->id], 10_000.00)->assertSessionHasErrors('verify_payment_ids');

        $this->assertSame(PaymentState::Received, $payment->fresh()->state);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame(0, BankReconciliation::count(), 'All or nothing: the reconciliation is not recorded either.');
    }

    public function test_a_payment_not_yet_received_cannot_be_verified(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoiceFor($resident);
        $payment = $this->pay($resident, $invoice);

        $this->reconcile($this->admin(), [$payment->id])->assertSessionHasErrors('verify_payment_ids');

        $this->assertSame(PaymentState::AwaitingTransfer, $payment->fresh()->state);
    }

    public function test_a_part_payment_leaves_the_invoice_partially_paid(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoiceFor($resident);
        $payment = $this->pay($resident, $invoice, ['amount' => 4_000]);
        $this->receive($payment, $this->admin());
        $this->reconcile($this->admin(), [$payment->id], 4_000.00);

        $this->assertSame('Partially Paid', $invoice->fresh()->status);
        $this->assertSame(6_000_00, $invoice->fresh()->balanceRemainingMinor());
    }

    public function test_chosen_line_items_are_marked_paid_only_when_the_payment_is(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoiceFor($resident);
        $item = $invoice->items()->create(['category' => 'Dues', 'title' => 'Security levy', 'amount_minor' => 2_000_00, 'status' => 'Unpaid']);
        $untouched = $invoice->items()->create(['category' => 'Dues', 'title' => 'Garbage', 'amount_minor' => 1_000_00, 'status' => 'Unpaid']);

        $payment = $this->pay($resident, $invoice, ['amount' => 2_000, 'item_ids' => [$item->id]]);
        $this->receive($payment, $this->admin());
        $this->assertSame('Unpaid', $item->fresh()->status);

        $this->reconcile($this->admin(), [$payment->id], 2_000.00);

        $this->assertSame('Paid', $item->fresh()->status);
        $this->assertSame('Unpaid', $untouched->fresh()->status);
    }

    // ── Rejection and the rules around it ──

    public function test_rejecting_a_payment_changes_nothing_it_would_have_paid(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoiceFor($resident);
        $payment = $this->pay($resident, $invoice);

        $this->actingAs($this->admin())
            ->post(route('dashboard.billing.payments.reject', $payment), ['reason' => 'No deposit on the statement'])
            ->assertSessionHasNoErrors();

        $payment->refresh();
        $this->assertSame(PaymentState::Rejected, $payment->state);
        $this->assertSame('No deposit on the statement', $payment->failure_reason);
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_a_rejected_payment_cannot_be_received_afterwards(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoiceFor($resident);
        $payment = $this->pay($resident, $invoice);
        $admin = $this->admin();

        $this->actingAs($admin)->post(route('dashboard.billing.payments.reject', $payment));

        $this->actingAs($admin)
            ->post(route('dashboard.billing.payments.receive', $payment))
            ->assertSessionHasErrors('payment');

        $this->assertSame(PaymentState::Rejected, $payment->fresh()->state);
    }

    public function test_a_resident_cannot_log_their_own_payment_as_received(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoiceFor($resident);
        $payment = $this->pay($resident, $invoice);

        $this->actingAs($resident)
            ->post(route('dashboard.billing.payments.receive', $payment))
            ->assertForbidden();

        $this->assertSame(PaymentState::AwaitingTransfer, $payment->fresh()->state);
    }

    public function test_a_slip_cannot_be_submitted_twice(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoiceFor($resident);
        $payment = $this->pay($resident, $invoice);

        $this->actingAs($resident)
            ->post(route('dashboard.billing.pay'), [
                'channel' => 'bank_wire',
                'amount' => 100,
                'invoice_id' => $invoice->id,
                'transaction_id' => $payment->transaction_id,
            ])
            ->assertSessionHasErrors('transaction_id');
    }

    public function test_administrators_see_the_office_queue_with_what_each_payment_allows(): void
    {
        $resident = $this->resident();
        $clerk = $this->admin();
        $waiting = $this->pay($resident, $this->invoiceFor($resident));
        $received = $this->pay($resident, $this->invoiceFor($resident));
        $this->receive($received, $clerk);

        $this->actingAs($clerk)
            ->get(route('dashboard.billing'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('pendingPayments', 2)
                ->where('pendingPayments.0.transactionId', $waiting->transaction_id)
                ->where('pendingPayments.0.receiveUrl', route('dashboard.billing.payments.receive', $waiting))
                ->where('pendingPayments.1.state', 'received')
                ->where('pendingPayments.1.receiveUrl', null)
                ->where('pendingPayments.1.canVerify', false)
            );

        $this->actingAs($this->admin())
            ->get(route('dashboard.billing'))
            ->assertInertia(fn (Assert $page) => $page->where('pendingPayments.1.canVerify', true));

        // A resident sees their own payments' progress, with no actions.
        $this->actingAs($resident)
            ->get(route('dashboard.billing'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('pendingPayments', 2)
                ->where('pendingPayments.0.receiveUrl', null)
                ->where('pendingPayments.0.rejectUrl', null)
            );
    }

    public function test_device_wallets_are_never_confirmed_by_the_office(): void
    {
        // Apple Pay, Google Pay and Samsung Pay were office channels: a
        // resident "paid" by saying so. Only a card processor can take them,
        // and with none configured they are refused outright.
        config(['payments.card_provider' => null, 'services.stripe.secret' => null]);
        $resident = $this->resident();

        foreach (['apple_pay', 'google_pay', 'samsung_wallet'] as $channel) {
            $this->actingAs($resident)
                ->post(route('dashboard.billing.pay'), ['channel' => $channel, 'amount' => 100, 'invoice_id' => $this->invoiceFor($resident)->id])
                ->assertSessionHasErrors('channel');
        }

        $this->assertSame(0, Payment::count());
    }

    public function test_a_card_attempt_never_reaches_the_office_queue(): void
    {
        config(['services.stripe.secret' => 'sk_test_card_attempt']);
        $resident = $this->resident();
        $invoice = $this->invoiceFor($resident);

        $this->actingAs($resident)
            ->postJson(route('dashboard.billing.transactions.initiate'), ['channel' => 'card', 'amount' => 100, 'invoice_id' => $invoice->id])
            ->assertOk();

        $this->actingAs($this->admin())
            ->get(route('dashboard.billing'))
            ->assertInertia(fn (Assert $page) => $page->has('pendingPayments', 0));

        $this->assertSame(0, Transaction::count());
    }

    // ── The Community Wallet, which the app can see ──

    public function test_the_wallet_still_pays_immediately(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoiceFor($resident, 1_000_00);
        Wallet::create(['user_id' => $resident->id, 'currency' => 'JMD', 'available_balance_minor' => 5_000_00, 'pending_balance_minor' => 0, 'rewards_balance_minor' => 0]);

        $payment = $this->pay($resident, $invoice, ['channel' => 'wallet']);

        $this->assertSame(PaymentState::Paid, $payment->state);
        $this->assertSame('internal', $payment->provider);
        $this->assertSame(Transaction::STATUS_COMPLETED, $payment->ledgerPayment()->status);
        $this->assertSame('Paid', $invoice->fresh()->status);
    }

    public function test_a_short_wallet_is_refused(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoiceFor($resident, 1_000_00);
        Wallet::create(['user_id' => $resident->id, 'currency' => 'JMD', 'available_balance_minor' => 100_00, 'pending_balance_minor' => 0, 'rewards_balance_minor' => 0]);

        $this->actingAs($resident)
            ->post(route('dashboard.billing.pay'), ['channel' => 'wallet', 'amount' => 1_000, 'invoice_id' => $invoice->id])
            ->assertSessionHasErrors('channel');

        $this->assertSame(PaymentState::Failed, Payment::sole()->state);
        $this->assertSame(0, Transaction::count());
        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    // ── Donations ──

    public function test_a_pending_gift_counts_only_once_verified(): void
    {
        $fundraiser = $this->fundraiser();
        $donor = $this->resident();

        $this->actingAs($donor)
            ->post(route('dashboard.fundraising.donate', $fundraiser), ['amount' => 500, 'channel' => 'zelle', 'donor_name' => 'The Clarkes'])
            ->assertSessionHasNoErrors();

        $donation = Donation::sole();
        $payment = Payment::sole();
        $this->assertSame('pending', $donation->status);
        $this->assertSame($donation->id, $payment->donation_id);
        $this->assertSame(0, $fundraiser->fresh()->raisedMinor());

        $this->actingAs($donor)
            ->get(route('dashboard.fundraising'))
            ->assertInertia(fn (Assert $page) => $page->has('fundraisers.0.recentDonations', 0));

        $this->receive($payment, $this->admin());
        $this->assertSame(0, $fundraiser->fresh()->raisedMinor());

        $this->reconcile($this->admin(), [$payment->id], 500.00)->assertSessionHasNoErrors();

        $this->assertSame('completed', $donation->fresh()->status);
        $this->assertSame(500_00, $fundraiser->fresh()->raisedMinor());
        $this->assertSame(1, $fundraiser->fresh()->donorCount());
    }

    public function test_a_rejected_gift_never_counts(): void
    {
        $fundraiser = $this->fundraiser();

        $this->actingAs($this->resident())
            ->post(route('dashboard.fundraising.donate', $fundraiser), ['amount' => 500, 'channel' => 'cash_office']);

        $this->actingAs($this->admin())
            ->post(route('dashboard.billing.payments.reject', Payment::sole()));

        $this->assertSame('rejected', Donation::sole()->status);
        $this->assertSame(0, $fundraiser->fresh()->raisedMinor());
    }

    public function test_a_pending_gift_cannot_be_refunded(): void
    {
        $fundraiser = $this->fundraiser();

        $this->actingAs($this->resident())
            ->post(route('dashboard.fundraising.donate', $fundraiser), ['amount' => 500, 'channel' => 'cash_office']);

        $this->actingAs($this->admin())
            ->post(route('dashboard.fundraising.donations.refund', Donation::sole()), ['reason' => 'Changed mind'])
            ->assertSessionHasErrors('refund');

        $this->assertSame('pending', Donation::sole()->status);
    }
}
