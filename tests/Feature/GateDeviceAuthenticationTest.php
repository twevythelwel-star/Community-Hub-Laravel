<?php

namespace Tests\Feature;

use App\Models\GateDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GateDeviceAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('gate.device')->get('/api/test-gate-device-sync', function () {
            return response()->json([
                'status' => 'authenticated',
                'device' => request()->attributes->get('gate_device')?->device_identifier,
            ]);
        });
    }

    public function test_rejects_requests_without_device_key(): void
    {
        $response = $this->getJson('/api/test-gate-device-sync');
        $response->assertStatus(401)
            ->assertJson(['error' => 'Device authentication required. Supply X-Gate-Device-Key header.']);
    }

    public function test_rejects_invalid_device_key(): void
    {
        $response = $this->withHeader('X-Gate-Device-Key', 'invalid-secret-key')
            ->getJson('/api/test-gate-device-sync');

        $response->assertStatus(401)
            ->assertJson(['error' => 'Gate device identity invalid, revoked, or suspended.']);
    }

    public function test_accepts_valid_active_gate_device_and_records_heartbeat(): void
    {
        $plainKey = 'dev_sec_main_gate_lane_01_alpha';
        $device = GateDevice::create([
            'device_identifier' => 'DEV-MAIN-LANE-01',
            'name' => 'Main Gate Scanner A',
            'gate_id' => 'GATE-01',
            'device_key_hash' => GateDevice::hashKey($plainKey),
            'status' => 'active',
            'firmware_version' => 'v2.4.1',
        ]);

        $response = $this->withHeader('X-Gate-Device-Key', $plainKey)
            ->getJson('/api/test-gate-device-sync');

        $response->assertOk()
            ->assertJson([
                'status' => 'authenticated',
                'device' => 'DEV-MAIN-LANE-01',
            ]);

        $device->refresh();
        $this->assertNotNull($device->last_heartbeat_at);
    }

    public function test_rejects_revoked_gate_device(): void
    {
        $plainKey = 'dev_sec_revoked_scanner_key';
        GateDevice::create([
            'device_identifier' => 'DEV-REVOKED-01',
            'name' => 'Compromised Guard Tablet',
            'gate_id' => 'GATE-02',
            'device_key_hash' => GateDevice::hashKey($plainKey),
            'status' => 'revoked',
        ]);

        $response = $this->withHeader('X-Gate-Device-Key', $plainKey)
            ->getJson('/api/test-gate-device-sync');

        $response->assertStatus(401)
            ->assertJson(['error' => 'Gate device identity invalid, revoked, or suspended.']);
    }
}
