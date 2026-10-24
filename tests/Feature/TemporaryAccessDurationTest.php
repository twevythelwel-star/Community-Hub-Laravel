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

class TemporaryAccessDurationTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_hour_temporary_access_for_cleaners_or_deliveries(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner, 'name' => 'Sarah Connor']);

        Carbon::setTestNow('2026-10-05 10:00:00');

        $response = $this->actingAs($homeowner)->postJson('/dashboard/delegation', [
            'name' => 'Maria Sparkle Cleaners',
            'email' => 'maria@cleaners.com',
            'phone' => '+18765550999',
            'relationship' => 'Cleaner',
            'access_level' => 'Domestic Staff',
            'duration_type' => '2_hours',
            'starts_at' => '2026-10-05 10:00:00',
        ]);

        $response->assertCreated();
        $delegation = DelegatedAccess::where('email', 'maria@cleaners.com')->first();
        $this->assertNotNull($delegation);
        $this->assertEquals('2_hours', $delegation->duration_type);
        $this->assertEquals('2026-10-05 12:00:00', $delegation->expires_at->toDateTimeString());

        $pass = $delegation->gatePasses()->first();
        $this->assertNotNull($pass);
        $this->assertEquals('2026-10-05 12:00:00', $pass->valid_until->toDateTimeString());

        // Scan at 11:00 AM (Within 2-hour window) -> Admitted
        Carbon::setTestNow('2026-10-05 11:00:00');
        $guard = User::factory()->create(['role' => UserRole::Security, 'name' => 'Officer Davis']);
        $engine = app(GatePassEngine::class);
        $token = $engine->issueToken($pass, GateId::Gate01)['token'];
        $scanner = app(GateScanner::class);
        $result = $scanner->scan($token, GateId::Gate01, $guard);
        $this->assertEquals('CHECK_IN', $result['decision']);

        // Scan at 12:05 PM (After 2 hours) -> Rejected Expired
        Carbon::setTestNow('2026-10-05 12:05:00');
        $tokenLate = $engine->issueToken($pass, GateId::Gate01)['token'];
        $resultLate = $scanner->scan($tokenLate, GateId::Gate01, $guard);
        $this->assertEquals('REJECT', $resultLate['decision']);
        $this->assertStringContainsString('OUTSIDE_PASS_VALIDITY', $resultLate['report']['primaryReason']);
    }

    public function test_one_day_temporary_access_for_babysitters_or_day_workers(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);
        Carbon::setTestNow('2026-10-05 08:00:00');

        $response = $this->actingAs($homeowner)->postJson('/dashboard/delegation', [
            'name' => 'Jessica Babysitter',
            'email' => 'jessica@babysit.org',
            'relationship' => 'Babysitter',
            'access_level' => 'Caregiver',
            'duration_type' => '1_day',
            'starts_at' => '2026-10-05 08:00:00',
        ]);

        $response->assertCreated();
        $delegation = DelegatedAccess::where('email', 'jessica@babysit.org')->first();
        $this->assertEquals('1_day', $delegation->duration_type);
        $this->assertEquals('2026-10-05 23:59:59', $delegation->expires_at->toDateTimeString());

        $pass = $delegation->gatePasses()->first();
        $this->assertEquals('2026-10-05 23:59:59', $pass->valid_until->toDateTimeString());
    }

    public function test_one_week_temporary_access_for_contractors_or_relatives(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);
        Carbon::setTestNow('2026-10-05 09:00:00');

        $response = $this->actingAs($homeowner)->postJson('/dashboard/delegation', [
            'name' => 'Apex Painting Crew',
            'email' => 'apex@painting.com',
            'relationship' => 'Contractor',
            'access_level' => 'Contractor',
            'duration_type' => '1_week',
            'starts_at' => '2026-10-05 09:00:00',
        ]);

        $response->assertCreated();
        $delegation = DelegatedAccess::where('email', 'apex@painting.com')->first();
        $this->assertEquals('1_week', $delegation->duration_type);
        $this->assertEquals('2026-10-12 23:59:59', $delegation->expires_at->toDateTimeString());
    }

    public function test_recurring_days_access_for_scheduled_cleaner_or_caregiver(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);
        $guard = User::factory()->create(['role' => UserRole::Security]);
        $engine = app(GatePassEngine::class);
        $scanner = app(GateScanner::class);

        // Schedule: Mon, Wed, Fri from 08:00 to 17:00
        $delegation = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Rita Regular Housekeeper',
            'email' => 'rita@housekeeping.com',
            'relationship' => 'Domestic Staff',
            'access_level' => 'Domestic Staff',
            'duration_type' => 'recurring',
            'access_rules' => [
                'gate_access' => true,
                'allowed_days' => ['Mon', 'Wed', 'Fri'],
                'entry_start_time' => '08:00',
                'entry_end_time' => '17:00',
            ],
            'status' => 'active',
            'starts_at' => Carbon::parse('2026-10-05 00:00:00'),
        ]);

        $pass = $delegation->issueDelegateGatePass($engine, $homeowner);

        // Monday 10:00 AM -> Valid recurring day and time -> CHECK_IN
        Carbon::setTestNow('2026-10-05 10:00:00'); // 2026-10-05 is a Monday
        $tokenMon = $engine->issueToken($pass, GateId::Gate01)['token'];
        $resultMon = $scanner->scan($tokenMon, GateId::Gate01, $guard);
        $this->assertEquals('CHECK_IN', $resultMon['decision']);

        // Tuesday 10:00 AM -> Off day -> REJECT
        Carbon::setTestNow('2026-10-06 10:00:00'); // Tuesday
        $tokenTue = $engine->issueToken($pass, GateId::Gate01)['token'];
        $resultTue = $scanner->scan($tokenTue, GateId::Gate01, $guard);
        $this->assertEquals('REJECT', $resultTue['decision']);
        $this->assertStringContainsString('Access not permitted on Tuesday', $resultTue['report']['primaryReason']);

        // Monday 18:30 PM -> Outside operational hours (08:00 - 17:00) -> REJECT
        Carbon::setTestNow('2026-10-05 18:30:00');
        $tokenLate = $engine->issueToken($pass, GateId::Gate01)['token'];
        $resultLate = $scanner->scan($tokenLate, GateId::Gate01, $guard);
        $this->assertEquals('REJECT', $resultLate['decision']);
        $this->assertStringContainsString('OUTSIDE_PERMITTED_HOURS', $resultLate['report']['primaryReason']);
    }

    public function test_access_until_manually_revoked(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);

        $response = $this->actingAs($homeowner)->postJson('/dashboard/delegation', [
            'name' => 'Uncle George',
            'email' => 'george@family.org',
            'relationship' => 'Family member',
            'access_level' => 'Family Member',
            'duration_type' => 'manual_revocation',
        ]);

        $response->assertCreated();
        $delegation = DelegatedAccess::where('email', 'george@family.org')->first();
        $this->assertEquals('manual_revocation', $delegation->duration_type);
        $this->assertNull($delegation->expires_at);
        $this->assertFalse($delegation->isExpired());

        $pass = $delegation->gatePasses()->first();
        $this->assertTrue($pass->isActive());
        // The delegation runs until revoked; each pass is capped and renewed
        // (config('delegation.max_pass_days')), not a two-year estate pass.
        $this->assertTrue($pass->valid_until->lte(now()->addDays(config('delegation.max_pass_days'))->addMinute()));
        $this->assertTrue($pass->valid_until->gt(now()->addDays(config('delegation.max_pass_days'))->subDay()));

        // Manually revoking invalidates the pass
        $this->actingAs($homeowner)->postJson("/dashboard/delegation/{$delegation->id}/revoke", [
            'reason' => 'Family visit concluded',
        ]);

        $delegation->refresh();
        $this->assertEquals('revoked', $delegation->status);
        $pass->refresh();
        $this->assertEquals(PassStatus::Revoked, $pass->status);
    }

    public function test_custom_date_range_and_updating_duration_preset(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);
        Carbon::setTestNow('2026-10-05 09:00:00');

        $response = $this->actingAs($homeowner)->postJson('/dashboard/delegation', [
            'name' => 'Custom Date Contractor',
            'email' => 'custom@contractor.com',
            'relationship' => 'Contractor',
            'access_level' => 'Contractor',
            'duration_type' => 'custom',
            'starts_at' => '2026-10-10',
            'expires_at' => '2026-10-25',
        ]);

        $response->assertCreated();
        $delegation = DelegatedAccess::where('email', 'custom@contractor.com')->first();
        $this->assertEquals('custom', $delegation->duration_type);
        $this->assertEquals('2026-10-10 00:00:00', $delegation->starts_at->toDateTimeString());
        $this->assertEquals('2026-10-25 00:00:00', $delegation->expires_at->toDateTimeString());

        // Update duration preset to 1_week extension
        $patchResponse = $this->actingAs($homeowner)->patchJson("/dashboard/delegation/{$delegation->id}/rules", [
            'duration_type' => '1_week',
            'starts_at' => '2026-10-05',
            'access_rules' => [
                'gate_access' => true,
            ],
        ]);

        $patchResponse->assertOk();
        $delegation->refresh();
        $this->assertEquals('1_week', $delegation->duration_type);
        $this->assertEquals('2026-10-12 23:59:59', $delegation->expires_at->toDateTimeString());
    }
}
