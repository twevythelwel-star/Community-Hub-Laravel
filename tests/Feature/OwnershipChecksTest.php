<?php

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\HouseholdMember;
use App\Models\ParkingPass;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\HouseholdManagementService;
use App\Services\VehicleManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Vehicles, parking passes and household members could be created or changed
 * by any signed-in account: someone else's plate edited or deleted by id, a
 * suspended plate switched back on by its owner, accessible bays self-issued,
 * and households (each member with a gate pass) built by any role.
 */
class OwnershipChecksTest extends TestCase
{
    use RefreshDatabase;

    private function homeowner(array $attributes = []): User
    {
        return User::factory()->role(UserRole::Homeowner)->create(['lot' => '9', 'street' => 'Palm Vista Drive'] + $attributes);
    }

    private function vehicleFor(User $owner, array $attributes = []): Vehicle
    {
        return app(VehicleManagementService::class)->registerVehicle($owner, $attributes + [
            'license_plate' => 'AB '.fake()->unique()->numerify('####'),
            'make' => 'Toyota',
            'model' => 'Corolla',
            'color' => 'Silver',
        ]);
    }

    // ── Vehicles ─────────────────────────────────────────────────────────

    public function test_a_resident_cannot_edit_or_delete_someone_elses_vehicle(): void
    {
        $vehicle = $this->vehicleFor($this->homeowner());
        $neighbour = $this->homeowner(['lot' => '10']);

        $this->actingAs($neighbour)->patch("/dashboard/vehicles/{$vehicle->id}", ['anpr_enabled' => false])->assertForbidden();
        $this->delete("/dashboard/vehicles/{$vehicle->id}")->assertForbidden();

        $this->assertTrue($vehicle->fresh()->anpr_enabled);
    }

    public function test_an_owner_cannot_lift_a_suspension_but_security_can(): void
    {
        $owner = $this->homeowner();
        $vehicle = $this->vehicleFor($owner, ['status' => 'suspended', 'anpr_enabled' => false]);

        $this->actingAs($owner)->patch("/dashboard/vehicles/{$vehicle->id}", [
            'status' => 'active',
            'anpr_enabled' => true,
            'color' => 'Blue',
        ])->assertRedirect();

        $vehicle->refresh();
        $this->assertSame('suspended', $vehicle->status);
        $this->assertFalse($vehicle->anpr_enabled);
        $this->assertSame('Blue', $vehicle->color);

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->patch("/dashboard/vehicles/{$vehicle->id}", ['status' => 'active', 'anpr_enabled' => true])
            ->assertRedirect();
        $this->assertSame('active', $vehicle->fresh()->status);
    }

    public function test_an_owner_can_delete_their_own_vehicle(): void
    {
        $owner = $this->homeowner();
        $vehicle = $this->vehicleFor($owner);

        $this->actingAs($owner)->delete("/dashboard/vehicles/{$vehicle->id}")->assertRedirect();
        $this->assertModelMissing($vehicle);
    }

    public function test_a_vehicle_can_only_be_tied_to_your_own_household_member(): void
    {
        $other = $this->homeowner(['lot' => '10']);
        $service = app(HouseholdManagementService::class);
        $theirs = $service->addMember($service->getOrCreateHousehold($other), ['name' => 'Not Mine', 'role_in_household' => 'child']);

        $owner = $this->homeowner();
        $mine = $service->addMember($service->getOrCreateHousehold($owner), ['name' => 'My Child', 'role_in_household' => 'child']);

        $vehicle = ['license_plate' => 'CD 1234', 'make' => 'Honda', 'model' => 'Fit', 'color' => 'Red'];
        $this->actingAs($owner)->post('/dashboard/vehicles', $vehicle + ['household_member_id' => $theirs->id])->assertForbidden();
        $this->post('/dashboard/vehicles', $vehicle + ['household_member_id' => $mine->id])->assertRedirect();

        $this->assertDatabaseHas('vehicles', ['license_plate' => 'CD 1234', 'household_member_id' => $mine->id]);
    }

