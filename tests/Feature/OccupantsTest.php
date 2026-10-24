<?php

namespace Tests\Feature;

use App\Enums\AuthorizedPersonRole;
use App\Enums\OwnershipEntityType;
use App\Enums\PassCategory;
use App\Enums\UserRole;
use App\Models\AuthorizedPerson;
use App\Models\DelegatedAccess;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\Occupant;
use App\Models\OwnershipEntity;
use App\Models\Property;
use App\Models\Renter;
use App\Models\Staff;
use App\Models\User;
use App\Services\People\PropertyPeopleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Renters, household members and staff share the occupants table. Each model
 * must see only its own rows, and all five kinds of person on a property must
 * be listable together.
 */
class OccupantsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['role' => UserRole::Homeowner, 'lot' => '42']);
        $this->property = Property::create([
            'property_code' => 'LOT-42',
            'lot_number' => '42',
            'street_address' => '42 Royal Palm Drive',
            'owner_user_id' => $this->owner->id,
            'status' => 'occupied',
        ]);
    }

    public function test_each_model_sees_only_its_own_type(): void
    {
        $renter = $this->renter();
        $member = $this->member($this->household('Unit Lot 42'));
        $staff = $this->staff('Lot 42, Royal Palm Drive');

        $this->assertSame([$renter->id], Renter::query()->pluck('id')->all());
        $this->assertSame([$member->id], HouseholdMember::query()->pluck('id')->all());
        $this->assertSame([$staff->id], Staff::query()->pluck('id')->all());

        $this->assertNull(Renter::find($member->id));
        $this->assertNull(HouseholdMember::find($staff->id));
        $this->assertNull(Staff::find($renter->id));
    }

    public function test_type_is_stamped_on_create_and_cannot_be_mass_assigned(): void
    {
        $member = HouseholdMember::create([
            'household_id' => $this->household('Unit Lot 42')->id,
            'name' => 'Sneaky',
            'role_in_household' => 'child',
            'relationship_label' => 'Child',
            'pass_category' => PassCategory::Renter->value,
            'type' => Renter::TYPE,
        ]);

        $this->assertSame(HouseholdMember::TYPE, $member->fresh()->type);
        $this->assertNull(Renter::find($member->id));
    }

    public function test_everyone_hydrates_each_row_as_its_own_model(): void
    {
        $this->renter();
        $this->member($this->household('Unit Lot 42'));
        $this->staff('Lot 42, Royal Palm Drive');

        $classes = Occupant::everyone()->orderBy('id')->get()->map(fn ($o) => $o::class)->all();

        $this->assertSame([Renter::class, HouseholdMember::class, Staff::class], $classes);
    }

    public function test_occupants_cannot_be_created_without_a_type(): void
    {
        $this->expectException(LogicException::class);

        $this->property->occupants()->forceCreate(['name' => 'Nobody', 'status' => 'Active']);
    }

    public function test_members_and_staff_are_linked_to_the_property_their_label_names(): void
    {
        $this->assertSame($this->property->id, $this->member($this->household('Unit Lot 42'))->property_id);
        $this->assertSame($this->property->id, $this->staff('Lot 42, Royal Palm Drive')->property_id);
    }

    public function test_community_staff_and_unknown_lots_stay_unlinked(): void
    {
        $this->assertNull($this->staff('Community Grounds')->property_id);
        $this->assertNull($this->member($this->household('Unit 99'))->property_id);
    }

    public function test_renter_routes_do_not_resolve_other_occupant_types(): void
    {
        $staff = $this->staff('Lot 42, Royal Palm Drive');
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->delete("/dashboard/renters/{$staff->id}")
            ->assertNotFound();

        $this->assertNotNull(Staff::find($staff->id));
    }

    public function test_everyone_at_a_property_covers_all_five_kinds(): void
    {
        $company = OwnershipEntity::create([
            'legal_name' => 'Lot 42 Holdings Ltd',
            'entity_type' => OwnershipEntityType::Company,
            'registration_number' => 'REG-42',
            'status' => 'active',
        ]);
        $this->property->update(['ownership_entity_id' => $company->id]);

        $this->renter();
        $this->renter(['name' => 'Past Tenant', 'lease_end' => now()->subMonth()->toDateString()]);
        $this->member($this->household('Unit Lot 42'));
        $this->staff('Lot 42, Royal Palm Drive');
        $this->staff('Community Grounds');
        DelegatedAccess::create([
            'property_id' => $this->property->id,
            'grantor_user_id' => $this->owner->id,
            'name' => 'Aunt May',
            'email' => 'may@example.com',
            'phone' => '+18765550100',
            'relationship' => 'Relative',
            'access_level' => 'Family Member',
            'status' => 'active',
        ]);
        AuthorizedPerson::create([
            'ownership_entity_id' => $company->id,
            'full_name' => 'Mary Brown',
            'role' => AuthorizedPersonRole::PropertyManager,
            'status' => 'active',
        ]);

        $people = app(PropertyPeopleService::class)->everyoneAt($this->property->fresh());
        $byName = $people->keyBy(fn ($p) => $p->personName());

        $this->assertEqualsCanonicalizing(
            ['renter', 'renter', 'household_member', 'staff', 'delegate', 'authorized_person'],
            $people->map->personType()->all(),
        );
        $this->assertArrayNotHasKey('Andre Bailey', $byName->all(), 'community staff belong to no property');

        $this->assertTrue($byName['Sarah Tenant']->hasCurrentAccess());
        $this->assertFalse($byName['Past Tenant']->hasCurrentAccess());
        $this->assertSame('Gardener', $byName['Devon Clarke']->personRole());
        $this->assertSame('Relative', $byName['Aunt May']->personRole());

        $summary = $byName['Mary Brown']->toPersonSummary();
        $this->assertSame('authorized_person', $summary['type']);
        $this->assertSame(AuthorizedPersonRole::PropertyManager->label(), $summary['role']);
        $this->assertTrue($summary['active']);
    }

    public function test_property_staff_layer_lists_registered_staff(): void
    {
        $this->staff('Lot 42, Royal Palm Drive');

        $layer = $this->property->staffLayer();

        $this->assertSame(['Devon Clarke'], $layer->pluck('name')->all());
        $this->assertSame('Gardener', $layer->first()['role']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function renter(array $overrides = []): Renter
    {
        return Renter::create([
            'homeowner_id' => $this->owner->id,
            'property_id' => $this->property->id,
            'name' => 'Sarah Tenant',
            'lease_start' => now()->subMonths(2)->toDateString(),
            'lease_end' => now()->addYear()->toDateString(),
            'lot' => '42',
            ...$overrides,
        ]);
    }

    private function household(string $propertyNumber): Household
    {
        return Household::create([
            'primary_homeowner_id' => $this->owner->id,
            'property_number' => $propertyNumber,
            'name' => 'The Smiths',
        ]);
    }

    private function member(Household $household): HouseholdMember
    {
        return HouseholdMember::create([
            'household_id' => $household->id,
            'name' => 'John Smith Jr',
            'role_in_household' => 'child',
            'relationship_label' => 'Child',
            'pass_category' => PassCategory::Renter->value,
        ]);
    }

    private function staff(string $label): Staff
    {
        return Staff::create([
            'name' => $label === 'Community Grounds' ? 'Andre Bailey' : 'Devon Clarke',
            'job' => $label === 'Community Grounds' ? 'Grounds Maintenance' : 'Gardener',
            'id_type' => 'Passport',
            'id_number' => 'A4471902',
            'id_expiry' => now()->addYear()->toDateString(),
            'property_label' => $label,
            'added_by' => $this->owner->id,
        ]);
    }
}
