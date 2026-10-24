<?php

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\UserRole;
use App\Models\Staff;
use App\Models\User;
use App\Services\GatePassEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Covers the props GatePassController hands to the wired-up page, and the
 * boundaries that replaced client-side behaviour: the directory is staff-only,
 * the token endpoint only ever serves the caller's own pass, and the visual
 * config comes from the server instead of a bundled constant.
 */
class GatePassPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_resident_sees_their_own_pass_but_no_directory(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($resident)
            ->get('/dashboard/gate-pass')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/GatePass')
                ->where('pass.category', PassCategory::Homeowner->value)
                ->where('can.manageSecurity', false)
                ->where('can.scan', false)
                // Other residents' passes must not be in the payload at all —
                // hiding the tab client-side would still ship the data.
                ->has('directory', 0)
                ->has('staff', 0)
            );
    }

    public function test_security_sees_the_full_directory(): void
    {
        $guard = User::factory()->role(UserRole::Security)->create();
        User::factory()->role(UserRole::Homeowner)->count(3)->create();

        $engine = app(GatePassEngine::class);
        User::all()->each(fn (User $u) => $engine->issuePassFor($u));

        $this->actingAs($guard)
            ->get('/dashboard/gate-pass')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Dashboard/GatePass')
                ->where('can.manageSecurity', true)
                ->where('can.scan', true)
                ->has('directory', 4)
                ->has('directory.0', fn (Assert $entry) => $entry
                    ->hasAll(['id', 'passId', 'category', 'userName', 'role', 'property', 'gate', 'status', 'colorVariant'])
                    ->etc()
                )
            );
    }

    public function test_the_page_sends_visual_config_for_every_category(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard/gate-pass')
            ->assertInertia(fn (Assert $page) => $page
                ->has('categoryConfigs', count(PassCategory::cases()))
                // camelCase keys, matching the CategoryVisualConfig type the
                // React components already expected.
                ->has('categoryConfigs.HOMEOWNER', fn (Assert $config) => $config
                    ->where('shape', 'HEXAGON')
                    ->hasAll(['displayName', 'shapeLabel', 'themeColor', 'accentColor', 'gradient', 'badgeBorder'])
                    ->etc()
                )
            );
    }

    public function test_the_policy_prop_is_camel_cased_for_the_client(): void
    {
        $staff = User::factory()->role(UserRole::Staff)->create(['lot' => null]);

        $this->actingAs($staff)
            ->get('/dashboard/gate-pass')
            ->assertInertia(fn (Assert $page) => $page
                ->where('policy.operationalHours.is24Hours', false)
                ->where('policy.operationalHours.startHour', 6)
                ->where('policy.operationalHours.endHour', 19)
                ->has('policy.authorizedZones')
                ->where('policy.privileges.canManageGuests', false)
            );
    }

    public function test_household_staff_get_the_house_hex_category(): void
    {
        // A staff account attached to a specific lot is household staff, not
        // community staff. The old client mapped every Staff role to STAFF.
        $household = User::factory()->role(UserRole::Staff)->create([
            'lot' => 'Lot 42',
            'street' => 'Royal Palm Drive',
        ]);

        $this->actingAs($household)
            ->get('/dashboard/gate-pass')
            ->assertInertia(fn (Assert $page) => $page
                ->where('pass.category', PassCategory::HomeownerStaff->value)
            );
    }

    public function test_a_system_admin_keeps_the_sysadmin_category(): void
    {
        // The removed mapRoleToCategory() collapsed System Admin into ADMIN.
        $sysadmin = User::factory()->role(UserRole::SystemAdmin)->create();

        $this->actingAs($sysadmin)
            ->get('/dashboard/gate-pass')
            ->assertInertia(fn (Assert $page) => $page
                ->where('pass.category', PassCategory::SysAdmin->value)
            );
    }

    public function test_the_token_endpoint_serves_only_the_callers_own_pass(): void
    {
        $owner = User::factory()->role(UserRole::Homeowner)->create();
        $intruder = User::factory()->role(UserRole::Homeowner)->create();

        $engine = app(GatePassEngine::class);
        $ownerPass = $engine->issuePassFor($owner);

        $response = $this->actingAs($intruder)->getJson('/dashboard/gate-pass/token');
        $response->assertOk();

        // There is no parameter to request another user's token; the endpoint
        // derives the pass from the session.
        $this->assertStringNotContainsString($ownerPass->pass_id, $response->getContent());
    }

    public function test_rotating_advances_only_the_callers_own_pass(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $engine = app(GatePassEngine::class);
        $mine = $engine->issuePassFor($user);
        $theirs = $engine->issuePassFor($other);
        $theirSeq = $theirs->rotation_seq;

        $this->actingAs($user)
            ->postJson('/dashboard/gate-pass/rotate')
            ->assertOk()
            ->assertJsonPath('rotationSeq', 2);

        $this->assertSame(2, $mine->fresh()->rotation_seq);
        $this->assertSame($theirSeq, $theirs->fresh()->rotation_seq);
    }

    public function test_only_security_roles_may_revoke_a_pass(): void
    {
        $holder = User::factory()->role(UserRole::Homeowner)->create();
        $pass = app(GatePassEngine::class)->issuePassFor($holder);

        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->post("/dashboard/gate-pass/{$pass->id}/revoke", ['reason' => 'because'])
            ->assertForbidden();

        $this->assertFalse($pass->fresh()->isRevoked());

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->post("/dashboard/gate-pass/{$pass->id}/revoke", ['reason' => 'Lost device'])
            ->assertRedirect();

        $this->assertTrue($pass->fresh()->isRevoked());
    }

    public function test_revocation_requires_a_reason(): void
    {
        $pass = app(GatePassEngine::class)->issuePassFor(User::factory()->create());

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->post("/dashboard/gate-pass/{$pass->id}/revoke", [])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($pass->fresh()->isRevoked());
    }

    public function test_the_staff_tab_labels_household_and_community_staff_separately(): void
    {
        $homeowner = User::factory()->role(UserRole::Homeowner)->create();
        $admin = User::factory()->role(UserRole::Admin)->create();

        Staff::create([
            'name' => 'Household Helper', 'job' => 'Housekeeper',
            'id_type' => 'National ID', 'id_number' => 'A1', 'id_expiry' => now()->addYear(),
            'property_label' => 'Lot 42', 'added_by' => $homeowner->id, 'status' => 'Active',
        ]);

        Staff::create([
            'name' => 'Grounds Crew', 'job' => 'Gardener',
            'id_type' => 'National ID', 'id_number' => 'B2', 'id_expiry' => now()->addYear(),
            'property_label' => 'Community Grounds', 'added_by' => $admin->id, 'status' => 'Active',
        ]);

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->get('/dashboard/gate-pass')
            ->assertInertia(fn (Assert $page) => $page
                ->has('staff', 2)
                // Ordered by name: "Grounds Crew" then "Household Helper".
                ->where('staff.0.name', 'Grounds Crew')
                ->where('staff.0.category', PassCategory::Staff->value)
                ->where('staff.1.name', 'Household Helper')
                ->where('staff.1.category', PassCategory::HomeownerStaff->value)
                ->etc()
            );
    }

    public function test_staff_with_an_expired_id_report_the_expired_status(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        Staff::create([
            'name' => 'Lapsed Document', 'job' => 'Gardener',
            'id_type' => 'Passport', 'id_number' => 'C3',
            'id_expiry' => now()->subMonth(),       // past
            'property_label' => 'Community Grounds', 'added_by' => $admin->id,
            'status' => 'Active',                    // still Active in the column
        ]);

        $this->actingAs($admin)
            ->get('/dashboard/gate-pass')
            ->assertInertia(fn (Assert $page) => $page
                ->where('staff.0.status', 'Expired ID')
                ->etc()
            );
    }
}
