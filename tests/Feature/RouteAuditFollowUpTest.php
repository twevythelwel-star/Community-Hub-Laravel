<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\WebhookSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Follow-ups from auditing every registered route: endpoints that were
 * signed-in but unscoped, or public when they should not have been.
 */
class RouteAuditFollowUpTest extends TestCase
{
    use RefreshDatabase;

    private function as(UserRole $role): User
    {
        return User::factory()->role($role)->create();
    }

    /** Forgets the guards so a test can switch between tokens. */
    private function bearer(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('Device', TokenAbility::forUser($user))->plainTextToken];
    }

    // ── Webhook subscriptions ───────────────────────────────────────

    public function test_a_resident_cannot_manage_webhook_subscriptions(): void
    {
        $headers = $this->bearer($this->as(UserRole::Homeowner));

        $this->getJson('/api/v1/webhooks/subscriptions', $headers)->assertForbidden();
        $this->postJson('/api/v1/webhooks/subscriptions', [
            'name' => 'Mine', 'url' => 'https://attacker.example/hook', 'events' => ['*'],
        ], $headers)->assertForbidden();

        $this->assertSame(0, WebhookSubscription::count());
    }

    public function test_a_signed_in_browser_user_cannot_reach_someone_elses_subscription(): void
    {
        $subscription = WebhookSubscription::create([
            'user_id' => $this->as(UserRole::SystemAdmin)->id,
            'name' => 'Accounting', 'url' => 'https://erp.example/hook',
            'secret' => WebhookSubscription::generateSecret(), 'events' => ['payment.succeeded'], 'is_active' => true,
        ]);

        // A session (cookie) request carries Sanctum's TransientToken, whose
        // tokenCan('*') is true — the old ownership check let this through.
        $this->actingAs($this->as(UserRole::Admin))
            ->deleteJson("/api/v1/webhooks/subscriptions/{$subscription->id}")
            ->assertForbidden();

        $this->assertModelExists($subscription);
    }

    public function test_a_system_admin_can_create_a_subscription(): void
    {
        $this->postJson('/api/v1/webhooks/subscriptions', [
            'name' => 'ERP', 'url' => 'https://erp.example/hook', 'events' => ['payment.succeeded'],
        ], $this->bearer($this->as(UserRole::SystemAdmin)))->assertSuccessful();
    }

    // ── Incoming webhooks ───────────────────────────────────────────

    public function test_an_unconfigured_incoming_webhook_is_refused(): void
    {
        $this->postJson('/api/v1/webhooks/incoming/unknownvendor', ['event' => 'x'])->assertNotFound();
    }

    public function test_an_incoming_webhook_is_verified_against_its_secret(): void
    {
        config(['services.webhooks.acme.secret' => 'acme_secret']);
        $body = json_encode(['event' => 'gate.opened']);

        $this->call('POST', '/api/v1/webhooks/incoming/acme', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=wrong',
        ], $body)->assertUnauthorized();

        $this->call('POST', '/api/v1/webhooks/incoming/acme', [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256='.hash_hmac('sha256', $body, 'acme_secret'),
        ], $body)->assertOk();
    }

    // ── Horizon and API docs ────────────────────────────────────────

    public function test_horizon_is_the_system_admins(): void
    {
        foreach ([UserRole::Security, UserRole::Admin] as $role) {
            $this->assertFalse(Gate::forUser($this->as($role))->allows('viewHorizon'), "{$role->value} should not see Horizon");
            $this->actingAs($this->as($role))->getJson('/horizon/api/stats')->assertForbidden();
        }

        $this->assertTrue(Gate::forUser($this->as(UserRole::SystemAdmin))->allows('viewHorizon'));
    }

    public function test_the_api_reference_is_not_public(): void
    {
        $this->get('/docs/api')->assertForbidden();
        $this->actingAs($this->as(UserRole::Admin))->get('/docs/api')->assertForbidden();
        $this->actingAs($this->as(UserRole::SystemAdmin))->get('/docs/api')->assertOk();
    }
}
