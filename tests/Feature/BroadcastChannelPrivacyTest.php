<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Events\Realtime\CommunityChatMessageEvent;
use App\Events\Realtime\DashboardTelemetryUpdatedEvent;
use App\Events\Realtime\GatePassStatusUpdatedEvent;
use App\Events\Realtime\OperationsCommandCenterEvent;
use App\Events\SecurityAlertBroadcastEvent;
use App\Events\VisitorCheckedInEvent;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Warning;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Broadcasts went out on public channels â€” community-alerts, gatehouse-stream,
 * community-chat-stream, dashboard-telemetry â€” which never consult the rules in
 * routes/channels.php, so any websocket client, signed in or not, could read
 * them: every chat room's messages, visitor names and pass codes, a resident's
 * phone and location from an SOS, and with each check-in the whole Visitor
 * record, ID number and guest-pass share_token included.
 */
class BroadcastChannelPrivacyTest extends TestCase
{
    use RefreshDatabase;

    /** @param  list<object>  $channels */
    private function names(array $channels): array
    {
        return array_map(fn ($channel) => $channel->name, $channels);
    }

    public function test_nothing_broadcasts_on_a_public_channel(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (preg_match('/new\s+Channel\s*\(/', file_get_contents($file->getPathname()))) {
                $offenders[] = $file->getPathname();
            }
        }

        $this->assertSame([], $offenders, 'Use PrivateChannel or PresenceChannel: a public Channel is readable by anyone');
    }

    public function test_each_event_goes_only_to_its_audience(): void
    {
        $warning = Warning::create(['title' => 'Water outage', 'description' => 'Main line repairs', 'author_name' => 'Estate Office', 'issued_at' => now()]);

        $this->assertSame(['private-community-alerts'], $this->names((new SecurityAlertBroadcastEvent($warning))->broadcastOn()));
        $this->assertSame(['presence-operations-center'], $this->names(
            (new OperationsCommandCenterEvent('A1', 'emergency', 'critical', 'SOS', 'Lot 4', 'Officer'))->broadcastOn(),
        ));
        $this->assertSame(['presence-chat.room.lounge'], $this->names(
            (new CommunityChatMessageEvent('lounge', 1, 'Sade', 'Homeowner', 'Hi', null, now()->toIso8601String()))->broadcastOn(),
        ));
        $this->assertSame(['private-dashboard-telemetry'], $this->names((new DashboardTelemetryUpdatedEvent([]))->broadcastOn()));
        $this->assertSame(['private-gatehouse-stream', 'private-passes.7'], $this->names(
            (new GatePassStatusUpdatedEvent(7, 'GP-7', 'cleared', 'Guest', 'Main Gate'))->broadcastOn(),
        ));
    }

    public function test_a_check_in_tells_the_host_and_the_gate_only_the_summary(): void
    {
        $host = User::factory()->role(UserRole::Homeowner)->create();
        $visitor = Visitor::create([
            'name' => 'Michael Chang', 'type' => 'One-time', 'contact' => '876-555-0142',
            'id_type' => 'Passport', 'id_number' => 'P1234567', 'vehicle' => 'ABC 123',
            'homeowner_id' => $host->id, 'homeowner_name' => $host->name,
            'status' => VisitorStatus::Expected, 'expected_at' => now(),
        ]);

        $event = new VisitorCheckedInEvent($visitor, 'Main Gate 1');

        // users.{id} is the rule routes/channels.php defines; "user." matched none.
        $this->assertSame(["private-users.{$host->id}", 'private-gatehouse-stream'], $this->names($event->broadcastOn()));

        $payload = json_encode($event->broadcastWith());
        $this->assertStringContainsString('Michael Chang', $payload);
        foreach (['P1234567', '876-555-0142', $visitor->share_token] as $secret) {
            $this->assertStringNotContainsString($secret, $payload);
        }
    }

    public function test_the_private_channels_admit_only_their_audience(): void
    {
        $rules = app(BroadcastManager::class)->driver()->getChannels();
        $can = fn (string $channel, User $user) => (bool) $rules[$channel]($user);

        $resident = User::factory()->role(UserRole::Homeowner)->create();
        $deactivated = User::factory()->role(UserRole::Homeowner)->create(['deactivated_at' => now()]);
        $officer = User::factory()->role(UserRole::Security)->create();
        $admin = User::factory()->role(UserRole::Admin)->create();

        $this->assertTrue($can('community-alerts', $resident));
        $this->assertFalse($can('community-alerts', $deactivated));

        $this->assertTrue($can('gatehouse-stream', $officer));
        $this->assertTrue($can('gatehouse-stream', $admin));
        $this->assertFalse($can('gatehouse-stream', $resident));

        $this->assertTrue($can('dashboard-telemetry', $admin));
        $this->assertFalse($can('dashboard-telemetry', $officer));
        $this->assertFalse($can('dashboard-telemetry', $resident));
    }
}
