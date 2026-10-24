<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiArchitectureLayerTest extends TestCase
{
    use RefreshDatabase;

    public function test_version_negotiation_via_uri_header_and_accept(): void
    {
        // 1. Negotiation via URI
        $v1Response = $this->getJson('/api/v1/version');
        $v1Response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('version', 'v1');

        $v2Response = $this->getJson('/api/v2/version');
        $v2Response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('version', 'v2');

        // 2. Negotiation via X-Api-Version header
        $headerResponse = $this->withHeader('X-Api-Version', 'v2')->getJson('/api/version');
        $headerResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'preview');

        // 3. Negotiation via Accept header
        $this->flushHeaders();
        $acceptResponse = $this->withHeader('Accept', 'application/vnd.communityhub.v1+json')->getJson('/api/version');
        $acceptResponse->assertStatus(200)
            ->assertJsonPath('data.status', 'current');
    }

    public function test_version_catalog_lists_v1_and_v2(): void
    {
        $response = $this->getJson('/api/versions');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'default_version',
                    'supported_versions',
                    'versions' => [
                        'v1' => ['status', 'release_date', 'changelog_summary'],
                        'v2' => ['status', 'release_date', 'changelog_summary'],
                    ],
                ],
            ]);
    }

    public function test_health_check_returns_healthy_system_status(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonStructure([
                'data' => [
                    'status',
                    'timestamp',
                    'checks' => [
                        'database' => ['status'],
                        'cache' => ['status'],
                        'storage' => ['status'],
                        'system' => ['php_version', 'laravel_version', 'memory_usage'],
                    ],
                ],
            ]);
    }

    public function test_api_logging_middleware_attaches_headers_and_records_telemetry(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertHeader('X-Request-ID');
        $response->assertHeader('X-Response-Time');
        $response->assertHeader('X-Api-Version');

        $requestId = $response->headers->get('X-Request-ID');

        // Assert record created in api_request_logs
        $this->assertDatabaseHas('api_request_logs', [
            'request_id' => $requestId,
            'method' => 'GET',
            'status_code' => 200,
        ]);
    }

    public function test_token_management_lifecycle_store_list_and_revoke(): void
    {
        $user = User::factory()->create(['role' => UserRole::Homeowner]);
        Sanctum::actingAs($user, ['*']);

        // 1. Create a personal access token with scoped abilities
        $createResponse = $this->postJson('/api/v1/tokens', [
            'token_name' => 'MobileAppToken',
            'abilities' => ['gate-pass:read', 'visitors:manage'],
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'token' => ['id', 'name', 'abilities', 'createdAt'],
                    'plainTextToken',
                ],
            ]);

        $tokenId = $createResponse->json('data.token.id');
        $this->assertNotNull($tokenId);

        // 2. List personal access tokens
        $listResponse = $this->getJson('/api/v1/tokens');
        $listResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');

        // 3. Revoke specific token
        $deleteResponse = $this->deleteJson("/api/v1/tokens/{$tokenId}");
        $deleteResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        // 4. Verify token list is now empty
        $afterDeleteList = $this->getJson('/api/v1/tokens');
        $afterDeleteList->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_token_endpoints_require_authentication(): void
    {
        $this->getJson('/api/v1/tokens')->assertStatus(401);
        $this->postJson('/api/v1/tokens', ['token_name' => 'Test'])->assertStatus(401);
    }

    public function test_webhook_subscription_lifecycle(): void
    {
        $user = User::factory()->create(['role' => UserRole::SystemAdmin]);
        Sanctum::actingAs($user, ['*']);

        // 1. Create a webhook subscription
        $createResponse = $this->postJson('/api/v1/webhooks/subscriptions', [
            'name' => 'Security Audit Webhook',
            'url' => 'https://example.com/api/webhooks',
            'events' => ['visitor.checked_in', 'gate_pass.created'],
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'subscription' => ['id', 'name', 'url', 'events', 'isActive'],
                    'signingSecret',
                ],
            ]);

        $subscriptionId = $createResponse->json('data.subscription.id');

        // 2. List subscriptions
        $listResponse = $this->getJson('/api/v1/webhooks/subscriptions');
        $listResponse->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // 3. Test ping dispatch
        Http::fake([
            'https://example.com/*' => Http::response(['received' => true], 200),
        ]);

        $testResponse = $this->postJson("/api/v1/webhooks/subscriptions/{$subscriptionId}/test");
        $testResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_success', true);

        // 4. Delete subscription
        $deleteResponse = $this->deleteJson("/api/v1/webhooks/subscriptions/{$subscriptionId}");
        $deleteResponse->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('webhook_subscriptions', ['id' => $subscriptionId]);
    }

    public function test_incoming_webhook_signature_verification(): void
    {
        config(['services.webhooks.stripe.secret' => 'whsec_test_secret_12345']);

        $payload = json_encode(['event' => 'payment_intent.succeeded', 'amount' => 5000]);
        $validSignature = 'sha256='.hash_hmac('sha256', $payload, 'whsec_test_secret_12345');

        // Valid signature -> 200 OK
        $validResponse = $this->call(
            'POST',
            '/api/v1/webhooks/incoming/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $validSignature,
            ],
            $payload
        );

        $validResponse->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.received', true);

        // Invalid signature -> 401 Unauthorized
        $invalidResponse = $this->call(
            'POST',
            '/api/v1/webhooks/incoming/stripe',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=invalid_signature_hash',
            ],
            $payload
        );

        $invalidResponse->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_SIGNATURE');
    }

    public function test_openapi_spec_json_for_v1_and_v2(): void
    {
        $v1Response = $this->getJson('/api/v1/openapi.json');
        $v1Response->assertStatus(200)
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('info.title', 'Community Hub Enterprise API')
            ->assertJsonPath('info.version', '1.0.0');

        $v2Response = $this->getJson('/api/v2/openapi.json');
        $v2Response->assertStatus(200)
            ->assertJsonPath('openapi', '3.0.3')
            ->assertJsonPath('info.version', '2.0.0-beta');
    }

    public function test_swagger_ui_html_documentation(): void
    {
        $response = $this->get('/api/docs');
        $response->assertStatus(200)
            ->assertSee('Community Hub Enterprise API')
            ->assertSee('swagger-ui');
    }

    public function test_scramble_documentation_route(): void
    {
        // The generated reference is System Admin only outside local (viewApiDocs).
        $response = $this->actingAs(User::factory()->role(UserRole::SystemAdmin)->create())->get('/docs/api');
        $response->assertStatus(200);
    }
}
