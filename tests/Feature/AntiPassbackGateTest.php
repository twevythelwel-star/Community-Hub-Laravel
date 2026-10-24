<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\AccessLogEntry;
use App\Models\GatePass;
use App\Models\GatePassTransition;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Anti-Passback Security Engine Test Suite.
 *
 * Verifies that:
 * 1. An initial entry scan admits the passholder and records CheckedIn status.
 * 2. An attempt to enter again without departing flags ⚠️ Already Checked In (Anti-Passback violation).
 * 3. An exit scan (direction: 'out') records departure and sets CheckedOut status.
 * 4. Re-entry after departure is permitted.
 * 5. Security officers can investigate and authorize an override admission with audit notes.
 * 6. Gate Scanner HTTP API cleanly handles passback rejection and override flows.
 */
class AntiPassbackGateTest extends TestCase
{
    use RefreshDatabase;

    private GatePassEngine $engine;

    private GateScanner $scanner;

    private User $guard;

    private User $homeowner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00'));

        $this->engine = app(GatePassEngine::class);
        $this->scanner = app(GateScanner::class);
        $this->guard = User::factory()->role(UserRole::Security)->create(['name' => 'Officer Rodriguez']);
        $this->homeowner = User::factory()->role(UserRole::Homeowner)->create(['name' => 'John Smith']);
    }

    private function createPass(bool $singleEntry = false): GatePass
    {
        $visitor = Visitor::factory()->create([
            'homeowner_id' => $this->homeowner->id,
            'name' => 'Marcus Vance',
            'type' => $singleEntry ? 'One-time' : 'Recurring',
            'expected_at' => now()->addMinutes(15),
        ]);

        return $this->engine->issueGuestPass($visitor, $this->homeowner, PassCategory::Visitor);
    }

    public function test_first_entry_scan_admits_passholder_and_sets_checked_in(): void
    {
        $pass = $this->createPass();
        $token = $this->engine->issueToken($pass)['token'];

        $result = $this->scanner->scan($token, GateId::Gate01, $this->guard, 'Digital Pass', 'in');

        $this->assertSame('CHECK_IN', $result['decision']);
        $this->assertNotNull($result['scanId']);
        $this->assertFalse($result['isPassbackViolation'] ?? false);

        // Confirm admission at barrier
        $confirm = $this->scanner->confirm($result['scanId'], $this->guard);
        $this->assertSame('CHECK_IN', $confirm['action']);

        $pass->refresh();
        $this->assertSame(PassStatus::CheckedIn, $pass->status);
        $this->assertNotNull($pass->checked_in_at);
    }

    public function test_duplicate_entry_attempt_without_leaving_is_rejected_as_already_checked_in(): void
    {
        $pass = $this->createPass();
        $token1 = $this->engine->issueToken($pass)['token'];

        // Initial check-in
        $in = $this->scanner->scan($token1, GateId::Gate01, $this->guard, 'Digital Pass', 'in');
        $this->scanner->confirm($in['scanId'], $this->guard);
        $pass->refresh();
        $this->assertSame(PassStatus::CheckedIn, $pass->status);

        // Second entry attempt with new rolling token or same pass at Inbound gate
        $this->travel(35)->seconds(); // Next 30s token slot
        $token2 = $this->engine->issueToken($pass)['token'];

        $duplicateScan = $this->scanner->scan($token2, GateId::Gate01, $this->guard, 'Digital Pass', 'in');

        // Must be rejected as Anti-Passback violation
        $this->assertSame('REJECT', $duplicateScan['decision']);
        $this->assertNull($duplicateScan['scanId']);
        $this->assertTrue($duplicateScan['isPassbackViolation']);
        $this->assertTrue($duplicateScan['canOverride']);
        $this->assertSame('ALREADY_CHECKED_IN', $duplicateScan['report']['denyReason']);
        $this->assertStringContainsString('⚠️ Already Checked In', $duplicateScan['report']['primaryReason']);
        $this->assertStringContainsString('Duplicate entry without prior departure prohibited', $duplicateScan['report']['primaryReason']);

        // Verify access log recorded the anti-passback rejection
        $log = AccessLogEntry::where('pass_id', $pass->pass_id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('REJECTED', $log->result);
        $this->assertStringContainsString('Already Checked In', $log->deny_reason);
    }

    public function test_exit_scan_checks_out_passholder_and_clears_presence(): void
    {
        $pass = $this->createPass();
        $token1 = $this->engine->issueToken($pass)['token'];

        // Check in
        $in = $this->scanner->scan($token1, GateId::Gate01, $this->guard, 'Digital Pass', 'in');
        $this->scanner->confirm($in['scanId'], $this->guard);
        $this->assertSame(PassStatus::CheckedIn, $pass->fresh()->status);

        // Exit scan at Outbound gate
        $this->travel(35)->seconds();
        $token2 = $this->engine->issueToken($pass)['token'];
        $out = $this->scanner->scan($token2, GateId::Gate01, $this->guard, 'Digital Pass', 'out');

        $this->assertSame('CHECK_OUT', $out['decision']);
        $this->assertNotNull($out['scanId']);

        $confirmOut = $this->scanner->confirm($out['scanId'], $this->guard);
        $this->assertSame('CHECK_OUT', $confirmOut['action']);

        $pass->refresh();
        $this->assertSame(PassStatus::CheckedOut, $pass->status);
    }

    public function test_subsequent_entry_after_prior_departure_is_permitted_for_multi_entry_pass(): void
    {
        $pass = $this->createPass(singleEntry: false);

        // In
        $in1 = $this->scanner->scan($this->engine->issueToken($pass)['token'], GateId::Gate01, $this->guard, 'Digital Pass', 'in');
        $this->scanner->confirm($in1['scanId'], $this->guard);
        $this->assertSame(PassStatus::CheckedIn, $pass->fresh()->status);

        // Out
        $this->travel(35)->seconds();
        $out = $this->scanner->scan($this->engine->issueToken($pass)['token'], GateId::Gate01, $this->guard, 'Digital Pass', 'out');
        $this->scanner->confirm($out['scanId'], $this->guard);
        $this->assertSame(PassStatus::CheckedOut, $pass->fresh()->status);

        // Return (In again)
        $this->travel(35)->seconds();
        $in2 = $this->scanner->scan($this->engine->issueToken($pass)['token'], GateId::Gate01, $this->guard, 'Digital Pass', 'in');
        $this->assertSame('CHECK_IN', $in2['decision']);
        $this->assertNotNull($in2['scanId']);

        $this->scanner->confirm($in2['scanId'], $this->guard);
        $this->assertSame(PassStatus::CheckedIn, $pass->fresh()->status);
    }

    public function test_security_officer_can_investigate_and_override_anti_passback(): void
    {
        $pass = $this->createPass();

        // Check In
        $in = $this->scanner->scan($this->engine->issueToken($pass)['token'], GateId::Gate01, $this->guard, 'Digital Pass', 'in');
        $this->scanner->confirm($in['scanId'], $this->guard);
        $firstCheckInAt = $pass->fresh()->checked_in_at;

        // Travel forward 15 minutes
        $this->travel(15)->minutes();

        // Attempt entry again -> Officer investigates and enters override
        $overrideScan = $this->scanner->scan(
            token: $this->engine->issueToken($pass)['token'],
            gate: GateId::Gate01,
            guard: $this->guard,
            method: 'Digital Pass',
            direction: 'in',
            overridePassback: true,
            overrideReason: 'Resident tailgated through exit gate without scan; visual ID confirmed.'
        );

        $this->assertSame('CHECK_IN', $overrideScan['decision']);
        $this->assertNotNull($overrideScan['scanId']);
        $this->assertTrue($overrideScan['report']['isPassbackOverride']);
        $this->assertStringContainsString('Security Override: Anti-passback cleared by Officer Rodriguez', $overrideScan['report']['primaryReason']);

        // Guard confirms admission
        $confirm = $this->scanner->confirm($overrideScan['scanId'], $this->guard);
        $this->assertSame('CHECK_IN', $confirm['action']);

        $pass->refresh();
        $this->assertSame(PassStatus::CheckedIn, $pass->status);
        $this->assertTrue($pass->checked_in_at->isAfter($firstCheckInAt));

        // Verify transition record logged override
        $transition = GatePassTransition::where('gate_pass_id', $pass->id)->latest('id')->first();
        $this->assertNotNull($transition);
        $this->assertStringContainsString('Security override', $transition->reason);
        $this->assertStringContainsString('Resident tailgated through exit gate', $transition->reason);
    }

    public function test_gate_scanner_http_api_supports_passback_rejection_and_officer_override(): void
    {
        $pass = $this->createPass();
        $token = $this->engine->issueToken($pass)['token'];

        // 1. Initial HTTP Scan Entry
        $response1 = $this->actingAs($this->guard)->postJson('/dashboard/gate-pass/scan', [
            'token' => $token,
            'gate' => 'GATE-01',
            'direction' => 'in',
        ]);

        $response1->assertOk()
            ->assertJsonPath('decision', 'CHECK_IN');

        $scanId = $response1->json('scanId');

        $this->actingAs($this->guard)->postJson("/dashboard/gate-pass/scans/{$scanId}/confirm", [
            'accept' => true,
        ])->assertOk();

        // 2. Duplicate Entry via HTTP API -> Anti-Passback Rejection
        $this->travel(35)->seconds();
        $token2 = $this->engine->issueToken($pass)['token'];

        $response2 = $this->actingAs($this->guard)->postJson('/dashboard/gate-pass/scan', [
            'token' => $token2,
            'gate' => 'GATE-01',
            'direction' => 'in',
        ]);

        $response2->assertOk()
            ->assertJsonPath('decision', 'REJECT')
            ->assertJsonPath('isPassbackViolation', true)
            ->assertJsonPath('canOverride', true)
            ->assertJsonPath('report.denyReason', 'ALREADY_CHECKED_IN');

        // 3. Officer Override via HTTP API (using fresh rolling token or pass ID)
        $this->travel(35)->seconds();
        $token3 = $this->engine->issueToken($pass)['token'];

        $response3 = $this->actingAs($this->guard)->postJson('/dashboard/gate-pass/scan', [
            'token' => $token3,
            'gate' => 'GATE-01',
            'direction' => 'in',
            'override_passback' => true,
            'override_reason' => 'Officer inspection verified credential legitimate.',
        ]);

        $response3->assertOk()
            ->assertJsonPath('decision', 'CHECK_IN')
            ->assertJsonPath('report.isPassbackOverride', true);

        $overrideScanId = $response3->json('scanId');
        $this->assertNotNull($overrideScanId);

        $this->actingAs($this->guard)->postJson("/dashboard/gate-pass/scans/{$overrideScanId}/confirm", [
            'accept' => true,
        ])->assertOk();
    }

    public function test_guard_can_override_anti_passback_using_pass_id_directly(): void
    {
        $pass = $this->createPass();

        // 1. Initial check in
        $in = $this->scanner->scan($this->engine->issueToken($pass)['token'], GateId::Gate01, $this->guard, 'Digital Pass', 'in');
        $this->scanner->confirm($in['scanId'], $this->guard);
        $this->assertSame(PassStatus::CheckedIn, $pass->fresh()->status);

        // 2. Guard overrides using direct Pass ID (GP-XXXX) in next slot
        $this->travel(35)->seconds();
        $overrideResponse = $this->actingAs($this->guard)->postJson('/dashboard/gate-pass/scan', [
            'token' => $pass->pass_id,
            'gate' => 'GATE-01',
            'direction' => 'in',
            'override_passback' => true,
            'override_reason' => 'Homeowner called security desk to confirm spouse re-entry.',
        ]);

        $overrideResponse->assertOk()
            ->assertJsonPath('decision', 'CHECK_IN')
            ->assertJsonPath('report.isPassbackOverride', true);

        $scanId = $overrideResponse->json('scanId');
        $this->assertNotNull($scanId);

        $this->actingAs($this->guard)->postJson("/dashboard/gate-pass/scans/{$scanId}/confirm", [
            'accept' => true,
        ])->assertOk();

        $this->assertSame(PassStatus::CheckedIn, $pass->fresh()->status);
    }
}
