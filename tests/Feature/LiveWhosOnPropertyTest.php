<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\OwnershipEntityType;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\GatePass;
use App\Models\OwnershipEntity;
use App\Models\Property;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use App\Services\Occupancy\OccupancyHierarchyBuilder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LiveWhosOnPropertyTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_dashboard_displays_live_who_is_on_property_with_six_categories_and_total(): void
    {
        Carbon::setTestNow('2026-10-06 14:00:00');

        $guard = User::factory()->create([
            'role' => UserRole::Security,
            'name' => 'Officer Davis',
        ]);

        $homeowner14 = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Charles Montgomery',
            'lot' => '14',
            'street' => 'Royal Palm Way',
        ]);

        $homeowner42 = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Sarah Connor',
            'lot' => '42',
            'street' => 'Hibiscus Boulevard',
        ]);

        // 1. Residents (2 checked in)
        GatePass::create([
            'pass_id' => 'GP-RES-0014',
            'user_id' => $homeowner14->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => 'Charles Montgomery',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(3),
        ]);

        GatePass::create([
            'pass_id' => 'GP-RES-0042',
            'user_id' => $homeowner42->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => 'Sarah Connor',
            'property' => 'Lot 42',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(5),
        ]);

        // 2. Long-Term Guest (1 checked in at Unit 14)
        GatePass::create([
            'pass_id' => 'GP-LTG-0014',
            'category' => PassCategory::LongTermOccupant,
            'holder_name' => 'Michael Brown',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subDays(2),
            'metadata' => [
                'authorization_type' => 'long_term_occupant',
                'relationship' => 'Family/Friend',
                'host_name' => 'Charles Montgomery',
            ],
        ]);

        // 3. Visitors (2 checked in: 1 at Unit 14, 1 at Lot 42)
        $visitor14 = Visitor::create([
            'homeowner_id' => $homeowner14->id,
            'homeowner_name' => 'Charles Montgomery',
            'name' => 'David Miller',
            'contact' => '+18765551122',
            'vehicle' => '1234-AB',
            'type' => 'One-time',
            'expected_at' => now()->subHour(),
            'status' => VisitorStatus::CheckedIn,
            'checked_in_at' => now()->subHour(),
        ]);
        GatePass::create([
            'pass_id' => 'GP-VIS-0014',
            'visitor_id' => $visitor14->id,
            'category' => PassCategory::Visitor,
            'holder_name' => 'David Miller',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHour(),
        ]);

        $visitor42 = Visitor::create([
            'homeowner_id' => $homeowner42->id,
            'homeowner_name' => 'Sarah Connor',
            'name' => 'Emily Watson',
            'type' => 'One-time',
            'expected_at' => now()->subMinutes(30),
            'status' => VisitorStatus::CheckedIn,
            'checked_in_at' => now()->subMinutes(30),
        ]);
        GatePass::create([
            'pass_id' => 'GP-VIS-0042',
            'visitor_id' => $visitor42->id,
            'category' => PassCategory::Visitor,
            'holder_name' => 'Emily Watson',
            'property' => 'Lot 42',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subMinutes(30),
        ]);

        // 4. Staff (1 checked in)
        GatePass::create([
            'pass_id' => 'GP-STF-0001',
            'category' => PassCategory::Staff,
            'holder_name' => 'Marcus Vance (Facilities)',
            'property' => 'Estate Operations',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(6),
        ]);

        // 5. Contractor (1 checked in at Unit 14)
        GatePass::create([
            'pass_id' => 'GP-CON-0014',
            'category' => PassCategory::Contractor,
            'holder_name' => 'Apex AC Maintenance',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(2),
            'metadata' => [
                'vehicle' => '9988-TR',
                'host_name' => 'Charles Montgomery',
            ],
        ]);

        // 6. Legacy Contact (1 checked in at Unit 14)
        GatePass::create([
            'pass_id' => 'GP-LEG-0014',
            'category' => PassCategory::Delegate,
            'holder_name' => 'Eleanor Vance (Attorney/Executor)',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subMinutes(45),
            'metadata' => [
                'access_level' => 'Legacy Delegate',
                'relationship' => 'Attorney',
                'host_name' => 'Charles Montgomery',
            ],
        ]);

        // Security requests the Live Who's On Property dashboard
        $response = $this->actingAs($guard)->get('/dashboard/occupancy');
        $response->assertOk();

        $page = $response->original->getData()['page'];
        $summary = $page['props']['summary'];

        // Assert 6 exact categories matching prompt specification
        $this->assertEquals(2, $summary['residents']);
        $this->assertEquals(1, $summary['long_term_guests']);
        $this->assertEquals(2, $summary['visitors']);
        $this->assertEquals(1, $summary['staff']);
        $this->assertEquals(1, $summary['contractors']);
        $this->assertEquals(1, $summary['legacy_contacts']);
        // Total = 2 + 1 + 2 + 1 + 1 + 1 = 8
        $this->assertEquals(8, $summary['total']);

        // Assert Enclosure System containment zones
        $enclosureZones = $page['props']['enclosureZones'];
        $this->assertCount(5, $enclosureZones);
        $this->assertEquals('outer_perimeter', $enclosureZones[0]['id']);
        $this->assertEquals(8, $enclosureZones[0]['occupant_count']);
    }

    public function test_unit_drill_down_retrieves_who_is_inside_unit_14(): void
    {
        Carbon::setTestNow('2026-10-06 15:30:00');

        $guard = User::factory()->create(['role' => UserRole::Security]);

        $homeowner14 = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Charles Montgomery',
            'lot' => '14',
        ]);

        $homeowner99 = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Other Resident',
            'lot' => '99',
        ]);

        // Unit 14 occupants:
        // 1. Resident
        GatePass::create([
            'pass_id' => 'GP-RES-0014',
            'user_id' => $homeowner14->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => 'Charles Montgomery',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(4),
        ]);

        // 2. Long-term guest
        GatePass::create([
            'pass_id' => 'GP-LTG-0014',
            'category' => PassCategory::LongTermOccupant,
            'holder_name' => 'Michael Brown',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(2),
            'metadata' => [
                'authorization_type' => 'long_term_occupant',
                'host_name' => 'Charles Montgomery',
            ],
        ]);

        // 3. Visitor
        GatePass::create([
            'pass_id' => 'GP-VIS-0014',
            'category' => PassCategory::Visitor,
            'holder_name' => 'Sophia Martinez',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subMinutes(50),
            'metadata' => [
                'host_name' => 'Charles Montgomery',
            ],
        ]);

        // 4. Contractor
        GatePass::create([
            'pass_id' => 'GP-CON-0014',
            'category' => PassCategory::Contractor,
            'holder_name' => 'Fast Electricians',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subMinutes(20),
            'metadata' => [
                'host_name' => 'Charles Montgomery',
            ],
        ]);

        // Occupant in Unit 99 (must NOT appear in Unit 14 drill-down)
        GatePass::create([
            'pass_id' => 'GP-VIS-0099',
            'category' => PassCategory::Visitor,
            'holder_name' => 'Unrelated Guest',
            'property' => 'Unit 99',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHour(),
        ]);

        // 1. API endpoint: "Who's inside Unit 14?"
        $apiResponse = $this->actingAs($guard)->getJson('/dashboard/occupancy/unit/Unit 14');
        $apiResponse->assertOk();
        $apiData = $apiResponse->json();

        $this->assertEquals('Unit 14', $apiData['unit']);
        $this->assertEquals(4, $apiData['total']);
        $occupantNames = collect($apiData['occupants'])->pluck('name')->all();
        $this->assertContains('Charles Montgomery', $occupantNames);
        $this->assertContains('Michael Brown', $occupantNames);
        $this->assertContains('Sophia Martinez', $occupantNames);
        $this->assertContains('Fast Electricians', $occupantNames);
        $this->assertNotContains('Unrelated Guest', $occupantNames);

        // 2. Inertia Page Filter: ?unit=Unit 14
        $pageResponse = $this->actingAs($guard)->get('/dashboard/occupancy?unit=Unit 14');
        $pageResponse->assertOk();
        $props = $pageResponse->original->getData()['page']['props'];

        $this->assertEquals('Unit 14', $props['selectedUnit']);
        $this->assertEquals(4, $props['occupants']['total']);
    }

    public function test_gate_scan_check_in_immediately_updates_community_and_unit_occupancy(): void
    {
        Carbon::setTestNow('2026-10-06 16:00:00');

        $guard = User::factory()->create(['role' => UserRole::Security]);
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Rachel Sterling',
            'lot' => '14',
        ]);

        $visitor = Visitor::create([
            'homeowner_id' => $homeowner->id,
            'homeowner_name' => $homeowner->name,
            'name' => 'Oliver Queen',
            'type' => 'One-time',
            'expected_at' => now(),
            'status' => VisitorStatus::Expected,
        ]);

        $engine = app(GatePassEngine::class);
        $pass = $engine->issueGuestPass($visitor, $homeowner);
        $scanner = app(GateScanner::class);
        $occupancyService = app(OccupancyHierarchyBuilder::class);

        // Before scan: 0 inside Unit 14
        $beforeDrilldown = $occupancyService->getUnitDrilldown('Unit 14');
        $this->assertEquals(0, $beforeDrilldown['total']);

        // Security scans QR at gate
        $tokenData = $engine->issueToken($pass->fresh());
        $scanResult = $scanner->scan($tokenData['token'], GateId::Gate01, $guard);
        $this->assertEquals('CHECK_IN', $scanResult['decision']);

        // Guard confirms check-in
        $confirmResult = $scanner->confirm($scanResult['scanId'], $guard);
        $this->assertEquals('CHECK_IN', $confirmResult['action']);

        // After scan check-in: 1 visitor inside Unit 14
        $afterDrilldown = $occupancyService->getUnitDrilldown('Unit 14');
        $this->assertEquals(1, $afterDrilldown['total']);
        $this->assertEquals(1, $afterDrilldown['summary']['visitors']);
        $this->assertEquals('Oliver Queen', $afterDrilldown['occupants'][0]['name']);

        // Now guest departs 2 hours later and guard scans check-out
        Carbon::setTestNow(now()->addHours(2));
        $exitToken = $engine->issueToken($pass->fresh());
        $exitScan = $scanner->scan($exitToken['token'], GateId::Gate01, $guard);
        $this->assertEquals('CHECK_OUT', $exitScan['decision']);

        $scanner->confirm($exitScan['scanId'], $guard);

        // After check-out: 0 inside Unit 14
        $exitDrilldown = $occupancyService->getUnitDrilldown('Unit 14');
        $this->assertEquals(0, $exitDrilldown['total']);
    }

    public function test_emergency_evacuation_roster_exports_csv_with_occupants(): void
    {
        $guard = User::factory()->create(['role' => UserRole::Security]);

        GatePass::create([
            'pass_id' => 'GP-RES-0014',
            'category' => PassCategory::Homeowner,
            'holder_name' => 'Bruce Wayne',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(1),
        ]);

        $response = $this->actingAs($guard)->get('/dashboard/occupancy/export');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $content = $response->getContent();
        $this->assertStringContainsString('Unit,Name,Category,Role,CheckIn_Time,Gate,Host_Resident,Vehicle,Contact,Pass_ID', $content);
        $this->assertStringContainsString('Unit 14', $content);
        $this->assertStringContainsString('Bruce Wayne', $content);
        $this->assertStringContainsString('Residents', $content);
    }

    public function test_ownership_does_not_equal_presence_and_entity_is_never_counted_as_human_occupant(): void
    {
        // Property owned by ABC Holdings Ltd
        $company = OwnershipEntity::create([
            'legal_name' => 'ABC Holdings Limited',
            'entity_type' => OwnershipEntityType::Company,
            'status' => 'active',
        ]);

        $property = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => '14',
            'ownership_entity_id' => $company->id,
            'status' => 'occupied',
        ]);

        // Mary Brown (Authorized Representative) enters
        GatePass::create([
            'pass_id' => 'GP-REP-001',
            'category' => PassCategory::Delegate,
            'holder_name' => 'Mary Brown',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subMinutes(45),
            'metadata' => [
                'authorization_type' => 'authorized_representative',
                'relationship' => 'Property Manager',
                'access_level' => 'Property Delegate',
            ],
        ]);

        // Sarah Smith (Long-Term Renter) enters
        GatePass::create([
            'pass_id' => 'GP-REN-002',
            'category' => PassCategory::Renter,
            'holder_name' => 'Sarah Smith',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(2),
        ]);

        $occupancyService = app(OccupancyHierarchyBuilder::class);
        $drilldown = $occupancyService->getUnitDrilldown('Unit 14');

        // Total physical humans present inside Unit 14 = 2 (Mary Brown, Sarah Smith)
        $this->assertEquals(2, $drilldown['totalCurrentlyInside']);

        // ABC Holdings Limited is the Legal Owner, but NEVER a physical occupant
        $occupantNames = collect($drilldown['occupants'])->pluck('name')->all();
        $this->assertContains('Mary Brown', $occupantNames);
        $this->assertContains('Sarah Smith', $occupantNames);
        $this->assertNotContains('ABC Holdings Limited', $occupantNames);

        // Ownership info confirms ABC Holdings Limited as owner
        $this->assertSame('ABC Holdings Limited', $drilldown['ownership']['owner']);
        $this->assertSame('Company', $drilldown['ownership']['ownershipType']);
    }
}
