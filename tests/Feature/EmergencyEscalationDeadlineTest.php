<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DistressAlert;
use App\Models\DistressAlertEvent;
use App\Models\SafetyProfile;
use App\Models\User;
use App\Services\Safety\SilentAssistanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmergencyEscalationDeadlineTest extends TestCase
{
    use RefreshDatabase;

    public function test_distress_alert_creation_assigns_acknowledgment_deadline_and_delivery_stages(): void
    {
        config(['safety.acknowledgment_timeout_seconds' => 90]);

        $resident = User::factory()->create([
            'role' => UserRole::Homeowner,
            'status' => 'Active',
        ]);

        $security = User::factory()->create([
            'role' => UserRole::Security,
            'status' => 'Active',
        ]);

        $service = app(SilentAssistanceService::class);
        SafetyProfile::updateOrCreate(['user_id' => $resident->id], [
            'silent_alerts_enabled' => true,
            'preferred_assistance' => 'security',
        ]);

        $alert = $service->raise($resident, 'app', 'security');

        $this->assertNotNull($alert);
        $this->assertNotNull($alert->acknowledgment_deadline_at);
        $this->assertSame(
            $alert->triggered_at->addSeconds(90)->timestamp,
            $alert->acknowledgment_deadline_at->timestamp
        );

        // Verify audit timeline contains full delivery lifecycle
        $eventTypes = $alert->auditEvents()->pluck('event_type')->all();
        $this->assertContains(DistressAlertEvent::ACTIVATED, $eventTypes);
        $this->assertContains(DistressAlertEvent::DISPATCH_QUEUED, $eventTypes);
        $this->assertContains(DistressAlertEvent::DISPATCH_SENT, $eventTypes);
        $this->assertContains(DistressAlertEvent::DISPATCH_DELIVERED, $eventTypes);
        $this->assertContains(DistressAlertEvent::SECURITY_NOTIFIED, $eventTypes);
    }

    public function test_unacknowledged_alert_past_deadline_escalates_to_security(): void
    {
        $resident = User::factory()->create([
            'role' => UserRole::Homeowner,
            'status' => 'Active',
        ]);

        $service = app(SilentAssistanceService::class);
        SafetyProfile::updateOrCreate(['user_id' => $resident->id], [
            'silent_alerts_enabled' => true,
            'preferred_assistance' => 'security',
        ]);

        $alert = $service->raise($resident, 'app', 'security');

        // Fast-forward past deadline
        $alert->update([
            'triggered_at' => now()->subMinutes(5),
            'acknowledgment_deadline_at' => now()->subMinutes(4),
        ]);

        $this->assertSame(DistressAlert::ESCALATION_SILENT_ALERT, $alert->fresh()->escalation_level);

        $this->artisan('safety:escalate-unacknowledged')
            ->expectsOutputToContain('Escalated 1 unacknowledged distress alert(s)')
            ->assertSuccessful();

        $freshAlert = $alert->fresh();
        $this->assertSame(DistressAlert::ESCALATION_SECURITY, $freshAlert->escalation_level);
        $this->assertSame(DistressAlert::SEVERITY_SILENT_EMERGENCY, $freshAlert->severity_level);
        $this->assertNotNull($freshAlert->escalated_at);

        $events = $freshAlert->auditEvents()->where('event_type', DistressAlertEvent::ACKNOWLEDGMENT_TIMEOUT_ESCALATED)->get();
        $this->assertCount(1, $events);
        $this->assertStringContainsString('unacknowledged', $events->first()->snapshot['reason']);
    }

    public function test_acknowledged_alert_does_not_timeout_escalate(): void
    {
        $resident = User::factory()->create([
            'role' => UserRole::Homeowner,
            'status' => 'Active',
        ]);

        $security = User::factory()->create([
            'role' => UserRole::Security,
            'status' => 'Active',
        ]);

        $service = app(SilentAssistanceService::class);
        SafetyProfile::updateOrCreate(['user_id' => $resident->id], [
            'silent_alerts_enabled' => true,
            'preferred_assistance' => 'security',
        ]);

        $alert = $service->raise($resident, 'app', 'security');
        $service->acknowledge($alert, $security);

        // Even if deadline is in the past
        $alert->update([
            'acknowledgment_deadline_at' => now()->subMinutes(10),
        ]);

        $this->artisan('safety:escalate-unacknowledged')
            ->expectsOutputToContain('No unacknowledged distress alerts exceeded their deadline.')
            ->assertSuccessful();

        $this->assertSame('acknowledged', $alert->fresh()->status);
        $this->assertNotSame(DistressAlert::ESCALATION_SECURITY, $alert->fresh()->escalation_level);
    }
}
