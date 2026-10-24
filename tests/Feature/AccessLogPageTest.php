<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccessLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the access log: who may read it, that refused attempts are visible at
 * all, the filters that had no controls, and a CSV export that now matches the
 * filtered view rather than dumping the whole table.
 */
class AccessLogPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercises actions behind password.confirm; the prompt itself is PasswordConfirmationTest's.
        $this->confirmPassword();
    }

    private function entry(array $overrides = []): AccessLogEntry
    {
        return AccessLogEntry::create(array_merge([
            'user_name' => 'Liam Johnson',
            'user_role' => UserRole::Homeowner->value,
            'method' => 'Digital Pass',
            'gate' => 'Main Gate',
            'result' => 'ALLOW',
            'occurred_at' => now()->subHour(),
        ], $overrides));
    }

    private function security(): User
    {
        return User::factory()->role(UserRole::Security)->create();
    }

    // ── Access ───────────────────────────────────────────────────────

    public function test_security_roles_can_read_the_log(): void
    {
        $this->entry();

        foreach ([UserRole::SystemAdmin, UserRole::Admin, UserRole::Security] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/access-log')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Dashboard/AccessLog')
                    ->where('canExport', true)
                    ->has('entries.data', 1)
                );
        }
    }

    public function test_a_security_guard_is_no_longer_shut_out(): void
    {
        // The page checked `role === 'Admin' || 'System Admin'`, so the people
        // who work the gate could not read the log of it.
        $this->actingAs($this->security())
            ->get('/dashboard/access-log')
            ->assertOk();
    }

    public function test_residents_and_staff_are_refused(): void
    {
        foreach ([UserRole::Homeowner, UserRole::TemporaryHomeowner, UserRole::Staff] as $role) {
            $this->actingAs(User::factory()->role($role)->create())
                ->get('/dashboard/access-log')
                ->assertForbidden();
        }
    }

    public function test_a_resident_cannot_export(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->get('/dashboard/access-log/export')
            ->assertForbidden();
    }

    // ── Refused attempts ─────────────────────────────────────────────

    public function test_refused_attempts_reach_the_page_with_their_reason(): void
    {
        /*
         | The whole point of the page. The original had no result column and
         | five mock rows that were all successful entries, so a refused scan
         | was invisible even though the engine had always recorded it.
         */
        $this->entry([
            'result' => 'DENY',
            'deny_reason' => 'TOKEN_EXPIRED: Dynamic credential expired before scan completed.',
        ]);

        $this->actingAs($this->security())
            ->get('/dashboard/access-log')
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.result', 'DENY')
                ->where('entries.data.0.denyReason', 'TOKEN_EXPIRED: Dynamic credential expired before scan completed.')
            );
    }

    public function test_the_refused_count_is_surfaced(): void
    {
        $this->entry();
        $this->entry();
        $this->entry(['result' => 'DENY', 'deny_reason' => 'PASS_REVOKED_BY_ADMIN']);

        $this->actingAs($this->security())
            ->get('/dashboard/access-log')
            ->assertInertia(fn (Assert $page) => $page
                ->where('stats.total', 3)
                ->where('stats.denied', 1)
            );
    }

    // ── Filters ──────────────────────────────────────────────────────

    public function test_filtering_by_result(): void
    {
        $this->entry(['user_name' => 'Allowed Person']);
        $this->entry(['user_name' => 'Refused Person', 'result' => 'DENY']);

        $this->actingAs($this->security())
            ->get('/dashboard/access-log?result=DENY')
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.userName', 'Refused Person')
                ->where('stats.total', 1)
            );
    }

    public function test_filtering_by_gate(): void
    {
        $this->entry(['gate' => 'Main Gate']);
        $this->entry(['gate' => 'Service Gate', 'user_name' => 'Service Visitor']);

        $this->actingAs($this->security())
            ->get('/dashboard/access-log?gate=Service+Gate')
            ->assertInertia(fn (Assert $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.userName', 'Service Visitor')
            );
    }

    public function test_the_to_date_includes_the_whole_of_that_day(): void
    {
        /*
         | `where('occurred_at', '<=', $to)` on a date-only value compares
         | against midnight, so filtering "to 30 June" dropped everything that
         | happened during 30 June. The bound is now the end of the day.
         */
        $this->entry(['occurred_at' => now()->subDays(2)->setTime(16, 30)]);

        $to = now()->subDays(2)->toDateString();

        $this->actingAs($this->security())
            ->get("/dashboard/access-log?to={$to}")
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    }

    public function test_the_from_date_includes_the_whole_of_that_day(): void
    {
        $this->entry(['occurred_at' => now()->subDays(2)->setTime(1, 15)]);

        $from = now()->subDays(2)->toDateString();

        $this->actingAs($this->security())
            ->get("/dashboard/access-log?from={$from}")
            ->assertInertia(fn (Assert $page) => $page->has('entries.data', 1));
    }

    public function test_the_gate_options_come_from_the_recorded_data(): void
    {
        // Not from config: the seeder writes a "Pedestrian Gate" that
        // config('gatepass.gates') does not contain.
        $this->entry(['gate' => 'Pedestrian Gate']);
        $this->entry(['gate' => 'Main Gate']);

        $this->actingAs($this->security())
            ->get('/dashboard/access-log')
            ->assertInertia(fn (Assert $page) => $page
                ->where('gates', ['Main Gate', 'Pedestrian Gate'])
            );
    }

    public function test_entries_are_listed_newest_first(): void
    {
        $this->entry(['user_name' => 'Older', 'occurred_at' => now()->subWeek()]);
        $this->entry(['user_name' => 'Newer', 'occurred_at' => now()->subMinute()]);

        $this->actingAs($this->security())
            ->get('/dashboard/access-log')
            ->assertInertia(fn (Assert $page) => $page
                ->where('entries.data.0.userName', 'Newer')
                ->where('entries.data.1.userName', 'Older')
            );
    }

    // ── Export ───────────────────────────────────────────────────────

    public function test_the_export_streams_a_csv(): void
    {
        $this->entry(['user_name' => 'Liam Johnson']);

        $response = $this->actingAs($this->security())->get('/dashboard/access-log/export');

        $response->assertOk();
        // Laravel appends the charset to the type given to streamDownload().
        $response->assertHeader('Content-Type', 'text/csv; charset=utf-8');

        $csv = $response->streamedContent();

        // fputcsv quotes the fields containing a space.
        $this->assertStringContainsString(
            'Timestamp,Name,Role,Method,Gate,"Pass ID",Result,"Deny Reason"',
            $csv,
        );
        $this->assertStringContainsString('Liam Johnson', $csv);
    }

    public function test_the_export_honours_the_active_filters(): void
    {
        /*
         | It used to dump the whole table regardless. Clicking Export while
         | looking at "refused only" handed you a file containing everything,
         | which is the kind of mismatch nobody notices until the file is
         | already in a report.
         */
        $this->entry(['user_name' => 'Allowed Person']);
        $this->entry(['user_name' => 'Refused Person', 'result' => 'DENY']);

        $csv = $this->actingAs($this->security())
            ->get('/dashboard/access-log/export?result=DENY')
            ->streamedContent();

        $this->assertStringContainsString('Refused Person', $csv);
        $this->assertStringNotContainsString('Allowed Person', $csv);
    }

    public function test_the_export_covers_every_page_not_just_the_first(): void
    {
        // Paginate is 50; chunkById walks the lot.
        for ($i = 0; $i < 60; $i++) {
            $this->entry(['user_name' => "Person {$i}"]);
        }

        $csv = $this->actingAs($this->security())
            ->get('/dashboard/access-log/export')
            ->streamedContent();

        $this->assertStringContainsString('Person 0', $csv);
        $this->assertStringContainsString('Person 59', $csv);
        // One header line plus 60 rows.
        $this->assertSame(61, substr_count(trim($csv), "\n") + 1);
    }
}
