<?php

namespace Tests\Feature;

use App\Actions\GatePass\ScanGatePassAction;
use App\Enums\ApprovalStatus;
use App\Enums\AuthorizationScope;
use App\Enums\AuthorizedPersonRole;
use App\Enums\GateId;
use App\Enums\OwnershipEntityType;
use App\Enums\PassCategory;
use App\Enums\PropertyRelationshipType;
use App\Enums\ScanDecision;
use App\Enums\UserRole;
use App\Models\AuthorizedPerson;
use App\Models\GatePass;
use App\Models\OwnershipEntity;
use App\Models\Person;
use App\Models\Property;
use App\Models\User;
use App\Services\Access\AccessAuthorizationService;
use App\Services\GatePassEngine;
use App\Services\Identity\PersonIdentityService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Enterprise People, Property Relationships & Access Authorization Architecture Test.
 *
 * Validates the 4 core tiers:
 * 1. Person / User Identity (One consistent identity, deduplication)
 * 2. Property Relationship (Resident · Renter · Household · Staff · Guest)
 * 3. Access Authorization (Scope · Permissions · Approval · Expiry · Revocation)
 * 4. Credentials → Access Events (QR passes, Gate decisions, Cross-property isolation)
 *
 * Plus:
 * - Ownership separated from occupancy
 * - Cross-property access denied unless explicitly authorized
 * - Compatible with existing QR scanning workflows
 */
class PeopleAndAccessArchitectureTest extends TestCase
{
    use RefreshDatabase;

    private PersonIdentityService $identityService;

    private AccessAuthorizationService $authService;

    private User $guard;

    private Property $unit14;

    private Property $unit15;

