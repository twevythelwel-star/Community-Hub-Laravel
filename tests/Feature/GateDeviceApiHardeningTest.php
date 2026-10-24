<?php

namespace Tests\Feature;

use App\Models\GateDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The device API takes its key from headers only, holds device offline sync
 * to the dashboard sync's limits, and keeps heartbeat telemetry small.
 */
class GateDeviceApiHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'gd_hardening-test-key';

    protected function setUp(): void
    {
        parent::setUp();

        GateDevice::create([
            'device_identifier' => 'GATE-01-SCANNER',
            'name' => 'Main Gate Scanner',
            'gate_id' => 'GATE-01',
            'device_key_hash' => GateDevice::hashKey(self::KEY),
            'status' => 'active',
        ]);
    }

    private function scan(array $overrides = []): array
    {
        return ['offline_id' => 'OFF-'.uniqid(), 'token_or_pin' => '123456', ...$overrides];
    }

    // ── Where the key may come from ──

    public function test_the_key_is_accepted_in_the_device_header_or_as_a_bearer_token(): void
    {
        $this->withHeader('X-Gate-Device-Key', self::KEY)->postJson('/api/gate-devices/heartbeat')->assertOk();
        $this->withToken(self::KEY)->postJson('/api/gate-devices/heartbeat')->assertOk();
    }

    public function test_a_key_in_the_query_string_or_body_is_refused(): void
    {
        $this->postJson('/api/gate-devices/heartbeat?device_key='.self::KEY)->assertUnauthorized();
        $this->postJson('/api/gate-devices/heartbeat', ['device_key' => self::KEY])->assertUnauthorized();
    }

    // ── Offline sync limits ──

    public function test_sync_refuses_another_gate(): void
    {
        $this->withHeader('X-Gate-Device-Key', self::KEY)
            ->postJson('/api/gate-devices/sync-scans', ['gate' => 'GATE-02', 'scans' => [$this->scan()]])
            ->assertJsonValidationErrors('gate');
    }

    public function test_sync_accepts_the_devices_own_gate(): void
    {
        $this->withHeader('X-Gate-Device-Key', self::KEY)
            ->postJson('/api/gate-devices/sync-scans', ['gate' => 'GATE-01', 'scans' => [$this->scan()]])
            ->assertOk()
            ->assertJsonPath('gate', 'GATE-01');
    }

    public function test_sync_refuses_a_scan_from_the_future(): void
    {
        $this->withHeader('X-Gate-Device-Key', self::KEY)
            ->postJson('/api/gate-devices/sync-scans', ['scans' => [$this->scan(['scanned_at' => now()->addDay()->toIso8601String()])]])
            ->assertJsonValidationErrors('scans.0.scanned_at');
    }

    public function test_sync_refuses_an_unknown_action(): void
    {
        $this->withHeader('X-Gate-Device-Key', self::KEY)
            ->postJson('/api/gate-devices/sync-scans', ['scans' => [$this->scan(['action' => 'delete_everything'])]])
            ->assertJsonValidationErrors('scans.0.action');
    }

    public function test_sync_batches_are_capped(): void
    {
        $scans = array_map(fn (int $i) => $this->scan(['offline_id' => "OFF-{$i}"]), range(1, 501));

        $this->withHeader('X-Gate-Device-Key', self::KEY)
            ->postJson('/api/gate-devices/sync-scans', ['scans' => $scans])
            ->assertJsonValidationErrors('scans');
    }

    // ── Heartbeat telemetry ──

    public function test_flat_telemetry_is_stored(): void
    {
        $this->withHeader('X-Gate-Device-Key', self::KEY)
            ->postJson('/api/gate-devices/heartbeat', ['telemetry' => ['battery' => 98, 'temperature_c' => 24.5, 'door_closed' => true, 'mode' => 'normal']])
            ->assertOk();

        $this->assertSame(98, GateDevice::firstOrFail()->metadata['battery']);
    }

    public function test_nested_or_oversized_telemetry_is_refused(): void
    {
        $this->withHeader('X-Gate-Device-Key', self::KEY)
            ->postJson('/api/gate-devices/heartbeat', ['telemetry' => ['dump' => ['nested' => 'data']]])
            ->assertJsonValidationErrors('telemetry.dump');

        $this->withHeader('X-Gate-Device-Key', self::KEY)
            ->postJson('/api/gate-devices/heartbeat', ['telemetry' => ['log' => str_repeat('x', 256)]])
            ->assertJsonValidationErrors('telemetry.log');

        $this->withHeader('X-Gate-Device-Key', self::KEY)
            ->postJson('/api/gate-devices/heartbeat', ['telemetry' => array_fill_keys(array_map(fn ($i) => "k{$i}", range(1, 51)), 1)])
            ->assertJsonValidationErrors('telemetry');
    }
}
