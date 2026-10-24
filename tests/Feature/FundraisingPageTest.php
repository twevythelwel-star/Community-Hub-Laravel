<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Fundraiser;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers fundraising: the page loading at all on SQLite, donations being
 * recorded in the fundraiser's own currency so the total means something,
 * anonymity, and opening an Upcoming fundraiser.
 */
class FundraisingPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercises actions behind password.confirm; the prompt itself is PasswordConfirmationTest's.
        $this->confirmPassword();
    }

    private function fundraiser(array $overrides = []): Fundraiser
    {
        return Fundraiser::create(array_merge([
            'title' => 'New Playground Equipment',
            'description' => 'A modern playground for the community children.',
            'goal_minor' => 150000000,        // JMD 1,500,000.00
            'goal_currency' => 'JMD',
            'start_date' => now()->subWeek(),
            'end_date' => now()->addMonth(),
            'status' => 'Active',
        ], $overrides));
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

    private function resident(array $attributes = []): User
    {
        return User::factory()->role(UserRole::Homeowner)->create($attributes);
    }

    private function admin(): User
    {
        return User::factory()->role(UserRole::Admin)->create();
    }

    // ── Loading ──────────────────────────────────────────────────────

    public function test_the_page_loads(): void
    {
        /*
         | This is not a formality. The listing ordered with
         | `FIELD(status, ...)`, which is a MySQL function — on SQLite, which is
         | what this suite and the quick-start setup both use, the page threw.
         | No test covered it, so nothing caught it.
         */
        $this->fundraiser();

        $this->actingAs($this->resident())
            ->get('/dashboard/fundraising')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Fundraising')
                ->has('fundraisers', 1)
                ->where('canManage', false)
            );
    }

    public function test_active_fundraisers_are_listed_before_upcoming_and_completed(): void
    {
        $this->fundraiser(['title' => 'Done', 'status' => 'Completed', 'end_date' => now()->subWeek()]);
        $this->fundraiser(['title' => 'Later', 'status' => 'Upcoming', 'start_date' => now()->addWeek()]);
        $this->fundraiser(['title' => 'Now', 'status' => 'Active']);

        $this->actingAs($this->resident())
            ->get('/dashboard/fundraising')
            ->assertInertia(fn (Assert $page) => $page
                ->where('fundraisers.0.title', 'Now')
                ->where('fundraisers.1.title', 'Later')
                ->where('fundraisers.2.title', 'Done')
            );
    }

    public function test_only_fundraiser_managers_are_offered_management(): void
    {
        foreach ([UserRole::Admin, UserRole::SystemAdmin] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/fundraising')
                ->assertInertia(fn (Assert $page) => $page->where('canManage', true));
        }

        foreach ([UserRole::Homeowner, UserRole::TemporaryHomeowner] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/fundraising')
                ->assertInertia(fn (Assert $page) => $page->where('canManage', false));
        }
    }

    /**
     * Fundraising is resident community life. Security and Staff are employed
     * by the estate rather than living in it, and the sidebar has never
     * offered them the page — but the route was open, so both could reach it
     * by URL and donate. `accessCommunityLife` now closes it.
     *
     * This previously asserted that Security saw the page with
     * `canManage => false`, which described the hole rather than the rule.
     */
    public function test_the_estates_employees_are_not_admitted_to_fundraising(): void
    {
        foreach ([UserRole::Security, UserRole::Staff] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/fundraising')
                ->assertForbidden();
        }
    }

    // ── Donations ────────────────────────────────────────────────────

    public function test_a_donation_is_recorded_in_the_fundraisers_currency(): void
    {
        /*
         | The form offered five currencies and defaulted to USD, while
         | raisedMinor() sums amount_minor with no conversion. USD 50 against a
         | JMD goal counted as JMD 50 — about 1/155th of what was given. The
         | currency now comes from the fundraiser and the request cannot set it.
         */
        $fundraiser = $this->fundraiser();
        $donor = $this->resident();

        $this->actingAs($donor)
            ->post("/dashboard/fundraising/{$fundraiser->id}/donate", [
                'amount' => 50,
                'currency' => 'USD',          // ignored
                'donor_name' => 'Marcus V.',
                'is_anonymous' => false,
                // Card gifts are recorded from Stripe's confirmation (see
                // StripeDonationTest); an office channel records immediately.
                'channel' => 'cash_office',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $donation = $fundraiser->donations()->firstOrFail();

        $this->assertSame('JMD', $donation->currency);
        $this->assertSame(5000, $donation->amount_minor);
    }

    public function test_the_total_is_summed_from_minor_units(): void
    {
        $fundraiser = $this->fundraiser(['goal_minor' => 1000000]);   // 10,000.00

        foreach ([3333, 3333, 3334, 50] as $minor) {
            $fundraiser->donations()->create([
                'user_id' => $this->resident()->id,
                'amount_minor' => $minor,
                'currency' => 'JMD',
                'is_anonymous' => false,
                'donated_at' => now(),
            ]);
        }

        $this->actingAs($this->resident())
            ->get('/dashboard/fundraising')
            ->assertInertia(fn (Assert $page) => $page
                ->where('fundraisers.0.raised', fn ($v) => (float) $v === 100.50)
                ->where('fundraisers.0.donorCount', 4)
            );
    }

    public function test_an_anonymous_donor_is_never_named_in_the_payload(): void
    {
        $fundraiser = $this->fundraiser();

        $this->actingAs($this->resident())
            ->post("/dashboard/fundraising/{$fundraiser->id}/donate", [
                'amount' => 100,
                'donor_name' => 'Should Not Appear',
                'is_anonymous' => true,
                'channel' => 'cash_office',
            ])
            ->assertRedirect();

        // The public feed shows confirmed gifts only.
        $this->receiveAndVerify(Payment::sole());

        $response = $this->actingAs($this->resident())->get('/dashboard/fundraising');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('fundraisers.0.recentDonations.0.donorName', 'Anonymous')
        );

        $response->assertDontSee('Should Not Appear');
    }

    public function test_a_closed_fundraiser_refuses_donations(): void
    {
        $fundraiser = $this->fundraiser(['status' => 'Upcoming', 'start_date' => now()->addWeek()]);

        $this->actingAs($this->resident())
            ->post("/dashboard/fundraising/{$fundraiser->id}/donate", ['amount' => 100])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('donations', 0);
    }

    public function test_an_expired_fundraiser_refuses_donations(): void
    {
        // Active, but past its end date — isOpen() checks both.
        $fundraiser = $this->fundraiser(['end_date' => now()->subDay()]);

        $this->actingAs($this->resident())
            ->post("/dashboard/fundraising/{$fundraiser->id}/donate", ['amount' => 100])
            ->assertSessionHasErrors('amount');

        $this->assertDatabaseCount('donations', 0);
    }

    public function test_the_amount_must_be_positive(): void
    {
        $fundraiser = $this->fundraiser();

        $this->actingAs($this->resident())
            ->post("/dashboard/fundraising/{$fundraiser->id}/donate", ['amount' => 0])
            ->assertSessionHasErrors('amount');
    }

    // ── Creating and opening ─────────────────────────────────────────

    public function test_an_administrator_creates_a_fundraiser(): void
    {
        $this->actingAs($this->admin())
            ->post('/dashboard/fundraising', [
                'title' => 'Community Garden Expansion',
                'description' => 'Ten new plots and an irrigation system.',
                'goal' => 400000,
                'start_date' => now()->addWeek()->toDateString(),
                'end_date' => now()->addMonths(2)->toDateString(),
                'status' => 'Upcoming',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('fundraisers', [
            'title' => 'Community Garden Expansion',
            'goal_minor' => 40000000,
            'status' => 'Upcoming',
        ]);
    }

    public function test_a_resident_cannot_create_a_fundraiser(): void
    {
        $this->actingAs($this->resident())
            ->post('/dashboard/fundraising', [
                'title' => 'Unauthorised',
                'description' => 'Should not be created.',
                'goal' => 1000,
                'start_date' => now()->toDateString(),
                'end_date' => now()->addMonth()->toDateString(),
                'status' => 'Active',
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('fundraisers', 0);
    }

    public function test_enable_now_opens_an_upcoming_fundraiser(): void
    {
        // The button existed with no handler and no endpoint, so a scheduled
        // fundraiser could never be opened.
        $fundraiser = $this->fundraiser(['status' => 'Upcoming', 'start_date' => now()->addDay()]);

        $this->actingAs($this->admin())
            ->patch("/dashboard/fundraising/{$fundraiser->id}", ['status' => 'Active'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('Active', $fundraiser->fresh()->status);
        $this->assertTrue($fundraiser->fresh()->isOpen());
    }

    public function test_an_expired_fundraiser_cannot_be_opened(): void
    {
        /*
         | isOpen() is Active AND not past its end date, so activating an
         | expired fundraiser produces one that looks open and refuses every
         | donation.
         */
        $fundraiser = $this->fundraiser(['status' => 'Upcoming', 'end_date' => now()->subDay()]);

        $this->actingAs($this->admin())
            ->patch("/dashboard/fundraising/{$fundraiser->id}", ['status' => 'Active'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Upcoming', $fundraiser->fresh()->status);
    }

    public function test_a_resident_cannot_open_a_fundraiser(): void
    {
        $fundraiser = $this->fundraiser(['status' => 'Upcoming']);

        $this->actingAs($this->resident())
            ->patch("/dashboard/fundraising/{$fundraiser->id}", ['status' => 'Active'])
            ->assertForbidden();

        $this->assertSame('Upcoming', $fundraiser->fresh()->status);
    }

    // ── Campaign Dashboard, Reporting, Reconciliation & Refunds ──────

    public function test_admin_receives_campaign_dashboard_reporting_props(): void
    {
        $fundraiser = $this->fundraiser();
        $donor = $this->resident();

        $fundraiser->donations()->create([
            'user_id' => $donor->id,
            'amount_minor' => 10000,
            'currency' => 'JMD',
            'donor_name' => 'Marcus V.',
            'is_anonymous' => false,
            'donated_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->get('/dashboard/fundraising')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Fundraising')
                ->where('canManage', true)
                ->has('adminStats')
                ->where('adminStats.totalCampaigns', 1)
                ->where('adminStats.totalRaised', fn ($v) => (float) $v === 100.0)
                ->has('donorReports', 1)
                ->where('donorReports.0.donorName', 'Marcus V.')
                ->has('reconciliation')
            );
    }

    public function test_resident_receives_their_own_donations_with_receipts(): void
    {
        $fundraiser = $this->fundraiser();
        $donor = $this->resident();
        $otherDonor = $this->resident();

        $myDonation = $fundraiser->donations()->create([
            'user_id' => $donor->id,
            'amount_minor' => 5000,
            'currency' => 'JMD',
            'donor_name' => 'My Name',
            'is_anonymous' => false,
            'donated_at' => now(),
        ]);

        $fundraiser->donations()->create([
            'user_id' => $otherDonor->id,
            'amount_minor' => 7500,
            'currency' => 'JMD',
            'donor_name' => 'Other Person',
            'is_anonymous' => false,
            'donated_at' => now(),
        ]);

        $this->actingAs($donor)
            ->get('/dashboard/fundraising')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Fundraising')
                ->where('canManage', false)
                ->has('userDonations', 1)
                ->where('userDonations.0.id', $myDonation->id)
                ->where('userDonations.0.amount', fn ($v) => (float) $v === 50.0)
            );
    }

    public function test_administrator_can_refund_a_donation(): void
    {
        $fundraiser = $this->fundraiser();
        $donor = $this->resident();

        $donation = $fundraiser->donations()->create([
            'user_id' => $donor->id,
            'amount_minor' => 25000,
            'currency' => 'JMD',
            'donor_name' => 'John D.',
            'receipt_number' => 'DON-REC-TEST-001',
            'payment_channel' => 'card',
            'donated_at' => now(),
            'status' => 'completed',
        ]);

        $this->assertSame(250.0, $fundraiser->fresh()->raised());

        $this->actingAs($this->admin())
            ->post("/dashboard/fundraising/donations/{$donation->id}/refund", [
                'reason' => 'Duplicate transaction entered in error',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('refunded', $donation->fresh()->status);
        $this->assertNotNull($donation->fresh()->refunded_at);
        $this->assertSame('Duplicate transaction entered in error', $donation->fresh()->refund_reason);

        // Deducted from raised total
        $this->assertSame(0.0, $fundraiser->fresh()->raised());

        // Master Ledger transaction recorded
        $this->assertDatabaseHas('transactions', [
            'user_id' => $donor->id,
            'fundraiser_id' => $fundraiser->id,
            'status' => 'refunded',
            'amount_minor' => 25000,
        ]);
    }

    public function test_an_already_refunded_donation_cannot_be_refunded_again(): void
    {
        $fundraiser = $this->fundraiser();
        $donor = $this->resident();

        $donation = $fundraiser->donations()->create([
            'user_id' => $donor->id,
            'amount_minor' => 25000,
            'currency' => 'JMD',
            'donor_name' => 'John D.',
            'status' => 'refunded',
            'refunded_at' => now(),
            'donated_at' => now(),
        ]);

        $this->actingAs($this->admin())
            ->post("/dashboard/fundraising/donations/{$donation->id}/refund", [
                'reason' => 'Trying to refund again',
            ])
            ->assertSessionHasErrors('refund');
    }

    public function test_a_resident_cannot_refund_a_donation(): void
    {
        $fundraiser = $this->fundraiser();
        $donor = $this->resident();

        $donation = $fundraiser->donations()->create([
            'user_id' => $donor->id,
            'amount_minor' => 10000,
            'currency' => 'JMD',
            'donor_name' => 'Resident Donor',
            'donated_at' => now(),
        ]);

        $this->actingAs($donor)
            ->post("/dashboard/fundraising/donations/{$donation->id}/refund", [
                'reason' => 'Unauthorized resident refund attempt',
            ])
            ->assertForbidden();

        $this->assertSame('completed', $donation->fresh()->status ?? 'completed');
    }

    public function test_administrator_can_export_donations_csv(): void
    {
        $fundraiser = $this->fundraiser();
        $donor = $this->resident();

        $fundraiser->donations()->create([
            'user_id' => $donor->id,
            'amount_minor' => 15000,
            'currency' => 'JMD',
            'donor_name' => 'Export Tester',
            'receipt_number' => 'DON-EXPORT-1',
            'donated_at' => now(),
        ]);

        $response = $this->actingAs($this->admin())
            ->get('/dashboard/fundraising/export/donations');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment; filename="fundraising-donations-', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_a_resident_cannot_export_donations(): void
    {
        $this->actingAs($this->resident())
            ->get('/dashboard/fundraising/export/donations')
            ->assertForbidden();
    }

    public function test_a_recurring_donation_records_frequency(): void
    {
        $fundraiser = $this->fundraiser();
        $donor = $this->resident();

        $this->actingAs($donor)
            ->post("/dashboard/fundraising/{$fundraiser->id}/donate", [
                'amount' => 50,
                'is_recurring' => true,
                'frequency' => 'quarterly',
                'channel' => 'cash_office',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $donation = $fundraiser->donations()->firstOrFail();
        $this->assertTrue($donation->is_recurring);
        $this->assertSame('quarterly', $donation->frequency);
    }

    public function test_administrator_can_post_campaign_update_with_image(): void
    {
        $fundraiser = $this->fundraiser();

        $this->actingAs($this->admin())
            ->post("/dashboard/fundraising/{$fundraiser->id}/updates", [
                'title' => 'Foundation Laid',
                'content' => 'The playground foundation cement has dried perfectly.',
                'image_url' => 'https://images.unsplash.com/photo-1541888946425-d0fbb186156a',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('fundraiser_updates', [
            'fundraiser_id' => $fundraiser->id,
            'title' => 'Foundation Laid',
            'image_url' => 'https://images.unsplash.com/photo-1541888946425-d0fbb186156a',
        ]);
    }
}
