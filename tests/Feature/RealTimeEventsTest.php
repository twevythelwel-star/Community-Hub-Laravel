<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Events\SecurityAlertBroadcastEvent;
use App\Events\VisitorCheckedInEvent;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RealTimeEventsTest extends TestCase
{
    use RefreshDatabase;

    public function test_checking_in_visitor_dispatches_realtime_event(): void
    {
        Event::fake([VisitorCheckedInEvent::class]);

        $security = User::factory()->create(['role' => UserRole::Security]);
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);

        $visitor = Visitor::create([
            'name' => 'Sarah Connor',
            'type' => 'One-time',
            'homeowner_id' => $homeowner->id,
            'homeowner_name' => $homeowner->name,
            'status' => VisitorStatus::Expected,
            'expected_at' => now()->addHour(),
        ]);

        $response = $this->actingAs($security)
            ->post("/dashboard/visitors/{$visitor->id}/check-in", [
                'gate' => 'North Gate',
            ]);

        $response->assertSessionHasNoErrors();

        Event::assertDispatched(VisitorCheckedInEvent::class, function ($event) use ($visitor) {
            return $event->visitor->id === $visitor->id &&
                $event->payload['visitor_name'] === 'Sarah Connor' &&
                $event->payload['entry_gate'] === 'North Gate';
        });
    }

    public function test_raising_warning_dispatches_security_alert_event(): void
    {
        Event::fake([SecurityAlertBroadcastEvent::class]);

        $user = User::factory()->create(['role' => UserRole::Homeowner]);

        $response = $this->actingAs($user)
            ->post('/dashboard/warnings', [
                'title' => 'Suspicious vehicle spotted near pool',
                'description' => 'A dark grey sedan without community decal was parked for 2 hours.',
            ]);

        $response->assertSessionHasNoErrors();

        Event::assertDispatched(SecurityAlertBroadcastEvent::class, function ($event) {
            return str_contains($event->payload['title'], 'Suspicious vehicle');
        });
    }
}
