<?php

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\DelegatedAccess;
use App\Models\GatePass;
use App\Models\Property;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DelegatedAccessCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_homeowner_access_center_aggregates_all_10_categories_with_credentials(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Alexander Sterling',
            'email' => 'alexander@example.com',
            'lot' => 'Lot 24',
            'street' => 'Highland Boulevard',
        ]);

        $property = Property::create([
            'property_code' => 'PROP-24',
            'owner_user_id' => $homeowner->id,
            'lot_number' => '24',
            'street_address' => 'Highland Boulevard',
        ]);

        // 1. Resident (Active Renter)
        $renter = Renter::create([
            'homeowner_id' => $homeowner->id,
            'name' => 'Claire Renter',
            'contact' => 'claire@example.com',
            'stay_type' => 'Lease Tenant',
            'status' => 'Active',
            'lease_start' => now()->subMonth(),
            'lease_end' => now()->addMonths(5),
            'lot' => '24',
            'street' => 'Highland Boulevard',
        ]);

        // 2. Long-term occupant
        $longTerm = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Marcus Vance',
            'email' => 'marcus@example.com',
            'relationship' => 'Guest / Relative',
            'authorization_type' => 'long_term_occupant',
            'access_level' => 'Long-Term Occupant',
            'permissions' => ['gate_access', 'pool_access', 'gym_access'],
            'access_rules' => [
                'gate_access' => true,
                'pool_access' => true,
                'allowed_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
            ],
            'status' => 'active',
            'starts_at' => now(),
            'expires_at' => now()->addMonths(6),
        ]);
        app(GatePassEngine::class);
        $longTerm->issueDelegateGatePass(app(GatePassEngine::class), $homeowner);

        // 3. Legacy contact
        $legacy = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Eleanor Sterling',
            'email' => 'eleanor@example.com',
            'relationship' => 'Attorney / Executor',
            'authorization_type' => 'legacy_contact',
            'access_level' => 'Legacy Delegate',
            'permissions' => ['emergency_communications', 'gate_access'],
            'status' => 'active',
        ]);
        $legacy->issueDelegateGatePass(app(GatePassEngine::class), $homeowner);

        // 4. Family member
        $family = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'David Sterling',
            'email' => 'david@example.com',
            'relationship' => 'Family member',
            'authorization_type' => 'family_member',
            'access_level' => 'Family Member',
            'permissions' => ['gate_access', 'visitor_authorization'],
            'status' => 'active',
        ]);
        $family->issueDelegateGatePass(app(GatePassEngine::class), $homeowner);

        // 5. Caregiver
        $caregiver = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Nurse Sarah Jenkins',
            'email' => 'sarah.nurse@example.com',
            'relationship' => 'Caregiver',
            'authorization_type' => 'caregiver',
            'access_level' => 'Caregiver',
            'permissions' => ['gate_access'],
            'access_rules' => [
                'allowed_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'entry_start_time' => '07:00',
                'entry_end_time' => '19:00',
                'requires_id_verification' => true,
            ],
            'status' => 'active',
        ]);
        $caregiver->issueDelegateGatePass(app(GatePassEngine::class), $homeowner);

        // 6. Property manager
        $manager = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Apex Property Management',
            'email' => 'contact@apexmanagement.com',
            'relationship' => 'Property manager',
            'authorization_type' => 'property_manager',
            'access_level' => 'Property Delegate',
            'permissions' => ['gate_access', 'property_maintenance', 'visitor_authorization'],
            'status' => 'active',
        ]);
        $manager->issueDelegateGatePass(app(GatePassEngine::class), $homeowner);

        // 7. Domestic staff
        $staff = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Carlos Rodriguez',
            'email' => 'carlos@example.com',
            'relationship' => 'Domestic Staff',
            'authorization_type' => 'domestic_staff',
            'access_level' => 'Domestic Staff',
            'permissions' => ['gate_access'],
            'access_rules' => [
                'allowed_days' => ['Mon', 'Wed', 'Fri'],
                'entry_start_time' => '08:00',
                'entry_end_time' => '16:00',
            ],
            'status' => 'active',
        ]);
        $staff->issueDelegateGatePass(app(GatePassEngine::class), $homeowner);

        // 8. Contractor
        $contractor = Visitor::create([
            'homeowner_id' => $homeowner->id,
            'name' => 'Patrick Electrician',
            'contact' => '+18765550299',
            'type' => 'Contractor',
            'status' => VisitorStatus::Expected,
            'expected_at' => now()->addHour(),
        ]);
        app(GatePassEngine::class)->issueGuestPass($contractor, $homeowner, PassCategory::Contractor);

        // 9. Visitor
        $visitor = Visitor::create([
            'homeowner_id' => $homeowner->id,
            'name' => 'Samantha Hill',
            'contact' => 'samantha@example.com',
            'type' => 'One-time',
            'status' => VisitorStatus::Expected,
            'expected_at' => now()->addHours(2),
        ]);
        app(GatePassEngine::class)->issueGuestPass($visitor, $homeowner, PassCategory::Visitor);

        // 10. Former occupant (Revoked delegate)
        $former = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'James Ex-Tenant',
            'email' => 'james.ex@example.com',
            'relationship' => 'Former subtenant',
            'authorization_type' => 'long_term_occupant',
            'access_level' => 'Long-Term Occupant',
            'permissions' => [],
            'status' => 'revoked',
        ]);

        $response = $this->actingAs($homeowner)->getJson('/dashboard/delegation');
        $response->assertOk();

        $data = $response->json();
        $this->assertArrayHasKey('authorizedPeople', $data);
        $this->assertArrayHasKey('categoryCounts', $data);

        $counts = $data['categoryCounts'];
        $this->assertGreaterThanOrEqual(1, $counts['residents']);
        $this->assertGreaterThanOrEqual(1, $counts['long_term_occupants']);
        $this->assertGreaterThanOrEqual(1, $counts['legacy_contacts']);
        $this->assertGreaterThanOrEqual(1, $counts['family_members']);
        $this->assertGreaterThanOrEqual(1, $counts['caregivers']);
        $this->assertGreaterThanOrEqual(1, $counts['property_managers']);
        $this->assertGreaterThanOrEqual(1, $counts['domestic_staff']);
        $this->assertGreaterThanOrEqual(1, $counts['contractors']);
        $this->assertGreaterThanOrEqual(1, $counts['visitors']);
        $this->assertGreaterThanOrEqual(1, $counts['former_occupants']);

        // Check credentials across the categories
        $people = collect($data['authorizedPeople']);

        $resPerson = $people->firstWhere('name', 'Alexander Sterling');
        $this->assertNotNull($resPerson['credential']);
        $this->assertStringStartsWith('GP-HO', $resPerson['credential']['categoryCode']);

        $ltoPerson = $people->firstWhere('name', 'Marcus Vance');
        $this->assertNotNull($ltoPerson['credential']);
        $this->assertStringStartsWith('GP-LTO', $ltoPerson['credential']['categoryCode']);

        $staffPerson = $people->firstWhere('name', 'Carlos Rodriguez');
        $this->assertNotNull($staffPerson['credential']);
        $this->assertStringStartsWith('GP-HST', $staffPerson['credential']['categoryCode']);

        $contractorPerson = $people->firstWhere('name', 'Patrick Electrician');
        $this->assertNotNull($contractorPerson['credential']);
        $this->assertStringStartsWith('GP-CON', $contractorPerson['credential']['categoryCode']);

        $visitorPerson = $people->firstWhere('name', 'Samantha Hill');
        $this->assertNotNull($visitorPerson['credential']);
        $this->assertStringStartsWith('GP-VIS', $visitorPerson['credential']['categoryCode']);
    }

    public function test_homeowner_can_update_access_rules_and_reissue_pass(): void
    {
        $homeowner = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Alexander Sterling',
        ]);

        $caregiver = DelegatedAccess::create([
            'grantor_user_id' => $homeowner->id,
            'name' => 'Nurse Sarah Jenkins',
            'email' => 'sarah@example.com',
            'relationship' => 'Caregiver',
            'authorization_type' => 'caregiver',
            'access_level' => 'Caregiver',
            'permissions' => ['gate_access'],
            'access_rules' => [
                'allowed_days' => ['Mon', 'Tue'],
                'entry_start_time' => '08:00',
                'entry_end_time' => '17:00',
            ],
            'status' => 'active',
        ]);

        $caregiver->issueDelegateGatePass(app(GatePassEngine::class), $homeowner);

        // Update rules
        $patchResponse = $this->actingAs($homeowner)->patchJson("/dashboard/delegation/{$caregiver->id}/rules", [
            'access_rules' => [
                'allowed_days' => ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'],
                'entry_start_time' => '07:00',
                'entry_end_time' => '19:00',
                'requires_id_verification' => true,
                'pool_access' => false,
            ],
            'permissions' => ['gate_access', 'emergency_communications'],
        ]);

        $patchResponse->assertOk();
        $this->assertTrue($patchResponse->json('success'));

        $caregiver->refresh();
        $this->assertEquals(['Mon', 'Tue', 'Wed', 'Thu', 'Fri'], $caregiver->access_rules['allowed_days']);
        $this->assertEquals('07:00', $caregiver->access_rules['entry_start_time']);
        $this->assertTrue($caregiver->hasPermission('emergency_communications'));

        // Reissue pass
        $reissueResponse = $this->actingAs($homeowner)->postJson("/dashboard/delegation/{$caregiver->id}/reissue-pass");
        $reissueResponse->assertOk();
        $this->assertTrue($reissueResponse->json('success'));
        $this->assertNotNull($reissueResponse->json('pass.pass_id'));

        // Old passes should be revoked
        $activePasses = $caregiver->gatePasses()->where('status', PassStatus::Active->value)->count();
        $this->assertEquals(1, $activePasses);
    }
}
