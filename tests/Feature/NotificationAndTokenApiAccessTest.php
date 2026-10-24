<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A token is a key to the API. A signed-in account gets no more than its
 * role allows. (The notification API this file also covered was removed;
 * RemovedShowcaseModulesTest pins that it stays gone.)
 */
class NotificationAndTokenApiAccessTest extends TestCase
{
    use RefreshDatabase;

    private function bearer(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('Device', TokenAbility::forUser($user))->plainTextToken];
    }

    public function test_a_resident_cannot_mint_a_token_wider_than_their_role(): void
    {
        $resident = User::factory()->role(UserRole::Homeowner)->create();

        $this->postJson('/api/v1/tokens', [
            'name' => 'Escalated',
            'abilities' => [TokenAbility::SecurityManage->value],
        ], $this->bearer($resident))->assertStatus(422);

        $this->assertFalse($resident->fresh()->tokens->contains(fn ($token) => $token->can(TokenAbility::SecurityManage->value)));
    }

    public function test_a_token_may_be_narrower_than_its_account(): void
    {
        $guard = User::factory()->role(UserRole::Security)->create();

        $this->postJson('/api/v1/tokens', [
            'name' => 'Scanner only',
            'abilities' => [TokenAbility::GatePassScan->value],
        ], $this->bearer($guard))->assertCreated();
    }
}
