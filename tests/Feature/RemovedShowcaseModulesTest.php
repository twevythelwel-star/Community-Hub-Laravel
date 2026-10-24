<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The showcase hubs (queues, Octane, observability, feature flags, generic
 * PDFs, the query-builder playground, the "Filament" role simulator, the
 * universal notification engine and the modular payment gateways) were
 * removed: none backed an estate screen, and several could queue jobs,
 * write audit entries, message residents, open checkout sessions or issue
 * documents in the estate's name. Even a System Admin must find nothing at
 * their old addresses.
 */
class RemovedShowcaseModulesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function pages(): array
    {
        return [
            'queues' => ['/operations/queues'],
            'octane' => ['/operations/octane'],
            'observability' => ['/operations/observability'],
            'feature flags' => ['/operations/features'],
            'pdf' => ['/operations/pdf'],
            // Livewire copies of React dashboard screens, removed 2026-10-13.
            'operations hub' => ['/operations'],
            'livewire pass manager' => ['/operations/passes'],
            'livewire directory' => ['/operations/directory'],
            'livewire warning desk' => ['/operations/warnings'],
            'livewire ui kit' => ['/operations/ui-kit'],
            'query builder' => ['/query-builder'],
            'admin panel' => ['/admin'],
            'portal' => ['/portal'],
            'filament' => ['/filament'],
            'notification hub' => ['/dashboard/notifications/hub'],
            'payment gateway hub' => ['/dashboard/billing/gateway-hub'],
        ];
    }

    #[DataProvider('pages')]
    public function test_the_page_is_gone(string $uri): void
    {
        $this->actingAs(User::factory()->role(UserRole::SystemAdmin)->create())
            ->get($uri)
            ->assertNotFound();
    }

    /** @return array<string, array{string, string}> */
    public static function endpoints(): array
    {
        return [
            'queue dispatch' => ['POST', '/api/v1/queues/dispatch'],
            'queue stats' => ['GET', '/api/v1/queues/stats'],
            'octane benchmark' => ['POST', '/api/v1/octane/benchmark-concurrency'],
            'observability logs' => ['GET', '/api/v1/observability/logs'],
            'write audit' => ['POST', '/api/v1/observability/write-audit'],
            'simulate error' => ['POST', '/api/v1/observability/simulate-error'],
            'flags' => ['GET', '/api/v1/features'],
            'flag activate' => ['POST', '/api/v1/features/activate'],
            'pdf generate' => ['POST', '/api/v1/pdf/generate'],
            'pdf preview' => ['POST', '/api/v1/pdf/preview/receipt'],
            'notification send' => ['POST', '/api/v1/notifications/send'],
            'notification dispatch' => ['POST', '/api/v1/notifications/dispatch'],
            'notification inbox' => ['GET', '/api/v1/notifications/inbox'],
            'notification deliveries' => ['GET', '/api/v1/notifications/deliveries'],
            'notification channels' => ['GET', '/api/v1/notifications/channels'],
            'checkout session' => ['POST', '/api/v1/payments/checkout-session'],
            'billing portal' => ['POST', '/api/v1/payments/billing-portal'],
            'subscriptions' => ['POST', '/api/v1/payments/subscriptions'],
            'cancel subscription' => ['DELETE', '/api/v1/payments/subscriptions/sub_1'],
            'coupons' => ['POST', '/api/v1/payments/coupons/validate'],
            'payment modules' => ['GET', '/api/v1/payments/modules'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_the_endpoint_is_gone(string $method, string $uri): void
    {
        $root = User::factory()->role(UserRole::SystemAdmin)->create();
        $token = $root->createToken('Device', TokenAbility::forUser($root))->plainTextToken;

        $this->json($method, $uri, [], ['Authorization' => "Bearer {$token}"])->assertNotFound();
    }
}