    public function test_a_resident_looks_up_only_their_own_plates(): void
    {
        $neighboursCar = $this->vehicleFor($this->homeowner(['lot' => '10']), ['license_plate' => 'NB 7777']);
        $owner = $this->homeowner();
        $this->vehicleFor($owner, ['license_plate' => 'MY 1111']);

        $this->actingAs($owner)->getJson('/dashboard/vehicles/anpr/lookup?plate=MY1111')
            ->assertOk()->assertJsonPath('found', true);

        // Another resident's plate reads exactly like an unregistered one: no
        // owner, property or parking spot, and no hint that it exists.
        $theirs = $this->getJson('/dashboard/vehicles/anpr/lookup?plate=NB7777')->assertNotFound();
        $unknown = $this->getJson('/dashboard/vehicles/anpr/lookup?plate=NB7777X')->assertNotFound();
        $this->assertSame(
            str_replace('NB7777', '', json_encode($theirs->json())),
            str_replace('NB7777X', '', json_encode($unknown->json())),
        );
        $this->assertStringNotContainsString($neighboursCar->property, $theirs->getContent());

        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->getJson('/dashboard/vehicles/anpr/lookup?plate=NB7777')
            ->assertOk()->assertJsonPath('found', true);
    }

    public function test_a_gate_officer_cannot_register_vehicles(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->post('/dashboard/vehicles', ['license_plate' => 'EF 5678', 'make' => 'Honda', 'model' => 'Fit', 'color' => 'Red'])
            ->assertForbidden();
    }

    // ── Parking ──────────────────────────────────────────────────────────

    public function test_a_resident_cannot_issue_staff_only_parking_categories(): void
    {
        $owner = $this->homeowner();

        foreach (['accessible', 'loading_zone'] as $category) {
            $this->actingAs($owner)->post('/dashboard/parking', [
                'category' => $category,
                'license_plate' => 'GH 1111',
                'holder_name' => 'Me',
            ])->assertForbidden();
        }

        $this->assertSame(0, ParkingPass::count());
    }

    public function test_a_residents_pass_is_for_their_own_vehicle_property_and_no_bay(): void
    {
        $owner = $this->homeowner();
        $neighboursCar = $this->vehicleFor($this->homeowner(['lot' => '10']));

        $this->actingAs($owner)->post('/dashboard/parking', [
            'category' => 'visitor',
            'license_plate' => 'IJ 2222',
            'holder_name' => 'A Guest',
            'vehicle_id' => $neighboursCar->id,
        ])->assertForbidden();

        $this->post('/dashboard/parking', [
            'category' => 'visitor',
            'license_plate' => 'IJ 2222',
            'holder_name' => 'A Guest',
            'property' => 'Lot 99',
            'assigned_bay' => 'VIP-01',
        ])->assertRedirect();

        $pass = ParkingPass::sole();
        $this->assertSame($owner->propertyLabel(), $pass->property);
        $this->assertNull($pass->assigned_bay);
    }

    public function test_viewing_parking_does_not_invent_passes(): void
    {
        $owner = $this->homeowner();

        $this->actingAs($owner)->get('/dashboard/parking')->assertOk();
        $this->assertSame(0, ParkingPass::count());
    }

    public function test_verifying_a_parking_pass_is_an_attendants_job(): void
    {
        $this->actingAs($this->homeowner())->getJson('/dashboard/parking/verify?payload=x')->assertForbidden();
        $this->actingAs(User::factory()->role(UserRole::Security)->create())->getJson('/dashboard/parking/verify?payload=x')->assertSuccessful();
    }

    // ── Households ───────────────────────────────────────────────────────

    public function test_staff_roles_cannot_build_a_household(): void
    {
        $officer = User::factory()->role(UserRole::Security)->create();

        $this->actingAs($officer)->get('/dashboard/household')->assertForbidden();
        $this->postJson('/dashboard/household/members', ['name' => 'Someone', 'role_in_household' => 'child'])->assertForbidden();
        $this->assertSame(0, HouseholdMember::count());
    }

    /** @return array<string, array{string}> */
    public static function removedSeedRoutes(): array
    {
        return [
            'example household' => ['/dashboard/household/seed-example'],
            'example vehicles' => ['/dashboard/vehicles/seed'],
            'example vehicles (alias)' => ['/dashboard/vehicles/seed-smith'],
            'example parking passes' => ['/dashboard/parking/seed'],
        ];
    }

