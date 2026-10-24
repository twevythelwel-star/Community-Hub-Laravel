<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Models\DelegatedAccess;
use App\Models\GatePass;
use App\Models\User;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringAccessDec31Test extends TestCase
{
    use RefreshDatabase;

    public function test_homeowner_authorizes_domestic_worker_with_persistent_qr_valid_until_dec_31(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner, 'name' => 'Alexander Hamilton']);

        // Set test date to Oct 5, 2026
        Carbon::setTestNow('2026-10-05 08:30:00');

        $response = $this->actingAs($homeowner)->postJson('/dashboard/delegation', [
            'name' => 'Clara Higgins',
            'email' => 'clara.higgins@cleaners.com',
            'phone' => '+18765550211',
            'relationship' => 'Domestic Worker',
            'access_level' => 'Domestic Staff',
            'authorization_type' => 'domestic_staff',
            'duration_type' => 'recurring',
            'starts_at' => '2026-10-05',
            'access_rules' => [
                'gate_access' => true,
                'allowed_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'entry_start_time' => '08:00',
                'entry_end_time' => '17:00',
                'requires_id_verification' => false,
            ],
        ]);

        $response->assertCreated();

        $delegation = DelegatedAccess::where('email', 'clara.higgins@cleaners.com')->first();
        $this->assertNotNull($delegation);
        $this->assertEquals('recurring', $delegation->duration_type);
        $this->assertEquals('Domestic Worker', $delegation->relationship);

        // Verify delegation expiration defaulted to December 31 23:59:59 of current year
        $this->assertEquals('2026-12-31 23:59:59', $delegation->expires_at->toDateTimeString());

        // Single persistent QR pass created
        $pass = $delegation->gatePasses()->first();
        $this->assertNotNull($pass);
        $this->assertEquals(PassStatus::Active, $pass->status);
        $this->assertEquals('2026-12-31 23:59:59', $pass->valid_until->toDateTimeString());
        $this->assertEquals(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], $pass->metadata['access_rules']['allowed_days']);
        $this->assertEquals('08:00', $pass->metadata['access_rules']['entry_start_time']);
        $this->assertEquals('17:00', $pass->metadata['access_rules']['entry_end_time']);

        // Check durationLabel
        $this->assertEquals('Mon–Fri Recurring', $delegation->durationLabel());
    }

    public function test_caregiver_recurring_schedule_allows_scan_during_shift_and_rejects_outside_shift(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);
        Carbon::setTestNow('2026-10-05 07:00:00'); // Monday

        $response = $this->actingAs($homeowner)->postJson('/dashboard/delegation', [
            'name' => 'Nurse Joyce Walker',
            'email' => 'joyce.care@healthcare.org',
            'phone' => '+18765550212',
            'relationship' => 'Caregiver',
            'access_level' => 'Caregiver',
            'duration_type' => 'recurring',
            'starts_at' => '2026-10-05',
            'access_rules' => [
                'gate_access' => true,
                'allowed_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'entry_start_time' => '08:00',
                'entry_end_time' => '17:00',
            ],
        ]);

        $response->assertCreated();
        $delegation = DelegatedAccess::where('email', 'joyce.care@healthcare.org')->first();
        $pass = $delegation->gatePasses()->first();
        $guard = User::factory()->create(['role' => UserRole::Security]);
        $engine = app(GatePassEngine::class);
        $scanner = app(GateScanner::class);

        // 1. Scan at 09:30 AM on Monday (Inside Mon–Fri 08:00–17:00) -> CHECK_IN
        Carbon::setTestNow('2026-10-05 09:30:00');
        $token = $engine->issueToken($pass, GateId::Gate01)['token'];
        $result = $scanner->scan($token, GateId::Gate01, $guard);

        $this->assertEquals('CHECK_IN', $result['decision']);
        $scanner->confirm($result['scanId'], $guard);
        $this->assertEquals(PassStatus::CheckedIn, $pass->fresh()->status);

        // Guard checks her out at 16:45 PM
        Carbon::setTestNow('2026-10-05 16:45:00');
        $tokenOut = $engine->issueToken($pass, GateId::Gate01)['token'];
        $resultOut = $scanner->scan($tokenOut, GateId::Gate01, $guard);
        $this->assertEquals('CHECK_OUT', $resultOut['decision']);
        $scanner->confirm($resultOut['scanId'], $guard);
        $this->assertEquals(PassStatus::CheckedOut, $pass->fresh()->status);

        // Reset to active for next test scan checks
        $pass->update(['status' => PassStatus::Active]);

        // 2. Scan at 18:30 PM on Monday (Outside shift hours) -> REJECT
        Carbon::setTestNow('2026-10-05 18:30:00');
        $tokenLate = $engine->issueToken($pass, GateId::Gate01)['token'];
        $resultLate = $scanner->scan($tokenLate, GateId::Gate01, $guard);
        $this->assertEquals('REJECT', $resultLate['decision']);
        $this->assertStringContainsString('Outside authorized operational hours', $resultLate['report']['primaryReason']);

        // 3. Scan at 10:00 AM on Saturday (Outside permitted days) -> REJECT
        Carbon::setTestNow('2026-10-10 10:00:00'); // Saturday
        $tokenSat = $engine->issueToken($pass, GateId::Gate01)['token'];
        $resultSat = $scanner->scan($tokenSat, GateId::Gate01, $guard);
        $this->assertEquals('REJECT', $resultSat['decision']);
        $this->assertStringContainsString('Access not permitted on Saturday', $resultSat['report']['primaryReason']);
    }

    public function test_gardener_and_pool_maintenance_and_driver_reusable_pass_across_multiple_weeks(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);
        Carbon::setTestNow('2026-10-05 08:00:00'); // Monday

        // Pool maintenance: Tuesdays & Fridays 08:00 - 13:00
        $delegation = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Dave Pool Service',
            'email' => 'dave@clearbluepools.com',
            'relationship' => 'Pool Maintenance',
            'access_level' => 'Contractor',
            'authorization_type' => 'contractor',
            'duration_type' => 'recurring',
            'status' => 'active',
            'approval_status' => 'approved',
            'starts_at' => Carbon::parse('2026-10-05'),
            'expires_at' => Carbon::parse('2026-12-31 23:59:59'),
            'access_rules' => [
                'gate_access' => true,
                'allowed_days' => ['Tue', 'Fri'],
                'entry_start_time' => '08:00',
                'entry_end_time' => '13:00',
            ],
        ]);

        $engine = app(GatePassEngine::class);
        $pass = $delegation->issueDelegateGatePass($engine, $homeowner);
        $guard = User::factory()->create(['role' => UserRole::Security]);
        $scanner = app(GateScanner::class);

        // Week 1: Tuesday Oct 6 at 09:00 AM -> Allowed
        Carbon::setTestNow('2026-10-06 09:00:00');
        $token = $engine->issueToken($pass, GateId::Gate01)['token'];
        $res = $scanner->scan($token, GateId::Gate01, $guard);
        $this->assertEquals('CHECK_IN', $res['decision']);
        $scanner->confirm($res['scanId'], $guard);

        // Check out at 11:30 AM
        Carbon::setTestNow('2026-10-06 11:30:00');
        $token2 = $engine->issueToken($pass, GateId::Gate01)['token'];
        $res2 = $scanner->scan($token2, GateId::Gate01, $guard);
        $this->assertEquals('CHECK_OUT', $res2['decision']);
        $scanner->confirm($res2['scanId'], $guard);

        // Week 1: Friday Oct 9 at 10:00 AM -> SAME pass used without re-generating!
        Carbon::setTestNow('2026-10-09 10:00:00');
        $tokenFri = $engine->issueToken($pass, GateId::Gate01)['token'];
        $resFri = $scanner->scan($tokenFri, GateId::Gate01, $guard);
        $this->assertEquals('CHECK_IN', $resFri['decision']);
        $scanner->confirm($resFri['scanId'], $guard);

        // Check out on Friday at 12:30 PM
        Carbon::setTestNow('2026-10-09 12:30:00');
        $tokenFriOut = $engine->issueToken($pass, GateId::Gate01)['token'];
        $resFriOut = $scanner->scan($tokenFriOut, GateId::Gate01, $guard);
        $this->assertEquals('CHECK_OUT', $resFriOut['decision']);
        $scanner->confirm($resFriOut['scanId'], $guard);

        // Week 4: Tuesday Oct 27 at 08:30 AM -> SAME pass still active and works!
        Carbon::setTestNow('2026-10-27 08:30:00');
        $tokenW4 = $engine->issueToken($pass, GateId::Gate01)['token'];
        $resW4 = $scanner->scan($tokenW4, GateId::Gate01, $guard);
        $this->assertEquals('CHECK_IN', $resW4['decision']);
    }

    public function test_recurring_access_expires_on_january_1_after_december_31(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);
        Carbon::setTestNow('2026-10-05 08:00:00');

        $delegation = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Marcus Private Driver',
            'email' => 'marcus@driverhub.com',
            'relationship' => 'Driver',
            'access_level' => 'Domestic Staff',
            'duration_type' => 'recurring',
            'status' => 'active',
            'approval_status' => 'approved',
            'starts_at' => Carbon::parse('2026-10-05'),
            'expires_at' => Carbon::parse('2026-12-31 23:59:59'),
            'access_rules' => [
                'gate_access' => true,
                'allowed_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'entry_start_time' => '06:00',
                'entry_end_time' => '20:00',
            ],
        ]);

        $engine = app(GatePassEngine::class);
        $pass = $delegation->issueDelegateGatePass($engine, $homeowner);
        $guard = User::factory()->create(['role' => UserRole::Security]);
        $scanner = app(GateScanner::class);

        // Active on Dec 31 at 12:00 PM -> Check-in succeeds
        Carbon::setTestNow('2026-12-31 12:00:00');
        $tokenDec = $engine->issueToken($pass, GateId::Gate01)['token'];
        $resDec = $scanner->scan($tokenDec, GateId::Gate01, $guard);
        $this->assertEquals('CHECK_IN', $resDec['decision']);

        $pass->update(['status' => PassStatus::Active]);

        // January 1, 2027 at 09:00 AM -> Rejected (Past Dec 31 expiration)
        Carbon::setTestNow('2027-01-01 09:00:00');
        $tokenJan = $engine->issueToken($pass, GateId::Gate01)['token'];
        $resJan = $scanner->scan($tokenJan, GateId::Gate01, $guard);
        $this->assertEquals('REJECT', $resJan['decision']);
        $this->assertStringContainsString('OUTSIDE_PASS_VALIDITY', $resJan['report']['primaryReason']);
    }

    public function test_homeowner_can_update_recurring_rules_and_it_syncs_to_gate_pass(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);
        Carbon::setTestNow('2026-10-05 08:00:00');

        $delegation = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Paul Contractor',
            'email' => 'paul@contractors.com',
            'relationship' => 'Long-Term Service Provider',
            'access_level' => 'Contractor',
            'duration_type' => 'recurring',
            'status' => 'active',
            'approval_status' => 'approved',
            'starts_at' => Carbon::parse('2026-10-05'),
            'expires_at' => Carbon::parse('2026-12-31 23:59:59'),
            'access_rules' => [
                'gate_access' => true,
                'allowed_days' => ['Mon', 'Wed', 'Fri'],
                'entry_start_time' => '08:00',
                'entry_end_time' => '17:00',
            ],
        ]);

        $engine = app(GatePassEngine::class);
        $pass = $delegation->issueDelegateGatePass($engine, $homeowner);

        // Update schedule to Monday–Friday 07:00 to 19:00
        $response = $this->actingAs($homeowner)->patchJson("/dashboard/delegation/{$delegation->id}/rules", [
            'duration_type' => 'recurring',
            'access_rules' => [
                'gate_access' => true,
                'allowed_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'entry_start_time' => '07:00',
                'entry_end_time' => '19:00',
            ],
        ]);

        $response->assertOk();

        $delegation->refresh();
        $this->assertEquals(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], $delegation->access_rules['allowed_days']);
        $this->assertEquals('07:00', $delegation->access_rules['entry_start_time']);
        $this->assertEquals('19:00', $delegation->access_rules['entry_end_time']);

        $pass->refresh();
        $this->assertEquals('2026-12-31 23:59:59', $pass->valid_until->toDateTimeString());
        $this->assertEquals(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], $pass->metadata['access_rules']['allowed_days']);
    }
}
