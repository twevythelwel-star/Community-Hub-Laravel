<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\GatePass;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Occupancy\OccupancyHierarchyBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CommunityAccessCommandCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_center_aggregates_all_seven_categories_currently_inside(): void
    {
        Carbon::setTestNow('2026-10-06 10:42:00');

        $guard = User::factory()->create([
            'role' => UserRole::Security,
            'name' => 'Officer Smith',
        ]);

        $homeowner14 = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => '14',
            'street' => 'Royal Palm Way',
        ]);

        // 1. Resident (1 inside)
        GatePass::create([
            'pass_id' => 'GP-RES-0014',
            'user_id' => $homeowner14->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => 'John Smith',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(2),
        ]);

        // 2. Long-Term Guest / Renter (1 inside)
        GatePass::create([
            'pass_id' => 'GP-LTG-0014',
            'category' => PassCategory::LongTermOccupant,
            'holder_name' => 'Michael Brown',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(3),
            'metadata' => [
                'authorization_type' => 'long_term_occupant',
                'relationship' => 'Long-Term Renter',
                'host_name' => 'John Smith',
            ],
        ]);

        // 3. Short-Term Rental Guest (Airbnb) (1 inside)
        GatePass::create([
            'pass_id' => 'GP-AIR-0014',
            'category' => PassCategory::Visitor,
            'holder_name' => 'David Vacationer',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHour(),
            'metadata' => [
                'authorization_type' => 'short_term_rental',
                'relationship' => 'Airbnb Guest',
                'host_name' => 'John Smith',
            ],
        ]);

        // 4. Visitor (1 inside)
        $vis = Visitor::create([
            'homeowner_id' => $homeowner14->id,
            'homeowner_name' => 'John Smith',
            'name' => 'Sarah Williams',
            'type' => 'One-time',
            'expected_at' => now(),
            'status' => VisitorStatus::CheckedIn,
            'checked_in_at' => now()->subMinutes(30),
        ]);
        GatePass::create([
            'pass_id' => 'GP-VIS-0014',
            'visitor_id' => $vis->id,
            'category' => PassCategory::Visitor,
            'holder_name' => 'Sarah Williams',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subMinutes(30),
        ]);

        // 5. Staff (1 inside)
        GatePass::create([
            'pass_id' => 'GP-STF-0014',
            'category' => PassCategory::HomeownerStaff,
            'holder_name' => 'Maria Johnson',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(4),
            'metadata' => [
                'access_level' => 'Caregiver / Staff',
                'host_name' => 'John Smith',
            ],
        ]);

        // 6. Contractor (1 inside)
        GatePass::create([
            'pass_id' => 'GP-CON-0014',
            'category' => PassCategory::Contractor,
            'holder_name' => 'ABC Plumbing',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(1),
            'metadata' => [
                'relationship' => 'Plumbing Contractor',
                'host_name' => 'John Smith',
            ],
        ]);

        // 7. Legacy Contact (1 inside)
        GatePass::create([
            'pass_id' => 'GP-LEG-0014',
            'category' => PassCategory::Delegate,
            'holder_name' => 'Mary Smith',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subMinutes(15),
            'designated_gate' => GateId::Gate01,
            'metadata' => [
                'access_level' => 'Legacy Delegate',
                'relationship' => 'Attorney/Emergency',
                'host_name' => 'John Smith',
            ],
        ]);

        $response = $this->actingAs($guard)->get('/dashboard/occupancy');
        $response->assertOk();

        $page = $response->original->getData()['page'];
        $summary = $page['props']['summary'];

        // Assert all 7 categories
        $this->assertEquals(1, $summary['residents']);
        $this->assertEquals(1, $summary['long_term_guests']);
        $this->assertEquals(1, $summary['short_term_guests']);
        $this->assertEquals(1, $summary['visitors']);
        $this->assertEquals(1, $summary['staff']);
        $this->assertEquals(1, $summary['contractors']);
        $this->assertEquals(1, $summary['legacy_contacts']);
        $this->assertEquals(7, $summary['total']);

        // Assert property association retained on every record
        $occupants = $page['props']['occupants']['data'];
        $this->assertCount(7, $occupants);
        foreach ($occupants as $occ) {
            $this->assertEquals('Unit 14', $occ['unit']);
            $this->assertEquals('INSIDE', $occ['checkInStatus']);
            $this->assertNotNull($occ['authorizedBy']);
            $this->assertArrayHasKey('relationshipChain', $occ);
        }
    }

    public function test_unit_14_drill_down_shows_seven_categories_with_in_out_statuses(): void
    {
        Carbon::setTestNow('2026-10-06 10:42:00');

        $guard = User::factory()->create(['role' => UserRole::Security]);
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => '14',
        ]);

        // Resident (IN)
        GatePass::create([
            'pass_id' => 'GP-RES-0014',
            'user_id' => $homeowner->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => 'John Smith',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(1),
        ]);

        // Long-term guest (IN)
        GatePass::create([
            'pass_id' => 'GP-LTG-0014',
            'category' => PassCategory::LongTermOccupant,
            'holder_name' => 'Michael Brown',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(2),
        ]);

        // Visitor 1 (IN)
        $v1 = Visitor::create([
            'homeowner_id' => $homeowner->id,
            'homeowner_name' => 'John Smith',
            'name' => 'Sarah Williams',
            'type' => 'One-time',
            'expected_at' => now(),
            'status' => VisitorStatus::CheckedIn,
            'checked_in_at' => now()->subHour(),
        ]);
        GatePass::create([
            'pass_id' => 'GP-VIS-001',
            'visitor_id' => $v1->id,
            'category' => PassCategory::Visitor,
            'holder_name' => 'Sarah Williams',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHour(),
        ]);

        // Visitor 2 (OUT)
        Visitor::create([
            'homeowner_id' => $homeowner->id,
            'homeowner_name' => 'John Smith',
            'name' => 'David Williams',
            'type' => 'One-time',
            'expected_at' => now()->subHours(3),
            'status' => VisitorStatus::CheckedOut,
            'checked_in_at' => now()->subHours(3),
        ]);

        // Staff (IN)
        GatePass::create([
            'pass_id' => 'GP-STF-001',
            'category' => PassCategory::HomeownerStaff,
            'holder_name' => 'Maria Johnson',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(4),
        ]);

        // Contractor (IN)
        GatePass::create([
            'pass_id' => 'GP-CON-001',
            'category' => PassCategory::Contractor,
            'holder_name' => 'ABC Plumbing',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(1),
        ]);

        // Legacy Contact (IN)
        GatePass::create([
            'pass_id' => 'GP-LEG-001',
            'category' => PassCategory::Delegate,
            'holder_name' => 'Mary Smith',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subMinutes(20),
            'metadata' => [
                'access_level' => 'Emergency',
                'host_name' => 'John Smith',
            ],
        ]);

        // Drill-down request
        $response = $this->actingAs($guard)->getJson('/dashboard/occupancy/unit/Unit 14');
        $response->assertOk();

        $data = $response->json();
        $this->assertEquals('Unit 14', $data['unit']);
        $this->assertEquals(6, $data['totalCurrentlyInside']); // 6 inside: John Smith, Michael Brown, Sarah Williams, Maria Johnson, ABC Plumbing, Mary Smith

        $roster = $data['rosterByCategory'];
        $this->assertNotEmpty($roster['residents']);
        $this->assertEquals('John Smith', $roster['residents'][0]['name']);
        $this->assertEquals('IN', $roster['residents'][0]['status']);

        $this->assertNotEmpty($roster['long_term_guests']);
        $this->assertEquals('Michael Brown', $roster['long_term_guests'][0]['name']);
        $this->assertEquals('IN', $roster['long_term_guests'][0]['status']);

        $this->assertEmpty($roster['short_term_guests']);

        // Visitors: Sarah Williams (IN) and David Williams (OUT)
        $visitorNames = collect($roster['visitors'])->pluck('name')->all();
        $this->assertContains('Sarah Williams', $visitorNames);
        $this->assertContains('David Williams', $visitorNames);

        $david = collect($roster['visitors'])->firstWhere('name', 'David Williams');
        $this->assertEquals('OUT', $david['status']);

        $this->assertEquals('ABC Plumbing', $roster['contractors'][0]['name']);
        $this->assertEquals('IN', $roster['contractors'][0]['status']);

        $this->assertEquals('Mary Smith', $roster['legacy_contacts'][0]['name']);
        $this->assertEquals('IN', $roster['legacy_contacts'][0]['status']);
    }

    public function test_community_hierarchy_maps_properties_spatially(): void
    {
        $guard = User::factory()->create(['role' => UserRole::Security]);
        $service = app(OccupancyHierarchyBuilder::class);

        $hierarchy = $service->getCommunityHierarchy();
        $this->assertArrayHasKey('properties', $hierarchy);
        $this->assertArrayHasKey('totalInside', $hierarchy);
        $this->assertArrayHasKey('totalExpected', $hierarchy);

        $response = $this->actingAs($guard)->getJson('/dashboard/occupancy/hierarchy');
        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_resident_cannot_access_other_unit_drilldown_or_estate_roster(): void
    {
        $resident = User::factory()->create([
            'role' => UserRole::Homeowner,
            'lot' => '14',
        ]);

        // Resident can view their own unit
        $this->actingAs($resident)->getJson('/dashboard/occupancy/unit/Unit 14')->assertOk();

        // Resident is blocked from inspecting Unit 15
        $this->actingAs($resident)->getJson('/dashboard/occupancy/unit/Unit 15')->assertForbidden();

        // Resident is blocked from exporting CSV
        $this->actingAs($resident)->get('/dashboard/occupancy/export')->assertForbidden();
    }
}
