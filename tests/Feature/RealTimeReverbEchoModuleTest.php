<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Events\Realtime\CommunityChatMessageEvent;
use App\Events\Realtime\DashboardTelemetryUpdatedEvent;
use App\Events\Realtime\GatePassStatusUpdatedEvent;
use App\Events\Realtime\LiveNotificationEvent;
use App\Events\Realtime\OperationsCommandCenterEvent;
use App\Livewire\Realtime\RealtimeOperationsHub;
use App\Models\User;
use App\Services\Realtime\RealtimeBroadcasterService;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Tests\TestCase;

class RealTimeReverbEchoModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_broadcasting_configuration_has_reverb_as_driver(): void
    {
        // Which connection is the default is set per environment by
        // BROADCAST_CONNECTION (off in tests, see phpunit.xml); what must hold
        // everywhere is that a complete Reverb connection is available to pick.
        $reverbConfig = config('broadcasting.connections.reverb');
        $this->assertNotNull($reverbConfig);
        $this->assertSame('reverb', $reverbConfig['driver']);
        $this->assertArrayHasKey('key', $reverbConfig);
        $this->assertArrayHasKey('secret', $reverbConfig);
        $this->assertArrayHasKey('app_id', $reverbConfig);
        $this->assertArrayHasKey('options', $reverbConfig);
    }

    public function test_echo_configuration_dto_provides_valid_connection_parameters(): void
    {
        $service = app(RealtimeBroadcasterService::class);
        $config = $service->getEchoConfig();

        $this->assertSame('reverb', $config['broadcaster']);
        $this->assertArrayHasKey('key', $config);
        $this->assertArrayHasKey('wsHost', $config);
        $this->assertArrayHasKey('wsPort', $config);
        $this->assertArrayHasKey('wssPort', $config);
        $this->assertArrayHasKey('forceTLS', $config);
        $this->assertArrayHasKey('enabledTransports', $config);
    }

    public function test_community_chat_message_event_broadcasts_on_presence_and_public_channels(): void
    {
        Event::fake([CommunityChatMessageEvent::class]);

        $service = app(RealtimeBroadcasterService::class);
        $user = User::factory()->create([
            'name' => 'Sarah Connor',
            'role' => UserRole::Homeowner,
        ]);

        $event = $service->broadcastChatMessage(
            roomId: 'main-lounge',
            user: $user,
            message: 'Hello community from Reverb WebSocket!'
        );

        Event::assertDispatched(CommunityChatMessageEvent::class);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);

        $this->assertInstanceOf(PresenceChannel::class, $channels[0]);
        $this->assertSame('presence-chat.room.main-lounge', $channels[0]->name);

        $payload = $event->broadcastWith();
        $this->assertSame('Hello community from Reverb WebSocket!', $payload['message']);
        $this->assertSame('Sarah Connor', $payload['user_name']);
        $this->assertSame('main-lounge', $payload['room_id']);
        $this->assertSame('chat.message', $event->broadcastAs());
    }

    public function test_dashboard_telemetry_updated_event_broadcasts_on_private_telemetry_channel(): void
    {
        Event::fake([DashboardTelemetryUpdatedEvent::class]);

        $service = app(RealtimeBroadcasterService::class);
        $stats = [
            'active_visitors' => 32,
            'open_passes' => 54,
            'scans_per_minute' => 12,
        ];

        $event = $service->broadcastDashboardTelemetry($stats);

        Event::assertDispatched(DashboardTelemetryUpdatedEvent::class);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-dashboard-telemetry', $channels[0]->name);

        $payload = $event->broadcastWith();
        $this->assertSame(32, $payload['active_visitors']);
        $this->assertSame('dashboard.telemetry-updated', $event->broadcastAs());
    }

    public function test_live_notification_event_broadcasts_on_user_private_channel(): void
    {
        Event::fake([LiveNotificationEvent::class]);

        $service = app(RealtimeBroadcasterService::class);
        $recipient = User::factory()->create();

        $event = $service->broadcastLiveNotification(
            userId: $recipient->id,
            title: 'Package Arrived',
            body: 'A delivery was checked in at Security Gate 1.',
            options: ['action_url' => '/dashboard/notifications', 'priority' => 'high']
        );

        Event::assertDispatched(LiveNotificationEvent::class);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);
        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-users.'.$recipient->id, $channels[0]->name);

        $payload = $event->broadcastWith();
        $this->assertSame('Package Arrived', $payload['title']);
        $this->assertSame('high', $payload['priority']);
        $this->assertSame('notification.received', $event->broadcastAs());
    }

    public function test_gate_pass_status_updated_event_broadcasts_on_gatehouse_and_pass_private_channels(): void
    {
        Event::fake([GatePassStatusUpdatedEvent::class]);

        $service = app(RealtimeBroadcasterService::class);

        $event = $service->broadcastGatePassUpdate(
            passId: 442,
            passCode: 'GP-9011',
            status: 'cleared',
            visitorName: 'David Miller',
            gate: 'East Gate 3'
        );

        Event::assertDispatched(GatePassStatusUpdatedEvent::class);

        $channels = $event->broadcastOn();
        $this->assertCount(2, $channels);

        $this->assertInstanceOf(PrivateChannel::class, $channels[0]);
        $this->assertSame('private-gatehouse-stream', $channels[0]->name);

        $this->assertInstanceOf(PrivateChannel::class, $channels[1]);
        $this->assertSame('private-passes.442', $channels[1]->name);

        $payload = $event->broadcastWith();
        $this->assertSame('GP-9011', $payload['pass_code']);
        $this->assertSame('cleared', $payload['status']);
        $this->assertSame('gatepass.status-updated', $event->broadcastAs());
    }

    public function test_operations_command_center_event_broadcasts_on_the_operations_presence_channel_only(): void
    {
        Event::fake([OperationsCommandCenterEvent::class]);

        $service = app(RealtimeBroadcasterService::class);

        $event = $service->broadcastOperationsAlert(
            type: 'incident_lockdown',
            severity: 'critical',
            headline: 'Gate 2 barrier sensor alert triggered',
            location: 'Sector C Perimeter',
            operatorName: 'Officer Vance'
        );

        Event::assertDispatched(OperationsCommandCenterEvent::class);

        $channels = $event->broadcastOn();
        $this->assertCount(1, $channels);

        $this->assertInstanceOf(PresenceChannel::class, $channels[0]);
        $this->assertSame('presence-operations-center', $channels[0]->name);

        $payload = $event->broadcastWith();
        $this->assertSame('critical', $payload['severity']);
        $this->assertSame('Sector C Perimeter', $payload['location']);
        $this->assertSame('operations.command-event', $event->broadcastAs());
    }

    public function test_realtime_api_config_endpoint_is_publicly_accessible(): void
    {
        $response = $this->getJson('/api/v1/realtime/config');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.broadcaster', 'reverb');
    }

    public function test_realtime_api_channels_catalog_returns_all_realtime_channels(): void
    {
        $response = $this->getJson('/api/v1/realtime/channels');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.infrastructure', 'Laravel Reverb (WebSocket Server)')
            ->assertJsonPath('data.client_library', 'Laravel Echo + Pusher JS');

        $channels = $response->json('data.channels');
        $this->assertIsArray($channels);
        $this->assertGreaterThanOrEqual(6, count($channels));
    }

    public function test_realtime_api_broadcast_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/realtime/broadcast', [
            'type' => 'chat',
            'message' => 'Unauthorized broadcast attempt',
        ]);

        $response->assertStatus(401);
    }

    public function test_realtime_api_broadcast_dispatches_events_for_authenticated_users(): void
    {
        Event::fake();

        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'name' => 'Commander Reyes',
        ]);

        // 1. Broadcast chat message
        $chatRes = $this->actingAs($user)->postJson('/api/v1/realtime/broadcast', [
            'type' => 'chat',
            'room_id' => 'ops-room',
            'message' => 'Command radio check.',
        ]);

        $chatRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('event', 'CommunityChatMessageEvent');

        Event::assertDispatched(CommunityChatMessageEvent::class);

        // 2. Broadcast telemetry update
        $telemetryRes = $this->actingAs($user)->postJson('/api/v1/realtime/broadcast', [
            'type' => 'telemetry',
            'telemetry' => ['active_sensors' => 48],
        ]);

        $telemetryRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('event', 'DashboardTelemetryUpdatedEvent');

        Event::assertDispatched(DashboardTelemetryUpdatedEvent::class);

        // 3. Broadcast operations alert
        $alertRes = $this->actingAs($user)->postJson('/api/v1/realtime/broadcast', [
            'type' => 'operations',
            'severity' => 'warning',
            'headline' => 'Elevated access traffic detected',
        ]);

        $alertRes->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('event', 'OperationsCommandCenterEvent');

        Event::assertDispatched(OperationsCommandCenterEvent::class);
    }

    public function test_livewire_realtime_operations_hub_renders_and_executes_actions(): void
    {
        Event::fake();

        $user = User::factory()->create([
            'role' => UserRole::Admin,
            'name' => 'Agent Sterling',
        ]);

        Livewire::actingAs($user)
            ->test(RealtimeOperationsHub::class)
            ->assertSee('Real-Time Operations Command')
            ->assertSee('Laravel Reverb + Echo Active')
            ->assertSee('Laravel Echo')
            ->call('selectTab', 'telemetry')
            ->assertSee('Active Visitors')
            ->call('selectTab', 'chat')
            ->assertSee('Real-Time Chat')
            ->set('chatMessageInput', 'Testing real-time message stream')
            ->call('sendChatMessage')
            ->assertSee('Message broadcasted in real time')
            ->call('selectTab', 'tracking')
            ->assertSee('Gate Pass Status Tracking')
            ->call('selectTab', 'operations')
            ->assertSee('Security Command')
            ->call('dispatchOperationsAlert')
            ->assertSee('Operations Alert');
    }

    public function test_web_route_for_realtime_operations_hub_is_accessible_to_authorized_user(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $response = $this->actingAs($user)->get('/operations/realtime');
        $response->assertStatus(200);
    }
}
