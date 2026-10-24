<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\BillingSetting;
use App\Models\Business;
use App\Models\Community;
use App\Models\Fundraiser;
use App\Models\Invoice;
use App\Models\Notification;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the dashboard aggregates that replaced the page's mock arrays and
 * hardcoded JSX, and the globally-shared boundary and billing props that
 * replaced MapProvider and BillingProvider.
 */
class OverviewPageTest extends TestCase
{
    use RefreshDatabase;

    private function resident(array $attributes = []): User
    {
        return User::factory()->role(UserRole::Homeowner)->create($attributes);
    }

    // ── Scoping ──────────────────────────────────────────────────────

    public function test_a_resident_only_sees_their_own_visitors_in_the_stats(): void
    {
        $mine = $this->resident();
        $theirs = $this->resident();

        foreach ([$mine, $theirs] as $owner) {
            Visitor::create([
                'name' => "Guest of {$owner->id}", 'type' => 'One-time', 'status' => 'Expected',
                'expected_at' => now()->addHour(), 'homeowner_id' => $owner->id,
                'homeowner_name' => $owner->display_name,
            ]);
        }

        $this->actingAs($mine)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/Overview')
                ->where('stats.upcomingVisitors', 1)
                ->has('recentVisitors', 1)
            );
    }

    public function test_security_sees_every_visitor_in_the_stats(): void
    {
        foreach (range(1, 3) as $i) {
            $owner = $this->resident();

            Visitor::create([
                'name' => "Guest {$i}", 'type' => 'One-time', 'status' => 'Expected',
                'expected_at' => now()->addHour(), 'homeowner_id' => $owner->id,
                'homeowner_name' => $owner->display_name,
            ]);
        }

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.upcomingVisitors', 3)
                ->where('permissions.viewActiveResidents', true)
            );
    }

    public function test_a_resident_gets_no_estate_wide_counts(): void
    {
        // The "452 Active Residents" tile was hardcoded markup shown to everyone.
        // The figure is now null for anyone who may not manage security, so the
        // tile is not rendered rather than merely hidden with CSS.
        $this->actingAs($this->resident())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.activeResidents', null)
                ->where('stats.entriesToday', null)
                ->where('permissions.viewActiveResidents', false)
                ->where('permissions.viewBilling', false)
            );
    }

    public function test_a_no_show_clearance_is_excluded_from_upcoming_visitors(): void
    {
        $resident = $this->resident();

        Visitor::create([
            'name' => 'Never Arrived', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now()->addHour(), 'homeowner_id' => $resident->id,
            'homeowner_name' => $resident->display_name,
            'expired_at' => now(),
        ]);

        $this->actingAs($resident)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('stats.upcomingVisitors', 0));
    }

    // ── Billing figures ──────────────────────────────────────────────

    public function test_billing_tiles_aggregate_real_invoices(): void
    {
        BillingSetting::create(['monthly_fee_minor' => 500000, 'currency' => 'JMD', 'due_day_of_month' => 1]);

        $a = $this->resident();
        $b = $this->resident();

        Invoice::create([
            'user_id' => $a->id, 'reference' => 'INV-A', 'amount_minor' => 500000,
            'currency' => 'JMD', 'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(), 'due_on' => now()->startOfMonth(),
            'status' => 'Paid', 'paid_at' => now(),
        ]);

        Invoice::create([
            'user_id' => $b->id, 'reference' => 'INV-B', 'amount_minor' => 750000,
            'currency' => 'JMD', 'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(), 'due_on' => now()->startOfMonth(),
            'status' => 'Unpaid',
        ]);

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('permissions.viewBilling', true)
                ->where('stats.totalCollected', 5000)
                ->where('stats.collectedHouseholds', 1)
                ->where('stats.outstandingDues', 7500)
                ->where('stats.outstandingHouseholds', 1)
            );
    }

    public function test_money_is_summed_from_integer_minor_units(): void
    {
        $resident = $this->resident();

        // Three amounts that would drift if summed as floats, plus a fourth
        // that leaves a fractional major unit — proving the /100 is real and
        // not a whole number that would read the same either way.
        foreach ([[1, 3333], [2, 3333], [3, 3334], [4, 50]] as [$n, $minor]) {
            Invoice::create([
                'user_id' => $resident->id, 'reference' => "INV-{$n}", 'amount_minor' => $minor,
                'currency' => 'JMD', 'period_start' => now()->startOfMonth(),
                'period_end' => now()->endOfMonth(), 'due_on' => now()->startOfMonth(),
                'status' => 'Paid', 'paid_at' => now(),
            ]);
        }

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                /*
                 | 100.50, not 100.5000000001. Asserted loosely on type because
                 | json_encode() drops a whole float's fraction — 100.0 goes out
                 | as `100` without JSON_PRESERVE_ZERO_FRACTION — so the value
                 | can arrive as int or float depending on the cents.
                 */
                ->where('stats.totalCollected', fn ($value) => (float) $value === 100.50)
            );
    }

    // ── Shared props ─────────────────────────────────────────────────

    public function test_the_published_boundary_is_shared_with_every_page(): void
    {
        $community = Community::default();

        $config = $community->boundaryConfigs()->create([
            'version' => 1,
            'status' => 'PUBLISHED',
            'published_coordinates' => [
                [18.4742, -77.9284],
                [18.4748, -77.9241],
                [18.4771, -77.9232],
                [18.4790, -77.9246],
            ],
            'last_published_at' => now(),
        ]);

        $this->actingAs($this->resident())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('boundary.coordinates', 4)
                ->where('boundary.version', 1)
                // Metrics are computed by GeofenceService, not in the browser.
                ->has('boundary.metrics.areaAcres')
                ->has('boundary.metrics.perimeterMeters')
            );
    }

    public function test_billing_settings_are_shared_rather_than_reset_per_browser(): void
    {
        BillingSetting::create(['monthly_fee_minor' => 725000, 'currency' => 'JMD', 'due_day_of_month' => 5]);

        // BillingProvider hardcoded 5000 in React state on every page load.
        $this->actingAs($this->resident())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->where('billing.monthlyFee', 7250)
                ->where('billing.dueDayOfMonth', 5)
            );
    }

    // ── Content ──────────────────────────────────────────────────────

    public function test_landmarks_come_from_the_database(): void
    {
        $community = Community::default();

        $community->landmarks()->create([
            'name' => 'Main Security Gate', 'category' => 'Security Gate',
            'description' => 'Primary vehicular entry.', 'lat' => 18.4750, 'lng' => -77.9257,
        ]);

        $this->actingAs($this->resident())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('landmarks', 1)
                ->where('landmarks.0.name', 'Main Security Gate')
                // Green pin for a gate, matching LANDMARK_PIN_CONFIGS.
                ->where('landmarks.0.color', '#10B981')
                ->has('landmarks.0.elevation')
            );
    }

    public function test_announcements_respect_their_target_roles(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        Notification::create([
            'title' => 'For Everyone', 'content' => 'Open notice.',
            'author_name' => 'Admin', 'published_at' => now(), 'target_roles' => null,
        ]);

        Notification::create([
            'title' => 'Security Only', 'content' => 'Restricted notice.',
            'author_name' => 'Admin', 'published_at' => now(),
            'target_roles' => [UserRole::Security->value],
        ]);

        $this->actingAs($this->resident())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('announcements', 1)
                ->where('announcements.0.title', 'For Everyone')
            );

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->has('announcements', 2));
    }

    public function test_the_resident_spotlight_hides_phone_numbers_from_other_residents(): void
    {
        $this->resident(['display_name' => 'Marcus Vance', 'phone' => '(876) 555-0103']);

        /*
         | The viewer's name is pinned to sort after Marcus. The spotlight orders
         | by display_name, and leaving this to the factory's faker made
         | `residents.0` whichever name happened to sort first — the test passed
         | or failed on the random seed.
         */
        $response = $this->actingAs($this->resident(['display_name' => 'Zara Young']))
            ->get('/dashboard');

        $response->assertInertia(fn (Assert $page) => $page
            ->where('residents.0.phone', null)
            ->where('residents.0.initials', 'MV')
        );

        $response->assertDontSee('(876) 555-0103');
    }

    public function test_an_admin_sees_resident_phone_numbers(): void
    {
        $this->resident(['display_name' => 'Marcus Vance', 'phone' => '(876) 555-0103']);

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('residents.0.phone', '(876) 555-0103'));
    }

    public function test_fundraiser_progress_is_computed_server_side(): void
    {
        $fundraiser = Fundraiser::create([
            'title' => 'Clubhouse Roof', 'description' => 'Repairs.',
            'goal_minor' => 100000, 'goal_currency' => 'JMD',
            'start_date' => now()->subWeek(), 'end_date' => now()->addMonth(),
            'status' => 'Active',
        ]);

        $donor = $this->resident();

        foreach ([25000, 25000] as $amount) {
            $fundraiser->donations()->create([
                'user_id' => $donor->id, 'amount_minor' => $amount,
                'currency' => 'JMD', 'is_anonymous' => false, 'donated_at' => now(),
            ]);
        }

        $this->actingAs($donor)
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('fundraisers', 1)
                // See the note in test_money_is_summed_from_integer_minor_units:
                // a whole float serialises as an integer literal in JSON.
                ->where('fundraisers.0.raised', fn ($v) => (float) $v === 500.0)
                ->where('fundraisers.0.goal', fn ($v) => (float) $v === 1000.0)
                ->where('fundraisers.0.progress', fn ($v) => (float) $v === 50.0)
                ->where('fundraisers.0.donorCount', 2)
            );
    }

    public function test_only_active_fundraisers_reach_the_dashboard(): void
    {
        foreach (['Active', 'Completed', 'Upcoming'] as $status) {
            Fundraiser::create([
                'title' => "{$status} drive", 'description' => 'x',
                'goal_minor' => 100000, 'goal_currency' => 'JMD',
                'start_date' => now()->subWeek(), 'end_date' => now()->addMonth(),
                'status' => $status,
            ]);
        }

        $this->actingAs($this->resident())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('fundraisers', 1)
                ->where('fundraisers.0.status', 'Active')
            );
    }

    public function test_perks_carry_their_vouchers(): void
    {
        $business = Business::create(['name' => 'Bayview Pharmacy', 'active' => true]);
        $business->vouchers()->create([
            'title' => '10% off prescriptions',
            'description' => 'Show your resident pass at the counter.',
        ]);

        $this->actingAs($this->resident())
            ->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page
                ->has('perks', 1)
                ->where('perks.0.vouchers.0.title', '10% off prescriptions')
            );
    }

    public function test_the_page_renders_with_an_entirely_empty_database(): void
    {
        // Every section was previously backed by a non-empty mock array, so the
        // empty states had never been exercised.
        $this->actingAs($this->resident())
            ->get('/dashboard')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('announcements', 0)
                ->has('recentVisitors', 0)
                ->has('fundraisers', 0)
                ->has('perks', 0)
                ->has('landmarks', 0)
                ->where('stats.upcomingVisitors', 0)
            );
    }
}
