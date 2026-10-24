<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\GateDevice;
use App\Models\GatePass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GateDeviceHardwareSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_hardware_device_heartbeat_requires_authentication(): void
    {
        $this->postJson('/api/gate-devices/heartbeat', [])
            ->assertStatus(401)
            ->assertJsonPath('error', 'Device authentication required. Supply X-Gate-Device-Key header.');
    }

    public function test_hardware_device_heartbeat_updates_telemetry(): void
    {
        $plainKey = 'device-secret-key-999';
        $device = GateDevice::create([
            'device_identifier' => 'SCANNER-HARDWARE-01',
            'name' => 'Main Gate Turnstile Scanner',
            'gate_id' => 'GATE-01',
            'device_key_hash' => GateDevice::hashKey($plainKey),
            'status' => 'active',
            'firmware_version' => '1.0.0',
        ]);

        $response = $this->withHeader('X-Gate-Device-Key', $plainKey)
            ->postJson('/api/gate-devices/heartbeat', [
                'firmware_version' => '1.2.0',
                'telemetry' => ['battery' => 98, 'temperature_c' => 24.5],
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('device_identifier', 'SCANNER-HARDWARE-01')
            ->assertJsonPath('gate_id', 'GATE-01');

        $device->refresh();
        $this->assertSame('1.2.0', $device->firmware_version);
        $this->assertSame(98, $device->metadata['battery']);
        $this->assertNotNull($device->last_heartbeat_at);
    }

    public function test_hardware_device_syncs_offline_scans_and_updates_sync_timestamp(): void
    {
        $plainKey = 'device-secret-key-1000';
        $device = GateDevice::create([
            'device_identifier' => 'SCANNER-HARDWARE-02',
            'name' => 'North Gate Vehicular Scanner',
            'gate_id' => 'GATE-01',
            'device_key_hash' => GateDevice::hashKey($plainKey),
            'status' => 'active',
        ]);

        $resident = User::factory()->create([
            'role' => UserRole::Homeowner,
            'status' => 'Active',
        ]);

        $pass = GatePass::create([
            'pass_id' => 'GP-TEST-OFFLINE-01',
            'user_id' => $resident->id,
            'holder_name' => $resident->name,
            'category' => PassCategory::Homeowner,
            'status' => PassStatus::Active,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addDays(7),
        ]);

        $offlinePin = $pass->offline_pin;
        $offlineScanId = 'SCAN-OFFLINE-UUID-'.Str::random(8);

        $payload = [
            'gate' => 'GATE-01',
            'scans' => [
                [
                    'offline_id' => $offlineScanId,
                    'token_or_pin' => $offlinePin,
                    'method' => 'Offline Gate PIN',
                    'action' => 'check_in',
                    'scanned_at' => now()->subMinutes(10)->toIso8601String(),
                ],
            ],
        ];

        $response = $this->withHeader('X-Gate-Device-Key', $plainKey)
            ->postJson('/api/gate-devices/sync-scans', $payload)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('accepted', 1)
            ->assertJsonPath('rejected', 0);

        $device->refresh();
        $this->assertNotNull($device->last_sync_at);

        $this->assertDatabaseHas('access_log_entries', [
            'pass_id' => $pass->pass_id,
            'result' => 'PERMITTED',
        ]);
    }

    public function test_hardware_device_fetches_revocation_blacklist(): void
    {
        $plainKey = 'device-secret-key-1001';
        $device = GateDevice::create([
            'device_identifier' => 'SCANNER-HARDWARE-03',
            'name' => 'Pedestrian Turnstile',
            'gate_id' => 'GATE-01',
            'device_key_hash' => GateDevice::hashKey($plainKey),
            'status' => 'active',
        ]);

        $resident = User::factory()->create([
            'role' => UserRole::Homeowner,
            'status' => 'Active',
        ]);

        $revokedPass = GatePass::create([
            'pass_id' => 'GP-REVOKED-999',
            'user_id' => $resident->id,
            'holder_name' => $resident->name,
            'category' => PassCategory::Homeowner,
            'status' => PassStatus::Revoked,
            'valid_from' => now()->subMonth(),
            'valid_until' => now()->addDays(7),
        ]);

        $response = $this->withHeader('X-Gate-Device-Key', $plainKey)
            ->getJson('/api/gate-devices/revocations')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonFragment(['GP-REVOKED-999']);
    }
}
