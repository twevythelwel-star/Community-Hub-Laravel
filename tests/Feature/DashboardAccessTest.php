<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\UserRole;
use App\Models\AccessLogEntry;
use App\Models\BlocklistEntry;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Warning;
use App\Services\GatePassEngine;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Proves the things the original app could only do in the UI are now enforced by
 * the server: role gates, blocklist checks, single-vote warnings and deactivation.
 */
class DashboardAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercises actions behind password.confirm; the prompt itself is PasswordConfirmationTest's.
        $this->confirmPassword();
    }

    // ── Authentication ───────────────────────────────────────────────

    public function test_the_dashboard_is_closed_to_guests(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_a_guest_sees_the_sign_in_page_at_the_root(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Welcome Back');
    }

    public function test_a_signed_in_user_is_sent_from_the_root_to_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertRedirect(route('dashboard.index'));
    }

    public function test_a_wrong_password_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'not-the-password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_correct_password_signs_the_user_in_and_issues_a_pass(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard.index'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->gatePasses()->first());
    }

    public function test_all_seeded_demo_profiles_can_login_with_seed_password(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seed(UserSeeder::class);

        $demoEmails = [
            'alexander.wright@communityhub.org',
            'elena.rostova@communityhub.org',
            'marcus.vance@residence.net',
            'sophia.taylor@residence.net',
            'dispatch@apexguard.com',
            'maria.garcia@communitystaff.org',
            'olivia.davis@residence.net',
            'john.smith@residence.net',
            'jane.doe@residence.net',
        ];

        foreach ($demoEmails as $email) {
            $response = $this->post('/login', [
                'email' => $email,
                'password' => 'ChangeMe!2026',
            ]);

            $response->assertRedirect(route('dashboard.index'));
            $this->assertAuthenticated();
            $this->post('/logout');
            $this->assertGuest();
        }
    }

    public function test_a_deactivated_account_cannot_sign_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_account_deactivated_mid_session_is_logged_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertOk();

        $user->update(['status' => 'Inactive', 'deactivated_at' => now()]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));
    }

    public function test_user_can_log_out_via_post(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect(route('landing'));
        $this->assertGuest();
    }

    /**
     * A GET could be triggered by any page linking to /logout (an image tag
     * in an email will do), so it must not sign anyone out.
     */
    public function test_a_get_request_does_not_log_out(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/logout')->assertMethodNotAllowed();

        $this->assertAuthenticatedAs($user);
    }

    public function test_inertia_logout_returns_location_response_without_plain_text_error(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post('/logout');

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', route('landing'));
        $this->assertGuest();
    }

    // ── Role gates ───────────────────────────────────────────────────

    public function test_a_homeowner_can_read_the_blocklist_but_not_change_it(): void
    {
        /*
         | This used to assert a flat 403. The read was opened to residents
         | because the sidebar had always offered them the page and the page
         | carried a resident-only "Request Removal" action — so the old gate
         | made the menu item 403 and the feature unreachable. Write stayed
         | gated. See BlockListPageTest for the redaction that goes with it.
         */
        $homeowner = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($homeowner)
            ->get('/dashboard/block-list')
            ->assertOk();

        $this->actingAs($homeowner)
            ->post('/dashboard/block-list', [
                'name' => 'Someone',
                'reason' => 'Because.',
            ])
            ->assertForbidden();
    }

    public function test_security_can_reach_the_blocklist(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->get('/dashboard/block-list')
            ->assertOk();
    }

    public function test_a_homeowner_cannot_reach_the_boundary_editor(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->get('/dashboard/map/boundary')
            ->assertForbidden();
    }

    public function test_an_admin_can_reach_the_boundary_editor(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/dashboard/map/boundary')
            ->assertOk();
    }

    public function test_a_homeowner_cannot_review_feedback(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->get('/dashboard/review-feedback')
            ->assertForbidden();
    }

    public function test_a_homeowner_cannot_scan_a_pass(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post('/dashboard/gate-pass/scan', ['token' => 'CH-GPE:v1.x.y'])
            ->assertForbidden();
    }

    public function test_only_a_system_admin_can_create_a_system_admin(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post('/dashboard/directory/users', [
                'name' => 'Escalation Attempt',
                'email' => 'escalate@example.com',
                'role' => UserRole::SystemAdmin->value,
                'password' => 'Str0ng-Password!23',
                'password_confirmation' => 'Str0ng-Password!23',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'escalate@example.com']);
    }

    public function test_an_admin_cannot_deactivate_their_own_account_from_the_directory(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin)
            ->patch("/dashboard/directory/users/{$admin->id}", ['status' => 'Inactive'])
            ->assertSessionHasErrors('status');

        $this->assertSame('Active', $admin->fresh()->status);
    }

    // ── Blocklist enforcement ────────────────────────────────────────

    public function test_a_blocklisted_person_cannot_be_registered_as_a_visitor(): void
    {
        BlocklistEntry::create([
            'name' => 'Known Troublemaker',
            'reason' => 'Repeated disturbances.',
            'date_added' => now(),
            'added_by' => 'Admin',
        ]);

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post('/dashboard/visitors', [
                'name' => 'known troublemaker',   // casing must not matter
                'type' => 'One-time',
                'expected_at' => now()->addHour()->toDateTimeString(),
            ])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('visitors', 0);
    }

    public function test_a_visitor_blocklisted_after_registration_is_refused_at_the_gate(): void
    {
        $homeowner = User::factory()->role(UserRole::Homeowner)->create();

        $visitor = Visitor::create([
            'name' => 'Later Blocked',
            'type' => 'One-time',
            'status' => 'Expected',
            'expected_at' => now(),
            'homeowner_id' => $homeowner->id,
            'homeowner_name' => $homeowner->display_name,
        ]);

        // Added to the blocklist only after they were registered.
        BlocklistEntry::create([
            'name' => 'Later Blocked',
            'reason' => 'Incident reported after registration.',
            'date_added' => now(),
            'added_by' => 'Security',
        ]);

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->post("/dashboard/visitors/{$visitor->id}/check-in")
            ->assertSessionHasErrors('visitor');

        $this->assertSame('Expected', $visitor->fresh()->status->value);
        $this->assertTrue($visitor->fresh()->is_blocked);
        $this->assertDatabaseHas('access_log_entries', [
            'user_name' => 'Later Blocked',
            'result' => 'DENY',
        ]);
    }

    public function test_an_expired_blocklist_entry_no_longer_blocks(): void
    {
        BlocklistEntry::create([
            'name' => 'Served Their Time',
            'reason' => 'Temporary ban.',
            'date_added' => now()->subYear(),
            'expiry_date' => now()->subDay(),
            'added_by' => 'Admin',
        ]);

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post('/dashboard/visitors', [
                'name' => 'Served Their Time',
                'type' => 'One-time',
                'expected_at' => now()->addHour()->toDateTimeString(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('visitors', 1);
    }

    // ── Visitor ownership ────────────────────────────────────────────

    public function test_a_resident_cannot_delete_another_residents_visitor(): void
    {
        $owner = User::factory()->role(UserRole::Homeowner)->create();
        $stranger = User::factory()->role(UserRole::Homeowner)->create();

        $visitor = Visitor::create([
            'name' => 'Someone Elses Guest',
            'type' => 'One-time',
            'status' => 'Expected',
            'expected_at' => now(),
            'homeowner_id' => $owner->id,
            'homeowner_name' => $owner->display_name,
        ]);

        $this->actingAs($stranger)
            ->delete("/dashboard/visitors/{$visitor->id}")
            ->assertForbidden();

        $this->assertDatabaseCount('visitors', 1);
    }

    // ── Warning votes ────────────────────────────────────────────────

    public function test_a_user_gets_one_vote_per_warning_and_can_change_it(): void
    {
        $warning = Warning::create([
            'title' => 'Perimeter fence damage',
            'description' => 'North section under repair.',
            'author_name' => 'Security Dispatch',
            'issued_at' => now(),
        ]);

        $user = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($user)->post("/dashboard/warnings/{$warning->id}/respond", ['response' => 'confirmed']);
        $this->actingAs($user)->post("/dashboard/warnings/{$warning->id}/respond", ['response' => 'confirmed']);

        // Voting twice must not stack.
        $this->assertSame(1, $warning->fresh()->confirmsCount());

        $this->actingAs($user)->post("/dashboard/warnings/{$warning->id}/respond", ['response' => 'denied']);

        $this->assertSame(0, $warning->fresh()->confirmsCount());
        $this->assertSame(1, $warning->fresh()->deniesCount());
    }

    // ── Gate pass scanning ───────────────────────────────────────────

    public function test_scanning_a_valid_pass_allows_entry_and_writes_the_log(): void
    {
        $holder = User::factory()->role(UserRole::Homeowner)->create();
        $engine = app(GatePassEngine::class);
        $pass = $engine->issuePassFor($holder);
        $token = $engine->issueToken($pass)['token'];

        $guard = User::factory()->role(UserRole::Security)->create();

        $response = $this->actingAs($guard)->postJson('/dashboard/gate-pass/scan', [
            'token' => $token,
            'gate' => GateId::Gate01->value,
        ]);

        $response->assertOk()->assertJsonPath('report.status', 'ALLOW');

        $this->assertDatabaseHas('access_log_entries', [
            'pass_id' => $pass->pass_id,
            'result' => 'ALLOW',
            'scanned_by' => $guard->id,
        ]);
    }

    public function test_scanning_a_revoked_pass_denies_entry_and_still_logs_it(): void
    {
        $holder = User::factory()->role(UserRole::Homeowner)->create();
        $engine = app(GatePassEngine::class);
        $pass = $engine->issuePassFor($holder);
        $token = $engine->issueToken($pass)['token'];

        $pass->revoke(null, 'Lost device');

        $guard = User::factory()->role(UserRole::Security)->create();

        $this->actingAs($guard)
            ->postJson('/dashboard/gate-pass/scan', ['token' => $token])
            ->assertOk()
            ->assertJsonPath('report.status', 'DENY');

        // A denial must be recorded, not silently dropped.
        $this->assertSame(1, AccessLogEntry::denied()->count());
    }

    public function test_the_token_endpoint_never_returns_the_signing_secret(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->getJson('/dashboard/gate-pass/token');

        $response->assertOk()->assertJsonStructure(['token', 'validFrom', 'validUntil', 'secondsRemaining']);
        $this->assertStringNotContainsString(config('gatepass.secret'), $response->getContent());
    }

    // ── Deactivation ─────────────────────────────────────────────────

    public function test_self_deactivation_revokes_the_pass_and_ends_the_session(): void
    {
        $user = User::factory()->create();
        $pass = app(GatePassEngine::class)->issuePassFor($user);

        $this->actingAs($user)->post('/dashboard/deactivation', [
            'password' => 'password',
            'confirm' => '1',
            'reason' => 'Moving away.',
        ])->assertRedirect(route('landing'));

        $this->assertGuest();
        $this->assertSame('Inactive', $user->fresh()->status);
        $this->assertTrue($pass->fresh()->isRevoked());
    }

    public function test_deactivation_requires_the_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/dashboard/deactivation', [
            'password' => 'wrong-password',
            'confirm' => '1',
        ])->assertSessionHasErrors('password');

        $this->assertSame('Active', $user->fresh()->status);
    }

    public function test_inertia_deactivation_asks_for_a_full_page_visit_to_the_landing_page(): void
    {
        /*
         | The page posts through Inertia and the landing page is Blade. A plain
         | redirect would have been rendered inside Inertia's error modal
         | instead of leaving the dashboard.
         */
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->withHeaders(['X-Inertia' => 'true'])
            ->post('/dashboard/deactivation', [
                'password' => 'password',
                'confirm' => true,
            ]);

        $response->assertStatus(409);
        $response->assertHeader('X-Inertia-Location', route('landing'));
        $response->assertSessionHas('success', 'Your account has been deactivated.');
        $this->assertGuest();
        $this->assertSame('Inactive', $user->fresh()->status);
    }

    public function test_deactivation_requires_explicit_confirmation(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/dashboard/deactivation', [
            'password' => 'password',
            'confirm' => false,
        ])->assertSessionHasErrors('confirm');

        $this->assertSame('Active', $user->fresh()->status);
    }

    public function test_a_temporary_homeowner_can_open_the_deactivation_page(): void
    {
        $user = User::factory()->role(UserRole::TemporaryHomeowner)->create();

        $this->actingAs($user)->get('/dashboard/deactivation')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Deactivation')
                ->where('user.email', $user->email)
            );
    }

    // ── Data exposure ────────────────────────────────────────────────

    public function test_the_directory_is_closed_to_non_admins(): void
    {
        /*
         | Previously this asserted 200-with-redaction. The route was open while
         | the page rendered "Access Denied" and the sidebar offered it to
         | administrators only, so a resident received a payload nothing would
         | draw. The gate now matches the page. Redaction still runs inside
         | DirectoryController as defence in depth, and the boundary that
         | actually keeps one resident's details from another is covered by
         | OverviewPageTest's resident-spotlight tests.
         */
        User::factory()->role(UserRole::Homeowner)->create([
            'email' => 'private.resident@example.com',
            'phone' => '(876) 555-9999',
        ]);

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->get('/dashboard/directory')
            ->assertForbidden();
    }

    public function test_the_directory_shows_contact_details_to_admins(): void
    {
        User::factory()->role(UserRole::Homeowner)->create([
            'email' => 'visible.resident@example.com',
        ]);

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/dashboard/directory')
            ->assertOk()
            ->assertSee('visible.resident@example.com');
    }
}
