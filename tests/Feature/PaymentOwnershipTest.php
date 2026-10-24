<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Donation;
use App\Models\Fundraiser;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use App\Services\GatePassEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ownership boundaries inside the payments area.
 *
 * The existing billing tests assert *which roles reach which routes*. They do
 * not assert *whose records a role may touch once it is inside*, which is why
 * widening `accessBilling` to residents opened a set of holes that the suite
 * stayed green over:
 *
 *   - `pay()` accepted any `invoice_id` and any `item_ids`, so one resident
 *     could settle another household's statement for a cent.
 *   - The wallet split ignored an insufficient-funds refusal.
 *   - `topUpWallet()` credited spendable balance with no processor involved.
 *   - The master-ledger CSV and the estate's payouts, reconciliations and
 *     transactions were readable by any resident.
 *   - The invoice PDF, gate-pass PDF and donation receipt had no check at all.
 *
 * Each test below is a second user of the *same role* — this is about records,
 * not privileges.
 */
class PaymentOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercises actions behind password.confirm; the prompt itself is PasswordConfirmationTest's.
        $this->confirmPassword();
    }

    /** One administrator logs the payment received; another verifies it in a reconciliation. */
    private function receiveAndVerify(Payment $payment): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post(route('dashboard.billing.payments.receive', $payment))
            ->assertSessionHasNoErrors();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post(route('dashboard.billing.reconciliations.store'), [
                'bank_statement_date' => now()->toDateString(),
                'statement_balance' => 0,
                'verify_payment_ids' => [$payment->id],
            ])
            ->assertSessionHasNoErrors();
    }

    private function homeowner(): User
    {
        return User::factory()->create(['role' => 'Homeowner', 'status' => 'Active']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'Admin', 'status' => 'Active']);
    }

    private function invoiceFor(User $user, int $minor = 5_000_00): Invoice
    {
        return Invoice::create([
            'user_id' => $user->id,
            'reference' => 'INV-'.$user->id.'-'.fake()->unique()->numberBetween(1000, 9999),
            'amount_minor' => $minor,
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addDays(10),
            'status' => 'Unpaid',
        ]);
    }

    // ── pay() ────────────────────────────────────────────────────────────

    public function test_a_resident_cannot_settle_another_households_invoice(): void
    {
        $victim = $this->homeowner();
        $attacker = $this->homeowner();
        $invoice = $this->invoiceFor($victim);

        $this->actingAs($attacker)
            ->post(route('dashboard.billing.pay'), [
                'channel' => 'cash_office',
                'amount' => 0.01,
                'invoice_id' => $invoice->id,
            ])
            ->assertSessionHasErrors('invoice_id');

        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    public function test_a_resident_cannot_mark_another_households_line_items_paid(): void
    {
        $victim = $this->homeowner();
        $attacker = $this->homeowner();
        $invoice = $this->invoiceFor($victim);
        $item = $invoice->items()->create([
            'category' => 'Dues',
            'title' => 'Monthly assessment',
            'amount_minor' => 5_000_00,
            'status' => 'Unpaid',
        ]);

        $this->actingAs($attacker)
            ->post(route('dashboard.billing.pay'), [
                'channel' => 'cash_office',
                'amount' => 0.01,
                'item_ids' => [$item->id],
            ])
            ->assertSessionHasErrors('item_ids');

        $this->assertSame('Unpaid', $item->fresh()->status);
    }

    public function test_a_resident_can_still_pay_their_own_invoice(): void
    {
        $user = $this->homeowner();
        $invoice = $this->invoiceFor($user, 1_000_00);

        $this->actingAs($user)
            ->post(route('dashboard.billing.pay'), [
                'channel' => 'cash_office',
                'amount' => 1000,
                'invoice_id' => $invoice->id,
            ])
            ->assertSessionHasNoErrors();

        // Cash is received and verified by the office before it settles anything.
        $this->assertSame('Unpaid', $invoice->fresh()->status);

        $this->receiveAndVerify(Payment::sole());

        $this->assertSame('Paid', $invoice->fresh()->status);
    }

    public function test_the_recorded_currency_follows_the_invoice_when_none_is_posted(): void
    {
        $user = $this->homeowner();
        $invoice = $this->invoiceFor($user);
        $invoice->update(['currency' => 'USD']);

        $this->actingAs($user)->post(route('dashboard.billing.pay'), [
            'channel' => 'cash_office',
            'amount' => 10,
            'invoice_id' => $invoice->id,
        ]);

        // `$invoice` was read one line before it was assigned, so this fell
        // through to the 'JMD' default and a USD statement was paid in JMD.
        $this->assertSame('USD', Payment::latest('id')->first()->currency);
    }

    // ── Wallet ───────────────────────────────────────────────────────────

    public function test_a_split_payment_is_refused_when_the_wallet_cannot_cover_it(): void
    {
        $user = $this->homeowner();
        Wallet::create([
            'user_id' => $user->id,
            'currency' => 'JMD',
            'available_balance_minor' => 0,
            'rewards_balance_minor' => 0,
        ]);
        $invoice = $this->invoiceFor($user, 10_000_00);

        $this->actingAs($user)
            ->post(route('dashboard.billing.pay'), [
                'channel' => 'cash_office',
                'amount' => 10000,
                'invoice_id' => $invoice->id,
                'split_wallet_amount' => 9999,
            ])
            ->assertSessionHasErrors('split_wallet_amount');

        // Wallet::debit() returned null for insufficient funds and the caller
        // discarded it, so an empty wallet used to settle most of an invoice.
        $this->assertSame('Unpaid', $invoice->fresh()->status);
        $this->assertSame(0, Transaction::where('user_id', $user->id)->count());
    }

    public function test_wallet_top_up_does_not_mint_balance(): void
    {
        $user = $this->homeowner();
        Wallet::create(['user_id' => $user->id, 'currency' => 'JMD', 'available_balance_minor' => 0]);

        $this->actingAs($user)
            ->post(route('dashboard.billing.wallet.topup'), ['amount' => 250000])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, Wallet::where('user_id', $user->id)->first()->available_balance_minor);
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_new_wallet_opens_empty(): void
    {
        $user = $this->homeowner();

        $this->actingAs($user)->get(route('dashboard.billing'))->assertOk();

        $wallet = Wallet::where('user_id', $user->id)->first();
        $this->assertNotNull($wallet);
        $this->assertSame(0, $wallet->available_balance_minor);
        $this->assertSame(0, $wallet->rewards_balance_minor);
    }

    public function test_autopay_is_not_switched_on_by_merely_opening_the_page(): void
    {
        $user = $this->homeowner();

        $this->actingAs($user)->get(route('dashboard.billing'))->assertOk();

        $this->assertFalse((bool) $user->fresh()->autoPaySetting->is_active);
    }

    // ── Estate-wide reads ────────────────────────────────────────────────

    public function test_a_resident_cannot_export_the_master_ledger(): void
    {
        $this->actingAs($this->homeowner())
            ->get(route('dashboard.billing.transactions.export'))
            ->assertForbidden();
    }

    public function test_an_administrator_can_still_export_the_master_ledger(): void
    {
        $this->actingAs($this->admin())
            ->get(route('dashboard.billing.transactions.export'))
            ->assertOk();
    }

    public function test_a_residents_billing_payload_does_not_carry_the_estate_ledger(): void
    {
        $neighbour = $this->homeowner();
        Transaction::create([
            'user_id' => $neighbour->id,
            'amount_minor' => 900_00,
            'currency' => 'JMD',
            'payment_channel' => 'cash_office',
            'reference' => 'NEIGHBOUR-TX',
            'status' => 'completed',
        ]);

        $user = $this->homeowner();
        $props = $this->actingAs($user)
            ->get(route('dashboard.billing'))
            ->viewData('page')['props'];

        $this->assertNull($props['financialDashboard']);
        $this->assertSame([], $props['payouts']);
        $this->assertSame([], $props['reconciliations']);
        $this->assertSame([], $props['paymentLinks']);

        $references = collect($props['transactions']['data'])->pluck('reference');
        $this->assertNotContains('NEIGHBOUR-TX', $references);
    }

    public function test_an_administrators_billing_payload_still_carries_the_estate_ledger(): void
    {
        $resident = $this->homeowner();
        Transaction::create([
            'user_id' => $resident->id,
            'amount_minor' => 900_00,
            'currency' => 'JMD',
            'payment_channel' => 'cash_office',
            'reference' => 'RESIDENT-TX',
            'status' => 'completed',
        ]);

        $props = $this->actingAs($this->admin())
            ->get(route('dashboard.billing'))
            ->viewData('page')['props'];

        $this->assertNotNull($props['financialDashboard']);
        $this->assertContains(
            'RESIDENT-TX',
            collect($props['transactions']['data'])->pluck('reference'),
        );
    }

    // ── Documents ────────────────────────────────────────────────────────

    public function test_a_resident_cannot_download_another_households_invoice_pdf(): void
    {
        $victim = $this->homeowner();
        $invoice = $this->invoiceFor($victim);

        $this->actingAs($this->homeowner())
            ->get(route('dashboard.billing.invoice.pdf', ['invoice' => $invoice->id]))
            ->assertForbidden();
    }

    public function test_an_administrator_can_download_a_households_invoice_pdf(): void
    {
        $invoice = $this->invoiceFor($this->homeowner());

        $this->actingAs($this->admin())
            ->get(route('dashboard.billing.invoice.pdf', ['invoice' => $invoice->id]))
            ->assertOk();
    }

    public function test_a_resident_cannot_download_another_residents_gate_pass(): void
    {
        $holder = $this->homeowner();
        $pass = app(GatePassEngine::class)->issuePassFor($holder);

        $this->actingAs($this->homeowner())
            ->get(route('dashboard.gate-pass.pdf', ['gatePass' => $pass->id]))
            ->assertForbidden();

        $this->actingAs($holder)
            ->get(route('dashboard.gate-pass.pdf', ['gatePass' => $pass->id]))
            ->assertOk();
    }

    public function test_a_resident_cannot_read_another_residents_donation_receipt(): void
    {
        $donor = $this->homeowner();
        $fundraiser = Fundraiser::create([
            'title' => 'Gatehouse Roof',
            'description' => 'Repairs',
            'goal_minor' => 1_000_000_00,
            'goal_currency' => 'JMD',
            'status' => 'Active',
            'created_by' => $donor->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $this->actingAs($donor)->post(
            route('dashboard.fundraising.donate', ['fundraiser' => $fundraiser->id]),
            ['amount' => 500, 'channel' => 'cash_office'],
        );

        $donation = Donation::latest('id')->first();

        $this->actingAs($this->homeowner())
            ->get(route('dashboard.fundraising.donation.receipt', ['donation' => $donation->id]))
            ->assertForbidden();

        // No receipt until the office confirms the cash arrived.
        $this->actingAs($donor)
            ->get(route('dashboard.fundraising.donation.receipt', ['donation' => $donation->id]))
            ->assertStatus(409);

        $this->receiveAndVerify(Payment::where('donation_id', $donation->id)->sole());

        $this->actingAs($donor)
            ->get(route('dashboard.fundraising.donation.receipt', ['donation' => $donation->id]))
            ->assertOk();
    }
}
