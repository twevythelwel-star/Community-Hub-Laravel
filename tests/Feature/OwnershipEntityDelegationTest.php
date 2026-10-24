<?php

namespace Tests\Feature;

use App\Enums\AuthorizedPersonRole;
use App\Enums\GateId;
use App\Enums\OwnershipEntityType;
use App\Enums\RepresentativePermission;
use App\Enums\UserRole;
use App\Models\Property;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use App\Services\Properties\OwnershipEntityService;
use App\Services\Safety\SilentAssistanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnershipEntityDelegationTest extends TestCase
{
    use RefreshDatabase;

    private OwnershipEntityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OwnershipEntityService::class);
    }

    public function test_mary_brown_can_manage_visitors_for_unit_14_without_becoming_the_legal_owner(): void
    {
        // 1. Create the Owner Entity: ABC Holdings Limited
        $abcHoldings = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
            'registration_number' => 'ABC-991248',
            'jurisdiction' => 'Jamaica',
            'primary_email' => 'admin@abcholdings.com',
        ]);

        // 2. Property: Unit 14
        $unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
            'street_address' => '14 Palm Ridge Drive',
            'ownership_entity_id' => $abcHoldings->id,
            'owner_user_id' => null,
        ]);

        // 3. Authorized Representative: Mary Brown (Property Manager)
        $mary = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'Mary Brown',
            'display_name' => 'Mary Brown',
            'email' => 'mary.brown@abcholdings.com',
            'lot' => null,
        ]);

        $authorizedPerson = $this->service->addAuthorizedPerson($abcHoldings, [
            'user_id' => $mary->id,
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'Mary Brown',
            'email' => 'mary.brown@abcholdings.com',
            'permissions' => [
                RepresentativePermission::ManageVisitors->value,
                RepresentativePermission::ManageContractors->value,
                RepresentativePermission::ManageVehicles->value,
            ],
            'status' => 'active',
        ]);

        // Invariant 1: ABC Holdings Limited is the sole legal owner
        $this->assertSame('ABC Holdings Limited', $unit14->ownerName());
        $this->assertSame($abcHoldings->id, $unit14->effectiveOwnershipEntity()->id);
        $this->assertNull($unit14->owner_user_id);
        $this->assertNotSame($mary->id, $unit14->owner_user_id);

        // Invariant 2: Mary has permission to manage visitors for Unit 14
        $this->assertTrue($this->service->canManageVisitors($mary, $unit14));
        $this->assertTrue($mary->canManageVisitorsForProperty($unit14));
        $this->assertTrue($mary->canManageVisitorsForAnyProperty());
        $this->assertTrue($mary->can('registerVisitors'));

        // Invariant 3: Mary does NOT have ownership, billing, or transfer authority
        $this->assertFalse($authorizedPerson->canManageOwnership());
        $this->assertFalse($authorizedPerson->canTransferOwnership());
        $this->assertFalse($authorizedPerson->canManageBilling());
        $this->assertFalse($this->service->canUserPerform($mary, $unit14, 'manage_ownership'));

        // Invariant 4: Mary visits the visitors dashboard and sees Unit 14 in host properties
        $this->actingAs($mary)
            ->get('/dashboard/visitors')
            ->assertOk();

        // Invariant 5: Mary registers a visitor for Unit 14
        $response = $this->actingAs($mary)->post('/dashboard/visitors', [
            'name' => 'Michael Chang',
            'contact' => '876-555-0199',
            'type' => 'One-time',
            'expected_at' => now()->addHours(2)->toDateTimeString(),
            'property_id' => $unit14->id,
        ]);

        $response->assertSessionHasNoErrors();

        // Invariant 6: Visitor record is created with Unit 14 property_id and Mary as registrant
        $visitor = Visitor::where('name', 'Michael Chang')->first();
        $this->assertNotNull($visitor);
        $this->assertSame($unit14->id, $visitor->property_id);
        $this->assertSame($mary->id, $visitor->homeowner_id);
        $this->assertSame('Mary Brown', $visitor->homeowner_name);

        // Invariant 7: Legal ownership of Unit 14 is STILL ABC Holdings Limited
        $unit14->refresh();
        $this->assertSame('ABC Holdings Limited', $unit14->ownerName());
        $this->assertSame($abcHoldings->id, $unit14->ownership_entity_id);
        $this->assertNull($unit14->owner_user_id);
    }

    public function test_mary_cannot_register_visitors_for_unauthorized_properties(): void
    {
        $abcHoldings = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
        ]);

        $unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
            'ownership_entity_id' => $abcHoldings->id,
        ]);

        $otherOwner = User::factory()->role(UserRole::Homeowner)->create();
        $unit99 = Property::create([
            'property_code' => 'UNIT-99',
            'lot_number' => 'Unit 99',
            'ownership_entity_id' => null,
            'owner_user_id' => $otherOwner->id,
        ]);

        $mary = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Mary Brown']);

        $this->service->addAuthorizedPerson($abcHoldings, [
            'user_id' => $mary->id,
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'Mary Brown',
            'permissions' => [RepresentativePermission::ManageVisitors->value],
        ]);

        // Mary tries to register a visitor for Unit 99 (which belongs to someone else)
        $response = $this->actingAs($mary)->post('/dashboard/visitors', [
            'name' => 'Intruder Guest',
            'type' => 'One-time',
            'expected_at' => now()->addHours(2)->toDateTimeString(),
            'property_id' => $unit99->id,
        ]);

        $response->assertSessionHasErrors(['property_id']);
        $this->assertDatabaseMissing('visitors', ['name' => 'Intruder Guest']);
    }

    public function test_revoking_mary_authorization_immediately_terminates_visitor_delegation(): void
    {
        $abcHoldings = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
        ]);

        $unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
            'ownership_entity_id' => $abcHoldings->id,
        ]);

        $mary = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Mary Brown']);

        $appointment = $this->service->addAuthorizedPerson($abcHoldings, [
            'user_id' => $mary->id,
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'Mary Brown',
            'permissions' => [RepresentativePermission::ManageVisitors->value],
        ]);

        $this->assertTrue($this->service->canManageVisitors($mary, $unit14));

        // Revoke Mary's appointment (e.g. employee offboarding / management contract end)
        $this->service->revokeAuthorizedPerson($appointment, 'Contract concluded');

        // Mary can no longer manage visitors for Unit 14
        $this->assertFalse($this->service->canManageVisitors($mary, $unit14));
        $this->assertFalse($mary->canManageVisitorsForProperty($unit14));

        // ABC Holdings Limited is STILL the legal owner of Unit 14
        $unit14->refresh();
        $this->assertSame('ABC Holdings Limited', $unit14->ownerName());
    }

    public function test_delegated_access_center_displays_ownership_entity_and_authorized_reps(): void
    {
        $abcHoldings = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
        ]);

        $unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
            'ownership_entity_id' => $abcHoldings->id,
        ]);

        $mary = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Mary Brown']);

        $this->service->addAuthorizedPerson($abcHoldings, [
            'user_id' => $mary->id,
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'Mary Brown',
            'permissions' => [RepresentativePermission::ManageVisitors->value],
        ]);

        $response = $this->actingAs($mary)->get('/dashboard/delegation');
        $response->assertOk();

        // Check JSON/Inertia props
        $jsonResponse = $this->actingAs($mary)->getJson('/dashboard/delegation');
        $jsonResponse->assertOk();
        $data = $jsonResponse->json();

        $this->assertNotNull($data['ownershipEntity']);
        $this->assertSame('ABC Holdings Limited', $data['ownershipEntity']['legal_name']);
        $this->assertSame('company', $data['ownershipEntity']['entity_type']);
        $this->assertCount(1, $data['ownershipEntity']['authorizedPeople']);
        $this->assertSame('Mary Brown', $data['ownershipEntity']['authorizedPeople'][0]['name']);
        $this->assertSame('Property Manager', $data['ownershipEntity']['authorizedPeople'][0]['role']);
        $this->assertTrue($data['ownershipEntity']['authorizedPeople'][0]['is_current_user']);
    }

    public function test_company_owns_unit_14_and_displays_owner_type_property_and_representatives(): void
    {
        // 1. ABC Holdings Limited
        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
            'registration_number' => 'ABC-102938',
            'jurisdiction' => 'Jamaica',
            'primary_email' => 'corporate@abcholdings.com',
        ]);

        // 2. Unit 14
        $unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
            'ownership_entity_id' => $company->id,
            'owner_user_id' => null,
        ]);

        // 3. Representatives
        $johnUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'John Brown']);
        $maryUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Mary Brown']);
        $davidUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'David Smith']);

        $this->service->addAuthorizedPerson($company, [
            'user_id' => $johnUser->id,
            'role' => AuthorizedPersonRole::Director,
            'full_name' => 'John Brown',
            'is_primary_contact' => true,
        ]);

        $this->service->addAuthorizedPerson($company, [
            'user_id' => $maryUser->id,
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'Mary Brown',
        ]);

        $this->service->addAuthorizedPerson($company, [
            'user_id' => $davidUser->id,
            'role' => AuthorizedPersonRole::AuthorizedRepresentative,
            'full_name' => 'David Smith',
        ]);

        $this->service->addAuthorizedPerson($company, [
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'XYZ Property Management',
        ]);

        // Invariant 1: Property Model displays exact attributes
        $this->assertSame('ABC Holdings Limited', $unit14->ownerName());
        $this->assertSame('Company', $unit14->ownershipType());
        $this->assertSame('Unit 14', $unit14->lot_number);

        $details = $unit14->ownershipDetails();
        $this->assertSame('ABC Holdings Limited', $details['owner']);
        $this->assertSame('Company', $details['ownershipType']);
        $this->assertSame('Unit 14', $details['property']);
        $this->assertTrue($details['isCorporate']);
        $this->assertCount(4, $details['representatives']);

        $repNamesAndRoles = collect($details['representatives'])->map(fn ($r) => "{$r['name']} — {$r['role']}")->all();
        $this->assertContains('John Brown — Director', $repNamesAndRoles);
        $this->assertContains('Mary Brown — Property Manager', $repNamesAndRoles);
        $this->assertContains('David Smith — Authorized Representative', $repNamesAndRoles);
        $this->assertContains('XYZ Property Management — Property Manager', $repNamesAndRoles);

        // Invariant 2: None of those people becomes the owner
        $this->assertNull($unit14->owner_user_id);
        $this->assertNotSame($johnUser->id, $unit14->owner_user_id);
        $this->assertNotSame($maryUser->id, $unit14->owner_user_id);
        $this->assertNotSame($davidUser->id, $unit14->owner_user_id);

        // Invariant 3: Drilldown API displays the exact ownership block
        $guard = User::factory()->role(UserRole::Security)->create(['name' => 'Gate Officer']);

        $response = $this->actingAs($guard)->getJson('/dashboard/occupancy/unit/14');
        $response->assertOk();

        $drilldown = $response->json();
        $this->assertNotNull($drilldown['ownership']);
        $this->assertSame('ABC Holdings Limited', $drilldown['ownership']['owner']);
        $this->assertSame('Company', $drilldown['ownership']['ownershipType']);
        $this->assertSame('Unit 14', $drilldown['ownership']['property']);
        $this->assertCount(4, $drilldown['ownership']['representatives']);
    }

    public function test_removing_john_brown_authorization_when_leaving_company_preserves_property_ownership_for_abc_holdings(): void
    {
        // ABC HOLDINGS LIMITED owns UNIT 14
        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC HOLDINGS LIMITED',
            'registration_number' => 'ABC-CORP-2026',
        ]);

        $unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
            'ownership_entity_id' => $company->id,
            'owner_user_id' => null,
        ]);

        // Authorizes John Brown, Mary Brown, David Smith
        $johnUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'John Brown']);
        $maryUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Mary Brown']);
        $davidUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'David Smith']);

        $john = $this->service->addAuthorizedPerson($company, [
            'user_id' => $johnUser->id,
            'role' => AuthorizedPersonRole::Director,
            'full_name' => 'John Brown',
        ]);

        $mary = $this->service->addAuthorizedPerson($company, [
            'user_id' => $maryUser->id,
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'Mary Brown',
        ]);

        $david = $this->service->addAuthorizedPerson($company, [
            'user_id' => $davidUser->id,
            'role' => AuthorizedPersonRole::AuthorizedRepresentative,
            'full_name' => 'David Smith',
        ]);

        // Initial state: 3 active representatives, ABC HOLDINGS LIMITED is owner
        $this->assertSame('ABC HOLDINGS LIMITED', $unit14->ownerName());
        $this->assertCount(3, $unit14->authorizedRepresentatives());
        $this->assertTrue($this->service->canManagePasses($johnUser, $unit14));
        $this->assertTrue($this->service->canManagePasses($maryUser, $unit14));
        $this->assertTrue($this->service->canManagePasses($davidUser, $unit14));

        // John leaves the company: Remove John's authorization
        $this->service->revokeAuthorizedPerson($john, 'John left ABC HOLDINGS LIMITED');

        // Verification 1: John's authorization is severed
        $this->assertFalse($john->fresh()->isActive());
        $this->assertSame('revoked', $john->fresh()->status);
        $this->assertFalse($this->service->canManagePasses($johnUser, $unit14));
        $this->assertFalse($johnUser->canManageVisitorsForProperty($unit14));

        // Verification 2: Mary Brown and David Smith remain active with zero interruption
        $this->assertTrue($mary->fresh()->isActive());
        $this->assertTrue($david->fresh()->isActive());
        $this->assertTrue($this->service->canManagePasses($maryUser, $unit14));
        $this->assertTrue($this->service->canManagePasses($davidUser, $unit14));

        // Verification 3: Active representatives list now only contains Mary and David
        $activeReps = $unit14->fresh()->authorizedRepresentatives();
        $this->assertCount(2, $activeReps);
        $this->assertEqualsCanonicalizing(['Mary Brown', 'David Smith'], $activeReps->pluck('full_name')->all());

        // Verification 4: Property ownership DOES NOT CHANGE
        $unit14->refresh();
        $this->assertSame('ABC HOLDINGS LIMITED', $unit14->ownerName());
        $this->assertSame($company->id, $unit14->ownership_entity_id);
        $this->assertNull($unit14->owner_user_id);
        $this->assertNotSame($johnUser->id, $unit14->owner_user_id);
    }

    public function test_security_scans_mary_brown_qr_and_resolves_authorized_representative_of_abc_holdings(): void
    {
        // 1. Company owns Unit 14
        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC HOLDINGS LIMITED',
        ]);

        $unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
            'street_address' => '14 Palm Ridge Drive',
            'ownership_entity_id' => $company->id,
            'owner_user_id' => null,
        ]);

        // 2. Mary Brown is an Authorized Property Representative / Property Manager
        $maryUser = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'Mary Brown',
            'display_name' => 'Mary Brown',
            'lot' => null,
            'street' => null,
        ]);

        $this->service->addAuthorizedPerson($company, [
            'user_id' => $maryUser->id,
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'Mary Brown',
            'permissions' => ['manage_visitors', 'manage_passes'],
            'status' => 'active',
        ]);

        // 3. Issue QR Gate Pass to Mary
        $engine = app(GatePassEngine::class);
        $pass = $engine->issuePassFor($maryUser);
        $token = $engine->issueToken($pass, GateId::Gate01)['token'];

        // 4. Security guard scans Mary's QR code at Gate 01
        $guard = User::factory()->role(UserRole::Security)->create(['name' => 'Officer Miller']);
        $scanner = app(GateScanner::class);
        $result = $scanner->scan($token, GateId::Gate01, $guard, 'QR Scan', 'in');

        // 5. System resolves: Mary Brown, Authorized Representative, ABC HOLDINGS LIMITED, Unit 14
        $this->assertSame('CHECK_IN', $result['decision']);
        $report = $result['report'];

        $this->assertSame('Mary Brown', $report['userName']);
        $this->assertTrue($report['isEntityRepresentative']);
        $this->assertSame('ABC HOLDINGS LIMITED', $report['representedEntity']);
        $this->assertSame('Property Manager', $report['representativeRole']);
        $this->assertSame('AUTHORIZED REPRESENTATIVE', $report['authorizationType']);
        $this->assertSame('ABC HOLDINGS LIMITED', $report['actingFor']);
        $this->assertSame('Property Access', $report['permissionSummary']);
        $this->assertStringContainsString('Unit 14', $report['property']);

        // Invariant: She is NOT resolved as the individual homeowner of Unit 14
        $this->assertNotSame($maryUser->id, $unit14->fresh()->owner_user_id);
        $this->assertSame('ABC HOLDINGS LIMITED', $unit14->fresh()->ownerName());
    }

    public function test_mary_brown_authorized_representative_discreet_police_assistance_alert_resolves_all_attributes(): void
    {
        // 1. Owner Entity: ABC Holdings Limited (Company)
        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
        ]);

        // 2. Property: Unit 14
        $unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
            'ownership_entity_id' => $company->id,
            'owner_user_id' => null,
        ]);

        // 3. Person: Mary Brown — Property Manager
        $mary = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'Mary Brown',
            'display_name' => 'Mary Brown',
            'email' => 'mary.brown@abcholdings.com',
            'lot' => null,
            'street' => null,
        ]);

        $this->service->addAuthorizedPerson($company, [
            'user_id' => $mary->id,
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'Mary Brown',
            'email' => 'mary.brown@abcholdings.com',
            'permissions' => ['*'],
            'status' => 'active',
        ]);

        // 4. Her credential has a safety profile with police assistance enabled
        $safetyService = app(SilentAssistanceService::class);
        $profile = $safetyService->profileFor($mary);
        $this->assertTrue($profile->silent_alerts_enabled);
        $this->assertTrue($safetyService->allowsAssistance($mary, 'police'));

        // 5. Mary discreetly activates Police Assistance at South Gate
        $alert = $safetyService->raise($mary, 'app', 'police', [
            'gate' => GateId::SouthGate->value,
        ]);

        $this->assertNotNull($alert);

        // 6. Verify exact system resolution
        // Person: Mary Brown
        $this->assertSame('Mary Brown', $alert->personName());

        // Capacity: Authorized Representative
        $this->assertSame('Authorized Representative', $alert->capacityLabel());
        $this->assertSame('Property Manager', $alert->representativeRole());

        // Owner Entity: ABC Holdings Limited
        $this->assertSame('ABC Holdings Limited', $alert->ownerEntityName());
        $this->assertSame('Company', $alert->ownerEntityType());

        // Property: Unit 14
        $this->assertSame('Unit 14', $alert->propertyLabel());

        // Gate: South Gate
        $this->assertSame('South Gate', $alert->gateLabel());

        // Assistance: Police
        $this->assertSame('Police', $alert->assistanceLabel());

        // Status: ACTIVE
        $this->assertSame('ACTIVE', $alert->activeStatusLabel());

        // 7. Check the snapshot audit event
        $snapshot = $alert->auditEvents()->first()?->snapshot;
        $this->assertNotNull($snapshot);
        $this->assertSame('Mary Brown', $snapshot['person']);
        $this->assertSame('Authorized Representative', $snapshot['capacity']);
        $this->assertSame('Property Manager', $snapshot['representativeRole']);
        $this->assertSame('ABC Holdings Limited', $snapshot['ownerEntity']);
        $this->assertSame('Unit 14', $snapshot['property']);
        $this->assertSame('South Gate', $snapshot['gate']);
        $this->assertSame('Police', $snapshot['assistance']);
        $this->assertSame('ACTIVE', $snapshot['status']);

        // 8. Legal ownership of Unit 14 remains ABC Holdings Limited; Mary is NOT owner
        $this->assertNull($unit14->fresh()->owner_user_id);
        $this->assertSame('ABC Holdings Limited', $unit14->fresh()->ownerName());
    }

    public function test_mary_brown_arms_credential_and_scans_at_south_gate_alerts_security_with_entity_context(): void
    {
        // 1. Owner Entity: ABC Holdings Limited
        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
        ]);

        $unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
            'ownership_entity_id' => $company->id,
            'owner_user_id' => null,
        ]);

        // 2. Mary Brown — Property Manager
        $mary = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'Mary Brown',
            'display_name' => 'Mary Brown',
            'lot' => null,
            'street' => null,
        ]);

        $this->service->addAuthorizedPerson($company, [
            'user_id' => $mary->id,
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'Mary Brown',
            'permissions' => ['*'],
            'status' => 'active',
        ]);

        $safetyService = app(SilentAssistanceService::class);
        $profile = $safetyService->profileFor($mary);
        $profile->update(['preferred_assistance' => 'police']);

        // 3. Discreet QR gesture: Mary arms her credential with preferred assistance = police
        $alert = $safetyService->arm($mary);
        $this->assertNotNull($alert);
        $this->assertSame('police', $alert->assistance_type);

        // 4. Mary presents her QR pass at South Gate
        $engine = app(GatePassEngine::class);
        $pass = $engine->issuePassFor($mary);
        $token = $engine->issueToken($pass, GateId::SouthGate)['token'];

        $guard = User::factory()->role(UserRole::Security)->create(['name' => 'Officer Davis']);
        $scanner = app(GateScanner::class);
        $result = $scanner->scan($token, GateId::SouthGate, $guard, 'QR Scan', 'in');

        // 5. Guard check-in proceeds normally on-screen, but silentAssistance conveys full distress context
        $this->assertSame('CHECK_IN', $result['decision']);
        $silentAssistance = $result['report']['silentAssistance'] ?? null;
        $this->assertNotNull($silentAssistance);

        // Person: Mary Brown
        $this->assertSame('Mary Brown', $silentAssistance['person']);

        // Capacity: Authorized Representative
        $this->assertSame('Authorized Representative', $silentAssistance['capacity']);
        $this->assertSame('Property Manager', $silentAssistance['representativeRole']);

        // Owner Entity: ABC Holdings Limited
        $this->assertSame('ABC Holdings Limited', $silentAssistance['ownerEntity']);

        // Property: Unit 14
        $this->assertSame('Unit 14', $silentAssistance['property']);

        // Gate: South Gate
        $this->assertSame('South Gate', $silentAssistance['gate']);

        // Assistance: Police
        $this->assertSame('Police', $silentAssistance['assistanceLabel']);

        // Status: ACTIVE
        $this->assertSame('ACTIVE', $silentAssistance['status']);
    }

    public function test_safepass_distress_functionality_works_across_trust_and_estate_ownership_entities(): void
    {
        $safetyService = app(SilentAssistanceService::class);

        // --- TEST CASE 1: Trust ---
        $trust = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Trust,
            'legal_name' => 'The Smith Family Trust',
        ]);

        $unit14Trust = Property::create([
            'property_code' => 'UNIT-14-T',
            'lot_number' => 'Unit 14',
            'ownership_entity_id' => $trust->id,
            'owner_user_id' => null,
        ]);

        $trustee = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'John Smith',
            'display_name' => 'John Smith',
            'lot' => null,
            'street' => null,
        ]);

        $this->service->addAuthorizedPerson($trust, [
            'user_id' => $trustee->id,
            'role' => AuthorizedPersonRole::Trustee,
            'full_name' => 'John Smith',
            'permissions' => ['*'],
            'status' => 'active',
        ]);

        $trustAlert = $safetyService->raise($trustee, 'app', 'police', ['gate' => GateId::SouthGate->value]);

        $this->assertSame('John Smith', $trustAlert->personName());
        $this->assertSame('Authorized Representative', $trustAlert->capacityLabel());
        $this->assertSame('Trustee', $trustAlert->representativeRole());
        $this->assertSame('The Smith Family Trust', $trustAlert->ownerEntityName());
        $this->assertSame('Trust', $trustAlert->ownerEntityType());
        $this->assertSame('Unit 14', $trustAlert->propertyLabel());
        $this->assertSame('South Gate', $trustAlert->gateLabel());
        $this->assertSame('Police', $trustAlert->assistanceLabel());
        $this->assertSame('ACTIVE', $trustAlert->activeStatusLabel());

        // --- TEST CASE 2: Estate ---
        $estate = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Estate,
            'legal_name' => 'Estate of Robert Johnson',
        ]);

        $unit14Estate = Property::create([
            'property_code' => 'UNIT-14-E',
            'lot_number' => 'Unit 14',
            'ownership_entity_id' => $estate->id,
            'owner_user_id' => null,
        ]);

        $executor = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'Sarah Jenkins',
            'display_name' => 'Sarah Jenkins',
            'lot' => null,
            'street' => null,
        ]);

        $this->service->addAuthorizedPerson($estate, [
            'user_id' => $executor->id,
            'role' => AuthorizedPersonRole::Executor,
            'full_name' => 'Sarah Jenkins',
            'permissions' => ['*'],
            'status' => 'active',
        ]);

        $estateAlert = $safetyService->raise($executor, 'app', 'police', ['gate' => GateId::SouthGate->value]);

        $this->assertSame('Sarah Jenkins', $estateAlert->personName());
        $this->assertSame('Authorized Representative', $estateAlert->capacityLabel());
        $this->assertSame('Executor / Administrator', $estateAlert->representativeRole());
        $this->assertSame('Estate of Robert Johnson', $estateAlert->ownerEntityName());
        $this->assertSame('Estate', $estateAlert->ownerEntityType());
        $this->assertSame('Unit 14', $estateAlert->propertyLabel());
        $this->assertSame('South Gate', $estateAlert->gateLabel());
        $this->assertSame('Police', $estateAlert->assistanceLabel());
        $this->assertSame('ACTIVE', $estateAlert->activeStatusLabel());
    }
}
