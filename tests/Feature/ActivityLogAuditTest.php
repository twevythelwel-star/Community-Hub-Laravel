<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Models\Activity;
use App\Models\GatePass;
use App\Models\User;
use App\Models\Warning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityLogAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_is_logged_on_model_creation_with_causer_and_subject(): void
    {
        $user = User::factory()->create([
            'name' => 'Officer Miller',
            'email' => 'officer@communityhub.test',
        ]);

        $this->actingAs($user);

        $warning = Warning::create([
            'title' => 'Speed Limit Violation',
            'description' => 'Vehicle exceeded 30km/h on Main Boulevard',
            'author_id' => $user->id,
            'author_name' => $user->name,
            'issued_at' => now(),
        ]);

        $activity = Activity::forSubject($warning)->first();

        $this->assertNotNull($activity);
        $this->assertEquals('created', $activity->event);
        $this->assertEquals('Warning created', $activity->description);

        // Who did it?
        $this->assertEquals($user->id, $activity->causer_id);
        $this->assertEquals(User::class, $activity->causer_type);
        $this->assertEquals('Officer Miller', $activity->causer->name);

        // Which record?
        $this->assertEquals($warning->id, $activity->subject_id);
        $this->assertEquals(Warning::class, $activity->subject_type);

        // What is the new value?
        $newValues = $activity->new_values;
        $this->assertEquals('Speed Limit Violation', $newValues['title']);
    }

    public function test_activity_logs_old_and_new_values_on_update(): void
    {
        $user = User::factory()->create(['name' => 'Supervisor Vance']);
        $this->actingAs($user);

        $warning = Warning::create([
            'title' => 'Initial Warning',
            'description' => 'Noise level high',
            'author_id' => $user->id,
            'author_name' => $user->name,
            'issued_at' => now(),
        ]);

        // Update the warning
        $warning->update([
            'title' => 'Escalated Warning',
            'description' => 'Noise level repeated violation after 10PM',
        ]);

        $updateActivity = Activity::forSubject($warning)
            ->where('event', 'updated')
            ->latest('id')
            ->first();

        $this->assertNotNull($updateActivity);

        // Old values vs new values
        $this->assertEquals('Initial Warning', $updateActivity->old_values['title']);
        $this->assertEquals('Escalated Warning', $updateActivity->new_values['title']);
        $this->assertEquals('Noise level high', $updateActivity->old_values['description']);
        $this->assertEquals('Noise level repeated violation after 10PM', $updateActivity->new_values['description']);

        // Diff helper
        $diff = $updateActivity->getChangesSummary();
        $this->assertArrayHasKey('title', $diff);
        $this->assertEquals('Initial Warning', $diff['title']['old']);
        $this->assertEquals('Escalated Warning', $diff['title']['new']);
    }

    public function test_activity_captures_network_ip_and_request_url(): void
    {
        $user = User::factory()->create(['name' => 'Gate Controller']);

        // Simulate an HTTP POST request
        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.105'])
            ->post('/api/admin/emergency/broadcast', [
                'type' => 'security',
                'severity' => 'critical',
                'title' => 'Gate Perimeter Alert',
                'message' => 'Intruder alert at North Gate',
            ]);

        // Manual or automated activity creation with enterprise enrichment
        activity()
            ->causedBy($user)
            ->withProperties(['event_type' => 'security_override'])
            ->log('Emergency override triggered');

        $activity = Activity::latest('id')->first();

        $this->assertNotNull($activity);
        $this->assertNotEmpty($activity->ip);
        $this->assertNotEmpty($activity->url);
        $this->assertNotEmpty($activity->method);
    }

    public function test_gate_pass_lifecycle_auditing(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $pass = GatePass::create([
            'pass_id' => 'GP-AUDIT-001',
            'category' => PassCategory::Homeowner,
            'holder_name' => 'Sarah Connor',
            'property' => 'Lot 42 Cyberdyne Way',
            'status' => PassStatus::Active,
            'valid_from' => now()->subHour(),
            'valid_until' => now()->addDay(),
            'color_variant' => 'slate',
        ]);

        $creationActivity = Activity::forSubject($pass)
            ->where('event', 'created')
            ->first();

        $this->assertNotNull($creationActivity);
        $this->assertEquals('Gate Pass created', $creationActivity->description);
        $this->assertEquals('GP-AUDIT-001', $creationActivity->new_values['pass_id']);

        // Update pass status
        $pass->update([
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now(),
        ]);

        $updateActivity = Activity::forSubject($pass)
            ->where('event', 'updated')
            ->first();

        $this->assertNotNull($updateActivity);
        $this->assertEquals(PassStatus::Active->value, $updateActivity->old_values['status']);
        $this->assertEquals(PassStatus::CheckedIn->value, $updateActivity->new_values['status']);
    }
}
