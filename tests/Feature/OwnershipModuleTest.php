<?php

namespace Tests\Feature;

use App\Enums\AuthorizedPersonRole;
use App\Enums\OwnershipEntityType;
use App\Enums\UserRole;
use App\Models\AuthorizedPerson;
use App\Models\Community;
use App\Models\Household;
use App\Models\Property;
use App\Models\User;
use App\Services\Properties\OwnershipEntityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OwnershipModuleTest extends TestCase
{
    use RefreshDatabase;

    protected OwnershipEntityService $service;

    protected Community $community;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OwnershipEntityService::class);
        $this->community = Community::create([
            'name' => 'Fairview Estate',
            'code' => 'fairview',
        ]);
    }

    public function test_company_ownership_navigation_and_module_ui(): void
    {
        $director = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Jane Sterling',
            'email' => 'jane@abcholdings.com',
        ]);

        $company = $this->service->createEntity([
            'community_id' => $this->community->id,
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
            'registration_number' => 'CORP-ABC-2024',
            'jurisdiction' => 'Jamaica',
            'status' => 'active',
            'verification_status' => 'verified',
            'verified_at' => now(),
            'beneficial_ownership' => [
                'declaration_reference' => 'COJ-BO-2024',
                'beneficial_owners' => [
                    ['full_name' => 'Jane Sterling', 'ownership_percentage' => 65],
                ],
            ],
        ]);

        $this->service->addAuthorizedPerson($company, [
            'user_id' => $director->id,
            'role' => AuthorizedPersonRole::Director,
            'full_name' => 'Jane Sterling',
            'is_primary_contact' => true,
            'is_signatory' => true,
            'status' => 'active',
        ]);

        Property::create([
            'community_id' => $this->community->id,
            'ownership_entity_id' => $company->id,
            'property_code' => 'UNIT-14',
            'lot_number' => 'Unit 14',
            'street_address' => '14 Palm Drive',
            'property_type' => 'residential',
            'status' => 'occupied',
        ]);

        $response = $this->actingAs($director)->get(route('dashboard.ownership'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Ownership')
            ->where('ownershipType', 'company')
            ->where('moduleTitle', 'Ownership & Representatives')
            ->where('activeEntity.legal_name', 'ABC Holdings Limited')
            ->where('activeEntity.registration_number', 'CORP-ABC-2024')
            ->where('canViewBeneficialOwnership', true)
            ->has('representatives', 1)
            ->where('representatives.0.name', 'Jane Sterling')
            ->has('properties', 1)
            ->where('ownershipNav.label', 'Ownership & Representatives')
            ->where('ownershipNav.type', 'company')
        );
    }

    public function test_individual_ownership_navigation_and_household_ui(): void
    {
        $resident = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'John Resident',
            'lot' => 'Lot 42',
        ]);

        $household = Household::create([
            'primary_homeowner_id' => $resident->id,
            'name' => 'The Resident Household',
            'property_number' => 'Lot 42',
            'address' => '42 Bougainvillea Avenue',
        ]);

        $household->members()->create([
            'name' => 'Mary Resident',
            'role_in_household' => 'spouse',
            'relationship_label' => 'Spouse',
            'pass_category' => 'resident_household',
            'status' => 'active',
        ]);

        $response = $this->actingAs($resident)->get(route('dashboard.ownership'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Ownership')
            ->where('ownershipType', 'individual')
            ->where('moduleTitle', 'Ownership & Household')
            ->where('household.name', 'The Resident Household')
            ->has('household.members', 2)
            ->where('ownershipNav.label', 'Ownership & Household')
            ->where('ownershipNav.type', 'individual')
        );
    }

    public function test_trust_ownership_navigation_and_module_ui(): void
    {
        $trustee = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Arthur Trustman',
        ]);

        $trust = $this->service->createEntity([
            'community_id' => $this->community->id,
            'entity_type' => OwnershipEntityType::Trust,
            'legal_name' => 'The Fairview Estate Family Trust',
            'registration_number' => 'TR-2021-994',
            'status' => 'active',
            'verification_status' => 'verified',
        ]);

        $this->service->addAuthorizedPerson($trust, [
            'user_id' => $trustee->id,
            'role' => AuthorizedPersonRole::Trustee,
            'full_name' => 'Arthur Trustman',
            'is_primary_contact' => true,
            'is_signatory' => true,
            'status' => 'active',
        ]);

        $response = $this->actingAs($trustee)->get(route('dashboard.ownership'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Ownership')
            ->where('ownershipType', 'trust')
            ->where('moduleTitle', 'Trustees & Representatives')
            ->where('activeEntity.legal_name', 'The Fairview Estate Family Trust')
            ->has('representatives', 1)
            ->where('ownershipNav.label', 'Trustees & Representatives')
            ->where('ownershipNav.type', 'trust')
        );
    }

    public function test_estate_ownership_navigation_and_module_ui(): void
    {
        $executor = User::factory()->create([
            'role' => UserRole::Homeowner,
            'name' => 'Eleanor Vance',
        ]);

        $estate = $this->service->createEntity([
            'community_id' => $this->community->id,
            'entity_type' => OwnershipEntityType::Estate,
            'legal_name' => 'Estate of Late Robert Sterling',
            'registration_number' => 'PROB-2023-0182',
            'status' => 'active',
            'verification_status' => 'verified',
        ]);

        $this->service->addAuthorizedPerson($estate, [
            'user_id' => $executor->id,
            'role' => AuthorizedPersonRole::Executor,
            'full_name' => 'Eleanor Vance',
            'is_primary_contact' => true,
            'is_signatory' => true,
            'status' => 'active',
        ]);

        $response = $this->actingAs($executor)->get(route('dashboard.ownership'));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Ownership')
            ->where('ownershipType', 'estate')
            ->where('moduleTitle', 'Executors & Representatives')
            ->where('activeEntity.legal_name', 'Estate of Late Robert Sterling')
            ->has('representatives', 1)
            ->where('ownershipNav.label', 'Executors & Representatives')
            ->where('ownershipNav.type', 'estate')
        );
    }

    public function test_switching_ownership_type_dynamically_changes_ui(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $company = $this->service->createEntity([
            'community_id' => $this->community->id,
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'Test Corp Limited',
        ]);

        $trust = $this->service->createEntity([
            'community_id' => $this->community->id,
            'entity_type' => OwnershipEntityType::Trust,
            'legal_name' => 'Test Trust Deed',
        ]);

        // Request company type
        $responseCompany = $this->actingAs($admin)->get(route('dashboard.ownership', ['type' => 'company']));
        $responseCompany->assertStatus(200);
        $responseCompany->assertInertia(fn (Assert $page) => $page
            ->where('ownershipType', 'company')
            ->where('moduleTitle', 'Ownership & Representatives')
        );

        // Request trust type
        $responseTrust = $this->actingAs($admin)->get(route('dashboard.ownership', ['type' => 'trust']));
        $responseTrust->assertStatus(200);
        $responseTrust->assertInertia(fn (Assert $page) => $page
            ->where('ownershipType', 'trust')
            ->where('moduleTitle', 'Trustees & Representatives')
        );

        // Request individual type
        $responseIndividual = $this->actingAs($admin)->get(route('dashboard.ownership', ['type' => 'individual']));
        $responseIndividual->assertStatus(200);
        $responseIndividual->assertInertia(fn (Assert $page) => $page
            ->where('ownershipType', 'individual')
            ->where('moduleTitle', 'Ownership & Household')
        );
    }

    public function test_appointing_and_revoking_representatives(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $company = $this->service->createEntity([
            'community_id' => $this->community->id,
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Holdings Limited',
        ]);

        // Appoint representative
        $storeResponse = $this->actingAs($admin)->post(route('dashboard.ownership.representatives.store'), [
            'ownership_entity_id' => $company->id,
            'full_name' => 'David Lee',
            'role' => AuthorizedPersonRole::PropertyManager->value,
            'email' => 'david@example.com',
            'phone' => '876-555-0199',
        ]);

        $storeResponse->assertRedirect();
        $this->assertDatabaseHas('authorized_people', [
            'ownership_entity_id' => $company->id,
            'full_name' => 'David Lee',
            'role' => AuthorizedPersonRole::PropertyManager->value,
            'status' => 'active',
        ]);

        $person = AuthorizedPerson::where('full_name', 'David Lee')->first();

        // Revoke representative
        $revokeResponse = $this->actingAs($admin)->post(route('dashboard.ownership.representatives.revoke', $person));
        $revokeResponse->assertRedirect();

        $this->assertDatabaseHas('authorized_people', [
            'id' => $person->id,
            'status' => 'revoked',
        ]);
    }

    public function test_a_plus_data_model_and_six_cardinal_invariants(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $company = $this->service->createEntity([
            'community_id' => $this->community->id,
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'ABC Enterprise Holdings Limited',
        ]);

        $this->service->addAuthorizedPerson($company, [
            'full_name' => 'Marcus Vance',
            'role' => AuthorizedPersonRole::Director,
            'email' => 'marcus@abcholdings.com',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin)->get(route('dashboard.ownership', [
            'entity_id' => $company->id,
        ]));

        $response->assertStatus(200);
        $response->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard/Ownership')
            ->has('hierarchy')
            ->has('hierarchy.entity_people')
            ->has('hierarchy.occupants')
            ->has('hierarchy.access_relationships')
            ->has('hierarchy.credentials')
            ->has('hierarchy.total_authorizations')
            // Verify 6 Cardinal Invariants exist on entity_people
            ->where('hierarchy.entity_people.0.invariants.who.person_name', 'Marcus Vance')
            ->where('hierarchy.entity_people.0.invariants.relationship.relationship_name', 'director')
            ->where('hierarchy.entity_people.0.invariants.scope.entity_name', 'ABC Enterprise Holdings Limited')
            ->has('hierarchy.entity_people.0.invariants.permissions.allowed_actions')
            ->has('hierarchy.entity_people.0.invariants.validity.status')
            ->has('hierarchy.entity_people.0.invariants.authorizer.authorized_by')
        );
    }
}
