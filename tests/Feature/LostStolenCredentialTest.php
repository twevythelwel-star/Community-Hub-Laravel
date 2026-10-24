<?php

namespace Tests\Feature;

use App\Enums\DenyReason;
use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\GatePass;
use App\Models\Household;
use App\Models\HouseholdMember;
use App\Models\User;
use App\Services\Credentials\WalletCredentialCode;
use App\Services\DigitalAccessWalletService;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LostStolenCredentialTest extends TestCase
{
    use RefreshDatabase;

    private GatePassEngine $engine;

    private GateScanner $scanner;

    private DigitalAccessWalletService $walletService;

    private User $homeowner;

    private User $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00'));

        $this->engine = app(GatePassEngine::class);
        $this->scanner = app(GateScanner::class);
        $this->walletService = app(DigitalAccessWalletService::class);

        $this->guard = User::factory()->role(UserRole::Security)->create([
            'name' => 'Officer Davis',
            'display_name' => 'Officer Davis',
        ]);

        $this->homeowner = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'John Smith',
            'display_name' => 'John Smith',
        ]);
    }

    public function test_reporting_credential_lost_immediately_revokes_old_pass_and_generates_replacement(): void
    {
        // 1. Initial active pass
        $oldPass = $this->engine->issuePassFor($this->homeowner);
        $this->assertTrue($oldPass->isActive());
        $this->assertEquals(PassStatus::Active, $oldPass->status);
        $this->assertEquals(1, $oldPass->rotation_seq);

        // Pre-generate a token representing a screenshot taken while pass was active
        $screenshotToken = $this->engine->issueToken($oldPass)['token'];

        // 2. One button: Report Credential Lost
        $response = $this->actingAs($this->homeowner)->postJson('/dashboard/wallet/report-lost', [
            'pass_id' => $oldPass->pass_id,
            'reason' => 'Phone stolen on commute. Immediate revocation required.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('revoked_pass.pass_id', $oldPass->pass_id)
            ->assertJsonPath('revoked_pass.status', 'REVOKED');

        // 3. Verify old pass is permanently REVOKED
        $oldPass->refresh();
        $this->assertTrue($oldPass->isRevoked());
        $this->assertFalse($oldPass->isActive());
        $this->assertEquals(PassStatus::Revoked, $oldPass->status);
        $this->assertNotNull($oldPass->revoked_at);
        $this->assertEquals($this->homeowner->id, $oldPass->revoked_by);
        $this->assertStringContainsString('Phone stolen', $oldPass->revocation_reason);

        // 4. Verify replacement pass is ACTIVE and has incremented rotation sequence
        $replacementPassData = $response->json('replacement_pass');
        $this->assertNotEquals($oldPass->pass_id, $replacementPassData['pass_id']);
        $this->assertTrue($replacementPassData['is_active']);
        $this->assertEquals('ACTIVE', $replacementPassData['status']);

        $newPass = GatePass::where('pass_id', $replacementPassData['pass_id'])->firstOrFail();
        $this->assertTrue($newPass->isActive());
        $this->assertEquals(2, $newPass->rotation_seq);
        $this->assertNotEquals($oldPass->offline_pin, $newPass->offline_pin);
        $this->assertEquals($oldPass->pass_id, $newPass->metadata['replaces_pass_id']);

        // 5. Test that the leaked screenshot of the old QR code is REJECTED at the gate scanner!
        $scanResult = $this->scanner->scan($screenshotToken, GateId::Gate01, $this->guard);
        $this->assertEquals('REJECT', $scanResult['decision']);
        $this->assertEquals(DenyReason::PassRevoked->value, $scanResult['report']['denyReason']);
        $this->assertNull($scanResult['scanId']);

        // The old wallet pass (Apple / Google, or its NFC message) is refused as revoked
        $walletScanResult = $this->scanner->scan(app(WalletCredentialCode::class)->for($oldPass), GateId::Gate01, $this->guard);
        $this->assertEquals('REJECT', $walletScanResult['decision']);
        $this->assertEquals(DenyReason::PassRevoked->value, $walletScanResult['report']['denyReason']);
        $this->assertNull($walletScanResult['scanId']);

        // And a bare pass ID never opens the gate
        $rawScanResult = $this->scanner->scan($oldPass->pass_id, GateId::Gate01, $this->guard);
        $this->assertEquals('REJECT', $rawScanResult['decision']);
        $this->assertNull($rawScanResult['scanId']);

        // 6. Test that the NEW replacement pass QR code is GRANTED access!
        $newScanToken = $this->engine->issueToken($newPass)['token'];
        $newScanResult = $this->scanner->scan($newScanToken, GateId::Gate01, $this->guard);
        $this->assertEquals('CHECK_IN', $newScanResult['decision']);
        $this->assertNotNull($newScanResult['scanId']);
        $this->assertTrue($newScanResult['report']['checks']['notRevoked']);
        $this->assertTrue($newScanResult['report']['checks']['cryptographicSignature']);
    }

    public function test_reporting_household_member_credential_lost_updates_member_link(): void
    {
        $household = Household::create([
            'primary_homeowner_id' => $this->homeowner->id,
            'name' => 'Smith Family Residence',
            'property_number' => 'Lot 14B',
        ]);

        $oldMemberPass = GatePass::create([
            'pass_id' => 'GP-HOM-ALEX-1001',
            'user_id' => $this->homeowner->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => 'Alex Smith',
            'property' => 'Lot 14B',
            'status' => PassStatus::Active,
            'rotation_seq' => 1,
            'offline_pin' => '123456',
        ]);

        $member = HouseholdMember::create([
            'household_id' => $household->id,
            'name' => 'Alex Smith',
            'role_in_household' => 'child',
            'relationship_label' => 'Son (High School Student)',
            'pass_category' => PassCategory::Homeowner->value,
            'gate_pass_id' => $oldMemberPass->id,
            'status' => 'active',
        ]);

        $oldScreenshotToken = $this->engine->issueToken($oldMemberPass)['token'];

        // Homeowner reports Alex's pass lost
        $response = $this->actingAs($this->homeowner)->postJson('/dashboard/wallet/report-lost', [
            'pass_id' => $oldMemberPass->pass_id,
            'reason' => 'Alex lost student backpack with printed gate pass QR code.',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        // Verify old pass is revoked
        $oldMemberPass->refresh();
        $this->assertTrue($oldMemberPass->isRevoked());

        // Verify household member is now linked to new replacement pass
        $member->refresh();
        $this->assertNotEquals($oldMemberPass->id, $member->gate_pass_id);

        $replacementPass = GatePass::findOrFail($member->gate_pass_id);
        $this->assertTrue($replacementPass->isActive());
        $this->assertEquals('Alex Smith', $replacementPass->holder_name);
        $this->assertEquals(2, $replacementPass->rotation_seq);

        // Old screenshot is rejected with PassRevoked
        $scanResult = $this->scanner->scan($oldScreenshotToken, GateId::Gate01, $this->guard);
        $this->assertEquals('REJECT', $scanResult['decision']);
        $this->assertEquals(DenyReason::PassRevoked->value, $scanResult['report']['denyReason']);
        $this->assertNull($scanResult['scanId']);

        // New pass is accepted
        $newToken = $this->engine->issueToken($replacementPass)['token'];
        $newResult = $this->scanner->scan($newToken, GateId::Gate01, $this->guard);
        $this->assertEquals('CHECK_IN', $newResult['decision']);
        $this->assertNotNull($newResult['scanId']);
        $this->assertTrue($newResult['report']['checks']['notRevoked']);
    }

    public function test_unauthorized_user_cannot_revoke_strangers_pass(): void
    {
        $stranger = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'Bob Jones',
            'display_name' => 'Bob Jones',
        ]);

        $strangerPass = $this->engine->issuePassFor($stranger);

        $response = $this->actingAs($this->homeowner)->postJson('/dashboard/wallet/report-lost', [
            'pass_id' => $strangerPass->pass_id,
        ]);

        $response->assertForbidden();

        $strangerPass->refresh();
        $this->assertTrue($strangerPass->isActive());
    }
}
