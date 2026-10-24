<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * API tokens used to live until someone revoked them: config/sanctum.php was
 * never published, so `expiration` was null, and a device token or a
 * personal token created without expires_at worked forever.
 */
class SanctumTokenExpiryTest extends TestCase
{
    use RefreshDatabase;

    private const THIRTY_DAYS = 60 * 24 * 30;

    private function deviceLogin(User $user): array
    {
        return $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
            'device_name' => 'Gate tablet',
        ])->assertOk()->json();
    }

    public function test_tokens_expire_after_thirty_days_by_default(): void
    {
        $this->assertSame(self::THIRTY_DAYS, config('sanctum.expiration'));
    }

    public function test_a_device_sign_in_reports_and_stores_its_expiry(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00');
        $user = User::factory()->create();

        $login = $this->deviceLogin($user);

        $this->assertTrue(Carbon::parse($login['expires_at'])->equalTo(Carbon::parse('2026-11-03 12:00:00')));
        $this->assertTrue($user->tokens()->sole()->expires_at->equalTo(Carbon::parse('2026-11-03 12:00:00')));
    }

    public function test_a_device_token_stops_working_once_it_expires(): void
    {
        $user = User::factory()->create();
        $token = $this->deviceLogin($user)['token'];

        $this->withToken($token)->getJson('/api/auth/me')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->travel(31)->days();

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_a_personal_token_without_an_expiry_gets_the_configured_lifetime(): void
    {
        Carbon::setTestNow('2026-10-04 12:00:00');
        $user = User::factory()->create();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/tokens', ['name' => 'Reporting script'])
            ->assertCreated();

        $this->assertTrue($user->tokens()->sole()->expires_at->equalTo(Carbon::parse('2026-11-03 12:00:00')));
    }

    public function test_a_personal_token_may_expire_sooner_but_not_later(): void
    {
        $user = User::factory()->create();
        $tokens = fn (array $data) => $this->actingAs($user, 'sanctum')->postJson('/api/v1/tokens', ['name' => 'Integration', ...$data]);

        $tokens(['expires_at' => now()->addDays(7)->toIso8601String()])->assertCreated();
        $this->assertTrue($user->tokens()->sole()->expires_at->isSameDay(now()->addDays(7)));

        $tokens(['expires_at' => now()->addDays(90)->toIso8601String()])
            ->assertUnprocessable();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_setting_the_lifetime_to_zero_turns_expiry_off(): void
    {
        config(['sanctum.expiration' => 0]);
        $user = User::factory()->create();

        $login = $this->deviceLogin($user);

        $this->assertNull($login['expires_at']);
        $this->assertNull($user->tokens()->sole()->expires_at);
    }

    public function test_expired_tokens_are_pruned_daily(): void
    {
        $scheduled = collect(app(Schedule::class)->events())->map(fn ($event) => $event->command ?? '')->implode("\n");

        $this->assertStringContainsString('sanctum:prune-expired', $scheduled);
    }
}
