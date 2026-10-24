<?php

namespace Tests\Feature;

use App\Models\GateDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class GateDeviceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** Runs a command and returns the device key it printed. */
    private function issuedKey(array $arguments, string $command = 'gate-devices:register'): string
    {
        $this->assertSame(0, Artisan::call($command, $arguments));
        $output = Artisan::output();
        $this->assertMatchesRegularExpression('/gd_[0-9a-f]{64}/', $output);
        preg_match('/gd_[0-9a-f]{64}/', $output, $match);

        return $match[0];
    }

    public function test_registering_a_device_issues_a_key_that_authenticates(): void
    {
        $key = $this->issuedKey(['identifier' => 'GATE-02-SENSOR', '--gate' => 'GATE-02', '--name' => 'Service Gate Sensor']);

        $device = GateDevice::where('device_identifier', 'GATE-02-SENSOR')->firstOrFail();
        $this->assertSame('GATE-02', $device->gate_id);
        $this->assertSame('Service Gate Sensor', $device->name);
        $this->assertNotSame($key, $device->device_key_hash);

        $this->withHeader('X-Gate-Device-Key', $key)->postJson('/api/gate-devices/heartbeat')->assertOk();
    }

    public function test_an_unknown_gate_is_refused(): void
    {
        $this->assertSame(1, Artisan::call('gate-devices:register', ['identifier' => 'X', '--gate' => 'GATE-99']));
        $this->assertDatabaseCount('gate_devices', 0);
    }

    public function test_rotating_replaces_the_key(): void
    {
        $old = $this->issuedKey(['identifier' => 'GATE-01-SENSOR']);
        $this->assertSame(1, Artisan::call('gate-devices:register', ['identifier' => 'GATE-01-SENSOR']));

        $new = $this->issuedKey(['identifier' => 'GATE-01-SENSOR', '--rotate' => true]);

        $this->withHeader('X-Gate-Device-Key', $old)->postJson('/api/gate-devices/heartbeat')->assertUnauthorized();
        $this->withHeader('X-Gate-Device-Key', $new)->postJson('/api/gate-devices/heartbeat')->assertOk();
    }

    public function test_a_revoked_device_is_refused(): void
    {
        $key = $this->issuedKey(['identifier' => 'GATE-01-SENSOR']);

        $this->assertSame(0, Artisan::call('gate-devices:revoke', ['identifier' => 'GATE-01-SENSOR']));

        $this->withHeader('X-Gate-Device-Key', $key)->postJson('/api/gate-devices/heartbeat')->assertUnauthorized();
    }
}
