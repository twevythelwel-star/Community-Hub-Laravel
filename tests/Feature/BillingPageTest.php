<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BillingSetting;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers billing: a resident's own invoices and nobody else's, the estate
 * figures and collections chart built from real rows, the fee/currency/due-day
 * settings, and marking an invoice paid — which the UI could never reach.
 */
class BillingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercises actions behind password.confirm; the prompt itself is PasswordConfirmationTest's.
        $this->confirmPassword();
    }

    private function resident(array $attributes = []): User
    {
        return User::factory()->role(UserRole::Homeowner)->create($attributes);
    }

    private function admin(): User
    {
        return User::factory()->role(UserRole::Admin)->create();
    }

    private function invoice(User $user, array $overrides = []): Invoice
    {
        static $sequence = 0;
        $sequence++;

        return Invoice::create(array_merge([
            'user_id' => $user->id,
            'reference' => sprintf('INV-TEST-%04d', $sequence),
            'amount_minor' => 500000,          // 5,000.00
            'currency' => 'JMD',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'due_on' => now()->addWeek(),
            'status' => 'Unpaid',
        ], $overrides));
    }

    // ── Resident view ────────────────────────────────────────────────

    public function test_a_resident_sees_their_own_invoices_only(): void
    {
        $resident = $this->resident();
        $neighbour = $this->resident();

        $this->invoice($resident, ['reference' => 'INV-MINE-0001']);
        $this->invoice($neighbour, ['reference' => 'INV-THEIRS-0001']);

        $response = $this->actingAs($resident)->get('/dashboard/billing');

        $response->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Billing')
                ->where('canManage', false)
                ->has('myInvoices.data', 1)
                ->where('myInvoices.data.0.reference', 'INV-MINE-0001')
            );

        $response->assertDontSee('INV-THEIRS-0001');
    }

    public function test_the_estate_figures_are_withheld_from_residents(): void
    {
        $this->actingAs($this->resident())
            ->get('/dashboard/billing')
            ->assertInertia(fn (Assert $page) => $page
                ->where('adminSummary', null)
                ->where('monthlyCollections', null)
                ->where('invoices', null)
            );
    }

    public function test_the_outstanding_total_is_summed_from_minor_units(): void
    {
        $resident = $this->resident();

        // Amounts that would drift if they were summed as floats.
        $this->invoice($resident, ['amount_minor' => 3333]);
        $this->invoice($resident, ['amount_minor' => 3333]);
        $this->invoice($resident, ['amount_minor' => 3334]);
        $this->invoice($resident, ['amount_minor' => 50]);
        // Paid invoices are not outstanding.
        $this->invoice($resident, ['amount_minor' => 900000, 'status' => 'Paid', 'paid_at' => now()]);

        $this->actingAs($resident)
            ->get('/dashboard/billing')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.outstanding', fn ($value) => (float) $value === 100.50)
            );
    }

    public function test_the_year_to_date_total_counts_only_this_years_payments(): void
    {
        // Replaces a hardcoded "Year-to-Date Payments (2025)" heading over four
        // fabricated payments.
        $resident = $this->resident();

        $this->invoice($resident, ['status' => 'Paid', 'paid_at' => now()->subMonth()]);
        $this->invoice($resident, ['status' => 'Paid', 'paid_at' => now()->subDays(3)]);
        $this->invoice($resident, ['status' => 'Paid', 'paid_at' => now()->subYears(2)]);

        $this->actingAs($resident)
            ->get('/dashboard/billing')
            ->assertInertia(fn (Assert $page) => $page
                ->where('summary.paidCountThisYear', 2)
                ->where('summary.paidThisYear', fn ($v) => (float) $v === 10000.0)
                ->where('summary.year', (int) now()->year)
            );
    }

    public function test_an_unpaid_invoice_past_its_due_date_reads_as_overdue(): void
    {
        $resident = $this->resident();
        $this->invoice($resident, ['status' => 'Unpaid', 'due_on' => now()->subWeek()]);

        $this->actingAs($resident)
            ->get('/dashboard/billing')
            ->assertInertia(fn (Assert $page) => $page->where('myInvoices.data.0.status', 'Overdue'));
    }

    // ── Admin view ───────────────────────────────────────────────────

    public function test_an_administrator_gets_the_estate_figures(): void
    {
        $resident = $this->resident();
        $other = $this->resident();

        $this->invoice($resident, ['status' => 'Paid', 'paid_at' => now()]);
        $this->invoice($resident, ['due_on' => now()->subWeek()]);   // overdue
        $this->invoice($other);                                      // outstanding

        $this->actingAs($this->admin())
            ->get('/dashboard/billing')
            ->assertInertia(fn (Assert $page) => $page
                ->where('canManage', true)
                ->where('adminSummary.collectedThisMonth', fn ($v) => (float) $v === 5000.0)
                ->where('adminSummary.totalOutstanding', fn ($v) => (float) $v === 10000.0)
                ->where('adminSummary.overdueCount', 1)
                ->where('adminSummary.householdsPaidThisMonth', 1)
                ->where('adminSummary.householdsOutstanding', 2)
                ->has('invoices.data', 3)
            );
    }

    public function test_the_collections_chart_has_twelve_real_months(): void
    {
        /*
         | `monthlyCollectionsData` was twelve hardcoded figures. The chart is
         | now aggregated from paid invoices, and still always twelve entries so
         | the axis does not change shape as months fill in.
         */
        $resident = $this->resident();

        $this->invoice($resident, [
            'status' => 'Paid',
            'paid_at' => now()->startOfYear()->addMonths(2)->setDay(15),
            'amount_minor' => 700000,
        ]);

        $this->actingAs($this->admin())
            ->get('/dashboard/billing')
            ->assertInertia(fn (Assert $page) => $page
                ->has('monthlyCollections', 12)
                ->where('monthlyCollections.2.total', fn ($v) => (float) $v === 7000.0)
                ->where('monthlyCollections.0.total', fn ($v) => (float) $v === 0.0)
            );
    }

    public function test_the_admin_table_names_the_household(): void
    {
        $resident = $this->resident(['display_name' => 'Marcus Vance', 'lot' => '42']);
        $this->invoice($resident);

        $this->actingAs($this->admin())
            ->get('/dashboard/billing')
            ->assertInertia(fn (Assert $page) => $page
                ->where('invoices.data.0.homeowner', 'Marcus Vance')
                ->where('invoices.data.0.lot', '42')
            );
    }

    // ── Settings ─────────────────────────────────────────────────────

    public function test_an_administrator_updates_the_fee_currency_and_due_day(): void
    {
        // The form only ever offered the fee, though updateSettings has always
        // validated all three.
        $this->actingAs($this->admin())
            ->patch('/dashboard/billing/settings', [
                'monthly_fee' => 7500.50,
                'currency' => 'usd',
                'due_day_of_month' => 15,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $settings = BillingSetting::current();

        $this->assertSame(750050, $settings->monthly_fee_minor);
        $this->assertSame('USD', $settings->currency, 'currency is upper-cased');
        $this->assertSame(15, $settings->due_day_of_month);
    }

    public function test_the_fee_survives_the_float_multiplication(): void
    {
        // 4999.99 * 100 is 499998.99999999994 in binary floating point, so a
        // bare (int) cast would store 499998 — one cent short.
        $this->actingAs($this->admin())
            ->patch('/dashboard/billing/settings', [
                'monthly_fee' => 4999.99,
                'currency' => 'JMD',
                'due_day_of_month' => 1,
            ])
            ->assertRedirect();

        $this->assertSame(499999, BillingSetting::current()->monthly_fee_minor);
    }

    public function test_a_due_day_beyond_28_is_rejected(): void
    {
        // 29, 30 and 31 do not exist in every month.
        $this->actingAs($this->admin())
            ->patch('/dashboard/billing/settings', [
                'monthly_fee' => 5000,
                'currency' => 'JMD',
                'due_day_of_month' => 31,
            ])
            ->assertSessionHasErrors('due_day_of_month');
    }

    public function test_a_resident_cannot_change_the_fee(): void
    {
        $this->actingAs($this->resident())
            ->patch('/dashboard/billing/settings', [
                'monthly_fee' => 1,
                'currency' => 'JMD',
                'due_day_of_month' => 1,
            ])
            ->assertForbidden();

        $this->assertNotSame(100, BillingSetting::current()->monthly_fee_minor);
    }

    // ── Marking paid ─────────────────────────────────────────────────

    public function test_an_administrator_marks_an_invoice_paid(): void
    {
        // The only action in the transactions table was "Send Reminder", which
        // had no handler; markPaid was written and unreachable.
        $invoice = $this->invoice($this->resident());
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post("/dashboard/billing/invoices/{$invoice->id}/pay")
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $invoice->fresh();

        $this->assertSame('Paid', $fresh->status);
        $this->assertNotNull($fresh->paid_at);

        $this->assertDatabaseHas('activity_log_entries', [
            'user_id' => $admin->id,
            'action' => "Marked invoice {$invoice->reference} paid",
        ]);
    }

    public function test_marking_a_paid_invoice_again_is_refused(): void
    {
        /*
         | markPaid() sets paid_at to now(). Running it twice would move an old
         | payment into the current month and inflate this month's collections.
         */
        $paidAt = now()->subMonths(3);
        $invoice = $this->invoice($this->resident(), ['status' => 'Paid', 'paid_at' => $paidAt]);

        $this->actingAs($this->admin())
            ->post("/dashboard/billing/invoices/{$invoice->id}/pay")
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertSame(
            $paidAt->toDateTimeString(),
            $invoice->fresh()->paid_at->toDateTimeString(),
            'the original payment date must not move',
        );
    }

    public function test_a_resident_cannot_mark_their_own_invoice_paid(): void
    {
        $resident = $this->resident();
        $invoice = $this->invoice($resident);

        $this->actingAs($resident)
            ->post("/dashboard/billing/invoices/{$invoice->id}/pay")
            ->assertForbidden();

        $this->assertSame('Unpaid', $invoice->fresh()->status);
    }

    // ── The claim that is gone ───────────────────────────────────────

    public function test_the_page_does_not_claim_a_compliance_certification(): void
    {
        /*
         | The resident view stated "Card information is securely collected by
         | Stripe (PCI-DSS Level 1 Compliant)" above a Pay button with no
         | handler, and collected card-holder details in its own inputs.
         |
         | This no longer asserts the absence of "Stripe": a real integration
         | was added alongside this page (StripeCheckoutController,
         | StripePaymentService), so the word legitimately appears in a checkout
         | URL. What must stay absent is the compliance claim and any attempt to
         | take card details in this application's own form — the parts that
         | were untrue, and that are a question of fact rather than of wording.
         */
        $response = $this->actingAs($this->resident())->get('/dashboard/billing');

        $response->assertDontSee('PCI-DSS');
        $response->assertDontSee('PCI DSS');
        $response->assertDontSee('Name on Card');
        $response->assertDontSee('Remember this card');
    }
}
