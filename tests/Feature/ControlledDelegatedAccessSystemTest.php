<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Events\Realtime\OperationsCommandCenterEvent;
use App\Models\DelegatedAccess;
use App\Models\DelegatedAccessEvent;
use App\Models\EmergencyContinuityPlan;
use App\Models\GatePass;
use App\Models\InAppNotification;
use App\Models\Property;
use App\Models\User;
use App\Services\GateScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ControlledDelegatedAccessSystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_homeowner_can_add_emergency_contact(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => 'Lot 14',
            'street' => 'Bougainvillea Way',
        ]);

        $response = $this->actingAs($homeowner)->postJson('/dashboard/delegation', [
            'name' => 'Dr. Robert Smith',
            'email' => 'dr.robert@example.com',
            'phone' => '+18765550301',
            'relationship' => 'Family member',
            'access_level' => 'Emergency Contact',
            'activation_method' => 'immediate',
        ]);

        $response->assertCreated();
        $response->assertJson([
            'success' => true,
            'delegation' => [
                'name' => 'Dr. Robert Smith',
                'access_level' => 'Emergency Contact',
                'relationship' => 'Family member',
            ],
        ]);

        $delegation = DelegatedAccess::first();
        $this->assertNotNull($delegation);
        $this->assertEquals($homeowner->id, $delegation->grantor_user_id);
        $this->assertTrue($delegation->isActive());
        $this->assertTrue($delegation->hasPermission('emergency_communications'));
        $this->assertFalse($delegation->hasPermission('gate_access'));

        // Verify audit event logged
        $event = DelegatedAccessEvent::where('delegated_access_id', $delegation->id)->first();
        $this->assertNotNull($event);
        $this->assertEquals('created', $event->event_type);

        // Verify notification to homeowner
        $notif = InAppNotification::where('user_id', $homeowner->id)->first();
        $this->assertNotNull($notif);
        $this->assertEquals('delegation', $notif->category);
    }

    public function test_homeowner_can_add_limited_delegate_with_scoped_permissions(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => 'Unit 14',
            'street' => 'Palm Vista Drive',
        ]);

        $property = Property::create([
            'owner_user_id' => $homeowner->id,
            'lot_number' => 'Unit 14',
            'street_address' => 'Palm Vista Drive',
            'property_code' => 'PROP-14',
            'property_type' => 'Villa',
            'status' => 'Active',
        ]);

        $response = $this->actingAs($homeowner)->postJson('/dashboard/delegation', [
            'name' => 'Mary Smith',
            'email' => 'mary.smith@example.com',
            'phone' => '+18765550302',
            'relationship' => 'Family member',
            'access_level' => 'Limited Delegate',
            'permissions' => ['gate_access', 'property_maintenance', 'emergency_communications'],
            'property_id' => $property->id,
            'activation_method' => 'immediate',
            'security_notes' => 'Authorized to oversee villa renovations and emergency access',
        ]);

        $response->assertCreated();

        $delegation = DelegatedAccess::where('email', 'mary.smith@example.com')->first();
        $this->assertNotNull($delegation);
        $this->assertTrue($delegation->hasPermission('gate_access'));
        $this->assertTrue($delegation->hasPermission('property_maintenance'));
        $this->assertFalse($delegation->hasPermission('billing_view'));

        // Verify Authorized Delegate Gate Pass was automatically issued
        $pass = GatePass::where('category', PassCategory::Delegate)->first();
        $this->assertNotNull($pass);
        $this->assertEquals('Mary Smith', $pass->holder_name);
        $this->assertEquals(PassStatus::Active, $pass->status);
        $this->assertEquals($delegation->id, $pass->metadata['delegated_access_id']);
        $this->assertEquals('John Smith', $pass->metadata['grantor_name']);
    }

    public function test_emergency_activation_grants_temporary_access_and_alerts_security(): void
    {
        Event::fake([OperationsCommandCenterEvent::class]);

        $homeowner = User::factory()->create(['role' => UserRole::Homeowner, 'name' => 'John Smith']);
        $securityGuard = User::factory()->create(['role' => UserRole::Security, 'name' => 'Guard Davis']);

        $delegation = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Mary Smith',
            'email' => 'mary.smith@example.com',
            'relationship' => 'Family member',
            'access_level' => 'Emergency Delegate',
            'permissions' => ['gate_access', 'emergency_communications'],
            'status' => 'active',
            'activation_method' => 'emergency_trigger',
            'is_emergency_active' => false,
        ]);

        // Prior to activation, activation_method == emergency_trigger makes it inactive
        $this->assertFalse($delegation->isActive());

        $response = $this->actingAs($homeowner)->postJson("/dashboard/delegation/{$delegation->id}/emergency/activate", [
            'reason' => 'Homeowner hospitalized following cardiac surgery',
            'duration_days' => 14,
            'notes' => 'Please provide 24/7 gate access to caregiver daughter',
        ]);

        $response->assertOk();

        $delegation->refresh();
        $this->assertTrue($delegation->is_emergency_active);
        $this->assertTrue($delegation->isActive());
        $this->assertEquals('Homeowner hospitalized following cardiac surgery', $delegation->emergency_reason);
        $this->assertNotNull($delegation->emergency_expires_at);

        // Verify Operations Command Center Event was broadcast
        Event::assertDispatched(OperationsCommandCenterEvent::class, function ($event) use ($homeowner) {
            return $event->type === 'emergency'
                && str_contains($event->headline, 'EMERGENCY ACCESS ACTIVATED')
                && str_contains($event->headline, $homeowner->name);
        });

        // Verify security officer received in-app alert
        $guardNotif = InAppNotification::where('user_id', $securityGuard->id)->first();
        $this->assertNotNull($guardNotif);
        $this->assertEquals('security', $guardNotif->category);
        $this->assertStringContainsString('Mary Smith', $guardNotif->body);

        // Deactivate Emergency
        $deactivateResponse = $this->actingAs($homeowner)->postJson("/dashboard/delegation/{$delegation->id}/emergency/deactivate", [
            'reason' => 'Homeowner released from hospital and resumed normal schedule',
        ]);

        $deactivateResponse->assertOk();
        $delegation->refresh();
        $this->assertFalse($delegation->is_emergency_active);
    }

    public function test_gate_scanner_identifies_authorized_delegate_credential(): void
    {
        \Illuminate\Support\Carbon::setTestNow('2026-10-04 14:00:00');

        $guard = User::factory()->create(['role' => UserRole::Security, 'name' => 'Sergeant Hayes']);
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner, 'name' => 'John Smith', 'lot' => 'Unit 14']);

        $delegation = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Mary Smith',
            'email' => 'mary@example.com',
            'relationship' => 'Daughter',
            'access_level' => 'Emergency Delegate',
            'permissions' => ['gate_access'],
            'status' => 'active',
            'is_emergency_active' => true,
        ]);

        $pass = GatePass::create([
            'pass_id' => 'GP-DEL-999',
            'user_id' => $homeowner->id,
            'category' => PassCategory::Delegate,
            'holder_name' => 'Mary Smith',
            'property' => 'Unit 14, Royal Palm Drive',
            'access_zone' => PassCategory::Delegate->defaultZone(),
            'designated_gate' => GateId::Any,
            'rotation_seq' => 1,
            'status' => PassStatus::Active,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
            'max_uses' => 50,
            'uses_count' => 0,
            'metadata' => [
                'delegated_access_id' => $delegation->id,
                'relationship' => 'Daughter',
                'grantor_name' => 'John Smith',
                'access_level' => 'Emergency Delegate',
                'is_emergency' => true,
            ],
        ]);

        $engine = app(\App\Services\GatePassEngine::class);
        $issued = $engine->issueToken($pass, GateId::Gate01);
        $token = $issued['token'];

        $scanner = app(GateScanner::class);
        $scanResult = $scanner->scan($token, GateId::Gate01, $guard);

        $this->assertEquals('CHECK_IN', $scanResult['decision']);
        $report = $scanResult['report'];
        $this->assertTrue($report['isDelegated']);
        $this->assertEquals('AUTHORIZED DELEGATE', $report['delegatedTitle']);
        $this->assertEquals('John Smith', $report['actingFor']);
        $this->assertEquals('Daughter', $report['relationship']);
        $this->assertEquals('Emergency Delegate', $report['accessLevel']);
        $this->assertTrue($report['isEmergency']);

        // Verify audit event was logged on the delegation
        $scanEvent = DelegatedAccessEvent::where('delegated_access_id', $delegation->id)
            ->where('event_type', 'gate_scanned')
            ->first();
        $this->assertNotNull($scanEvent);

        // Test Late-Night (2:00 AM) Suspicious Access Alert
        \Illuminate\Support\Carbon::setTestNow('2026-10-05 02:00:00');
        $nightIssued = $engine->issueToken($pass, GateId::Gate01);
        $scanner->scan($nightIssued['token'], GateId::Gate01, $guard);

        $suspiciousEvent = DelegatedAccessEvent::where('delegated_access_id', $delegation->id)
            ->where('event_type', 'suspicious_attempt')
            ->first();
        $this->assertNotNull($suspiciousEvent);

        $notification = \App\Models\InAppNotification::where('user_id', $homeowner->id)
            ->where('category', 'security')
            ->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('Notice: Delegate Mary Smith Gate Access', $notification->title);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_homeowner_can_configure_emergency_continuity_plan(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);

        $delegate1 = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Executor James',
            'email' => 'james@example.com',
            'relationship' => 'Attorney',
            'access_level' => 'Legacy Delegate',
            'status' => 'active',
        ]);

        $delegate2 = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Sister Anna',
            'email' => 'anna@example.com',
            'relationship' => 'Family member',
            'access_level' => 'Emergency Delegate',
            'status' => 'active',
        ]);

        $response = $this->actingAs($homeowner)->postJson('/dashboard/delegation/continuity-plan', [
            'primary_delegate_id' => $delegate1->id,
            'secondary_delegate_id' => $delegate2->id,
            'activation_conditions' => ['medical_emergency', 'incapacity', 'extended_absence'],
            'required_verification' => 'two_officer_signoff',
            'authorized_actions' => ['gate_access', 'property_maintenance', 'emergency_dispatch'],
            'max_duration_days' => 45,
            'requires_admin_approval' => true,
            'notify_homeowner_on_trigger' => true,
            'notify_community_security' => true,
            'special_instructions' => 'Legal power of attorney document is registered with the HOA secretary.',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $plan = EmergencyContinuityPlan::where('user_id', $homeowner->id)->first();
        $this->assertNotNull($plan);
        $this->assertEquals($delegate1->id, $plan->primary_delegate_id);
        $this->assertEquals($delegate2->id, $plan->secondary_delegate_id);
        $this->assertEquals(45, $plan->max_duration_days);
        $this->assertContains('medical_emergency', $plan->activation_conditions);
    }

    public function test_delegation_can_be_revoked_safely(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);

        $delegation = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Ex-Caregiver Mark',
            'email' => 'mark@example.com',
            'relationship' => 'Caregiver',
            'access_level' => 'Limited Delegate',
            'status' => 'active',
        ]);

        $pass = GatePass::create([
            'pass_id' => 'GP-DEL-101',
            'user_id' => $homeowner->id,
            'category' => PassCategory::Delegate,
            'holder_name' => 'Ex-Caregiver Mark',
            'property' => 'Lot 14',
            'access_zone' => PassCategory::Delegate->defaultZone(),
            'designated_gate' => GateId::Any,
            'rotation_seq' => 1,
            'status' => PassStatus::Active,
            'valid_from' => now()->subDay(),
            'valid_until' => now()->addMonth(),
            'max_uses' => 50,
            'uses_count' => 0,
        ]);

        $response = $this->actingAs($homeowner)->postJson("/dashboard/delegation/{$delegation->id}/revoke", [
            'reason' => 'Contract terminated',
        ]);

        $response->assertOk();

        $delegation->refresh();
        $this->assertEquals('revoked', $delegation->status);
        $this->assertNotNull($delegation->revoked_at);
        $this->assertEquals('Contract terminated', $delegation->revocation_reason);
        $this->assertFalse($delegation->isActive());

        // Verify audit event
        $event = DelegatedAccessEvent::where('delegated_access_id', $delegation->id)
            ->where('event_type', 'revoked')
            ->first();
        $this->assertNotNull($event);

        // Verify another homeowner cannot revoke this delegation
        $stranger = User::factory()->create(['role' => UserRole::Homeowner]);
        $unauthResponse = $this->actingAs($stranger)->postJson("/dashboard/delegation/{$delegation->id}/revoke", [
            'reason' => 'Malicious revoke',
        ]);
        $unauthResponse->assertForbidden();
    }

    public function test_homeowner_can_view_delegation_dashboard_screen(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => 'Lot 14',
            'street' => 'Bougainvillea Way',
        ]);

        $response = $this->actingAs($homeowner)->get('/dashboard/delegation');
        $response->assertOk();
    }

    public function test_long_term_occupant_credential_issuance_rules_and_auto_expiration(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Smith',
            'lot' => 'Unit 14',
            'street' => 'Royal Palm Drive',
        ]);

        $guard = User::factory()->create(['role' => UserRole::Security, 'name' => 'Officer Miller']);

        // Homeowner creates Long-Term Occupant Michael Brown (Oct 10, 2026 to Apr 10, 2027).
        // Pinned to that day: a start date in the past is refused.
        \Illuminate\Support\Carbon::setTestNow('2026-10-10 09:00:00');
        $response = $this->actingAs($homeowner)->postJson('/dashboard/delegation', [
            'name' => 'Michael Brown',
            'email' => 'michael.brown@residence-guest.com',
            'phone' => '+18765550144',
            'relationship' => 'Family/Friend',
            'access_level' => 'Long-Term Occupant',
            'authorization_type' => 'long_term_occupant',
            'starts_at' => '2026-10-10 00:00:00',
            'expires_at' => '2027-04-10 23:59:59',
            'permissions' => ['gate_access'],
            'access_rules' => [
                'gate_access' => true,
                'parking_access' => true,
                'can_create_visitors' => false,
                'emergency_access' => false,
                'verify_id' => true,
                'amenity_access' => [
                    'pool' => true,
                    'gym' => false,
                    'clubhouse' => true,
                ],
            ],
        ]);

        $response->assertCreated();

        $delegation = DelegatedAccess::where('email', 'michael.brown@residence-guest.com')->first();
        $this->assertNotNull($delegation);
        $this->assertEquals('Long-Term Occupant', $delegation->access_level);
        $this->assertEquals('long_term_occupant', $delegation->authorization_type);

        // Verify unique Long-Term Occupant Gate Pass was issued
        $pass = GatePass::where('delegated_access_id', $delegation->id)->first();
        $this->assertNotNull($pass);
        $this->assertEquals(PassCategory::LongTermOccupant, $pass->category);
        $this->assertStringStartsWith('GP-LTO-', $pass->pass_id);
        $this->assertEquals('Unit 14, Royal Palm Drive', $pass->property);

        // Security scan during authorized window (Oct 15, 2026)
        \Illuminate\Support\Carbon::setTestNow('2026-10-15 14:00:00');
        $engine = app(\App\Services\GatePassEngine::class);
        $issued = $engine->issueToken($pass, GateId::Gate01);

        $scanner = app(GateScanner::class);
        $scanResult = $scanner->scan($issued['token'], GateId::Gate01, $guard);

        $this->assertEquals('CHECK_IN', $scanResult['decision']);
        $report = $scanResult['report'];
        $this->assertEquals('LONG-TERM OCCUPANT', $report['delegatedTitle']);
        $this->assertEquals('LONG-TERM OCCUPANT', $report['authorizationType']);
        $this->assertEquals('RESIDENT ACCESS', $report['accessTier']);
        $this->assertEquals('John Smith', $report['actingFor']);
        $this->assertEquals('Family/Friend', $report['relationship']);
        $this->assertTrue($report['accessRulesSummary']['Gate']);
        $this->assertTrue($report['accessRulesSummary']['Pool']);
        $this->assertFalse($report['accessRulesSummary']['Gym']);
        $this->assertTrue($report['accessRulesSummary']['Parking']);
        $this->assertFalse($report['accessRulesSummary']['Create Visitors']);
        $this->assertTrue($report['requiresIdVerification']);

        // Automatic expiration: Scan after April 10, 2027 (e.g. April 12, 2027)
        \Illuminate\Support\Carbon::setTestNow('2027-04-12 09:00:00');
        $expiredToken = $engine->issueToken($pass, GateId::Gate01);
        $expiredResult = $scanner->scan($expiredToken['token'], GateId::Gate01, $guard);

        $this->assertEquals('REJECT', $expiredResult['decision']);
        $this->assertEquals('DENY', $expiredResult['report']['status']);

        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_schedule_rule_enforcement_at_scanner(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner, 'name' => 'Eleanor Vance', 'lot' => 'Unit 14']);
        $guard = User::factory()->create(['role' => UserRole::Security, 'name' => 'Officer Miller']);

        // Caregiver authorized only Mon-Fri from 06:00 to 21:00
        $delegation = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Sarah Caregiver',
            'email' => 'sarah.caregiver@example.com',
            'relationship' => 'Caregiver',
            'access_level' => 'Caregiver',
            'authorization_type' => 'caregiver',
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'expires_at' => now()->addYear(),
            'access_rules' => [
                'days_permitted' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
                'time_window' => ['start' => '06:00', 'end' => '21:00'],
                'gate_access' => true,
            ],
        ]);

        $engine = app(\App\Services\GatePassEngine::class);
        $pass = $delegation->issueDelegateGatePass($engine, $homeowner);

        // Test scan at 22:30 (outside operational window)
        \Illuminate\Support\Carbon::setTestNow('2026-10-12 22:30:00'); // Monday 10:30 PM
        $token = $engine->issueToken($pass, GateId::Gate01);

        $scanner = app(GateScanner::class);
        $scanResult = $scanner->scan($token['token'], GateId::Gate01, $guard);

        $this->assertEquals('REJECT', $scanResult['decision']);
        $this->assertStringContainsString('Outside authorized operational hours', $scanResult['report']['primaryReason']);

        // Test scan at 14:00 (within operational window)
        \Illuminate\Support\Carbon::setTestNow('2026-10-12 14:00:00'); // Monday 2:00 PM
        $validToken = $engine->issueToken($pass, GateId::Gate01);
        $validResult = $scanner->scan($validToken['token'], GateId::Gate01, $guard);

        $this->assertEquals('CHECK_IN', $validResult['decision']);
        $this->assertEquals('CAREGIVER', $validResult['report']['authorizationType']);

        \Illuminate\Support\Carbon::setTestNow();
    }
}
