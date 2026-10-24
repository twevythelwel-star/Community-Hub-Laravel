<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Events\Realtime\OperationsCommandCenterEvent;
use App\Events\Realtime\ResidentPanicAlertEvent;
use App\Models\InAppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ResidentEmergencyPanicDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_homeowner_can_trigger_emergency_sos_panic(): void
    {
        Event::fake([ResidentPanicAlertEvent::class, OperationsCommandCenterEvent::class]);

        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Sarah Connor',
            'phone' => '+18765550999',
            'lot' => 'Lot 42',
            'street' => 'Royal Palm Way',
        ]);

        $securityOfficer = User::factory()->create([
            'role' => UserRole::Security,
            'name' => 'Guard Officer Vance',
        ]);
        $otherResident = User::factory()->role(UserRole::Homeowner)->create();

        $response = $this->actingAs($homeowner)->postJson('/dashboard/emergency/panic', [
            'emergency_type' => 'medical',
            'latitude' => 18.0179,
            'longitude' => -76.8099,
            'notes' => 'Severe allergic reaction, epipen needed urgently.',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Your request has been received.',
        ]);

        $this->assertArrayNotHasKey('alert_id', $response->json());
        $this->assertArrayNotHasKey('location', $response->json());
        $this->assertArrayNotHasKey('emergency_type', $response->json());

        // Verify Resident Panic Event was broadcast
        Event::assertDispatched(ResidentPanicAlertEvent::class, function ($event) use ($homeowner) {
            return $event->residentId === $homeowner->id
                && $event->residentName === 'Sarah Connor'
                && $event->emergencyType === 'medical'
                && $event->latitude === 18.0179
                && $event->longitude === -76.8099
                && str_contains($event->notes, 'allergic reaction');
        });

        // Verify Operations Command Center Event was broadcast
        Event::assertDispatched(OperationsCommandCenterEvent::class, function ($event) {
            return $event->type === 'emergency'
                && $event->severity === 'critical'
                && str_contains($event->headline, 'RESIDENT SOS: MEDICAL ALERT');
        });

        // Verify InAppNotification was created for Security officer
        $notification = InAppNotification::where('user_id', $securityOfficer->id)->first();
        $this->assertNotNull($notification);
        $this->assertEquals('emergency', $notification->category);
        $this->assertEquals('critical', $notification->priority);
        $this->assertStringContainsString('Sarah Connor', $notification->body);
        $this->assertStringContainsString('allergic reaction', $notification->body);
        $this->assertSame(0, InAppNotification::where('user_id', $homeowner->id)->count());
        $this->assertSame(0, InAppNotification::where('user_id', $otherResident->id)->count());
    }

    public function test_temporary_homeowner_renter_can_trigger_emergency_panic(): void
    {
        Event::fake([ResidentPanicAlertEvent::class, OperationsCommandCenterEvent::class]);

        $renter = User::factory()->create([
            'role' => UserRole::TemporaryHomeowner,
            'name' => 'John Renter',
            'lot' => 'Villa 7B',
        ]);

        $response = $this->actingAs($renter)->postJson('/dashboard/emergency/panic', [
            'emergency_type' => 'security',
            'notes' => 'Prowler spotted near back terrace.',
        ]);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'message' => 'Your request has been received.',
        ]);

        Event::assertDispatched(ResidentPanicAlertEvent::class);
    }

    public function test_unauthenticated_user_cannot_trigger_emergency_panic(): void
    {
        $response = $this->postJson('/dashboard/emergency/panic', [
            'emergency_type' => 'medical',
        ]);

        $response->assertUnauthorized();
    }

    public function test_validation_rejects_invalid_emergency_types(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);

        $response = $this->actingAs($homeowner)->postJson('/dashboard/emergency/panic', [
            'emergency_type' => 'alien_invasion',
        ]);

        $response->assertUnprocessable();
        $response->assertJsonValidationErrors(['emergency_type']);
    }
}
