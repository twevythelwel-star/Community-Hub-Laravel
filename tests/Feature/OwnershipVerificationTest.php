<?php

namespace Tests\Feature;

use App\Enums\AuthorizedPersonRole;
use App\Enums\OwnershipEntityType;
use App\Enums\UserRole;
use App\Models\Property;
use App\Models\User;
use App\Services\Properties\OwnershipEntityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OwnershipVerificationTest extends TestCase
{
    use RefreshDatabase;

    private OwnershipEntityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OwnershipEntityService::class);
    }

    /**
     * Requirement 1: Company Ownership Verification
     * "Company: Verify appropriate corporate information/documentation.
     * The Companies Office of Jamaica provides company registration and basic company information,
     * and identifies directors, shareholders and other corporate information;
     * beneficial ownership information has separate confidentiality/access rules."
     */
    public function test_company_ownership_verification_with_coj_records_directors_and_shareholders(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create(['name' => 'Compliance Officer']);

        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
            'registration_number' => 'CORP-ABC-2024',
            'jurisdiction' => 'Jamaica',
            'billing_address' => 'Suite 400, 14 Financial Crescent, New Kingston, Jamaica',
        ]);

        $directorUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Jane Sterling', 'email' => 'jane@abcholdings.com']);

        $this->service->addAuthorizedPerson($company, [
            'user_id' => $directorUser->id,
            'role' => AuthorizedPersonRole::Director,
            'full_name' => 'Jane Sterling',
            'email' => 'jane@abcholdings.com',
            'is_primary_contact' => true,
            'is_signatory' => true,
        ]);

        $payload = [
            'registration_number' => 'CORP-ABC-2024',
            'company_status' => 'Active / Good Standing',
            'directors' => [
                ['name' => 'Jane Sterling', 'role' => 'Managing Director', 'coj_verified' => true],
                ['name' => 'Marcus Sterling', 'role' => 'Executive Director', 'coj_verified' => true],
            ],
            'shareholders' => [
                ['name' => 'Sterling Family Holdings Ltd.', 'shares' => '65,000 Ordinary', 'voting_pct' => '65%'],
                ['name' => 'Marcus Sterling', 'shares' => '35,000 Ordinary', 'voting_pct' => '35%'],
            ],
            'articles_verified' => true,
            'cert_of_incorporation_verified' => true,
            'registered_office_verified' => true,
            'beneficial_ownership' => [
                'declaration_reference' => 'COJ-BO-2024-88190',
                'filing_date' => '2024-05-18',
                'coj_form_reference' => 'COJ Form 38 (Beneficial Ownership Declaration)',
                'beneficial_owners' => [
                    [
                        'full_name' => 'Jane Elizabeth Sterling',
                        'nationality' => 'Jamaican',
                        'id_trn' => 'TRN-109-882-314',
                        'percentage_interest' => 65.0,
                        'control_nature' => 'Direct shareholding (>25% voting rights) & Managing Director',
                        'pep_status' => false,
                        'verified_at' => '2024-05-18',
                    ],
                ],
            ],
        ];

        $verifiedCompany = $this->service->verifyOwnershipEntity($company, $payload, $admin);

        $this->assertTrue($verifiedCompany->isVerified());
        $this->assertSame('verified', $verifiedCompany->verification_status);
        $this->assertSame('Companies Office of Jamaica (COJ)', $verifiedCompany->verification_source);
        $this->assertTrue($verifiedCompany->verification_checklist['coj_registration_number_verified']);
        $this->assertTrue($verifiedCompany->verification_checklist['directors_identified']);
        $this->assertTrue($verifiedCompany->verification_checklist['shareholders_identified']);

        $summary = $verifiedCompany->getVerificationSummary();
        $this->assertNotNull($summary['coj_details']);
        $this->assertCount(2, $summary['coj_details']['directors']);
        $this->assertCount(2, $summary['coj_details']['shareholders']);
    }

    /**
     * Requirement 2: Trust Ownership Verification
     * "Trust: Require appropriate trust documentation and trustee authorization."
     */
    public function test_trust_ownership_verification_requires_trust_deed_and_trustee_authorization(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        $trust = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Trust,
            'legal_name' => 'The Fairview Estate Family Trust',
            'registration_number' => 'TRUST-JA-2021-994',
        ]);

        $payload = [
            'deed_reference_number' => 'TR-2021-994',
            'settlement_date' => '2021-03-15',
            'governing_law' => 'Jamaica',
            'trustees' => [
                ['name' => 'Marcus Alexander Sterling', 'role' => 'Managing Trustee'],
                ['name' => 'National Commercial Trust Ltd.', 'role' => 'Corporate Trustee'],
            ],
            'trustee_resolution_verified' => true,
            'beneficiaries' => [
                ['name' => 'Chloe Sterling', 'type' => 'Primary Beneficiary'],
            ],
            'documents' => [
                [
                    'title' => 'Stamped Trust Deed & Settlement Deed',
                    'document_type' => 'trust_deed',
                    'file_path' => 'documents/trust/deed.pdf',
                    'file_name' => 'deed.pdf',
                ],
            ],
        ];

        $verifiedTrust = $this->service->verifyOwnershipEntity($trust, $payload, $admin);

        $this->assertTrue($verifiedTrust->isVerified());
        $this->assertSame('Trust Deed & Trustee Authorization Registry', $verifiedTrust->verification_source);
        $this->assertTrue($verifiedTrust->verification_checklist['trust_deed_inspected']);
        $this->assertTrue($verifiedTrust->verification_checklist['trustees_identified_and_authorized']);
        $this->assertTrue($verifiedTrust->verification_checklist['trustee_resolution_verified']);
        $this->assertCount(1, $verifiedTrust->documents);
        $this->assertSame('trust_deed', $verifiedTrust->documents->first()->document_type);
    }

    /**
     * Requirement 3: Estate Ownership Verification
     * "Estate: Require appropriate estate/executor documentation."
     */
    public function test_estate_ownership_verification_requires_grant_of_probate_and_executor_documentation(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        $estate = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Estate,
            'legal_name' => 'Estate of Late Robert Sterling',
            'registration_number' => 'PROB-2023-0182',
        ]);

        $payload = [
            'deceased_name' => 'Robert Alexander Sterling',
            'date_of_death' => '2022-11-04',
            'probate_suit_number' => 'PROB-2023-0182',
            'court_jurisdiction' => 'Supreme Court of Jamaica (Probate Division)',
            'death_cert_verified' => true,
            'executors' => [
                ['name' => 'Jane Elizabeth Sterling', 'role' => 'Sole Executrix under Will'],
            ],
            'documents' => [
                [
                    'title' => 'Supreme Court Grant of Probate',
                    'document_type' => 'grant_of_probate',
                    'file_path' => 'documents/estate/grant_probate.pdf',
                    'file_name' => 'grant_probate.pdf',
                ],
            ],
        ];

        $verifiedEstate = $this->service->verifyOwnershipEntity($estate, $payload, $admin);

        $this->assertTrue($verifiedEstate->isVerified());
        $this->assertSame('Supreme Court Probate & Administration Division', $verifiedEstate->verification_source);
        $this->assertTrue($verifiedEstate->verification_checklist['grant_of_probate_or_administration_verified']);
        $this->assertTrue($verifiedEstate->verification_checklist['death_certificate_verified']);
        $this->assertTrue($verifiedEstate->verification_checklist['executors_or_administrators_authorized']);
        $this->assertCount(1, $verifiedEstate->documents);
    }

    /**
     * Requirement 4: Individual Ownership Verification
     * "Individual: Verify identity and property ownership/authorization."
     */
    public function test_individual_ownership_verification_requires_identity_and_property_title(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        $individual = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Individual,
            'legal_name' => 'Dr. Keith Reynolds',
        ]);

        $payload = [
            'id_type' => 'National ID',
            'id_number' => 'NID-992-104-81',
            'trn' => 'TRN-182-990-213',
            'volume' => '1408',
            'folio' => '30',
            'title_verified' => true,
            'ownership_confirmed' => true,
            'registered_owner' => 'Dr. Keith Reynolds',
            'documents' => [
                [
                    'title' => 'Registered Duplicate Certificate of Title (Vol 1408 / Fol 30)',
                    'document_type' => 'certificate_of_title',
                    'file_path' => 'documents/titles/title_reynolds.pdf',
                    'file_name' => 'title_reynolds.pdf',
                ],
            ],
        ];

        $verifiedIndividual = $this->service->verifyOwnershipEntity($individual, $payload, $admin);

        $this->assertTrue($verifiedIndividual->isVerified());
        $this->assertSame('National Land Agency (NLA) & Registrar General', $verifiedIndividual->verification_source);
        $this->assertTrue($verifiedIndividual->verification_checklist['government_photo_id_verified']);
        $this->assertTrue($verifiedIndividual->verification_checklist['trn_verified']);
        $this->assertTrue($verifiedIndividual->verification_checklist['duplicate_certificate_of_title_verified']);
        $this->assertTrue($verifiedIndividual->verification_checklist['property_authorization_confirmed']);
    }

    /**
     * Requirement 5: Strict Beneficial Ownership Confidentiality & Protection
     * "Do not expose beneficial ownership information to ordinary residents or security personnel.
     * The Companies Office specifically treats beneficial ownership information as private/confidential and limits access."
     */
    public function test_beneficial_ownership_is_strictly_hidden_from_residents_and_security_personnel(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create(['name' => 'Compliance Admin']);

        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
            'registration_number' => 'CORP-ABC-2024',
        ]);

        // Director
        $directorUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Jane Sterling', 'email' => 'jane@abcholdings.com']);
        $this->service->addAuthorizedPerson($company, [
            'user_id' => $directorUser->id,
            'role' => AuthorizedPersonRole::Director,
            'full_name' => 'Jane Sterling',
            'is_signatory' => true,
        ]);

        // Property Manager (Representative who is NOT director or signatory)
        $managerUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Mark Vance']);
        $this->service->addAuthorizedPerson($company, [
            'user_id' => $managerUser->id,
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'Mark Vance',
            'is_signatory' => false,
        ]);

        // Security Guard (Gate Personnel)
        $guardUser = User::factory()->role(UserRole::Security)->create(['name' => 'Officer Miller']);

        // Ordinary Resident / Homeowner (Neighboring unit)
        $residentUser = User::factory()->role(UserRole::Homeowner)->create(['name' => 'Alice Resident']);

        // Set confidential beneficial ownership records (COJ Form 38)
        $company->update([
            'beneficial_ownership' => [
                'declaration_reference' => 'COJ-BO-2024-88190',
                'filing_date' => '2024-05-18',
                'coj_form_reference' => 'COJ Form 38 (Beneficial Ownership Declaration)',
                'beneficial_owners' => [
                    [
                        'full_name' => 'Jane Elizabeth Sterling',
                        'nationality' => 'Jamaican',
                        'id_trn' => 'TRN-109-882-314',
                        'percentage_interest' => 65.0,
                        'control_nature' => 'Direct shareholding (>25% voting rights) & Managing Director',
                    ],
                    [
                        'full_name' => 'Marcus Alexander Sterling',
                        'nationality' => 'Jamaican',
                        'id_trn' => 'TRN-144-902-188',
                        'percentage_interest' => 35.0,
                        'control_nature' => 'Direct shareholding (>25% voting rights)',
                    ],
                ],
            ],
        ]);

        // 1. Director Jane Sterling CAN view beneficial ownership
        $this->assertTrue($company->canUserViewBeneficialOwnership($directorUser));
        $directorProfile = $company->getBeneficialOwnershipProfile($directorUser);
        $this->assertFalse($directorProfile['is_restricted']);
        $this->assertCount(2, $directorProfile['beneficial_owners']);
        $this->assertSame('Jane Elizabeth Sterling', $directorProfile['beneficial_owners'][0]['full_name']);

        // 2. Compliance Admin CAN view beneficial ownership
        $this->assertTrue($company->canUserViewBeneficialOwnership($admin));
        $adminProfile = $company->getBeneficialOwnershipProfile($admin);
        $this->assertFalse($adminProfile['is_restricted']);
        $this->assertCount(2, $adminProfile['beneficial_owners']);

        // 3. Property Manager Mark Vance CANNOT view beneficial ownership
        $this->assertFalse($company->canUserViewBeneficialOwnership($managerUser));
        $managerProfile = $company->getBeneficialOwnershipProfile($managerUser);
        $this->assertTrue($managerProfile['is_restricted']);
        $this->assertEmpty($managerProfile['beneficial_owners']);

        // 4. Security Guard CANNOT view beneficial ownership (MUST NEVER BE EXPOSED)
        $this->assertFalse($company->canUserViewBeneficialOwnership($guardUser));
        $guardProfile = $company->getBeneficialOwnershipProfile($guardUser);
        $this->assertTrue($guardProfile['is_restricted']);
        $this->assertEmpty($guardProfile['beneficial_owners']);
        $this->assertStringContainsString('confidential', strtolower($guardProfile['statutory_notice']));

        // 5. Ordinary Resident CANNOT view beneficial ownership
        $this->assertFalse($company->canUserViewBeneficialOwnership($residentUser));
        $residentProfile = $company->getBeneficialOwnershipProfile($residentUser);
        $this->assertTrue($residentProfile['is_restricted']);
        $this->assertEmpty($residentProfile['beneficial_owners']);
    }

    /**
     * Requirement 6: Controller API Endpoints & Redaction Verification
     */
    public function test_api_endpoints_redact_beneficial_ownership_and_enforce_role_checks(): void
    {
        $admin = User::factory()->role(UserRole::Admin)->create();

        $company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
            'registration_number' => 'CORP-ABC-2024',
        ]);

        $directorUser = User::factory()->role(UserRole::Homeowner)->create();
        $this->service->addAuthorizedPerson($company, [
            'user_id' => $directorUser->id,
            'role' => AuthorizedPersonRole::Director,
            'full_name' => 'Jane Sterling',
            'is_signatory' => true,
        ]);

        $guardUser = User::factory()->role(UserRole::Security)->create();
        $residentUser = User::factory()->role(UserRole::Homeowner)->create();

        // 1. Admin verifies company via POST /dashboard/ownership-entities/{entity}/verify
        $verifyResp = $this->actingAs($admin)->postJson("/dashboard/ownership-entities/{$company->id}/verify", [
            'registration_number' => 'CORP-ABC-2024',
            'company_status' => 'Active / Good Standing',
            'directors' => [['name' => 'Jane Sterling', 'role' => 'Managing Director']],
            'shareholders' => [['name' => 'Sterling Family Holdings', 'voting_pct' => '100%']],
            'beneficial_ownership' => [
                'declaration_reference' => 'COJ-BO-2024-88190',
                'filing_date' => '2024-05-18',
                'beneficial_owners' => [
                    ['full_name' => 'Jane Elizabeth Sterling', 'percentage_interest' => 100.0],
                ],
            ],
        ])->assertOk();

        $this->assertSame('verified', $verifyResp->json('verification.status'));

        // 2. Director requests GET /dashboard/ownership-entities/{entity}
        $directorShow = $this->actingAs($directorUser)->getJson("/dashboard/ownership-entities/{$company->id}")->assertOk();
        $this->assertTrue($directorShow->json('can_view_beneficial_ownership'));
        $this->assertFalse($directorShow->json('beneficial_ownership.is_restricted'));
        $this->assertCount(1, $directorShow->json('beneficial_ownership.beneficial_owners'));

        // 3. A guard has no business with the entity's records at all: the
        //    gate scan carries what the gate needs.
        $this->actingAs($guardUser)->getJson("/dashboard/ownership-entities/{$company->id}")->assertForbidden();

        // 4. Security Guard requests GET /dashboard/ownership-entities/{entity}/beneficial-ownership directly -> 403 Forbidden
        $this->actingAs($guardUser)->getJson("/dashboard/ownership-entities/{$company->id}/beneficial-ownership")->assertForbidden();

        // 5. Ordinary Resident requests GET /dashboard/ownership-entities/{entity}/beneficial-ownership -> 403 Forbidden
        $this->actingAs($residentUser)->getJson("/dashboard/ownership-entities/{$company->id}/beneficial-ownership")->assertForbidden();

        // 6. Director requests GET /dashboard/ownership-entities/{entity}/beneficial-ownership -> 200 OK
        $directorBo = $this->actingAs($directorUser)->getJson("/dashboard/ownership-entities/{$company->id}/beneficial-ownership")->assertOk();
        $this->assertFalse($directorBo->json('beneficial_ownership.is_restricted'));
    }
}