    protected function setUp(): void
    {
        parent::setUp();

        $this->identityService = app(PersonIdentityService::class);
        $this->authService = app(AccessAuthorizationService::class);

        $this->guard = User::factory()->create([
            'role' => UserRole::Security,
            'name' => 'Officer Guard',
        ]);

        $this->unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => '14',
            'street_address' => '14 Royal Palm Way',
            'status' => 'occupied',
        ]);

        $this->unit15 = Property::create([
            'property_code' => 'UNIT-15',
            'lot_number' => '15',
            'street_address' => '15 Royal Palm Way',
            'status' => 'occupied',
        ]);
    }

    // ── 1. Person / User Identity & Deduplication ───────────────────────

    public function test_person_identity_is_consistent_and_deduplicated(): void
    {
        // Create an individual by email & phone
        $person1 = $this->identityService->findOrCreate([
            'name' => 'John Sterling',
            'email' => 'john.sterling@example.com',
            'phone' => '+18765550199',
        ]);

        $this->assertInstanceOf(Person::class, $person1);
        $this->assertSame('john.sterling@example.com', $person1->email);

        // Resolving again with same email returns the identical Person record (no duplicate identity)
        $person2 = $this->identityService->findOrCreate([
            'name' => 'J. Sterling',
            'email' => 'JOHN.STERLING@EXAMPLE.COM',
        ]);

        $this->assertSame($person1->id, $person2->id);
        $this->assertSame(1, Person::where('email', 'john.sterling@example.com')->count());

        // Resolving for User maps to the same identity
        $user = User::factory()->create([
            'email' => 'john.sterling@example.com',
            'phone' => '+18765550199',
        ]);

        $personForUser = $this->identityService->findOrCreateForUser($user);
        $this->assertSame($person1->id, $personForUser->id);
        $this->assertSame($user->id, $personForUser->user_id);
    }

    // ── 2. Ownership Separate From Occupancy ────────────────────────────

    public function test_ownership_is_strictly_separated_from_occupancy(): void
    {
        // Corporate Owner: ABC Holdings Ltd
        $company = OwnershipEntity::create([
            'legal_name' => 'ABC Holdings Ltd',
            'entity_type' => OwnershipEntityType::Company,
            'registration_number' => 'REG-ABC-991',
            'status' => 'active',
        ]);

        $this->unit14->update(['ownership_entity_id' => $company->id]);

        // Authorized Representative of the Company (Corporate Director)
        $directorUser = User::factory()->create(['name' => 'Victoria Vance']);
        $rep = AuthorizedPerson::create([
            'ownership_entity_id' => $company->id,
            'user_id' => $directorUser->id,
            'full_name' => 'Victoria Vance',
            'role' => AuthorizedPersonRole::Director,
            'status' => 'active',
        ]);

        // Occupant: Primary Resident living at Unit 14
        $residentPerson = $this->identityService->findOrCreate([
            'name' => 'Marcus Cole',
            'email' => 'marcus.cole@example.com',
        ]);

        $residentRel = $this->authService->establishRelationship(
            $residentPerson,
            $this->unit14,
            PropertyRelationshipType::Resident,
            ['role_label' => 'Primary Resident']
        );

        $this->unit14->refresh();

        // Legal owner is the Company; Occupant is Marcus Cole
        $this->assertSame('ABC Holdings Ltd', $this->unit14->ownerName());
        $this->assertSame('Company', $this->unit14->ownershipType());
        $this->assertSame(PropertyRelationshipType::Resident, $residentRel->type);
        $this->assertSame('Marcus Cole', $residentRel->person->displayName());
        $this->assertSame('Victoria Vance', $rep->full_name);
        $this->assertFalse($this->unit14->is_owner_occupied);
    }

    // ── 3. Relationship Types: Resident, Renter, Household, Staff, Guest ──

    public function test_all_five_relationship_types_can_be_established_and_queried(): void
    {
        // 1. Resident
        $resident = $this->identityService->findOrCreate(['name' => 'Alice Resident', 'email' => 'alice@example.com']);
        $relResident = $this->authService->establishRelationship(
            $resident,
            $this->unit14,
            PropertyRelationshipType::Resident,
            ['role_label' => 'Homeowner Resident']
        );

        // 2. Renter (Tenant)
        $renter = $this->identityService->findOrCreate(['name' => 'Bob Renter', 'email' => 'bob@example.com']);
        $relRenter = $this->authService->establishRelationship(
            $renter,
            $this->unit14,
            PropertyRelationshipType::Renter,
            [
                'role_label' => 'Long-Term Tenant',
                'start_date' => Carbon::now()->subMonths(1),
                'end_date' => Carbon::now()->addMonths(11),
            ]
        );

        // 3. Household Member
        $household = $this->identityService->findOrCreate(['name' => 'Charlie Child', 'email' => 'charlie@example.com']);
        $relHousehold = $this->authService->establishRelationship(
            $household,
            $this->unit14,
            PropertyRelationshipType::Household,
            ['role_label' => 'Daughter', 'sponsor_person_id' => $resident->id]
        );

        // 4. Staff
        $staff = $this->identityService->findOrCreate(['name' => 'Diana Gardener', 'phone' => '+18765550400']);
        $relStaff = $this->authService->establishRelationship(
            $staff,
            $this->unit14,
            PropertyRelationshipType::Staff,
            ['role_label' => 'Head Gardener']
        );

        // 5. Guest
        $guest = $this->identityService->findOrCreate(['name' => 'Evan Guest', 'phone' => '+18765550500']);
        $relGuest = $this->authService->establishRelationship(
            $guest,
            $this->unit14,
            PropertyRelationshipType::Guest,
            [
                'role_label' => 'Weekend Guest',
                'start_date' => Carbon::today(),
                'end_date' => Carbon::today()->addDays(2),
            ]
        );

        $relationships = $this->unit14->propertyRelationships()->get();
        $this->assertCount(5, $relationships);

        $types = $relationships->map(fn ($r) => $r->type->value)->all();
        $this->assertEqualsCanonicalizing(['resident', 'renter', 'household', 'staff', 'guest'], $types);

        $this->assertTrue($relResident->isActive());
        $this->assertTrue($relRenter->isActive());
        $this->assertTrue($relHousehold->isActive());
        $this->assertTrue($relStaff->isActive());
        $this->assertTrue($relGuest->isActive());
        $this->assertFalse($relGuest->isExpired());
    }

    // ── 4. Access Authorization: Scope, Permissions, Approval, Expiry, Revocation ──

    public function test_access_authorization_lifecycle_and_approval_workflow(): void
    {
        $person = $this->identityService->findOrCreate(['name' => 'Frank Contractor', 'email' => 'frank@contractor.com']);

        // 1. Issue Pending Authorization
        $auth = $this->authService->issueAuthorization(
            $person,
            $this->unit14,
            null,
            [
                'scope' => AuthorizationScope::Property,
                'permissions' => ['gate_access', 'maintenance_access'],
                'approval_status' => ApprovalStatus::Pending,
                'valid_from' => Carbon::now(),
                'valid_until' => Carbon::now()->addDays(7),
            ]
        );

        $this->assertSame(ApprovalStatus::Pending, $auth->approval_status);
        $this->assertFalse($auth->isCurrentlyActive(), 'Pending authorization must not be active');

        // 2. Admin Approves Authorization
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $auth->approve($admin);

        $auth->refresh();
        $this->assertSame(ApprovalStatus::Approved, $auth->approval_status);
        $this->assertSame($admin->id, $auth->approved_by);
        $this->assertTrue($auth->isCurrentlyActive());
        $this->assertTrue($auth->hasPermission('gate_access'));
        $this->assertTrue($auth->hasPermission('maintenance_access'));
        $this->assertFalse($auth->hasPermission('visitor_authorization'));

        // 3. Expiry Check
        $expiredTime = Carbon::now()->addDays(10);
        $this->assertFalse($auth->isCurrentlyActive($expiredTime), 'Expired authorization must not be active');

        // 4. Revocation
        $auth->revoke($admin, 'Work completed early');
        $auth->refresh();

        $this->assertTrue($auth->is_revoked);
        $this->assertSame('revoked', $auth->status);
        $this->assertSame('Work completed early', $auth->revocation_reason);
        $this->assertFalse($auth->isCurrentlyActive());
    }

    // ── 5. Cross-Property Access Isolation ──

    public function test_cross_property_access_is_denied_unless_explicitly_authorized(): void
    {
        $residentUnit14 = $this->identityService->findOrCreate(['name' => 'George Unit14', 'email' => 'george@unit14.com']);

        // Authorize George strictly for Unit 14
        $auth = $this->authService->issueAuthorization(
            $residentUnit14,
            $this->unit14,
            null,
            [
                'scope' => AuthorizationScope::Property,
                'permissions' => ['gate_access'],
                'approval_status' => ApprovalStatus::Approved,
            ]
        );

        // Verification for Unit 14 succeeds
        $resultUnit14 = $this->authService->evaluatePropertyAccess($residentUnit14, $this->unit14);
        $this->assertTrue($resultUnit14['allowed']);
        $this->assertNull($resultUnit14['reason']);

        // Verification for Unit 15 MUST BE DENIED!
        $resultUnit15 = $this->authService->evaluatePropertyAccess($residentUnit14, $this->unit15);
        $this->assertFalse($resultUnit15['allowed']);
        $this->assertStringContainsString('Cross-property access denied', $resultUnit15['reason']);

        // Community-wide scope permits all properties
        $staffMember = $this->identityService->findOrCreate(['name' => 'Estate Plumber', 'email' => 'plumber@community.com']);
        $this->authService->issueAuthorization(
            $staffMember,
            null,
            null,
            [
                'scope' => AuthorizationScope::Community,
                'permissions' => ['gate_access'],
                'approval_status' => ApprovalStatus::Approved,
            ]
        );

        $resultCommunity14 = $this->authService->evaluatePropertyAccess($staffMember, $this->unit14);
        $resultCommunity15 = $this->authService->evaluatePropertyAccess($staffMember, $this->unit15);
        $this->assertTrue($resultCommunity14['allowed']);
        $this->assertTrue($resultCommunity15['allowed']);
    }

    // ── 6. Credentials → Gate Decisions & Scanning Workflow ────────────

    public function test_qr_passes_and_gate_scanner_workflow_integrate_seamlessly(): void
    {
        $guestPerson = $this->identityService->findOrCreate([
            'name' => 'Helen Invitee',
            'email' => 'helen@example.com',
        ]);

        $auth = $this->authService->issueAuthorization(
            $guestPerson,
            $this->unit14,
            null,
            [
                'scope' => AuthorizationScope::Property,
                'permissions' => ['gate_access'],
                'approval_status' => ApprovalStatus::Approved,
                'valid_from' => Carbon::now()->subHour(),
                'valid_until' => Carbon::now()->addHours(6),
            ]
        );

        // Issue GatePass credential
        $pass = $this->authService->issueCredential($auth, PassCategory::Visitor, [
            'single_entry' => true,
        ]);

        $this->assertSame($guestPerson->id, $pass->person_id);
        $this->assertSame($auth->id, $pass->access_authorization_id);
        $this->assertSame($this->unit14->id, $pass->property_id);
        $this->assertTrue($pass->isActive());

        // Generate signed token via GatePassEngine
        $engine = app(GatePassEngine::class);
        $tokenData = $engine->issueToken($pass, GateId::Gate01);
        $token = $tokenData['token'];

        // Guard scans token at Gate 01
        $scanAction = app(ScanGatePassAction::class);
        $scanResult = $scanAction->execute($token, 'GATE_01', $this->guard);

        $this->assertSame(ScanDecision::CheckIn->value, $scanResult['decision']);
        $this->assertSame($pass->pass_id, $scanResult['report']['passId']);

        // Test Revocation stops gate scan
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $auth->revoke($admin, 'Access terminated by host');

        // New token on revoked pass is rejected
        $revokedToken = $engine->issueToken($pass->fresh(), GateId::Gate01)['token'];
        $revokedScan = $scanAction->execute($revokedToken, 'GATE_01', $this->guard);

        $this->assertSame(ScanDecision::Reject->value, $revokedScan['decision']);
    }

    // ── 7. GateScanner Enforces Cross-Property Rejection ────────────────

    public function test_gate_scanner_rejects_cross_property_access_when_requested(): void
    {
        $guestPerson = $this->identityService->findOrCreate(['name' => 'Ian Visitor', 'email' => 'ian@example.com']);

        $auth = $this->authService->issueAuthorization(
            $guestPerson,
            $this->unit14,
            null,
            [
                'scope' => AuthorizationScope::Property,
                'permissions' => ['gate_access'],
                'approval_status' => ApprovalStatus::Approved,
            ]
        );

        $pass = $this->authService->issueCredential($auth, PassCategory::Visitor, [
            'metadata' => [
                'target_property_id' => $this->unit15->id, // Intentionally attempted for Unit 15!
            ],
        ]);

        $engine = app(GatePassEngine::class);
        $token = $engine->issueToken($pass, GateId::Gate01)['token'];

        $scanAction = app(ScanGatePassAction::class);
        $scanResult = $scanAction->execute($token, 'GATE_01', $this->guard);

        $this->assertSame(ScanDecision::Reject->value, $scanResult['decision']);
        $this->assertStringContainsString('Cross-property access denied', $scanResult['report']['primaryReason']);
    }
}
