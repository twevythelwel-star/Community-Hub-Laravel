<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Livewire\Realtime\RealtimeOperationsHub;
use App\Models\GatePass;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Realtime messages reach everybody watching, so who may send each kind, and
 * who may listen on each channel, follows the same roles as the dashboard.
 */
class RealtimeAccessTest extends TestCase
{
    use RefreshDatabase;

    private function as(UserRole $role, array $attributes = []): User
    {
        return User::factory()->role($role)->create($attributes);
    }

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('Device', TokenAbility::forUser($user))->plainTextToken];
    }

    private function channel(string $name): Closure
    {
        $callback = Broadcast::getChannels()->get($name);
        $this->assertInstanceOf(Closure::class, $callback, "channel [{$name}] should be registered");

        return $callback;
    }

    // ── POST /api/v1/realtime/broadcast ─────────────────────────────

    /** @return array<string, array{array<string, mixed>}> */
    public static function estateWideBroadcasts(): array
    {
        return [
            'security alert' => [['type' => 'operations', 'headline' => 'Intruder at the north fence', 'severity' => 'critical']],
            'pass update' => [['type' => 'pass', 'pass_id' => 1, 'status' => 'cleared']],
            'dashboard figures' => [['type' => 'telemetry', 'telemetry' => ['active_visitors_count' => 0]]],
        ];
    }

    #[DataProvider('estateWideBroadcasts')]
    public function test_a_resident_cannot_broadcast_for_the_estate(array $payload): void
    {
        $this->postJson('/api/v1/realtime/broadcast', $payload, $this->bearer($this->as(UserRole::Homeowner)))
            ->assertForbidden();
    }

    #[DataProvider('estateWideBroadcasts')]
    public function test_security_can_broadcast_for_the_estate(array $payload): void
    {
        $this->postJson('/api/v1/realtime/broadcast', $payload, $this->bearer($this->as(UserRole::Security)))
            ->assertOk();
    }

    public function test_a_resident_cannot_push_a_notification_to_someone_else(): void
    {
        $neighbour = $this->as(UserRole::Homeowner);

        $this->postJson('/api/v1/realtime/broadcast', [
            'type' => 'notification',
            'user_id' => $neighbour->id,
            'title' => 'Your gate code changed',
            'body' => 'Confirm it at this link.',
        ], $this->bearer($this->as(UserRole::Homeowner)))->assertForbidden();
    }

    public function test_an_administrator_can_push_a_notification(): void
    {
        $this->postJson('/api/v1/realtime/broadcast', [
            'type' => 'notification',
            'user_id' => $this->as(UserRole::Homeowner)->id,
            'title' => 'Water outage Thursday',
        ], $this->bearer($this->as(UserRole::Admin)))->assertOk();
    }

    public function test_a_resident_can_chat_as_themselves(): void
    {
        $resident = $this->as(UserRole::Homeowner, ['name' => 'Marcia Brown']);

        $this->postJson('/api/v1/realtime/broadcast', [
            'type' => 'chat',
            'room_id' => 'general',
            'message' => 'Is the pool open today?',
        ], $this->bearer($resident))
            ->assertOk()
            ->assertJsonPath('payload.user_id', $resident->id)
            ->assertJsonPath('payload.user_name', 'Marcia Brown');
    }

    public function test_an_unknown_severity_is_rejected(): void
    {
        $this->postJson('/api/v1/realtime/broadcast', [
            'type' => 'operations',
            'severity' => 'apocalyptic',
        ], $this->bearer($this->as(UserRole::Security)))->assertUnprocessable();
    }

    // ── /operations/realtime ────────────────────────────────────────

    public function test_a_resident_cannot_open_the_realtime_hub(): void
    {
        $this->actingAs($this->as(UserRole::Homeowner))->get('/operations/realtime')->assertForbidden();
        Livewire::actingAs($this->as(UserRole::Homeowner))->test(RealtimeOperationsHub::class)->assertForbidden();
    }

    public function test_security_can_dispatch_an_alert_from_the_hub(): void
    {
        Livewire::actingAs($this->as(UserRole::Security, ['name' => 'Officer Grant']))
            ->test(RealtimeOperationsHub::class)
            ->set('newAlertSeverity', 'critical')
            ->call('dispatchOperationsAlert')
            ->assertHasNoErrors()
            ->assertSet('activeAlerts.0.severity', 'critical');
    }

    public function test_the_hub_rejects_a_severity_the_form_does_not_offer(): void
    {
        Livewire::actingAs($this->as(UserRole::Security))
            ->test(RealtimeOperationsHub::class)
            ->set('newAlertSeverity', 'apocalyptic')
            ->call('dispatchOperationsAlert')
            ->assertHasErrors('newAlertSeverity');
    }

    // ── Channel authorisation (routes/channels.php) ─────────────────

    public function test_gate_channel_admits_security_and_admins_only(): void
    {
        $gate = $this->channel('gate.{gateId}');

        $this->assertTrue($gate($this->as(UserRole::Security), 'gate-01'));
        $this->assertTrue($gate($this->as(UserRole::Admin), 'gate-01'));
        $this->assertFalse($gate($this->as(UserRole::Homeowner), 'gate-01'));
    }

    public function test_pass_channel_admits_the_desk_and_the_passholder(): void
    {
        $passes = $this->channel('passes.{passId}');
        $owner = $this->as(UserRole::Homeowner);
        $pass = GatePass::factory()->create(['user_id' => $owner->id]);

        $this->assertTrue($passes($this->as(UserRole::Security), $pass->id));
        $this->assertTrue($passes($owner, $pass->id));
        $this->assertFalse($passes($this->as(UserRole::Homeowner), $pass->id));
    }

    public function test_operations_center_presence_is_for_the_desk(): void
    {
        $center = $this->channel('operations-center');

        $this->assertIsArray($center($this->as(UserRole::Security)));
        $this->assertFalse($center($this->as(UserRole::Homeowner)));
    }

    public function test_a_deactivated_guard_cannot_subscribe(): void
    {
        $guard = $this->as(UserRole::Security, ['status' => 'Inactive', 'deactivated_at' => now()]);
        $pass = GatePass::factory()->create();

        $this->assertFalse($this->channel('gate.{gateId}')($guard, 'gate-01'));
        $this->assertFalse($this->channel('passes.{passId}')($guard, $pass->id));
        $this->assertFalse($this->channel('users.{id}')($guard, $guard->id));
    }
}
