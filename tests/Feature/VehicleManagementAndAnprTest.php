<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\HouseholdManagementService;
use App\Services\VehicleManagementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VehicleManagementAndAnprTest extends TestCase
{
    use RefreshDatabase;

    private VehicleManagementService $service;

    private User $homeowner;

    private User $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00'));

        $this->service = app(VehicleManagementService::class);
        $this->homeowner = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'John Smith',
            'email' => 'john.smith@community.test',
        ]);
        $this->guard = User::factory()->role(UserRole::Security)->create([
            'name' => 'Officer Rodriguez',
        ]);
    }

    public function test_can_register_vehicle_with_all_attributes(): void
    {
        $vehicle = $this->service->registerVehicle([
            'license_plate' => 'XXX-1234',
            'jurisdiction' => 'Jamaica',
            'make' => 'Toyota',
            'model' => 'Land Cruiser',
            'color' => 'White',
            'year' => 2024,
            'parking_location' => 'Bay R-14 (Garage)',
            'is_ev' => false,
            'is_temporary' => false,
            'anpr_enabled' => true,
        ], $this->homeowner);

        $this->assertInstanceOf(Vehicle::class, $vehicle);
        $this->assertEquals('XXX-1234', $vehicle->license_plate);
        $this->assertEquals('Toyota', $vehicle->make);
        $this->assertEquals('Land Cruiser', $vehicle->model);
        $this->assertEquals('White', $vehicle->color);
        $this->assertEquals('Bay R-14 (Garage)', $vehicle->parking_location);
        $this->assertTrue($vehicle->anpr_enabled);
        $this->assertFalse($vehicle->is_ev);
    }

    public function test_seeds_smith_family_vehicles_connected_to_members(): void
    {
        app(HouseholdManagementService::class)->seedExampleSmithHousehold($this->homeowner);
        $this->actingAs($this->homeowner);

        $household = Household::where('primary_homeowner_id', $this->homeowner->id)->first();
        $this->assertNotNull($household);

        $mary = HouseholdMember::where('household_id', $household->id)->where('name', 'Mary Smith')->first();
        $alex = HouseholdMember::where('household_id', $household->id)->where('name', 'Alex Smith')->first();
        $this->assertNotNull($mary);
        $this->assertNotNull($alex);

        $vehicles = $this->service->seedSmithHouseholdVehicles($this->homeowner);

        $this->assertCount(3, $vehicles);

        // 1. John Smith's Land Cruiser
        $johnCar = Vehicle::where('license_plate', 'XXX-1234')->first();
        $this->assertNotNull($johnCar);
        $this->assertEquals('Toyota', $johnCar->make);
        $johnMember = HouseholdMember::where('household_id', $household->id)->where('name', 'John Smith')->first();
        $this->assertNotNull($johnMember);
        $this->assertEquals($this->homeowner->id, $johnCar->user_id);
        $this->assertEquals($johnMember->id, $johnCar->household_member_id);

        // 2. Mary Smith's Lexus EV
        $maryCar = Vehicle::where('license_plate', '9821-JA')->first();
        $this->assertNotNull($maryCar);
        $this->assertEquals('Lexus', $maryCar->make);
        $this->assertTrue($maryCar->is_ev);
        $this->assertEquals($mary->id, $maryCar->household_member_id);

        // 3. Alex Smith's Civic
        $alexCar = Vehicle::where('license_plate', '4412-JA')->first();
        $this->assertNotNull($alexCar);
        $this->assertEquals('Honda', $alexCar->make);
        $this->assertEquals($alex->id, $alexCar->household_member_id);
    }

    public function test_anpr_optical_plate_lookup_returns_driver_and_vehicle(): void
    {
        $this->service->registerVehicle([
            'license_plate' => 'XXX-1234',
            'make' => 'Toyota',
            'model' => 'Land Cruiser',
            'color' => 'Pearl White',
            'parking_location' => 'Bay R-14',
            'anpr_enabled' => true,
        ], $this->homeowner);

        // 1. Exact lookup
        $result = $this->service->lookupPlate('XXX-1234');
        $this->assertTrue($result['found']);
        $this->assertTrue($result['authorized']);
        $this->assertEquals('John Smith', $result['owner']['name']);
        $this->assertEquals('Toyota', $result['vehicle']['make']);
        $this->assertEquals('Land Cruiser', $result['vehicle']['model']);

        // 2. Lookup with differing casing/spacing (optical camera variance)
        $normalizedResult = $this->service->lookupPlate('xxx 1234');
        $this->assertTrue($normalizedResult['found']);
        $this->assertTrue($normalizedResult['authorized']);

        // 3. Unregistered plate
        $unregisteredResult = $this->service->lookupPlate('UNKNOWN-999');
        $this->assertFalse($unregisteredResult['found']);
        $this->assertFalse($unregisteredResult['authorized']);
    }

    public function test_vehicle_management_web_endpoints(): void
    {
        $res = $this->actingAs($this->homeowner)->get('/dashboard/vehicles');
        $res->assertOk();

        // Register via controller
        $storeRes = $this->actingAs($this->homeowner)->post('/dashboard/vehicles', [
            'license_plate' => 'JAM-5555',
            'make' => 'BMW',
            'model' => 'X5',
            'color' => 'Black',
            'parking_location' => 'Bay 99',
            'anpr_enabled' => true,
        ]);
        $storeRes->assertRedirect();

        $this->assertDatabaseHas('vehicles', ['license_plate' => 'JAM-5555']);

        // ANPR endpoint
        $anprRes = $this->actingAs($this->guard)->getJson('/dashboard/vehicles/anpr/lookup?plate=JAM-5555');
        $anprRes->assertOk();
        $anprRes->assertJsonFragment(['authorized' => true]);
    }
}
