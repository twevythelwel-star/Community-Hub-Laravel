<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\DelegatedAccess;
use App\Models\Property;
use App\Models\User;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A delegation issues an estate gate pass, so who may create one, for which
 * property, under whose approval, and when the gate stops honouring it all
 * matter. Each was open: any role could create one for any property, a
 * pending one already had a working pass, typing an email linked a stranger's
 * account, and suspension, expiry or the end of an emergency never reached
 * the gate.
 */
class DelegationSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $homeowner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->homeowner = User::factory()->role(UserRole::Homeowner)->create(['lot' => '14']);
    }

    private function create(User $as, array $overrides = []): TestResponse
    {
        return $this->actingAs($as)->postJson('/dashboard/delegation', $overrides + [
            'name' => 'Mary Smith',
            'email' => 'mary.smith@example.com',
            'relationship' => 'Family member',
            'access_level' => 'Property Delegate',
            'permissions' => ['gate_access'],
            'activation_method' => 'immediate',
        ]);
    }

    private function livePasses(DelegatedAccess $delegation): int
    {
        return $delegation->gatePasses()->whereIn('status', [PassStatus::Active, PassStatus::Issued, PassStatus::CheckedIn])->count();
    }

    public function test_security_and_staff_cannot_create_delegations(): void
    {
        foreach ([UserRole::Security, UserRole::Staff] as $role) {
            $this->create(User::factory()->role($role)->create())->assertForbidden();
        }

        $this->assertSame(0, DelegatedAccess::count());
        $this->create($this->homeowner)->assertCreated();
    }

    public function test_only_your_own_property(): void
    {
        $someoneElses = Property::create([
            'owner_user_id' => User::factory()->role(UserRole::Homeowner)->create()->id,
            'lot_number' => 'Unit 9', 'street_address' => 'Palm Vista Drive',
            'property_code' => 'PROP-9', 'property_type' => 'Villa', 'status' => 'Active',
        ]);

        $this->create($this->homeowner, ['property_id' => $someoneElses->id])->assertForbidden();
        $this->assertSame(0, DelegatedAccess::count());
    }

    public function test_dates_cannot_run_backwards_or_start_in_the_past(): void
    {
        $this->create($this->homeowner, ['starts_at' => now()->subWeek()->toDateString()])->assertJsonValidationErrors('starts_at');
        $this->create($this->homeowner, ['expires_at' => now()->subDay()->toIso8601String()])->assertJsonValidationErrors('expires_at');
    }

    public function test_typing_an_email_does_not_link_an_account_until_its_owner_accepts(): void
    {
        $mary = User::factory()->create(['email' => 'mary.smith@example.com']);

        $this->create($this->homeowner)->assertCreated();
        $delegation = DelegatedAccess::sole();
        $this->assertNull($delegation->delegate_user_id);

        $this->actingAs(User::factory()->create())
            ->postJson("/dashboard/delegation/accept/{$delegation->invite_token}")
            ->assertForbidden();

        $this->actingAs($mary)->postJson("/dashboard/delegation/accept/{$delegation->invite_token}")->assertOk();
        $this->assertSame($mary->id, $delegation->fresh()->delegate_user_id);
        $this->assertNull($delegation->fresh()->invite_token, 'single use');
    }

    public function test_a_delegation_awaiting_approval_has_no_pass_until_an_administrator_approves(): void
    {
        $this->create($this->homeowner, ['access_level' => 'Full Authorized Representative'])->assertCreated();
        $delegation = DelegatedAccess::sole();

        $this->assertSame('pending', $delegation->approval_status);
        $this->assertSame(0, $this->livePasses($delegation));

        $this->actingAs($this->homeowner)->postJson("/dashboard/delegation/{$delegation->id}/approve")->assertForbidden();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->postJson("/dashboard/delegation/{$delegation->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $delegation->fresh()->approval_status);
        $this->assertSame(1, $this->livePasses($delegation));
    }

    public function test_a_rejected_delegation_never_gets_a_pass(): void
    {
        $this->create($this->homeowner, ['access_level' => 'Full Authorized Representative'])->assertCreated();
        $delegation = DelegatedAccess::sole();

        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->postJson("/dashboard/delegation/{$delegation->id}/reject", ['reason' => 'No proof of authority'])
            ->assertOk();

        $this->assertSame('rejected', $delegation->fresh()->approval_status);
        $this->assertSame(0, $this->livePasses($delegation));
    }

    public function test_the_gate_refuses_a_pass_once_its_delegation_is_suspended(): void
    {
        $this->create($this->homeowner)->assertCreated();
        $delegation = DelegatedAccess::sole();
        $pass = $delegation->gatePasses()->sole();
        $guard = User::factory()->role(UserRole::Security)->create();
        $scan = fn () => app(GateScanner::class)->scan(app(GatePassEngine::class)->issueToken($pass->fresh(), GateId::Gate01)['token'], GateId::Gate01, $guard);

        $delegation->update(['status' => 'suspended']);

        $result = $scan();
        $this->assertSame('REJECT', $result['decision']);
        $this->assertStringContainsString('not currently active', $result['report']['primaryReason']);
    }

    public function test_ending_an_emergency_ends_it_at_the_gate(): void
    {
        $this->create($this->homeowner, ['access_level' => 'Emergency Delegate', 'activation_method' => 'emergency_trigger'])->assertCreated();
        $delegation = DelegatedAccess::sole();

        $this->actingAs($this->homeowner)->postJson("/dashboard/delegation/{$delegation->id}/emergency/activate", ['reason' => 'Hospitalised'])->assertOk();
        $this->postJson("/dashboard/delegation/{$delegation->id}/emergency/activate", ['reason' => 'Still hospitalised'])->assertOk();
        $this->assertSame(1, $this->livePasses($delegation), 'one emergency pass, not one per activation');

        $this->postJson("/dashboard/delegation/{$delegation->id}/emergency/deactivate")->assertOk();

        // Emergency-only, so with the emergency over nothing should work at the gate.
        $this->assertSame(0, $this->livePasses($delegation->fresh()));
    }

    public function test_revoking_one_delegation_leaves_the_same_persons_other_delegations_alone(): void
    {
        $mary = User::factory()->create(['email' => 'mary.smith@example.com']);
        $neighbour = User::factory()->role(UserRole::Homeowner)->create(['lot' => '15']);

        foreach ([$this->homeowner, $neighbour] as $grantor) {
            $this->create($grantor)->assertCreated();
            $token = DelegatedAccess::where('grantor_user_id', $grantor->id)->value('invite_token');
            $this->actingAs($mary)->postJson("/dashboard/delegation/accept/{$token}")->assertOk();
        }

        [$mine, $theirs] = [DelegatedAccess::where('grantor_user_id', $this->homeowner->id)->sole(), DelegatedAccess::where('grantor_user_id', $neighbour->id)->sole()];

        $this->actingAs($this->homeowner)->postJson("/dashboard/delegation/{$mine->id}/revoke")->assertOk();

        $this->assertSame(0, $this->livePasses($mine));
        $this->assertSame(1, $this->livePasses($theirs), "the neighbour's delegation is untouched");
    }

    public function test_no_pass_outlives_the_configured_maximum(): void
    {
        $this->create($this->homeowner, ['duration_type' => 'manual_revocation'])->assertCreated();

        $pass = DelegatedAccess::sole()->gatePasses()->sole();
        $this->assertTrue($pass->valid_until->lte(now()->addDays(config('delegation.max_pass_days'))->addMinute()));
    }
}
