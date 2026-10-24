<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Events\SecurityAlertBroadcastEvent;
use App\Events\VisitorCheckedInEvent;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Warning;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use ReflectionClass;
use Tests\TestCase;

/**
 * Realtime updates are a convenience; admitting a visitor or raising an alert
 * is the job. When the broadcast server is down, the job must still be done —
 * and Laravel broadcasts before it runs an event's listeners, so an
 * unrescued failure would also skip the homeowner's arrival notice.
 */
class BroadcastOutageTest extends TestCase
{
    use RefreshDatabase;

    private int $attempts = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Broadcast::extend('down', function () {
            return new class($this) extends Broadcaster
            {
                public function __construct(private BroadcastOutageTest $test) {}

                public function auth($request): mixed
                {
                    return null;
                }

                public function validAuthenticationResponse($request, $result): mixed
                {
                    return null;
                }

                public function broadcast(array $channels, $event, array $payload = []): void
                {
                    $this->test->recordAttempt();

                    throw new BroadcastException('Reverb is unreachable.');
                }
            };
        });

        config([
            'broadcasting.connections.down' => ['driver' => 'down'],
            'broadcasting.default' => 'down',
        ]);
    }

    public function recordAttempt(): void
    {
        $this->attempts++;
    }

    public function test_raising_an_alert_succeeds_while_broadcasting_is_down(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($resident)
            ->post('/dashboard/warnings', [
                'title' => 'Gate 2 barrier stuck open',
                'description' => 'The barrier at gate 2 has not closed since this morning.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, Warning::count());
        $this->assertSame(1, $this->attempts, 'the broadcast should have been tried, and failed');
    }

    public function test_checking_a_visitor_in_succeeds_while_broadcasting_is_down(): void
    {
        $homeowner = User::factory()->role(UserRole::Homeowner)->create();
        $guard = User::factory()->role(UserRole::Security)->create();
        $visitor = Visitor::create([
            'name' => 'Courier Dave', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now(), 'homeowner_id' => $homeowner->id,
            'homeowner_name' => $homeowner->display_name,
        ]);

        $this->actingAs($guard)
            ->post("/dashboard/visitors/{$visitor->id}/check-in", ['gate' => 'Main Gate 1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(VisitorStatus::CheckedIn, $visitor->fresh()->status);
        // The visitor is saved before the event fires, so status alone proves
        // little: an unrescued failure lost the access-log entry written after it.
        $this->assertDatabaseHas('access_log_entries', ['user_name' => 'Courier Dave', 'result' => 'ALLOW']);
        $this->assertSame(1, $this->attempts);
    }

    public function test_listeners_still_run_after_a_failed_broadcast(): void
    {
        $heard = false;
        Event::listen(VisitorCheckedInEvent::class, function () use (&$heard) {
            $heard = true;
        });

        $visitor = Visitor::create([
            'name' => 'Plumber Pat', 'type' => 'One-time', 'status' => 'Expected',
            'expected_at' => now(), 'homeowner_id' => User::factory()->create()->id,
            'homeowner_name' => 'Owner',
        ]);

        event(new VisitorCheckedInEvent($visitor, 'Main Gate 1'));

        $this->assertSame(1, $this->attempts);
        $this->assertTrue($heard, 'the arrival-notice listener must not be skipped');
    }

    public function test_every_broadcast_event_is_rescued(): void
    {
        $unrescued = collect(File::allFiles(app_path('Events')))
            ->map(fn ($file) => 'App\\Events\\'.str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname()))
            ->filter(fn (string $class) => class_exists($class) && ! (new ReflectionClass($class))->isAbstract())
            ->filter(fn (string $class) => is_subclass_of($class, ShouldBroadcast::class))
            ->reject(fn (string $class) => is_subclass_of($class, ShouldRescue::class))
            ->values()
            ->all();

        $this->assertSame([], $unrescued, 'Broadcast events must implement ShouldRescue so an outage cannot fail the request.');
        $this->assertTrue(is_subclass_of(SecurityAlertBroadcastEvent::class, ShouldRescue::class));
    }
}
