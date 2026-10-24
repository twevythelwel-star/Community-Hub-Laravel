<?php

namespace Tests\Feature;

use App\Enums\AuthorizedPersonRole;
use App\Enums\OwnershipEntityType;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\AuthorizedPerson;
use App\Models\DelegatedAccess;
use App\Models\OwnershipEntity;
use App\Models\Property;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Occupancy\OccupancyHierarchyBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnershipOccupancySeparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_ownership_is_strictly_separated_from_occupancy(): void
    {
        // 1. Corporate Owner: ABC Holdings Ltd
        $company = OwnershipEntity::create([
            'legal_name' => 'ABC Holdings Ltd',
            'entity_type' => OwnershipEntityType::Company,
            'registration_number' => 'REG-ABC-8821',
            'primary_email' => 'contact@abcholdings.com',
            'primary_phone' => '+18765550100',
            'status' => 'active',
        ]);

        // 2. Occupant / Resident: John Brown
        $occupant = User::factory()->create([
            'name' => 'John Brown',
            'display_name' => 'John Brown',
            'role' => UserRole::Homeowner,
            'email' => 'john.brown@example.com',
            'phone' => '+18765550123',
            'lot' => '14',
            'street' => 'Royal Palm Way',
        ]);

        // 3. Property: Unit 14 owned by ABC Holdings Ltd, occupied by John Brown
        $property = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => '14',
            'street_address' => '14 Royal Palm Way',
            'ownership_entity_id' => $company->id,
            'owner_user_id' => null, // NOT owned by John Brown!
            'primary_occupant_id' => $occupant->id,
            'status' => 'occupied',
        ]);

        // Verify fundamental rule: Owner != Resident
        $this->assertSame('ABC Holdings Ltd', $property->ownerName());
        $this->assertSame('Company', $property->ownershipType());
        $this->assertSame('John Brown', $property->occupantName());
        $this->assertFalse($property->is_owner_occupied);
        $this->assertNotSame($property->ownerName(), $property->occupantName());

        // 4. Authorized Representatives of ABC Holdings Ltd
        $mary = User::factory()->create(['name' => 'Mary Brown', 'display_name' => 'Mary Brown']);
        AuthorizedPerson::create([
            'ownership_entity_id' => $company->id,
            'user_id' => $mary->id,
            'full_name' => 'Mary Brown',
            'role' => AuthorizedPersonRole::PropertyManager,
            'status' => 'active',
        ]);

        // 5. Long-term rental: Sarah Smith
        Renter::create([
            'name' => 'Sarah Smith',
            'email' => 'sarah@example.com',
            'phone' => '+18765550300',
            'lot_number' => '14',
            'property_id' => $property->id,
            'stay_type' => 'Long-term',
            'status' => 'Active',
            'lease_start' => now()->subMonths(2),
            'lease_end' => now()->addMonths(10),
        ]);

        // 6. Short-term rental: Guest
        Visitor::create([
            'property_id' => $property->id,
            'name' => 'Guest',
            'homeowner_id' => $occupant->id,
            'homeowner_name' => 'Unit 14',
            'type' => 'Short-term',
            'status' => VisitorStatus::CheckedIn,
            'expected_at' => now(),
        ]);

        // 7. Staff: Maria
        DelegatedAccess::create([
            'property_id' => $property->id,
            'grantor_user_id' => $occupant->id,
            'name' => 'Maria',
            'email' => 'maria@staff.example.com',
            'phone' => '+18765550400',
            'relationship' => 'Housekeeper',
            'access_level' => 'Domestic Staff',
            'status' => 'active',
        ]);

        // 8. Contractors: ABC Plumbing
        DelegatedAccess::create([
            'property_id' => $property->id,
            'grantor_user_id' => $occupant->id,
            'name' => 'ABC Plumbing',
            'email' => 'info@abcplumbing.example.com',
            'phone' => '+18765550500',
            'relationship' => 'Plumbing Contractor',
            'access_level' => 'Contractor',
            'status' => 'active',
        ]);

        // 9. Legacy Contacts: David Smith
        DelegatedAccess::create([
            'property_id' => $property->id,
            'grantor_user_id' => $occupant->id,
            'name' => 'David Smith',
            'email' => 'david@legacy.example.com',
            'phone' => '+18765550600',
            'relationship' => 'Emergency Delegate',
            'access_level' => 'Legacy Delegate',
            'status' => 'active',
        ]);

        // Test the complete 7-branch ecosystem tree
        $tree = $property->propertyEcosystemTree();

        // Branch 1: OWNERSHIP
        $this->assertSame('ABC Holdings Ltd', $tree['ownership']['owner']);
        $this->assertSame('Company', $tree['ownership']['ownership_type']);
        $this->assertTrue($tree['ownership']['is_corporate']);
        $this->assertCount(1, $tree['ownership']['representatives']);
        $this->assertSame('Mary Brown', $tree['ownership']['representatives'][0]['name']);
        $this->assertSame('Property Manager', $tree['ownership']['representatives'][0]['role']);

        // Branch 2: OCCUPANCY
        $this->assertSame('John Brown', $tree['occupancy']['occupant_name']);
        $this->assertFalse($tree['occupancy']['is_owner_occupied']);
        $this->assertSame($occupant->id, $tree['occupancy']['primary_occupant']['id']);

        // Branch 3: LONG-TERM RENTAL
        $this->assertCount(1, $tree['long_term_rental']);
        $this->assertSame('Sarah Smith', $tree['long_term_rental'][0]['name']);

        // Branch 4: SHORT-TERM RENTAL
        $this->assertCount(1, $tree['short_term_rental']);
        $this->assertSame('Guest', $tree['short_term_rental'][0]['name']);

        // Branch 5: STAFF
        $this->assertCount(1, $tree['staff']);
        $this->assertSame('Maria', $tree['staff'][0]['name']);

        // Branch 6: CONTRACTORS
        $this->assertCount(1, $tree['contractors']);
        $this->assertSame('ABC Plumbing', $tree['contractors'][0]['name']);

        // Branch 7: LEGACY CONTACTS
        $this->assertCount(1, $tree['legacy_contacts']);
        $this->assertSame('David Smith', $tree['legacy_contacts'][0]['name']);
    }

    public function test_property_occupancy_service_drilldown_and_hierarchy_expose_ecosystem_tree(): void
    {
        $company = OwnershipEntity::create([
            'legal_name' => 'ABC Holdings Ltd',
            'entity_type' => OwnershipEntityType::Company,
            'status' => 'active',
        ]);

        $resident = User::factory()->create([
            'name' => 'John Brown',
            'display_name' => 'John Brown',
            'role' => UserRole::Homeowner,
            'lot' => '14',
        ]);

        $property = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => '14',
            'ownership_entity_id' => $company->id,
            'primary_occupant_id' => $resident->id,
            'status' => 'occupied',
        ]);

        $service = app(OccupancyHierarchyBuilder::class);

        // 1. Drilldown Endpoint Test
        $drilldown = $service->getUnitDrilldown('Unit 14');

        $this->assertNotNull($drilldown['propertyEcosystem']);
        $this->assertSame('ABC Holdings Ltd', $drilldown['propertyEcosystem']['ownership']['owner']);
        $this->assertSame('John Brown', $drilldown['propertyEcosystem']['occupancy']['occupant_name']);
        $this->assertSame('ABC Holdings Ltd', $drilldown['ownership']['owner']);
        $this->assertSame('John Brown', $drilldown['ownership']['primaryOccupant']);
        $this->assertFalse($drilldown['ownership']['isOwnerOccupied']);

        // 2. Community Hierarchy Endpoint Test
        $hierarchy = $service->getCommunityHierarchy('Unit 14');
        $this->assertNotEmpty($hierarchy['properties']);

        $propNode = $hierarchy['properties'][0];
        $this->assertSame('ABC Holdings Ltd', $propNode['owner']);
        $this->assertSame('John Brown', $propNode['primaryOccupant']);
        $this->assertFalse($propNode['isOwnerOccupied']);
        $this->assertNotNull($propNode['ecosystem']);
    }

    public function test_owner_occupied_property_correctly_identifies_resident_as_owner(): void
    {
        $homeowner = User::factory()->create([
            'name' => 'Individual Homeowner',
            'display_name' => 'Individual Homeowner',
            'role' => UserRole::Homeowner,
            'lot' => '15',
        ]);

        $property = Property::create([
            'property_code' => 'UNIT-15',
            'lot_number' => '15',
            'owner_user_id' => $homeowner->id,
            'primary_occupant_id' => $homeowner->id,
            'status' => 'occupied',
        ]);

        $this->assertSame('Individual Homeowner', $property->ownerName());
        $this->assertSame('Individual Homeowner', $property->occupantName());
        $this->assertTrue($property->is_owner_occupied);
    }
}
