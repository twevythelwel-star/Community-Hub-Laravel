<?php

namespace Tests\Feature;

use App\Enums\DenyReason;
use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Exceptions\ScanNotConfirmable;
use App\Models\AccessLogEntry;
use App\Models\BlocklistEntry;
use App\Models\GatePass;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scanner validates signature, pass ID, person, profile, community,
 * property, gate, zone, start/end time, revocation, replay and current status,
 * and answers CHECK_IN, CHECK_OUT or REJECT.
 */
class GateScannerTest extends TestCase
{
    use RefreshDatabase;

    private GatePassEngine $engine;

    private GateScanner $scanner;

    private User $guard;

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday mid-morning: inside every shift in config/gatepass.php.
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00'));

        $this->engine = app(GatePassEngine::class);
        $this->scanner = app(GateScanner::class);
        $this->guard = User::factory()->role(UserRole::Security)->create();
    }

    private function guestPass(PassCategory $category = PassCategory::Visitor, string $type = 'One-time'): GatePass
    {
        $visitor = Visitor::factory()->create(['type' => $type, 'expected_at' => now()->addMinutes(30)]);

        return $this->engine->issueGuestPass($visitor, $visitor->homeowner, $category);
    }

    private function scanNow(GatePass $pass, GateId $gate = GateId::Gate01): array
    {
        return $this->scanner->scan($this->engine->issueToken($pass->fresh())['token'], $gate, $this->guard);
    }

    /** Moves to the next token slot, so the next scan has a fresh nonce. */
    private function nextSlot(): void
    {
        $this->travel((int) config('gatepass.window_seconds', 30) + 1)->seconds();
    }

    private function assertRejectedFor(DenyReason $reason, array $result): void
    {
        $this->assertSame('REJECT', $result['decision'], $result['report']['primaryReason']);
        $this->assertSame($reason->value, $result['report']['denyReason']);
        $this->assertNull($result['scanId']);
    }

    // ── Decisions ──

    public function test_an_issued_visitor_pass_is_told_to_check_in_and_every_check_is_ticked(): void
    {
        $result = $this->scanNow($this->guestPass());

        $this->assertSame('CHECK_IN', $result['decision']);
        $this->assertNotNull($result['scanId']);

        foreach (['cryptographicSignature', 'passRegistered', 'personMatch', 'profileMatch', 'communityMatch',
            'propertyMatch', 'physicalGateAuth', 'zoneAuth', 'validityPeriod', 'notRevoked', 'replayFree', 'statusEligible'] as $check) {
            $this->assertTrue($result['report']['checks'][$check], $check);
        }
    }

    public function test_the_full_visit_check_in_then_check_out_then_no_second_entry(): void
    {
        $pass = $this->guestPass();

        $in = $this->scanNow($pass);
        $this->scanner->confirm($in['scanId'], $this->guard);
        $this->assertSame(PassStatus::CheckedIn, $pass->fresh()->status);
        $this->assertSame('Checked In', $pass->visitor->fresh()->status->value);

        $this->nextSlot();
        $out = $this->scanNow($pass);
        $this->assertSame('CHECK_OUT', $out['decision']);
        $this->scanner->confirm($out['scanId'], $this->guard);
        $this->assertSame(PassStatus::CheckedOut, $pass->fresh()->status);

        $this->nextSlot();
        $this->assertRejectedFor(DenyReason::PassNotUsable, $this->scanNow($pass));
    }

    public function test_a_multi_entry_pass_can_come_back_in(): void
    {
        $pass = $this->guestPass(type: 'Recurring');

        foreach (['CHECK_IN', 'CHECK_OUT', 'CHECK_IN'] as $expected) {
            $result = $this->scanNow($pass);
            $this->assertSame($expected, $result['decision']);
            $this->scanner->confirm($result['scanId'], $this->guard);
            $this->nextSlot();
        }
    }

    public function test_residents_alternate_in_and_out(): void
    {
        $pass = $this->engine->issuePassFor(User::factory()->create());

        $in = $this->scanNow($pass);
        $this->assertSame('CHECK_IN', $in['decision']);
        $this->scanner->confirm($in['scanId'], $this->guard);

        $this->nextSlot();
        $this->assertSame('CHECK_OUT', $this->scanNow($pass)['decision']);
    }

    // ── Each check ──

    public function test_signature_trailing_segment_must_match(): void
    {
        $issued = $this->engine->issueToken($this->guestPass());
        $forged = str_replace($issued['payload']['sig'], str_repeat('0', 64), $issued['token']);

        $this->assertRejectedFor(DenyReason::SignatureMismatch, $this->scanner->scan($forged, GateId::Gate01, $this->guard));
    }

    public function test_pass_id_must_be_on_the_registry(): void
    {
        $pass = $this->guestPass();
        $token = $this->engine->issueToken($pass)['token'];
        $pass->delete();

        $this->assertRejectedFor(DenyReason::UnknownPass, $this->scanner->scan($token, GateId::Gate01, $this->guard));
    }

    public function test_person_must_match_the_registry(): void
    {
        $pass = $this->guestPass();
        $token = $this->engine->issueToken($pass)['token'];
        $pass->update(['holder_name' => 'Someone Else']);

        $this->assertRejectedFor(DenyReason::ClaimMismatch, $this->scanner->scan($token, GateId::Gate01, $this->guard));
    }

    public function test_profile_must_match_the_registry(): void
    {
        $pass = $this->guestPass();
        $token = $this->engine->issueToken($pass)['token'];
        $pass->update(['category' => PassCategory::Contractor]);

        $this->assertRejectedFor(DenyReason::ClaimMismatch, $this->scanner->scan($token, GateId::Gate01, $this->guard));
    }

    public function test_community(): void
    {
        $token = $this->engine->issueToken($this->guestPass())['token'];
        config(['gatepass.default_community_id' => 'CID-ANOTHER-ESTATE']);

        $this->assertRejectedFor(DenyReason::UnauthorizedCommunity, $this->scanner->scan($token, GateId::Gate01, $this->guard));
    }

    public function test_property_must_match_the_registry(): void
    {
        $pass = $this->guestPass();
        $token = $this->engine->issueToken($pass)['token'];
        $pass->update(['property' => 'Lot 999, Elsewhere']);

        $this->assertRejectedFor(DenyReason::ClaimMismatch, $this->scanner->scan($token, GateId::Gate01, $this->guard));
    }

    public function test_gate_visitors_use_the_main_gate(): void
    {
        $this->assertRejectedFor(DenyReason::UnauthorizedGate, $this->scanNow($this->guestPass(), GateId::Gate02));
    }

    public function test_zone_must_be_served_by_the_gate(): void
    {
        // A resident whose zone the service gate does not admit into.
        $pass = $this->engine->issuePassFor(User::factory()->create());
        $pass->update(['access_zone' => 'ZONE-HOST-RESIDENCE']);

        $this->assertRejectedFor(DenyReason::UnauthorizedZone, $this->scanNow($pass, GateId::Gate02));
    }

    public function test_start_time(): void
    {
        $visitor = Visitor::factory()->create(['type' => 'One-time', 'expected_at' => now()->addDays(2)]);
        $pass = $this->engine->issueGuestPass($visitor, $visitor->homeowner);

        $this->assertRejectedFor(DenyReason::OutsideValidity, $this->scanNow($pass));
    }

    public function test_end_time(): void
    {
        $pass = $this->guestPass();
        $pass->update(['valid_until' => now()->subMinute()]);

        $this->assertRejectedFor(DenyReason::OutsideValidity, $this->scanNow($pass));
    }

    public function test_revocation(): void
    {
        $pass = $this->guestPass();
        $token = $this->engine->issueToken($pass)['token'];
        $pass->revoke($this->guard, 'Host withdrew the invitation');

        $this->assertRejectedFor(DenyReason::PassRevoked, $this->scanner->scan($token, GateId::Gate01, $this->guard));
    }

    public function test_replay(): void
    {
        $token = $this->engine->issueToken($this->guestPass())['token'];

        $this->scanner->scan($token, GateId::Gate01, $this->guard);

        $this->assertRejectedFor(DenyReason::ReplayAttack, $this->scanner->scan($token, GateId::Gate01, $this->guard));
    }

    public function test_current_status_a_contractor_awaiting_approval(): void
    {
        $this->assertRejectedFor(DenyReason::PassNotUsable, $this->scanNow($this->guestPass(PassCategory::Contractor)));
    }

    public function test_current_status_suspended(): void
    {
        $pass = $this->guestPass();
        $pass->transitionTo(PassStatus::Suspended, $this->guard, 'Under review');

        $this->assertRejectedFor(DenyReason::PassNotUsable, $this->scanNow($pass));
    }

    public function test_rotation_supersedes_earlier_codes(): void
    {
        $pass = $this->engine->issuePassFor(User::factory()->create());
        $token = $this->engine->issueToken($pass)['token'];
        $this->engine->rotateVisualIdentity($pass);

        $this->assertRejectedFor(DenyReason::PassSuperseded, $this->scanner->scan($token, GateId::Gate01, $this->guard));
    }

    public function test_a_blocklisted_visitor_is_refused_even_with_a_valid_pass(): void
    {
        $pass = $this->guestPass();
        BlocklistEntry::create([
            'name' => $pass->holder_name,
            'reason' => 'Trespass',
            'date_added' => now(),
            'added_by' => $this->guard->display_name,
        ]);

        $this->assertRejectedFor(DenyReason::HolderBlocked, $this->scanNow($pass));
    }

    public function test_colour_is_never_the_credential(): void
    {
        // Changing the stored colour alone changes nothing about access.
        $pass = $this->guestPass();
        $pass->update(['color_variant' => 'vis_magenta']);

        $this->assertSame('CHECK_IN', $this->scanNow($pass)['decision']);
    }

    // ── Leaving is always possible ──

    public function test_a_contractor_can_leave_after_hours_and_after_the_pass_window(): void
    {
        $pass = $this->guestPass(PassCategory::Contractor);
        $this->engine->approve($pass, $this->guard);

        $in = $this->scanNow($pass);
        $this->scanner->confirm($in['scanId'], $this->guard);

        // Sunday night, outside contractor hours and outside the pass window.
        $this->travelTo(CarbonImmutable::parse('2026-10-11 22:00:00'));

        $this->assertSame('CHECK_OUT', $this->scanNow($pass)['decision']);
    }

    // ── Confirming ──

    public function test_a_decision_can_only_be_confirmed_once(): void
    {
        $result = $this->scanNow($this->guestPass());
        $this->scanner->confirm($result['scanId'], $this->guard);

        $this->expectException(ScanNotConfirmable::class);
        $this->scanner->confirm($result['scanId'], $this->guard);
    }

    public function test_only_the_guard_who_scanned_can_confirm(): void
    {
        $pass = $this->guestPass();
        $result = $this->scanNow($pass);

        try {
            $this->scanner->confirm($result['scanId'], User::factory()->role(UserRole::Security)->create());
            $this->fail('Another guard confirmed the scan.');
        } catch (ScanNotConfirmable) {
            $this->assertSame(PassStatus::Issued, $pass->fresh()->status);
        }
    }

    public function test_a_decision_lapses(): void
    {
        $result = $this->scanNow($this->guestPass());
        $this->travel((int) config('gatepass.scan_confirm_seconds') + 1)->seconds();

        $this->expectException(ScanNotConfirmable::class);
        $this->scanner->confirm($result['scanId'], $this->guard);
    }

    public function test_a_pass_revoked_between_scan_and_confirm_is_not_admitted(): void
    {
        $pass = $this->guestPass();
        $result = $this->scanNow($pass);
        $pass->revoke($this->guard, 'Revoked while at the gate');

        try {
            $this->scanner->confirm($result['scanId'], $this->guard);
            $this->fail('A revoked pass was checked in.');
        } catch (ScanNotConfirmable) {
            $this->assertSame(PassStatus::Revoked, $pass->fresh()->status);
        }
    }

    public function test_the_guard_can_refuse_after_inspection_and_it_is_logged(): void
    {
        $pass = $this->guestPass();
        $result = $this->scanNow($pass);

        $outcome = $this->scanner->confirm($result['scanId'], $this->guard, false, 'Photo ID did not match');

        $this->assertSame('REFUSED', $outcome['action']);
        $this->assertSame(PassStatus::Issued, $pass->fresh()->status);
        $this->assertStringContainsString('Photo ID did not match', AccessLogEntry::latest('id')->first()->deny_reason);
    }

    // ── Without a code ──

    public function test_manual_check_in_applies_the_same_pass_rules(): void
    {
        $pass = $this->guestPass(PassCategory::Contractor);

        $this->expectException(ScanNotConfirmable::class);
        $this->scanner->manualCheckIn($pass, $this->guard, GateId::Gate01);
    }
}
