<?php

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\DelegatedAccess;
use App\Models\GatePass;
use App\Models\Property;
use App\Models\Renter;
use App\Models\User;
use App\Services\DigitalAccessWalletService;
use App\Services\HouseholdManagementService;
use App\Services\Occupancy\OccupancyCensusQueryService;
use App\Services\Occupancy\OccupancyHierarchyBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The occupancy count is what an emergency roll call starts from, and a
 * renter's people list must be their own unit's. Both were reading invented
 * data: fixed counts for Units 14 and 15, a made-up "Royal Palm Way" address,
 * and a default lot of "14" that matched another household's property.
 */
class OccupancyAndRenterScopeAccuracyTest extends TestCase
{
    use RefreshDatabase;

    private function hierarchy(): array
    {
        return app(OccupancyHierarchyBuilder::class)->getCommunityHierarchy();
    }

    private function checkedIn(User $holder, ?string $property = null): GatePass
    {
        return GatePass::create([
            'pass_id' => 'GP-TEST-'.$holder->id,
            'user_id' => $holder->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => $holder->name,
            'property' => $property,
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHour(),
        ]);
    }

    // ── Occupancy ────────────────────────────────────────────────────────

    public function test_an_empty_estate_shows_no_units(): void
    {
        $hierarchy = $this->hierarchy();

        // It invented Units 14, 15 and 42.
        $this->assertSame([], $hierarchy['properties']);
        $this->assertSame(0, $hierarchy['totalInside']);
    }

    public function test_unit_14_reports_who_is_actually_inside(): void
    {
        $this->checkedIn(User::factory()->role(UserRole::Homeowner)->create(['lot' => '14']), 'Unit 14');

        $unit = collect($this->hierarchy()['properties'])->firstWhere('unit', 'Unit 14');

        // It always said "5 inside, 8 expected".
        $this->assertSame(1, $unit['insideCount']);
        $this->assertSame(1, $unit['categoriesInside']['residents']);
    }

    public function test_a_unit_is_addressed_from_its_property_record(): void
    {
        $owner = User::factory()->role(UserRole::Homeowner)->create(['lot' => 'Lot 42', 'street' => 'Royal Palm Drive']);
        Property::create(['owner_user_id' => $owner->id, 'property_code' => 'PROP-42', 'lot_number' => 'Lot 42', 'street_address' => 'Royal Palm Drive']);
        $this->checkedIn($owner);

        $unit = collect($this->hierarchy()['properties'])->sole();

        // "Lot 42" was prefixed into "Unit Lot 42", which normalised to "Unit Lot".
        $this->assertSame('Unit 42', $unit['unit']);
        $this->assertSame('Lot 42, Royal Palm Drive', $unit['address']);
    }

    public function test_a_doubly_prefixed_property_counts_under_its_unit(): void
    {
        $owner = User::factory()->role(UserRole::Homeowner)->create(['lot' => 'Lot 42']);

        // Household passes were stored as "Unit Lot 42" and counted under "Unit Lot".
        $this->checkedIn($owner, 'Unit Lot 42');
        $this->assertSame('Unit 42', collect($this->hierarchy()['properties'])->sole()['unit']);

        // And new households record the lot as it is.
        $this->assertSame('Lot 42', app(HouseholdManagementService::class)->getOrCreateHousehold($owner)->property_number);
    }

    public function test_staff_posts_are_one_estate_operations_group_not_units(): void
    {
        $officer = User::factory()->role(UserRole::Security)->create(['lot' => 'Gatehouse 1', 'street' => 'Main Perimeter Entrance']);
        $this->checkedIn($officer, 'Gatehouse 1, Main Perimeter Entrance');
        $manager = User::factory()->role(UserRole::Admin)->create(['lot' => 'Admin Suite']);
        $this->checkedIn($manager, 'Admin Suite');

        // An administrator who lives here is counted at their home.
        $resident = User::factory()->role(UserRole::Admin)->create(['lot' => 'Lot 101', 'street' => 'Royal Palm Drive']);
        Property::create(['owner_user_id' => $resident->id, 'property_code' => 'PROP-101', 'lot_number' => 'Lot 101', 'street_address' => 'Royal Palm Drive']);
        $this->checkedIn($resident, 'Lot 101');

        $units = collect($this->hierarchy()['properties'])->keyBy('unit');

        $this->assertEqualsCanonicalizing([OccupancyCensusQueryService::ESTATE_OPERATIONS, 'Unit 101'], $units->keys()->all());
        $this->assertSame(2, $units[OccupancyCensusQueryService::ESTATE_OPERATIONS]['insideCount']);
        $this->assertSame(1, $units['Unit 101']['insideCount']);
    }

    public function test_a_resident_without_a_lot_is_unassigned_not_a_unit_called_unassigned(): void
    {
        $this->checkedIn(User::factory()->role(UserRole::Homeowner)->create(['lot' => null]));

        $this->assertSame(['Unassigned'], collect($this->hierarchy()['properties'])->pluck('unit')->all());
    }

