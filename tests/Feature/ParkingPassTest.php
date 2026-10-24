<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ParkingPass;
use App\Models\User;
use App\Services\ParkingPassService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParkingPassTest extends TestCase
{
    use RefreshDatabase;

    private ParkingPassService $service;

    private User $homeowner;

    private User $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00'));

        $this->service = app(ParkingPassService::class);
        $this->homeowner = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'John Smith',
            'email' => 'john.smith@community.test',
        ]);
        $this->guard = User::factory()->role(UserRole::Security)->create([
            'name' => 'Officer Rodriguez',
        ]);
    }

    public function test_can_issue_all_six_parking_credential_categories(): void
    {
        $categories = ['resident', 'visitor', 'contractor', 'temporary', 'accessible', 'loading_zone'];

        foreach ($categories as $cat) {
            $pass = $this->service->issuePass([
                'category' => $cat,
                'license_plate' => 'XXX-1234',
                'holder_name' => 'Test Holder',
                'property' => 'Lot 42',
                'assigned_bay' => 'Bay 1',
            ], $this->homeowner);

            $this->assertInstanceOf(ParkingPass::class, $pass);
            $this->assertEquals($cat, $pass->category);
            $this->assertEquals('active', $pass->status);
            $this->assertStringStartsWith('CHUB-PARK|', $pass->qr_payload);
        }
    }

    public function test_loading_zone_enforces_strict_thirty_minute_duration(): void
    {
        $pass = $this->service->issuePass([
            'category' => 'loading_zone',
            'license_plate' => 'DELIV-99',
            'holder_name' => 'Courier Express',
            'assigned_bay' => 'Loading Bay North',
        ], $this->homeowner);

        $this->assertEquals(30, $pass->max_duration_minutes);
        $this->assertNotNull($pass->valid_until);
        $this->assertEquals(
            CarbonImmutable::parse('2026-10-07 10:30:00')->toDateTimeString(),
            $pass->valid_until->toDateTimeString()
        );
    }

    public function test_can_verify_parking_credential_by_token(): void
    {
        $pass = $this->service->issuePass([
            'category' => 'resident',
            'license_plate' => 'XXX-1234',
            'holder_name' => 'John Smith',
            'assigned_bay' => 'Bay R-14',
        ], $this->homeowner);

        $result = $this->service->verifyParkingPass($pass->qr_payload);

        $this->assertTrue($result['valid']);
        $this->assertEquals($pass->pass_id, $result['pass']->pass_id);
        $this->assertEquals('Resident Parking', $result['category']['name']);
    }

    public function test_expired_parking_pass_is_rejected(): void
    {
        $pass = $this->service->issuePass([
            'category' => 'temporary',
            'license_plate' => 'TEMP-101',
            'holder_name' => 'Guest Short Stay',
            'valid_until' => CarbonImmutable::parse('2026-10-07 11:00:00'),
        ], $this->homeowner);

        // Fast forward past expiration
        $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00:00'));

        $result = $this->service->verifyParkingPass($pass->qr_payload);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('expired', strtolower($result['message']));
    }

    public function test_seed_example_parking_passes_creates_all_six_types(): void
    {
        $created = $this->service->seedExampleParkingPasses($this->homeowner);

        $this->assertCount(6, $created);
        $this->assertEquals(6, ParkingPass::where('user_id', $this->homeowner->id)->count());

        $categories = ParkingPass::where('user_id', $this->homeowner->id)->pluck('category')->all();
        $this->assertContains('resident', $categories);
        $this->assertContains('visitor', $categories);
        $this->assertContains('contractor', $categories);
        $this->assertContains('temporary', $categories);
        $this->assertContains('accessible', $categories);
        $this->assertContains('loading_zone', $categories);
    }

    public function test_parking_web_routes_flow(): void
    {
        // 1. Visit index page
        $res = $this->actingAs($this->homeowner)->get('/dashboard/parking');
        $res->assertOk();

        // 2. Accessible passes and bays are the estate office's to issue
        $accessible = [
            'category' => 'accessible',
            'license_plate' => 'ACC-777',
            'holder_name' => 'Senior Resident',
            'assigned_bay' => 'ACC-01',
        ];
        $this->actingAs($this->homeowner)->post('/dashboard/parking', $accessible)->assertForbidden();

        $storeRes = $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->post('/dashboard/parking', $accessible);
        $storeRes->assertRedirect();

        $this->assertDatabaseHas('parking_passes', [
            'license_plate' => 'ACC-777',
            'category' => 'accessible',
        ]);

        // 3. Verify pass via API
        $pass = ParkingPass::where('license_plate', 'ACC-777')->first();
        $verifyRes = $this->actingAs($this->guard)->postJson('/dashboard/parking/verify', [
            'token' => $pass->qr_payload,
        ]);
        $verifyRes->assertOk();
        $verifyRes->assertJsonFragment(['valid' => true]);
    }
}
