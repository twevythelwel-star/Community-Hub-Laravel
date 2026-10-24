<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\GateDevice;
use App\Models\GateSensorEvent;
use App\Models\InAppNotification;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Occupancy sensor readings come from the gate's own hardware, authenticated
 * by its device key. Guards review and resolve them; they cannot type them in.
 */
class GateTailgatingDetectionTest extends TestCase
{
    use RefreshDatabase;

    private const DEVICE_KEY = 'gd_test-sensor-key';

    private GateDevice $device;

    private User $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00'));

        $this->device = GateDevice::create([
            'device_identifier' => 'GATE-01-SENSOR-A',
            'name' => 'Main Gate Optical Sensor',
            'gate_id' => 'GATE-01',
            'device_key_hash' => GateDevice::hashKey(self::DEVICE_KEY),
            'status' => 'active',
        ]);
        $this->guard = User::factory()->role(UserRole::Security)->create(['name' => 'Officer Rodriguez']);
    }

    private function report(array $reading, string $key = self::DEVICE_KEY): TestResponse
    {
        return $this->withHeader('X-Gate-Device-Key', $key)->postJson('/api/gate-devices/sensor-events', $reading);
    }

    public function test_a_normal_transit_is_recorded_without_an_alert(): void
    {
        $this->report(['authorized_occupants' => 1, 'detected_occupants' => 1])
            ->assertCreated()
            ->assertJson(['is_tailgating' => false, 'gate' => 'GATE-01']);

        $this->assertDatabaseHas('gate_sensor_events', ['gate' => 'GATE-01', 'is_tailgating' => false, 'resolution_status' => 'CLEAR']);
        $this->assertDatabaseCount('in_app_notifications', 0);
    }

    public function test_more_people_than_allowed_raises_an_alert_for_every_active_officer(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->report([
            'pass_id' => 'GP-TEST-001',
            'license_plate' => 'xxx-1234',
            'authorized_occupants' => 1,
            'detected_occupants' => 3,
        ])->assertCreated()->assertJson(['is_tailgating' => true, 'severity' => 'CRITICAL']);

        $event = GateSensorEvent::firstOrFail();
        $this->assertSame('UNRESOLVED', $event->resolution_status);
        $this->assertSame('XXX-1234', $event->license_plate);
        $this->assertStringContainsString('allowed 1 person(s)', $event->alert_message);
        $this->assertStringContainsString('counted 3', $event->alert_message);

        $this->assertDatabaseHas('in_app_notifications', ['user_id' => $this->guard->id, 'category' => 'security']);
        $this->assertDatabaseHas('in_app_notifications', ['user_id' => $admin->id, 'category' => 'security']);
        $this->assertDatabaseMissing('in_app_notifications', ['user_id' => $resident->id]);
    }

    public function test_only_what_the_device_reports_is_stored(): void
    {
        $this->report(['detected_occupants' => 1])->assertCreated();

        $metadata = GateSensorEvent::firstOrFail()->sensor_metadata;
        $this->assertSame('GATE-01-SENSOR-A', $metadata['device_identifier']);
        $this->assertArrayNotHasKey('confidence', $metadata);
        $this->assertArrayNotHasKey('transit_duration_ms', $metadata);
        $this->assertArrayNotHasKey('sequence_steps', $metadata);
    }

    public function test_the_gate_is_the_devices_own(): void
    {
        $this->report(['detected_occupants' => 1, 'gate' => 'GATE-02'])->assertCreated();

        $this->assertSame('GATE-01', GateSensorEvent::firstOrFail()->gate);
    }

    public function test_a_reading_needs_a_valid_active_device_key(): void
    {
        $this->postJson('/api/gate-devices/sensor-events', ['detected_occupants' => 3])->assertUnauthorized();
        $this->report(['detected_occupants' => 3], 'gd_wrong-key')->assertUnauthorized();

        $this->device->update(['status' => 'revoked']);
        $this->report(['detected_occupants' => 3])->assertUnauthorized();

        $this->assertDatabaseCount('gate_sensor_events', 0);
    }

    public function test_a_reading_from_the_future_is_refused(): void
    {
        $this->report(['detected_occupants' => 1, 'occurred_at' => now()->addDay()->toIso8601String()])
            ->assertJsonValidationErrors('occurred_at');
    }

    public function test_readings_can_no_longer_be_typed_in_from_the_dashboard(): void
    {
        $this->actingAs($this->guard)
            ->postJson('/dashboard/gate-sensor/sequence', ['detected_occupants' => 3])
            ->assertNotFound();
    }

    public function test_a_guard_resolves_an_alert_and_sees_the_feed(): void
    {
        $eventId = $this->report(['authorized_occupants' => 1, 'detected_occupants' => 2])->json('event_id');

        $this->actingAs($this->guard)->postJson('/dashboard/gate-sensor/resolve', [
            'event_id' => $eventId,
            'status' => 'CONFIRMED_VIOLATION',
            'notes' => 'Patrol escorted the second car to the gatehouse.',
        ])->assertOk();

        $event = GateSensorEvent::findOrFail($eventId);
        $this->assertSame('CONFIRMED_VIOLATION', $event->resolution_status);
        $this->assertSame($this->guard->id, $event->resolved_by_user_id);

        $this->getJson('/dashboard/gate-sensor/recent')
            ->assertOk()
            ->assertJsonPath('events.0.id', $eventId);
    }

    public function test_alerts_are_not_sent_to_deactivated_officers(): void
    {
        $former = User::factory()->role(UserRole::Security)->create(['status' => 'Deactivated', 'deactivated_at' => now()]);

        $this->report(['authorized_occupants' => 1, 'detected_occupants' => 2])->assertCreated();

        $this->assertSame(0, InAppNotification::where('user_id', $former->id)->count());
    }
}
