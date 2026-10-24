<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\Renter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Sanctum-backed login for the Capacitor shell and handheld scanners: a
 * device token, scoped to what the account is actually allowed to do.
 */
class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Exercises actions behind password.confirm; the prompt itself is PasswordConfirmationTest's.
        $this->confirmPassword();
    }

    public function test_login_issues_a_bearer_token_for_the_device(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'device_name' => "Sade's iPhone",
        ])->assertOk();

        $this->assertNotEmpty($response->json('token'));
        $this->assertSame($user->uid, $response->json('user.uid'));
        $this->assertCount(1, $user->fresh()->tokens);
    }

    public function test_login_rejects_wrong_credentials(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
            'device_name' => 'Test device',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_a_residents_token_gets_base_abilities_only(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $abilities = TokenAbility::forUser($resident);

        $this->assertEqualsCanonicalizing([
            TokenAbility::GatePassRead->value,
            TokenAbility::VisitorsManage->value,
            TokenAbility::MapRead->value,
        ], $abilities);
    }

    public function test_a_security_users_token_also_gets_scan_and_security_abilities(): void
    {
        $guard = User::factory()->role(UserRole::Security)->create();

        $abilities = TokenAbility::forUser($guard);

        $this->assertEqualsCanonicalizing([
            TokenAbility::GatePassRead->value,
            TokenAbility::VisitorsManage->value,
            TokenAbility::MapRead->value,
            TokenAbility::GatePassScan->value,
            TokenAbility::SecurityManage->value,
        ], $abilities);
    }

    public function test_a_token_without_the_scan_ability_cannot_scan_even_for_a_security_user(): void
    {
        $guard = User::factory()->role(UserRole::Security)->create();
        Sanctum::actingAs($guard, [TokenAbility::GatePassRead->value]);

        $this->postJson('/api/gate-pass/validate', ['token' => 'irrelevant'])
            ->assertForbidden();
    }

    public function test_a_token_with_the_scan_ability_passes_the_ability_gate(): void
    {
        $guard = User::factory()->role(UserRole::Security)->create();
        Sanctum::actingAs($guard, [TokenAbility::GatePassScan->value]);

        // The ability gate lets the request through; whatever happens next is
        // the scanner's business, not the token scope's.
        $response = $this->postJson('/api/gate-pass/validate', ['token' => 'irrelevant']);

        $this->assertNotSame(403, $response->getStatusCode());
    }

    public function test_a_base_token_cannot_reach_the_security_only_access_log(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();
        Sanctum::actingAs($resident, [TokenAbility::GatePassRead->value]);

        $this->getJson('/api/access-log')->assertForbidden();
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-password')]);

        $token = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'correct-password',
            'device_name' => 'Test device',
        ])->json('token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertCount(0, $user->fresh()->tokens);
    }

    // ── Accounts that stop being active lose their devices too ──

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('Test device', TokenAbility::forUser($user))->plainTextToken];
    }

    public function test_a_deactivated_accounts_existing_token_is_refused_and_deleted(): void
    {
        $user = User::factory()->role(UserRole::Homeowner)->create();
        $headers = $this->bearer($user);

        $user->update(['status' => 'Inactive', 'deactivated_at' => now()]);

        $this->getJson('/api/auth/me', $headers)->assertUnauthorized();

        $this->assertCount(0, $user->fresh()->tokens);
    }

    public function test_a_deactivated_guard_cannot_scan_with_an_old_token(): void
    {
        $guard = User::factory()->role(UserRole::Security)->create();
        $headers = $this->bearer($guard);

        $guard->update(['status' => 'Inactive', 'deactivated_at' => now()]);

        $this->postJson('/api/gate-pass/validate', ['token' => 'anything'], $headers)->assertUnauthorized();
    }

    public function test_a_token_stops_working_when_a_temporary_stay_ends(): void
    {
        $guest = User::factory()->role(UserRole::TemporaryHomeowner)->create();
        $renter = Renter::create([
            'homeowner_id' => User::factory()->role(UserRole::Homeowner)->create()->id,
            'user_id' => $guest->id,
            'name' => $guest->name,
            'stay_type' => 'Short-term (Airbnb)',
            'lease_start' => now()->subDays(10)->toDateString(),
            'lease_end' => now()->addDay()->toDateString(),
        ]);
        $headers = $this->bearer($guest);

        $renter->update(['lease_end' => now()->subDay()->toDateString()]);

        $this->getJson('/api/auth/me', $headers)
            ->assertUnauthorized()
            ->assertJsonPath('message', fn (string $message) => str_contains($message, 'temporary homeowner access expired'));
    }

    public function test_an_active_accounts_token_keeps_working(): void
    {
        $user = User::factory()->role(UserRole::Homeowner)->create();

        $this->getJson('/api/auth/me', $this->bearer($user))->assertOk();
    }

    public function test_an_administrator_deactivating_an_account_revokes_its_tokens(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();
        $resident = User::factory()->role(UserRole::Homeowner)->create();
        $resident->createToken('Phone');

        $this->actingAs($admin)
            ->patch("/dashboard/directory/users/{$resident->id}", ['status' => 'Inactive'])
            ->assertSessionHasNoErrors();

        $this->assertCount(0, $resident->fresh()->tokens);
    }

    public function test_deactivating_your_own_account_revokes_your_tokens(): void
    {
        $user = User::factory()->create();
        $user->createToken('Phone');

        $this->actingAs($user)->post('/dashboard/deactivation', [
            'password' => 'password',
            'confirm' => '1',
        ]);

        $this->assertCount(0, $user->fresh()->tokens);
    }
}
