<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Events\Safety\SilentAssistanceRequested;
use App\Models\AccessLogEntry;
use App\Models\DistressAlert;
use App\Models\GatePass;
use App\Models\InAppNotification;
use App\Models\SafetyProfile;
use App\Models\User;
use App\Services\GatePassEngine;
use App\Services\Safety\SilentAssistanceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Discreet distress: a resident can ask for help without anyone nearby being
 * able to tell, by the app, by holding their QR code, or by a duress PIN at the
 * gate. The QR code itself never carries the meaning.
 */
class SilentAssistanceTest extends TestCase
{
    use RefreshDatabase;

    private User $resident;

    private User $guard;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00:00'));
        Event::fake([SilentAssistanceRequested::class]);
        $this->resident = User::factory()->role(UserRole::Homeowner)->create(['lot' => 'Lot 14', 'street' => 'Hibiscus Way']);
        $this->guard = User::factory()->role(UserRole::Security)->create();
    }

    private function pass(): GatePass
    {
        return app(GatePassEngine::class)->issuePassFor($this->resident);
    }

    private function scan(string $token): array
    {
        return $this->actingAs($this->guard)
            ->postJson('/dashboard/gate-pass/scan', ['token' => $token, 'gate' => 'GATE-01'])
            ->assertOk()
            ->json();
    }

    // ── Asking in the app ────────────────────────────────────────────────

    public function test_an_app_request_alerts_security_and_answers_plainly(): void
    {
        $this->actingAs($this->resident)
            ->postJson('/dashboard/safety/assist', ['assistance' => 'police'])
            ->assertOk()
            ->assertExactJson(['message' => 'Request sent.']);

        $alert = DistressAlert::sole();
        $this->assertSame(['app', 'police', 'open'], [$alert->channel, $alert->assistance_type, $alert->status]);

        // Security hears it; the resident's own notifications, which someone
        // beside them could see, do not.
        $this->assertSame(1, InAppNotification::where('user_id', $this->guard->id)->count());
        $this->assertSame(0, InAppNotification::where('user_id', $this->resident->id)->count());
        Event::assertDispatched(SilentAssistanceRequested::class);
    }

    public function test_only_people_who_live_here_can_use_it(): void
    {
        $this->actingAs($this->guard)->get('/dashboard/safety')->assertForbidden();
        $this->actingAs($this->guard)->postJson('/dashboard/safety/assist', ['assistance' => 'police'])->assertForbidden();
        $this->actingAs($this->resident)->get('/dashboard/safety')->assertOk();

        $otherResident = User::factory()->role(UserRole::Homeowner)->create();
        $this->actingAs($this->resident)
            ->postJson('/dashboard/safety/assist', ['assistance' => 'police'])
            ->assertOk();

        $this->actingAs($otherResident)
            ->get('/dashboard/safety')
            ->assertInertia(fn ($page) => $page->where('incidents', []));

        $this->actingAs($this->resident)
            ->get('/dashboard/safety')
            ->assertInertia(fn ($page) => $page->has('incidents', 1));

        $this->assertSame(1, DistressAlert::count());
    }

    public function test_safety_page_exposes_only_the_configured_direct_emergency_number(): void
    {
        config(['safety.public_emergency_number' => '+1 876 555 0100']);

        $this->actingAs($this->resident)
            ->get('/dashboard/safety')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Safety')
                ->where('publicEmergencyNumber', '+1 876 555 0100'));

        config(['safety.public_emergency_number' => null]);

        $this->actingAs($this->resident)
            ->get('/dashboard/safety')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Dashboard/Safety')
                ->where('publicEmergencyNumber', null));
    }

    // ── The QR gesture ───────────────────────────────────────────────────

    public function test_holding_the_qr_raises_an_alert_and_changes_nothing_on_screen(): void
    {
        $this->actingAs($this->resident)->postJson('/dashboard/safety/hold')->assertNoContent();

        $this->assertSame('qr_gesture', DistressAlert::sole()->channel);
        $this->assertTrue(SafetyProfile::where('user_id', $this->resident->id)->sole()->isArmed());
    }

    public function test_the_qr_code_is_the_same_whether_or_not_the_credential_is_armed(): void
    {
        $engine = app(GatePassEngine::class);
        $before = $engine->issueToken($this->pass())['token'];

        $this->actingAs($this->resident)->postJson('/dashboard/safety/hold');

        // Nothing in the code an observer could scan says anything has happened.
        $this->assertSame($before, $engine->issueToken($this->pass()->fresh())['token']);
    }

    public function test_an_armed_credential_tells_the_guard_and_the_alert_where(): void
    {
        $token = app(GatePassEngine::class)->issueToken($this->pass())['token'];
        $this->actingAs($this->resident)->postJson('/dashboard/safety/hold');

        $result = $this->scan($token);

        // The gate behaves normally...
        $this->assertSame('CHECK_IN', $result['decision']);
        // ...and only the guard's screen carries the request.
        // The resident's preferred help (security, unless they chose otherwise).
        $this->assertSame('security', $result['report']['silentAssistance']['assistance']);
        $this->assertArrayHasKey('silentAssistance', $result['report']);
        $this->assertSame('GATE-01', DistressAlert::sole()->gate);
        $this->assertFalse(SafetyProfile::where('user_id', $this->resident->id)->sole()->isArmed());

        // The access log reads like any other entry.
        $this->assertArrayNotHasKey('silentAssistance', AccessLogEntry::sole()->validation_report);
    }

    public function test_an_unarmed_credential_scans_normally(): void
    {
        $result = $this->scan(app(GatePassEngine::class)->issueToken($this->pass())['token']);

        $this->assertArrayNotHasKey('silentAssistance', $result['report']);
        $this->assertSame(0, DistressAlert::count());
    }

    // ── The duress PIN ───────────────────────────────────────────────────

    public function test_the_duress_pin_opens_the_gate_like_the_normal_pin_and_raises_an_alert(): void
    {
        $pass = $this->pass();
        $this->confirmPassword();
        $this->actingAs($this->resident)
            ->put('/dashboard/safety/duress-pin', ['pin' => '271828', 'pin_confirmation' => '271828'])
            ->assertSessionHasNoErrors();

        $normal = $this->scan($pass->offline_pin);
        $this->assertArrayNotHasKey('silentAssistance', $normal['report']);

        $this->travel(2)->minutes();
        $duress = $this->scan('271828');

        // Same decision and same recorded method as the normal PIN.
        $this->assertSame($normal['decision'], $duress['decision']);
        $this->assertSame(['Offline Gate PIN', 'Offline Gate PIN'], AccessLogEntry::orderBy('id')->pluck('method')->all());
        $this->assertArrayHasKey('silentAssistance', $duress['report']);
        $this->assertSame('duress_pin', DistressAlert::sole()->channel);
        $this->assertSame($pass->pass_id, $duress['report']['passId']);
    }

    public function test_the_duress_pin_is_stored_as_a_keyed_hash(): void
    {
        app(SilentAssistanceService::class)->setDuressPin($this->resident, '271828');

        $stored = DB::table('safety_profiles')->value('duress_pin_hash');
        $this->assertNotNull($stored);
        $this->assertStringNotContainsString('271828', $stored);
    }

    public function test_a_duress_pin_must_differ_from_the_normal_pin_and_from_everyone_elses(): void
    {
        $safety = app(SilentAssistanceService::class);
        $pass = $this->pass();

        $this->assertThrows(fn () => $safety->setDuressPin($this->resident, $pass->offline_pin), \DomainException::class);

        $safety->setDuressPin(User::factory()->role(UserRole::Homeowner)->create(), '314159');
        $this->assertThrows(fn () => $safety->setDuressPin($this->resident, '314159'), \DomainException::class);
    }

    public function test_setting_a_duress_pin_needs_the_password_confirmed(): void
    {
        $this->actingAs($this->resident)
            ->put('/dashboard/safety/duress-pin', ['pin' => '271828', 'pin_confirmation' => '271828'])
            ->assertStatus(302);

        $this->assertFalse(SafetyProfile::where('user_id', $this->resident->id)->first()?->hasDuressPin() ?? false);
    }

    // ── Turning hidden triggers off ──────────────────────────────────────

    public function test_with_silent_alerts_off_the_hidden_triggers_do_nothing_but_the_app_still_works(): void
    {
        $safety = app(SilentAssistanceService::class);
        $safety->profileFor($this->resident)->update(['silent_alerts_enabled' => false]);

        $this->actingAs($this->resident)->postJson('/dashboard/safety/hold')->assertNoContent();
        $this->assertSame(0, DistressAlert::count());

        $this->postJson('/dashboard/safety/assist', ['assistance' => 'medical'])->assertOk();
        $this->assertSame(1, DistressAlert::count());
    }

    // ── The safety profile ───────────────────────────────────────────────

    public function test_medical_notes_are_encrypted_at_rest(): void
    {
        $this->actingAs($this->resident)->patch('/dashboard/safety/profile', [
            'preferred_assistance' => 'medical',
            'medical_notes' => 'Type 1 diabetic',
            'silent_alerts_enabled' => true,
        ])->assertSessionHasNoErrors();

        $this->assertStringNotContainsString('Type 1 diabetic', (string) DB::table('safety_profiles')->value('medical_notes'));
        $this->assertSame('Type 1 diabetic', SafetyProfile::sole()->medical_notes);
    }

    public function test_rotating_the_safety_credential_retires_the_old_one(): void
    {
        $safety = app(SilentAssistanceService::class);
        $old = $safety->profileFor($this->resident)->safety_credential_id;

        $new = $safety->rotateCredential($this->resident)->safety_credential_id;

        $this->assertNotSame($old, $new);
        $this->assertStringStartsWith('SCID-', $new);
        $this->assertFalse(SafetyProfile::where('safety_credential_id', $old)->exists());
    }

    // ── Security's console ───────────────────────────────────────────────

    public function test_security_sees_the_profile_while_a_request_is_open_and_closes_it(): void
    {
        $safety = app(SilentAssistanceService::class);
        $safety->profileFor($this->resident)->update(['emergency_contact_name' => 'Ann Brown', 'medical_notes' => 'Asthma']);
        $alert = $safety->raise($this->resident, 'app', 'medical');

        $this->actingAs($this->guard)->get('/dashboard/silent-assistance')->assertInertia(fn ($page) => $page
            ->where('alerts.0.profile.emergencyContactName', 'Ann Brown')
            ->where('alerts.0.profile.medicalNotes', 'Asthma'));

        $this->post("/dashboard/silent-assistance/{$alert->id}/acknowledge")->assertSessionHasNoErrors();
        $this->assertSame('acknowledged', $alert->fresh()->status);

        $this->post("/dashboard/silent-assistance/{$alert->id}/resolve", ['outcome' => 'resolved', 'notes' => 'Ambulance attended'])
            ->assertSessionHasNoErrors();
        $this->assertSame('resolved', $alert->fresh()->status);

        // Once closed, the medical notes and contact are no longer on screen.
        $this->get('/dashboard/silent-assistance')->assertInertia(fn ($page) => $page->where('alerts.0.profile', null));
    }

    public function test_residents_cannot_open_the_console(): void
    {
        $this->actingAs($this->resident)->get('/dashboard/silent-assistance')->assertForbidden();
    }

    // ── Escalation safeguards ─────────────────────────────────────────────

    public function test_alerts_start_at_level_1_silent_alert(): void
    {
        $this->actingAs($this->resident)->postJson('/dashboard/safety/assist', ['assistance' => 'police'])->assertOk();

        $alert = DistressAlert::sole();
        $this->assertSame(DistressAlert::ESCALATION_SILENT_ALERT, $alert->escalation_level);
    }

    public function test_security_can_escalate_to_level_2(): void
    {
        $safety = app(SilentAssistanceService::class);
        $alert = $safety->raise($this->resident, 'app', 'police');

        $this->actingAs($this->guard)
            ->post("/dashboard/silent-assistance/{$alert->id}/escalate-security", ['notes' => 'On-site verification'])
            ->assertSessionHasNoErrors();

        $alert = $alert->fresh();
        $this->assertSame(DistressAlert::ESCALATION_SECURITY, $alert->escalation_level);
        $this->assertSame($this->guard->id, $alert->escalated_by);
        $this->assertNotNull($alert->escalated_at);
        $this->assertStringContainsString('Escalation note: On-site verification', $alert->notes);
    }

    public function test_cannot_escalate_already_escalated_alert(): void
    {
        $safety = app(SilentAssistanceService::class);
        $alert = $safety->raise($this->resident, 'app', 'police');
        $safety->escalateToSecurity($alert, $this->guard);

        $this->actingAs($this->guard)
            ->post("/dashboard/silent-assistance/{$alert->id}/escalate-security")
            ->assertSessionHasErrors('escalation');
    }

    public function test_only_eligible_types_can_escalate_to_emergency_services(): void
    {
        config(['safety.emergency_services_enabled' => true]);
        $safety = app(SilentAssistanceService::class);
        $alert = $safety->raise($this->resident, 'app', 'police');
        $safety->escalateToSecurity($alert, $this->guard);

        $this->actingAs($this->guard)
            ->post("/dashboard/silent-assistance/{$alert->id}/escalate-emergency", [
                'service' => 'police',
                'confirmed' => true,
            ])
            ->assertSessionHasNoErrors();

        $alert = $alert->fresh();
        $this->assertSame(DistressAlert::ESCALATION_EMERGENCY_SERVICES, $alert->escalation_level);
    }

    /** Off unless the estate turns it on (SAFETY_EMERGENCY_SERVICES_ENABLED). */
    public function test_emergency_services_escalation_is_off_by_default(): void
    {
        $safety = app(SilentAssistanceService::class);
        $alert = $safety->raise($this->resident, 'app', 'police');
        $safety->escalateToSecurity($alert, $this->guard);

        $this->actingAs($this->guard)
            ->post("/dashboard/silent-assistance/{$alert->id}/escalate-emergency", [
                'service' => 'police',
                'confirmed' => true,
            ])
            ->assertSessionHasErrors('escalation');

        $this->assertNotSame(DistressAlert::ESCALATION_EMERGENCY_SERVICES, $alert->fresh()->escalation_level);
    }

    public function test_non_eligible_types_cannot_escalate_to_emergency_services(): void
    {
        $safety = app(SilentAssistanceService::class);
        $alert = $safety->raise($this->resident, 'app', 'contact');
        $safety->escalateToSecurity($alert, $this->guard);

        $this->actingAs($this->guard)
            ->post("/dashboard/silent-assistance/{$alert->id}/escalate-emergency", [
                'service' => 'police',
                'confirmed' => true,
            ])
            ->assertSessionHasErrors('escalation');
    }

    public function test_requires_confirmation_for_emergency_escalation(): void
    {
        $safety = app(SilentAssistanceService::class);
        $alert = $safety->raise($this->resident, 'app', 'police');
        $safety->escalateToSecurity($alert, $this->guard);

        $this->actingAs($this->guard)
            ->post("/dashboard/silent-assistance/{$alert->id}/escalate-emergency", [
                'service' => 'police',
            ])
            ->assertSessionHasErrors('confirmed');
    }
}