    /**
     * Each created invented records (gate passes, ANPR-enabled plates and
     * parking permits) for whoever called it, in any environment.
     */
    #[DataProvider('removedSeedRoutes')]
    public function test_example_data_cannot_be_loaded_over_http(string $uri): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())->postJson($uri)->assertNotFound();

        $this->assertSame(0, HouseholdMember::count());
        $this->assertSame(0, Vehicle::count());
        $this->assertSame(0, ParkingPass::count());
    }

    public function test_viewing_vehicles_does_not_invent_any(): void
    {
        $smith = $this->homeowner(['name' => 'John Smith', 'email' => 'john.smith@example.com']);

        $this->actingAs($smith)->get('/dashboard/vehicles')->assertOk();
        $this->assertSame(0, Vehicle::count());
    }

    public function test_staff_roles_cannot_remove_members(): void
    {
        $owner = $this->homeowner();
        $service = app(HouseholdManagementService::class);
        $member = $service->addMember($service->getOrCreateHousehold($owner), ['name' => 'Carer', 'role_in_household' => 'caregiver']);
        $members = HouseholdMember::count();

        $this->actingAs(User::factory()->role(UserRole::Security)->create());
        $this->deleteJson("/dashboard/household/members/{$member->id}")->assertForbidden();

        $this->assertModelExists($member);
        $this->assertSame($members, HouseholdMember::count());
    }

    public function test_a_resident_cannot_change_or_remove_a_neighbours_member(): void
    {
        $service = app(HouseholdManagementService::class);
        $member = $service->addMember($service->getOrCreateHousehold($this->homeowner()), ['name' => 'Carer', 'role_in_household' => 'caregiver']);
        $neighbour = $this->homeowner(['lot' => '10']);

        $this->actingAs($neighbour)->patchJson("/dashboard/household/members/{$member->id}", ['status' => 'suspended'])->assertForbidden();
        $this->deleteJson("/dashboard/household/members/{$member->id}")->assertForbidden();

        $this->assertSame('active', $member->fresh()->status);
        // Checking used to create the neighbour a household with its own homeowner pass.
        $this->assertDatabaseMissing('households', ['primary_homeowner_id' => $neighbour->id]);
    }

    public function test_an_administrator_manages_any_member_without_getting_a_household(): void
    {
        $service = app(HouseholdManagementService::class);
        $member = $service->addMember($service->getOrCreateHousehold($this->homeowner()), ['name' => 'Carer', 'role_in_household' => 'caregiver']);
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->actingAs($admin)->patchJson("/dashboard/household/members/{$member->id}", ['status' => 'suspended'])->assertOk();
        $this->deleteJson("/dashboard/household/members/{$member->id}")->assertOk();

        $this->assertModelMissing($member);
        $this->assertDatabaseMissing('households', ['primary_homeowner_id' => $admin->id]);
    }

    public function test_a_member_cannot_be_added_as_a_second_homeowner_or_with_unknown_permissions(): void
    {
        $this->actingAs($this->homeowner());

        $this->postJson('/dashboard/household/members', ['name' => 'Second Owner', 'role_in_household' => 'homeowner'])
            ->assertJsonValidationErrors('role_in_household');
        $this->postJson('/dashboard/household/members', ['name' => 'Admin Wannabe', 'role_in_household' => 'other', 'permissions' => ['manage_everything']])
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_an_other_member_gets_an_occupant_pass_that_ends(): void
    {
        config(['delegation.max_pass_days' => 30]);
        $owner = $this->homeowner();

        $this->actingAs($owner)->postJson('/dashboard/household/members', [
            'name' => 'Lodger',
            'role_in_household' => 'other',
            'valid_until' => now()->addYears(5)->toDateString(),
        ])->assertCreated();

        $pass = HouseholdMember::where('name', 'Lodger')->sole()->gatePass;
        $this->assertSame(PassCategory::LongTermOccupant, $pass->category);
        $this->assertNotNull($pass->valid_until);
        $this->assertTrue($pass->valid_until->lte(now()->addDays(30)->addMinute()));
    }

    public function test_reactivating_a_member_never_revives_a_revoked_pass(): void
    {
        $owner = $this->homeowner();
        $service = app(HouseholdManagementService::class);
        $member = $service->addMember($service->getOrCreateHousehold($owner), ['name' => 'Carer', 'role_in_household' => 'caregiver']);

        $this->actingAs($owner)->patchJson("/dashboard/household/members/{$member->id}", ['status' => 'suspended'])->assertOk();
        $this->assertSame(PassStatus::Suspended, $member->gatePass->fresh()->status);

        $this->patchJson("/dashboard/household/members/{$member->id}", ['status' => 'active'])->assertOk();
        $this->assertSame(PassStatus::Active, $member->gatePass->fresh()->status);

        // Security revokes it; the household switching the member back on must not undo that.
        $member->gatePass->update(['status' => PassStatus::Revoked]);
        $this->patchJson("/dashboard/household/members/{$member->id}", ['status' => 'suspended'])->assertOk();
        $this->patchJson("/dashboard/household/members/{$member->id}", ['status' => 'active'])->assertOk();
        $this->assertSame(PassStatus::Revoked, $member->gatePass->fresh()->status);
    }
}