    public function test_a_unit_without_a_property_record_is_not_given_an_invented_street(): void
    {
        $this->checkedIn(User::factory()->role(UserRole::Homeowner)->create(['lot' => '9']), 'Unit 9');

        $this->assertSame('Unit 9', collect($this->hierarchy()['properties'])->sole()['address']);
    }

    public function test_a_renter_without_a_lot_is_expected_at_their_homeowners(): void
    {
        Carbon::setTestNow('2026-10-07 10:00');
        $owner = User::factory()->role(UserRole::Homeowner)->create(['lot' => 'Lot 42']);
        Renter::create(['homeowner_id' => $owner->id, 'name' => 'Lease Tenant', 'status' => 'Active', 'lease_start' => '2026-10-01', 'lease_end' => '2027-03-31']);

        $expected = app(OccupancyCensusQueryService::class)->getAllExpectedToday()->firstWhere('name', 'Lease Tenant');

        // It defaulted to "Unit 14".
        $this->assertSame('Unit 42', $expected['unit']);
    }

    // ── A renter's people list ───────────────────────────────────────────

    private function renterOf(User $owner, ?string $lot = null): User
    {
        Carbon::setTestNow('2026-10-07 10:00');
        $renter = User::factory()->role(UserRole::TemporaryHomeowner)->create(['lot' => $lot]);
        Renter::create([
            'user_id' => $renter->id,
            'homeowner_id' => $owner->id,
            'name' => $renter->name,
            'lot' => $lot,
            'status' => 'Active',
            'lease_start' => '2026-10-01',
            'lease_end' => '2027-03-31',
        ]);

        return $renter;
    }

    private function contractorAt(Property $property, string $name): void
    {
        DelegatedAccess::create([
            'grantor_user_id' => $property->owner_user_id,
            'property_id' => $property->id,
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'relationship' => 'Contractor',
            'access_level' => 'Contractor',
            'authorization_type' => 'contractor',
            'status' => 'active',
        ]);
    }

    private function peopleSeenBy(User $renter): Collection
    {
        return collect($this->actingAs($renter)->getJson('/dashboard/delegation')->assertOk()->json('authorizedPeople'))->pluck('name');
    }

    public function test_a_renter_with_no_lot_does_not_see_another_households_people(): void
    {
        $neighbour = User::factory()->role(UserRole::Homeowner)->create(['lot' => '14']);
        $theirHome = Property::create(['owner_user_id' => $neighbour->id, 'property_code' => 'PROP-14', 'lot_number' => '14']);
        $this->contractorAt($theirHome, 'Neighbours Plumber');

        $landlord = User::factory()->role(UserRole::Homeowner)->create(['lot' => null]);

        // No lot anywhere used to mean lot "14": the neighbour's.
        $this->assertNotContains('Neighbours Plumber', $this->peopleSeenBy($this->renterOf($landlord)));
    }

    public function test_a_renter_sees_the_property_they_rent_of_an_owner_with_several(): void
    {
        $owner = User::factory()->role(UserRole::Homeowner)->create(['lot' => 'Lot 1']);
        $first = Property::create(['owner_user_id' => $owner->id, 'property_code' => 'PROP-1', 'lot_number' => 'Lot 1']);
        $rented = Property::create(['owner_user_id' => $owner->id, 'property_code' => 'PROP-2', 'lot_number' => 'Lot 2']);
        $this->contractorAt($first, 'Lot One Gardener');
        $this->contractorAt($rented, 'Lot Two Electrician');

        $people = $this->peopleSeenBy($this->renterOf($owner, 'Unit 2'));

        // It took the owner's first property, whichever one they rent.
        $this->assertContains('Lot Two Electrician', $people);
        $this->assertNotContains('Lot One Gardener', $people);
    }

    public function test_an_owner_of_several_without_a_lot_to_go_on_shares_none(): void
    {
        $owner = User::factory()->role(UserRole::Homeowner)->create(['lot' => 'Lot 1']);
        $first = Property::create(['owner_user_id' => $owner->id, 'property_code' => 'PROP-1', 'lot_number' => 'Lot 1']);
        Property::create(['owner_user_id' => $owner->id, 'property_code' => 'PROP-2', 'lot_number' => 'Lot 2']);
        $this->contractorAt($first, 'Lot One Gardener');

        $this->assertNotContains('Lot One Gardener', $this->peopleSeenBy($this->renterOf($owner)));
    }

    // ── Wallet ───────────────────────────────────────────────────────────

    public function test_a_wallet_pass_without_a_property_is_not_given_unit_14(): void
    {
        $user = User::factory()->role(UserRole::Security)->create();
        $pass = GatePass::create([
            'pass_id' => 'GP-NOPROP-1',
            'user_id' => $user->id,
            'category' => PassCategory::Staff,
            'holder_name' => $user->name,
            'property' => null,
            'status' => PassStatus::Active,
            'rotation_seq' => 0,
        ]);

        $credential = app(DigitalAccessWalletService::class)->formatWalletPass($pass, 'Staff');

        $this->assertStringNotContainsString('Unit 14', json_encode($credential));
        $this->assertSame('Unassigned', $credential['property']);
    }
}
