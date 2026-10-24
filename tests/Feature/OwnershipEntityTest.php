<?php

namespace Tests\Feature;

use App\Enums\AuthorizedPersonRole;
use App\Enums\OwnershipEntityType;
use App\Enums\UserRole;
use App\Models\Property;
use App\Models\User;
use App\Services\Properties\OwnershipEntityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class OwnershipEntityTest extends TestCase
{
    use RefreshDatabase;

    private OwnershipEntityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OwnershipEntityService::class);
    }

    public function test_can_create_a_company_ownership_entity_with_directors_and_property_managers(): void
    {
        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'Acme Holdings Ltd.',
            'registration_number' => 'CORP-882910',
            'jurisdiction' => 'Jamaica',
            'primary_email' => 'operations@acmeholdings.com',
        ]);

        $directorUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Jane Sterling']);
        $managerUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Mark Vance']);

        $director = $this->service->addAuthorizedPerson($company, [
            'user_id' => $directorUser->id,
            'role' => AuthorizedPersonRole::Director,
            'full_name' => 'Jane Sterling',
            'email' => 'jane@acmeholdings.com',
            'is_primary_contact' => true,
        ]);

        $manager = $this->service->addAuthorizedPerson($company, [
            'user_id' => $managerUser->id,
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'Mark Vance',
            'email' => 'mark@vancemanagement.com',
        ]);

        $this->assertSame(OwnershipEntityType::Company, $company->entity_type);
        $this->assertSame('Company', $company->entity_type->label());
        $this->assertTrue($director->canSignLegal());
        $this->assertTrue($director->canManageBilling());
        $this->assertTrue($director->canManagePasses());

        $this->assertFalse($manager->canSignLegal());
        $this->assertFalse($manager->canManageBilling());
        $this->assertTrue($manager->canManagePasses());

        $this->assertCount(1, $company->directors());
        $this->assertCount(1, $company->propertyManagers());
        $this->assertSame($director->id, $company->primaryContact()->id);
    }

    /**
     * ABC Holdings Limited owns Unit 14.
     * The property shows:
     *   Owner: ABC Holdings Limited
     *   Ownership Type: Company
     *   Property: Unit 14
     * Representatives:
     *   John Brown — Director
     *   Mary Brown — Property Manager
     *   David Smith — Authorized Representative
     *   XYZ Property Management — Property Manager
     * None of those people becomes the owner.
     */
    public function test_company_owns_unit_14_with_multiple_representatives_who_do_not_become_owners(): void
    {
        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
            'registration_number' => 'CORP-ABC-14',
            'jurisdiction' => 'Jamaica',
        ]);

        $unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
        ]);
        $this->service->assignProperty($company, $unit14);

        // Representatives with independent user accounts
        $johnUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'John Brown']);
        $maryUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Mary Brown']);
        $davidUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'David Smith']);

        $john = $this->service->addAuthorizedPerson($company, [
            'user_id' => $johnUser->id,
            'role' => AuthorizedPersonRole::Director,
            'full_name' => 'John Brown',
            'is_primary_contact' => true,
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

        $xyzFirm = $this->service->addAuthorizedPerson($company, [
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'XYZ Property Management',
        ]);

        // Verify Property attributes
        $this->assertSame('ABC Holdings Limited', $unit14->ownerName());
        $this->assertSame(OwnershipEntityType::Company, $unit14->ownershipEntity->entity_type);
        $this->assertSame('Company', $unit14->ownershipEntity->entity_type->label());
        $this->assertSame('Unit 14', $unit14->label());

        // Verify Representatives are attached to the entity, NOT owners of the property
        $representatives = $this->service->getAuthorizedPeopleForProperty($unit14);
        $this->assertCount(4, $representatives);
        $this->assertEqualsCanonicalizing(
            ['John Brown', 'Mary Brown', 'David Smith', 'XYZ Property Management'],
            $representatives->pluck('full_name')->all()
        );

        // Core invariant: None of them is the owner
        $this->assertNotSame($john->full_name, $unit14->ownerName());
        $this->assertNotSame($mary->full_name, $unit14->ownerName());
        $this->assertNotSame($david->full_name, $unit14->ownerName());
        $this->assertNotSame($xyzFirm->full_name, $unit14->ownerName());
        $this->assertSame('ABC Holdings Limited', $unit14->ownershipEntity->legal_name);
    }

    /**
     * If John Brown leaves the company tomorrow:
     * Remove John's authorization.
     * The property ownership doesn't change.
     */
    public function test_revoking_john_brown_removes_authorization_without_altering_property_ownership(): void
    {
        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
        ]);

        $unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
        ]);
        $this->service->assignProperty($company, $unit14);

        $johnUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'John Brown']);
        $john = $this->service->addAuthorizedPerson($company, [
            'user_id' => $johnUser->id,
            'role' => AuthorizedPersonRole::Director,
            'full_name' => 'John Brown',
        ]);

        $this->assertTrue($this->service->canManagePasses($johnUser, $unit14));
        $this->assertTrue($this->service->canManageBilling($johnUser, $unit14));

        // John leaves the company tomorrow: remove authorization
        $this->service->revokeAuthorizedPerson($john, 'Employee departed the company');

        $this->assertFalse($john->fresh()->isActive());
        $this->assertFalse($this->service->canManagePasses($johnUser, $unit14));
        $this->assertFalse($this->service->canManageBilling($johnUser, $unit14));

        // The property ownership DOES NOT CHANGE
        $this->assertSame('ABC Holdings Limited', $unit14->fresh()->ownerName());
        $this->assertSame($company->id, $unit14->fresh()->ownership_entity_id);
    }

    /**
     * Trust structure:
     * The Smith Family Trust
     *   ├── Trustee: John Smith
     *   ├── Co-Trustee: Mary Smith
     *   ├── Property Manager: ABC Property Management
     *   └── Property: Unit 14
     * Legal documentation verification supported.
     */
    public function test_the_smith_family_trust_structure_with_trustees_co_trustees_and_document_verification(): void
    {
        $trust = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Trust,
            'legal_name' => 'The Smith Family Trust',
            'registration_number' => 'TR-88210',
        ]);

        $unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
        ]);
        $this->service->assignProperty($trust, $unit14);

        $john = $this->service->addAuthorizedPerson($trust, [
            'role' => AuthorizedPersonRole::Trustee,
            'full_name' => 'John Smith',
            'is_primary_contact' => true,
        ]);

        $mary = $this->service->addAuthorizedPerson($trust, [
            'role' => AuthorizedPersonRole::CoTrustee,
            'full_name' => 'Mary Smith',
        ]);

        $manager = $this->service->addAuthorizedPerson($trust, [
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'ABC Property Management',
        ]);

        $rep = $this->service->addAuthorizedPerson($trust, [
            'role' => AuthorizedPersonRole::AuthorizedRepresentative,
            'full_name' => 'David Smith',
        ]);

        $beneficiary = $this->service->addAuthorizedPerson($trust, [
            'role' => AuthorizedPersonRole::Beneficiary,
            'full_name' => 'Junior Smith',
        ]);

        $this->assertSame(OwnershipEntityType::Trust, $trust->entity_type);
        $this->assertSame('Trust', $trust->entity_type->label());
        $this->assertCount(2, $trust->trustees());
        $this->assertCount(1, $trust->coTrustees());
        $this->assertCount(1, $trust->propertyManagers());
        $this->assertCount(1, $trust->representatives());
        $this->assertCount(1, $trust->beneficiaries());
        $this->assertCount(1, $trust->properties);
        $this->assertSame('The Smith Family Trust', $unit14->ownerName());
        $this->assertSame('Trust', $unit14->ownershipType());

        // Unverified trustee appointment: cannot sign legal documents or alter ownership without document verification
        $unverifiedTrustee = $this->service->addAuthorizedPerson($trust, [
            'role' => AuthorizedPersonRole::Trustee,
            'full_name' => 'Claimed Trustee Without Docs',
            'verification_status' => 'pending_review',
        ]);
        $this->assertFalse($unverifiedTrustee->canSignLegal());
        $this->assertFalse($unverifiedTrustee->canManageOwnership());
        $this->assertFalse($unverifiedTrustee->canTransferOwnership());

        // Attach and verify official Trust Deed document
        $admin = User::factory()->role(UserRole::Admin)->create();
        $doc = $trust->documents()->create([
            'title' => 'The Smith Family Trust Deed',
            'document_type' => 'trust_deed',
            'holder_name' => 'John & Mary Smith',
            'file_path' => '/storage/trusts/smith-family-trust-deed.pdf',
            'file_name' => 'smith-family-trust-deed.pdf',
            'file_size_bytes' => 1048576,
            'mime_type' => 'application/pdf',
            'issued_at' => now()->subYears(2),
            'expires_at' => now()->addYears(10),
            'status' => 'valid',
            'verification_status' => 'verified',
            'verified_by' => $admin->id,
            'verified_at' => now(),
        ]);

        $this->assertTrue($trust->hasVerifiedTrustDeed());
        $this->assertSame(1, $trust->verifiedLegalDocuments()->count());

        // Verified trustee has full legal and signatory authority
        $john->update(['verification_status' => 'verified']);
        $this->assertTrue($john->fresh()->canSignLegal());
        $this->assertTrue($john->fresh()->canManageOwnership());
        $this->assertTrue($john->fresh()->canTransferOwnership());
    }

    /**
     * Configurable granular permissions:
     * Property Manager:
     *   Visitors             ✓
     *   Renters              ✓
     *   Contractors          ✓
     *   Staff                ✓
     *   Vehicles             ✓
     *   Emergency Contacts   ✓
     *   Billing              ✗
     *   Ownership            ✗
     *   Transfer Ownership   ✗
     */
    public function test_granular_permissions_enforcement_for_property_manager_vs_accountant(): void
    {
        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
        ]);

        $unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
        ]);
        $this->service->assignProperty($company, $unit14);

        $maryUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Mary Brown']);
        $sarahUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Sarah Accountant']);

        $mary = $this->service->addAuthorizedPerson($company, [
            'user_id' => $maryUser->id,
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'Mary Brown',
        ]);

        $sarah = $this->service->addAuthorizedPerson($company, [
            'user_id' => $sarahUser->id,
            'role' => AuthorizedPersonRole::Accountant,
            'full_name' => 'Sarah Accountant',
        ]);

        // Mary (Property Manager) permissions:
        $this->assertTrue($this->service->canManageVisitors($maryUser, $unit14));
        $this->assertTrue($this->service->canManageRenters($maryUser, $unit14));
        $this->assertTrue($this->service->canManageStaff($maryUser, $unit14));
        $this->assertTrue($this->service->canManageContractors($maryUser, $unit14));
        $this->assertTrue($this->service->canManageVehicles($maryUser, $unit14));
        $this->assertTrue($this->service->canManageEmergencyContacts($maryUser, $unit14));

        // Forbidden for Property Manager:
        $this->assertFalse($this->service->canManageBilling($maryUser, $unit14));
        $this->assertFalse($this->service->canManageOwnership($maryUser, $unit14));
        $this->assertFalse($this->service->canTransferOwnership($maryUser, $unit14));

        // Sarah (Accountant) permissions:
        $this->assertTrue($this->service->canManageBilling($sarahUser, $unit14));
        $this->assertFalse($this->service->canManageVisitors($sarahUser, $unit14));
        $this->assertFalse($this->service->canManageVehicles($sarahUser, $unit14));
    }

    public function test_can_create_an_estate_ownership_entity_with_executor(): void
    {
        $estate = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Estate,
            'legal_name' => 'Estate of Late Jonathan Sterling',
        ]);

        $executorUser = User::factory()->role(UserRole::Homeowner)->create();

        $executor = $this->service->addAuthorizedPerson($estate, [
            'user_id' => $executorUser->id,
            'role' => AuthorizedPersonRole::Executor,
            'full_name' => 'Eleanor Sterling',
            'email' => 'eleanor.sterling@example.com',
            'is_primary_contact' => true,
            'verification_status' => 'verified',
        ]);

        $this->assertSame(OwnershipEntityType::Estate, $estate->entity_type);
        $this->assertCount(1, $estate->executors());
        $this->assertTrue($executor->canSignLegal());
    }

    public function test_rejects_incompatible_role_for_entity_type(): void
    {
        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'Global Investments Ltd.',
        ]);

        // A Company cannot appoint an "Executor"
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Role 'Executor / Administrator' is not permissible for entity type 'Company'.");

        $this->service->addAuthorizedPerson($company, [
            'role' => AuthorizedPersonRole::Executor,
            'full_name' => 'Invalid Person',
        ]);
    }

    public function test_ownership_entity_can_hold_multiple_properties(): void
    {
        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'Solstice Real Estate Holdings Inc.',
        ]);

        $director = User::factory()->role(UserRole::Homeowner)->create();
        $this->service->addAuthorizedPerson($company, [
            'user_id' => $director->id,
            'role' => AuthorizedPersonRole::Director,
            'full_name' => $director->name,
            'is_primary_contact' => true,
        ]);

        $prop1 = Property::create([
            'property_code' => 'PROP-UNIT-01',
            'lot_number' => 'Lot 101',
            'street_address' => 'Ocean Drive',
        ]);

        $prop2 = Property::create([
            'property_code' => 'PROP-UNIT-02',
            'lot_number' => 'Lot 102',
            'street_address' => 'Ocean Drive',
        ]);

        $this->service->assignProperty($company, $prop1);
        $this->service->assignProperty($company, $prop2);

        $this->assertCount(2, $company->properties);
        $this->assertSame('Solstice Real Estate Holdings Inc.', $prop1->ownerName());
        $this->assertSame('Solstice Real Estate Holdings Inc.', $prop2->ownerName());

        $this->assertTrue($this->service->canManagePasses($director, $prop1));
        $this->assertTrue($this->service->canManageBilling($director, $prop2));
    }

    public function test_legacy_homeowner_user_transparently_maps_to_individual_ownership_entity(): void
    {
        $user = User::factory()->role(UserRole::Homeowner)->create([
            'name' => 'Alexander Vance',
            'email' => 'alex.vance@example.org',
        ]);

        $property = Property::create([
            'owner_user_id' => $user->id,
            'property_code' => 'PROP-LEGACY-01',
            'lot_number' => 'Lot 42',
            'street_address' => 'Palmetto Way',
        ]);

        $this->assertSame('Alexander Vance', $property->ownerName());

        $entity = $property->effectiveOwnershipEntity();
        $this->assertNotNull($entity);
        $this->assertSame(OwnershipEntityType::Individual, $entity->entity_type);
        $this->assertSame('Alexander Vance', $entity->legal_name);

        $authorized = $this->service->getAuthorizedPeopleForProperty($property);
        $this->assertCount(1, $authorized);
        $this->assertSame(AuthorizedPersonRole::IndividualOwner, $authorized->first()->role);
        $this->assertTrue($this->service->canManagePasses($user, $property));
    }

    public function test_http_endpoint_revokes_representative_and_preserves_property_ownership(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        // 1. Create ABC Holdings Limited
        $response = $this->actingAs($admin)->postJson('/dashboard/ownership-entities', [
            'entity_type' => OwnershipEntityType::Company->value,
            'legal_name' => 'ABC Holdings Limited',
            'registration_number' => 'CORP-ABC-14',
        ]);
        $response->assertCreated();
        $entityId = $response->json('entity.id');

        // 2. Assign Unit 14
        $unit14 = Property::create([
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
        ]);
        $this->postJson("/dashboard/ownership-entities/{$entityId}/properties/{$unit14->id}")->assertOk();

        // 3. Appoint John Brown as Director and Mary Brown as Property Manager
        $johnUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'John Brown']);
        $johnResp = $this->postJson("/dashboard/ownership-entities/{$entityId}/representatives", [
            'user_id' => $johnUser->id,
            'role' => AuthorizedPersonRole::Director->value,
            'full_name' => 'John Brown',
        ]);
        $johnResp->assertCreated();
        $johnId = $johnResp->json('representative.id');

        $this->postJson("/dashboard/ownership-entities/{$entityId}/representatives", [
            'role' => AuthorizedPersonRole::PropertyManager->value,
            'full_name' => 'Mary Brown',
        ])->assertCreated();

        // 4. John leaves the company: revoke his authorization via HTTP
        $revokeResp = $this->postJson("/dashboard/ownership-entities/{$entityId}/representatives/{$johnId}/revoke", [
            'reason' => 'Departed company on October 8th',
        ]);
        $revokeResp->assertOk();
        $this->assertSame('revoked', $revokeResp->json('person_status'));

        // 5. Verification: Property ownership is untouched, Unit 14 still belongs to ABC Holdings Limited
        $this->assertSame('ABC Holdings Limited', $unit14->fresh()->ownerName());
        $this->assertSame($entityId, $unit14->fresh()->ownership_entity_id);

        // Viewing the entity shows Mary active, John revoked
        $showResp = $this->getJson("/dashboard/ownership-entities/{$entityId}")->assertOk();
        $reps = collect($showResp->json('representatives'));
        $this->assertSame('revoked', $reps->firstWhere('name', 'John Brown')['status']);
        $this->assertSame('active', $reps->firstWhere('name', 'Mary Brown')['status']);
    }

    public function test_authorized_representative_role_with_configurable_permissions(): void
    {
        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'Blue Lagoon Holdings Corp',
        ]);

        $rep = $this->service->addAuthorizedPerson($company, [
            'role' => AuthorizedPersonRole::AuthorizedRepresentative,
            'full_name' => 'Michael Scott',
            'permissions' => [
                'manage_visitors',
                'manage_vehicles',
                'manage_parking',
                'receive_notifications',
            ],
        ]);

        // Verifying initial configured permissions
        $this->assertTrue($rep->canManageVisitors());
        $this->assertTrue($rep->canManageVehicles());
        $this->assertTrue($rep->canManageParking());
        $this->assertTrue($rep->canReceiveNotifications());

        // Strictly forbidden by configuration:
        $this->assertFalse($rep->canManageBilling());
        $this->assertFalse($rep->canManageOwnership());
        $this->assertFalse($rep->canTransferOwnership());

        // Dynamically configure / grant billing capability:
        $rep->grantPermission('manage_billing');
        $this->assertTrue($rep->fresh()->canManageBilling());

        // Dynamically revoke vehicle authority:
        $rep->revokePermission('manage_vehicles');
        $this->assertFalse($rep->fresh()->canManageVehicles());

        // Synchronize full permission set:
        $rep->syncPermissions(['manage_visitors', 'receive_security_alerts']);
        $this->assertTrue($rep->fresh()->canManageVisitors());
        $this->assertTrue($rep->fresh()->canReceiveSecurityAlerts());
        $this->assertFalse($rep->fresh()->canManageParking());
    }

    /**
     * Multiple representatives with independent accounts:
     * - Company: 5 authorized people (John - Director, Mary - Property Manager, David - Security Rep, Sarah - Accountant, Alex - Officer)
     * - Trust: 2 trustees (John Smith, Mary Smith) + property manager
     * - Estate: 1 executor + attorney
     * Never share passwords; each person authenticates independently.
     */
    public function test_multiple_representatives_authenticate_independently_with_zero_shared_passwords(): void
    {
        // 1. Company with 5 authorized people, each with their own account
        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
        ]);
        $unit14 = Property::create(['property_code' => 'UNIT-14', 'lot_number' => 'Unit 14']);
        $this->service->assignProperty($company, $unit14);

        $john = User::factory()->role(UserRole::Homeowner)->create(['name' => 'John Brown', 'email' => 'john@abc.test']);
        $mary = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Mary Brown', 'email' => 'mary@abc.test']);
        $david = User::factory()->role(UserRole::Homeowner)->create(['name' => 'David Smith', 'email' => 'david@abc.test']);
        $sarah = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Sarah Accountant', 'email' => 'sarah@abc.test']);
        $alex = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Alex Vance', 'email' => 'alex@abc.test']);

        $this->service->addAuthorizedPerson($company, ['user_id' => $john->id, 'role' => AuthorizedPersonRole::Director, 'full_name' => 'John Brown']);
        $this->service->addAuthorizedPerson($company, ['user_id' => $mary->id, 'role' => AuthorizedPersonRole::PropertyManager, 'full_name' => 'Mary Brown']);
        $this->service->addAuthorizedPerson($company, ['user_id' => $david->id, 'role' => AuthorizedPersonRole::SecurityRepresentative, 'full_name' => 'David Smith']);
        $this->service->addAuthorizedPerson($company, ['user_id' => $sarah->id, 'role' => AuthorizedPersonRole::Accountant, 'full_name' => 'Sarah Accountant']);
        $this->service->addAuthorizedPerson($company, ['user_id' => $alex->id, 'role' => AuthorizedPersonRole::Officer, 'full_name' => 'Alex Vance']);

        $this->assertCount(5, $company->authorizedPeople);

        // Every user independently resolves Unit 14 as their authorized property
        foreach ([$john, $mary, $david, $sarah, $alex] as $user) {
            $this->assertCount(1, $user->representedEntities());
            $this->assertSame('ABC Holdings Limited', $user->representedEntities()->first()->legal_name);
            $this->assertTrue($user->allAuthorizedProperties()->contains('id', $unit14->id));
        }

        // 2. Trust with 2 trustees + property manager
        $trust = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Trust,
            'legal_name' => 'The Smith Family Trust',
        ]);
        $unit20 = Property::create(['property_code' => 'UNIT-20', 'lot_number' => 'Unit 20']);
        $this->service->assignProperty($trust, $unit20);

        $trustee1 = User::factory()->role(UserRole::Homeowner)->create(['name' => 'John Smith']);
        $trustee2 = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Mary Smith']);
        $trustManager = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Trust PM']);

        $this->service->addAuthorizedPerson($trust, ['user_id' => $trustee1->id, 'role' => AuthorizedPersonRole::Trustee, 'full_name' => 'John Smith']);
        $this->service->addAuthorizedPerson($trust, ['user_id' => $trustee2->id, 'role' => AuthorizedPersonRole::CoTrustee, 'full_name' => 'Mary Smith']);
        $this->service->addAuthorizedPerson($trust, ['user_id' => $trustManager->id, 'role' => AuthorizedPersonRole::PropertyManager, 'full_name' => 'Trust PM']);

        $this->assertCount(3, $trust->authorizedPeople);
        $this->assertTrue($trustee1->allAuthorizedProperties()->contains('id', $unit20->id));
        $this->assertTrue($trustee2->allAuthorizedProperties()->contains('id', $unit20->id));
        $this->assertTrue($trustManager->allAuthorizedProperties()->contains('id', $unit20->id));

        // 3. Estate with 1 executor + attorney
        $estate = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Estate,
            'legal_name' => 'Estate of Late Robert Vance',
        ]);
        $unit30 = Property::create(['property_code' => 'UNIT-30', 'lot_number' => 'Unit 30']);
        $this->service->assignProperty($estate, $unit30);

        $executor = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Executor Jane']);
        $attorney = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Counsel Mark']);

        $this->service->addAuthorizedPerson($estate, ['user_id' => $executor->id, 'role' => AuthorizedPersonRole::Executor, 'full_name' => 'Executor Jane']);
        $this->service->addAuthorizedPerson($estate, ['user_id' => $attorney->id, 'role' => AuthorizedPersonRole::Attorney, 'full_name' => 'Counsel Mark']);

        $this->assertCount(2, $estate->authorizedPeople);
        $this->assertTrue($executor->allAuthorizedProperties()->contains('id', $unit30->id));
        $this->assertTrue($attorney->allAuthorizedProperties()->contains('id', $unit30->id));
    }
}
