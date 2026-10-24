<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AccessApprovalRequest;
use App\Models\DelegatedAccess;
use App\Models\GatePass;
use App\Models\Property;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use App\Services\AccessVisibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessVisibilityModelTest extends TestCase
{
    use RefreshDatabase;

    private AccessVisibilityService $visibilityService;

    private User $homeowner;

    private User $otherHomeowner;

    private User $security;

    private User $communityAdmin;

    private User $systemAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->visibilityService = app(AccessVisibilityService::class);

        $this->homeowner = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'John Homeowner',
            'lot' => 'Lot 14',
            'street' => 'Palm Drive',
        ]);

        $this->otherHomeowner = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'Sarah Neighbour',
            'lot' => 'Lot 88',
            'street' => 'Hibiscus Way',
        ]);

        $this->security = User::factory()->role(UserRole::Security)->create([
            'name' => 'Officer Miller',
            'lot' => null,
        ]);

        $this->communityAdmin = User::factory()->role(UserRole::Admin)->create([
            'name' => 'Admin Teresa',
            'lot' => null,
        ]);

        $this->systemAdmin = User::factory()->role(UserRole::SystemAdmin)->create([
            'name' => 'Sysadmin Root',
            'lot' => null,
        ]);
    }

    public function test_visibility_matrix_covers_all_ten_governance_roles(): void
    {
        $matrix = $this->visibilityService->getVisibilityMatrix();

        $rolesInMatrix = array_column($matrix, 'role');

        $this->assertContains('Homeowner', $rolesInMatrix);
        $this->assertContains('Long-Term Renter', $rolesInMatrix);
        $this->assertContains('Short-Term Rental / Airbnb Host/Guest', $rolesInMatrix);
        $this->assertContains('Visitor', $rolesInMatrix);
        $this->assertContains('Staff', $rolesInMatrix);
        $this->assertContains('Contractor', $rolesInMatrix);
        $this->assertContains('Legacy Contact', $rolesInMatrix);
        $this->assertContains('Security', $rolesInMatrix);
        $this->assertContains('Community Admin', $rolesInMatrix);
        $this->assertContains('System Admin', $rolesInMatrix);
    }

    public function test_homeowner_sees_own_property_but_not_other_properties_or_entire_community(): void
    {
        // 1. Can see own property
        $this->assertTrue($this->visibilityService->canSee($this->homeowner, 'occupants', null, 'Lot 14'));

        // 2. Cannot see other properties
        $this->assertFalse($this->visibilityService->canSee($this->homeowner, 'occupants', null, 'Lot 88'));
        $this->assertFalse($this->visibilityService->canSee($this->homeowner, 'occupants', null, 'Unit 99'));

        // 3. Query scoping: Homeowner sees only passes belonging to their own property
        $ownPass = GatePass::factory()->create([
            'user_id' => $this->homeowner->id,
            'property' => 'Lot 14',
            'holder_name' => 'John Guest',
        ]);

        $otherPass = GatePass::factory()->create([
            'user_id' => $this->otherHomeowner->id,
            'property' => 'Lot 88',
            'holder_name' => 'Stranger Guest',
        ]);

        $scopedQuery = $this->visibilityService->scopeAccessQuery(GatePass::query(), $this->homeowner);
        $results = $scopedQuery->get();

        $this->assertTrue($results->contains('id', $ownPass->id));
        $this->assertFalse($results->contains('id', $otherPass->id));
    }

    public function test_long_term_renter_sees_authorized_people_for_leased_unit_only(): void
    {
        $renterUser = User::factory()->role(UserRole::TemporaryHomeowner)->create([
            'name' => 'David Renter',
            'lot' => 'Villa 22',
        ]);

        Renter::create([
            'user_id' => $renterUser->id,
            'homeowner_id' => $this->homeowner->id,
            'name' => 'David Renter',
            'lot' => 'Villa 22',
            'stay_type' => 'Long-Term',
            'lease_start' => now()->subMonth(),
            'lease_end' => now()->addMonths(11),
            'status' => 'Active',
        ]);

        // Can see own leased property
        $this->assertTrue($this->visibilityService->canSee($renterUser, 'occupants', null, 'Villa 22'));

        // Cannot see other properties
        $this->assertFalse($this->visibilityService->canSee($renterUser, 'occupants', null, 'Lot 14'));
        $this->assertFalse($this->visibilityService->canSee($renterUser, 'occupants', null, 'Lot 88'));

        // Can act to invite visitor for leased unit
        $this->assertTrue($this->visibilityService->canAct($renterUser, 'invite_visitor', 'visitor', null, 'Villa 22'));

        // Cannot act on permanent subleases or property title
        $this->assertFalse($this->visibilityService->canAct($renterUser, 'sponsor_occupant', 'occupant', null, 'Villa 22'));
    }

    public function test_visitor_only_sees_their_own_credential_and_zero_other_properties(): void
    {
        $visitorUser = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'Alice Visitor',
            'title' => 'Visitor',
            'lot' => null,
        ]);

        $visitorPass = GatePass::factory()->create([
            'user_id' => $visitorUser->id,
            'holder_name' => 'Alice Visitor',
            'property' => 'Lot 14',
        ]);

        $otherPass = GatePass::factory()->create([
            'user_id' => $this->homeowner->id,
            'holder_name' => 'John Homeowner',
            'property' => 'Lot 14',
        ]);

        // Visitor can see their own credential
        $this->assertTrue($this->visibilityService->canSee($visitorUser, 'credential', $visitorPass));

        // Visitor cannot see other passes even on same property
        $this->assertFalse($this->visibilityService->canSee($visitorUser, 'credential', $otherPass));

        // Visitor cannot see any property
        $this->assertFalse($this->visibilityService->canSee($visitorUser, 'occupants', null, 'Lot 14'));

        // Visitor has ZERO creation action permissions
        $this->assertFalse($this->visibilityService->canAct($visitorUser, 'invite_visitor', 'visitor', null, 'Lot 14'));
        $this->assertFalse($this->visibilityService->canAct($visitorUser, 'create_pass', 'gate_pass'));
    }

    public function test_contractor_sees_assigned_properties_only(): void
    {
        $contractorUser = User::factory()->role(UserRole::Staff)->create([
            'name' => 'Carlos Contractor',
            'email' => 'carlos@contractor.com',
            'title' => 'Contractor',
            'lot' => null,
        ]);

        // Approved contractor request for Lot 19
        AccessApprovalRequest::create([
            'request_number' => 'APR-CON-9912',
            'category' => 'contractor',
            'applicant_name' => 'Carlos Contractor',
            'applicant_email' => 'carlos@contractor.com',
            'homeowner_id' => $this->homeowner->id,
            'property_number' => 'Lot 19',
            'workflow_type' => 'contractor_property_admin',
            'current_stage' => 'completed',
            'status' => 'approved',
            'requested_from' => now(),
            'requested_until' => now()->addDays(7),
        ]);

        // Can see assigned Lot 19
        $this->assertTrue($this->visibilityService->canSee($contractorUser, 'work_clearance', null, 'Lot 19'));

        // Cannot see unassigned Lot 14 or Lot 88
        $this->assertFalse($this->visibilityService->canSee($contractorUser, 'work_clearance', null, 'Lot 14'));
        $this->assertFalse($this->visibilityService->canSee($contractorUser, 'work_clearance', null, 'Lot 88'));

        // Can attach compliance documents
        $this->assertTrue($this->visibilityService->canAct($contractorUser, 'attach_compliance_document', 'document', null, 'Lot 19'));

        // Cannot invite visitors or approve passes
        $this->assertFalse($this->visibilityService->canAct($contractorUser, 'invite_visitor', 'visitor', null, 'Lot 19'));
    }

    public function test_legacy_contact_sees_delegated_properties_only(): void
    {
        $legacyContact = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'Mary Smith Delegate',
            'email' => 'mary@family.com',
            'title' => 'Legacy Contact',
            'lot' => null,
        ]);

        DelegatedAccess::create([
            'grantor_user_id' => $this->homeowner->id,
            'delegate_user_id' => $legacyContact->id,
            'name' => 'Mary Smith Delegate',
            'email' => 'mary@family.com',
            'relationship' => 'Family member',
            'access_level' => 'Legacy Delegate',
            'duration_type' => 'recurring',
            'status' => 'active',
        ]);

        // Can see delegated Lot 14 (from grantor homeowner)
        $this->assertTrue($this->visibilityService->canSee($legacyContact, 'delegation', null, 'Lot 14'));

        // Cannot see un-delegated Lot 88
        $this->assertFalse($this->visibilityService->canSee($legacyContact, 'delegation', null, 'Lot 88'));

        // Can trigger emergency protocol
        $this->assertTrue($this->visibilityService->canAct($legacyContact, 'emergency_activation', 'emergency', null, 'Lot 14'));

        // Cannot transfer deed or sell property
        $this->assertFalse($this->visibilityService->canAct($legacyContact, 'transfer_deed', 'property', null, 'Lot 14'));
    }

    public function test_security_community_admin_and_system_admin_have_full_community_scope(): void
    {
        $allProperties = ['Lot 14', 'Lot 88', 'Villa 22', 'Commercial Unit 3'];

        foreach ($allProperties as $prop) {
            // Security: sees all properties
            $this->assertTrue($this->visibilityService->canSee($this->security, 'occupants', null, $prop));

            // Community Admin: sees all properties
            $this->assertTrue($this->visibilityService->canSee($this->communityAdmin, 'occupants', null, $prop));

            // System Admin: sees all properties
            $this->assertTrue($this->visibilityService->canSee($this->systemAdmin, 'occupants', null, $prop));
        }

        // Query scoping: Security sees all passes across all lots
        GatePass::factory()->create(['user_id' => $this->homeowner->id, 'property' => 'Lot 14']);
        GatePass::factory()->create(['user_id' => $this->otherHomeowner->id, 'property' => 'Lot 88']);

        $securityScope = $this->visibilityService->scopeAccessQuery(GatePass::query(), $this->security);
        $this->assertEquals(GatePass::count(), $securityScope->count());
    }

    public function test_can_see_and_can_act_are_strictly_separate_permissions(): void
    {
        // 1. Homeowner can see Lot 14 occupants, can act to invite visitors, but CANNOT act to modify boundary
        $this->assertTrue($this->visibilityService->canSee($this->homeowner, 'occupants', null, 'Lot 14'));
        $this->assertTrue($this->visibilityService->canAct($this->homeowner, 'invite_visitor', 'visitor', null, 'Lot 14'));
        $this->assertFalse($this->visibilityService->canAct($this->homeowner, 'modify_boundary_infrastructure', 'boundary', null, 'Lot 14'));

        // 2. Security can see all properties, can scan passes, but CANNOT act to reassign property deeds or leases
        $this->assertTrue($this->visibilityService->canSee($this->security, 'occupants', null, 'Lot 14'));
        $this->assertTrue($this->visibilityService->canAct($this->security, 'scan_pass', 'gate_pass'));
        $this->assertTrue($this->visibilityService->canAct($this->security, 'emergency_lockdown', 'gate_pass'));
        $this->assertFalse($this->visibilityService->canAct($this->security, 'reassign_property_deed', 'property', null, 'Lot 14'));
        $this->assertFalse($this->visibilityService->canAct($this->security, 'modify_hoa_dues', 'billing'));

        // 3. Visitor can see their own pass, but CANNOT act to invite anyone
        $visitor = User::factory()->role(UserRole::Homeowner)->create(['title' => 'Visitor']);
        $pass = GatePass::factory()->create(['user_id' => $visitor->id]);
        $this->assertTrue($this->visibilityService->canSee($visitor, 'credential', $pass));
        $this->assertFalse($this->visibilityService->canAct($visitor, 'invite_visitor', 'visitor'));
    }
}
