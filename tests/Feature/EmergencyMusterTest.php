<?php

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\GatePass;
use App\Models\MusterSession;
use App\Models\User;
use App\Models\Visitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmergencyMusterTest extends TestCase
{
    use RefreshDatabase;

    public function test_security_can_start_emergency_muster_and_roll_call_captures_all_on_property(): void
    {
        $guard = User::factory()->create(['role' => UserRole::Security]);
        $resident = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Dwight Schrute',
            'lot' => '14',
            'phone' => '+18765551001',
        ]);

        GatePass::create([
            'pass_id' => 'GP-RES-0014',
            'user_id' => $resident->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => 'Dwight Schrute',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subHours(2),
        ]);

        $visitor = Visitor::create([
            'homeowner_id' => $resident->id,
            'name' => 'Jim Halpert',
            'contact' => '+18765551002',
            'type' => 'Visitor',
            'expected_at' => now(),
            'status' => VisitorStatus::CheckedIn,
            'checked_in_at' => now()->subHour(),
        ]);

        $contractorPass = GatePass::create([
            'pass_id' => 'GP-CON-0088',
            'category' => PassCategory::Contractor,
            'holder_name' => 'Bob Vance (Vance Refrigeration)',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now()->subMinutes(45),
            'metadata' => ['contact' => '+18765551003'],
        ]);

        // Start Emergency Muster for Hurricane incident
        $response = $this->actingAs($guard)->postJson('/dashboard/occupancy/muster/start', [
            'incident_type' => 'hurricane',
            'title' => 'Hurricane Beryl Emergency Evacuation',
            'assembly_point' => 'Central Park East Muster Field (Gate 01 Ingress)',
            'notes' => 'Category 4 hurricane approaching. Immediate roll call initiated.',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('muster_sessions', [
            'incident_type' => 'hurricane',
            'title' => 'Hurricane Beryl Emergency Evacuation',
            'status' => 'active',
        ]);

        $session = MusterSession::where('status', 'active')->first();
        $this->assertNotNull($session);

        // Verify roll call contains all people inside
        $this->assertGreaterThanOrEqual(3, $session->rollCalls()->count());
        $this->assertDatabaseHas('muster_roll_calls', [
            'muster_session_id' => $session->id,
            'occupant_name' => 'Dwight Schrute',
            'status' => 'missing',
        ]);
        $this->assertDatabaseHas('muster_roll_calls', [
            'muster_session_id' => $session->id,
            'occupant_name' => 'Jim Halpert',
            'status' => 'missing',
        ]);
        $this->assertDatabaseHas('muster_roll_calls', [
            'muster_session_id' => $session->id,
            'occupant_name' => 'Bob Vance (Vance Refrigeration)',
            'status' => 'missing',
        ]);
    }

    public function test_security_can_mark_occupant_safe_missing_evacuated_needs_assistance_and_checked_out(): void
    {
        $guard = User::factory()->create(['role' => UserRole::Security]);
        $resident = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Pam Beesly',
            'lot' => '14',
        ]);

        GatePass::create([
            'pass_id' => 'GP-RES-0014',
            'user_id' => $resident->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => 'Pam Beesly',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now(),
        ]);

        $this->actingAs($guard)->postJson('/dashboard/occupancy/muster/start', [
            'incident_type' => 'fire',
            'title' => 'Building 14 Structural Fire Alert',
            'assembly_point' => 'North Clubhouse Lawn', // required: there is no invented default
        ]);

        $session = MusterSession::where('status', 'active')->first();
        $occupantId = 'pass_'.GatePass::where('holder_name', 'Pam Beesly')->value('id');

        // 1. Mark Needs Assistance
        $resp1 = $this->actingAs($guard)->postJson('/dashboard/occupancy/muster/status', [
            'session_id' => $session->id,
            'occupant_id' => $occupantId,
            'status' => 'needs_assistance',
            'notes' => 'Requires wheelchair assistance down stairs',
        ]);
        $resp1->assertOk();
        $this->assertDatabaseHas('muster_roll_calls', [
            'muster_session_id' => $session->id,
            'occupant_id' => $occupantId,
            'status' => 'needs_assistance',
            'notes' => 'Requires wheelchair assistance down stairs',
        ]);

        // 2. Mark Evacuated
        $resp2 = $this->actingAs($guard)->postJson('/dashboard/occupancy/muster/status', [
            'session_id' => $session->id,
            'occupant_id' => $occupantId,
            'status' => 'evacuated',
            'notes' => 'Assisted out by security team to Gate 01',
        ]);
        $resp2->assertOk();
        $this->assertDatabaseHas('muster_roll_calls', [
            'muster_session_id' => $session->id,
            'status' => 'evacuated',
        ]);

        // 3. Mark Safe
        $resp3 = $this->actingAs($guard)->postJson('/dashboard/occupancy/muster/status', [
            'session_id' => $session->id,
            'occupant_id' => $occupantId,
            'status' => 'safe',
            'notes' => 'Present at assembly point',
        ]);
        $resp3->assertOk();
        $this->assertDatabaseHas('muster_roll_calls', [
            'muster_session_id' => $session->id,
            'status' => 'safe',
        ]);

        // 4. Mark Checked Out
        $resp4 = $this->actingAs($guard)->postJson('/dashboard/occupancy/muster/status', [
            'session_id' => $session->id,
            'occupant_id' => $occupantId,
            'status' => 'checked_out',
            'notes' => 'Departed community via Gate 01 exit',
        ]);
        $resp4->assertOk();
        $this->assertDatabaseHas('muster_roll_calls', [
            'muster_session_id' => $session->id,
            'status' => 'checked_out',
        ]);
    }

    public function test_muster_roster_can_be_exported_and_session_resolved(): void
    {
        $guard = User::factory()->create(['role' => UserRole::Security]);
        $resident = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Stanley Hudson',
            'lot' => '14',
        ]);

        GatePass::create([
            'pass_id' => 'GP-RES-0014',
            'user_id' => $resident->id,
            'category' => PassCategory::Homeowner,
            'holder_name' => 'Stanley Hudson',
            'property' => 'Unit 14',
            'status' => PassStatus::CheckedIn,
            'checked_in_at' => now(),
        ]);

        $this->actingAs($guard)->postJson('/dashboard/occupancy/muster/start', [
            'incident_type' => 'flood',
            'title' => 'Coastal Surge & Flash Flood Incident',
            'assembly_point' => 'North Clubhouse Lawn', // required: there is no invented default
        ]);

        $session = MusterSession::where('status', 'active')->first();

        // Export Muster Roster
        $exportResp = $this->actingAs($guard)->get('/dashboard/occupancy/muster/export');
        $exportResp->assertOk();
        $exportResp->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Stanley Hudson', $exportResp->getContent());
        $this->assertStringContainsString('Unit 14', $exportResp->getContent());

        // Resolve Muster Session
        $resolveResp = $this->actingAs($guard)->postJson('/dashboard/occupancy/muster/resolve', [
            'session_id' => $session->id,
            'resolution_notes' => 'Floodwaters receded. All occupants accounted for.',
        ]);
        $resolveResp->assertOk();

        $this->assertDatabaseHas('muster_sessions', [
            'id' => $session->id,
            'status' => 'resolved',
        ]);
    }

    public function test_homeowner_cannot_initiate_emergency_muster(): void
    {
        $homeowner = User::factory()->create(['role' => UserRole::Homeowner]);

        $response = $this->actingAs($homeowner)->postJson('/dashboard/occupancy/muster/start', [
            'incident_type' => 'fire',
            'title' => 'Unauthorized Drill',
        ]);

        $response->assertForbidden();
    }
}
