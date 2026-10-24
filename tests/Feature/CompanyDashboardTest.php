<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\OwnershipEntity;
use App\Models\Property;
use App\Models\User;
use Database\Seeders\CompanyPropertySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CompanyPropertySeeder::class);
    }

    public function test_company_director_can_access_company_dashboard_with_all_properties(): void
    {
        $director = User::where('email', 'jane.sterling@abcholdings.com')->firstOrFail();

        $response = $this->actingAs($director)->get('/dashboard/company');
        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard/Company')
            ->has('company', fn ($comp) => $comp
                ->where('legal_name', 'ABC Holdings Limited')
                ->where('entity_type', 'company')
                ->etc()
            )
            ->has('properties', 3)
            ->where('properties.0.lot_number', 'Unit 14')
            ->where('properties.0.status', 'occupied')
            ->where('properties.1.lot_number', 'Unit 18')
            ->where('properties.1.status', 'vacant')
            ->where('properties.2.lot_number', 'Unit 22')
            ->where('properties.2.status', 'rented')
            ->has('people_and_access.residents')
            ->has('people_and_access.renters')
            ->has('people_and_access.visitors')
            ->has('people_and_access.staff')
            ->has('people_and_access.contractors')
            ->has('people_and_access.legacy_contacts')
            ->has('people_and_access.representatives')
            ->has('operations.vehicles')
            ->has('operations.parking')
            ->has('operations.credentials')
            ->has('operations.incidents')
            ->has('operations.payments')
            ->has('operations.documents')
            ->where('verification.status', 'verified')
            ->where('verification.source', 'Companies Office of Jamaica (COJ)')
            ->where('can_view_beneficial_ownership', true)
            ->where('beneficial_ownership.is_restricted', false)
            ->has('beneficial_ownership.beneficial_owners', 2)
        );
    }

    public function test_company_representative_sees_only_properties_they_are_authorized_to_manage(): void
    {
        // Mark Vance is authorized ONLY for Unit 14 and Unit 18 (Unit 22 is NOT authorized)
        $manager = User::where('email', 'mark.vance@abcholdings.com')->firstOrFail();

        $response = $this->actingAs($manager)->get('/dashboard/company');
        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard/Company')
            ->has('properties', 2)
            ->where('properties.0.lot_number', 'Unit 14')
            ->where('properties.1.lot_number', 'Unit 18')
            ->where('can_view_beneficial_ownership', false)
            ->where('beneficial_ownership.is_restricted', true)
            ->has('beneficial_ownership.beneficial_owners', 0)
        );

        // Verify Unit 22 is completely excluded from Mark Vance's view
        $unit22 = Property::where('lot_number', 'Unit 22')->firstOrFail();

        $forbiddenResponse = $this->actingAs($manager)->get('/dashboard/company?property_id='.$unit22->id);
        $forbiddenResponse->assertForbidden();
    }

    public function test_admin_can_access_company_dashboard_for_any_entity(): void
    {
        $admin = User::factory()->create([
            'role' => UserRole::Admin,
            'email' => 'admin.test@communityhub.org',
        ]);

        $company = OwnershipEntity::where('legal_name', 'ABC Holdings Limited')->firstOrFail();

        $response = $this->actingAs($admin)->get('/dashboard/company/'.$company->id);
        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard/Company')
            ->where('company.legal_name', 'ABC Holdings Limited')
            ->has('properties', 3)
        );
    }

    public function test_unauthorized_user_cannot_access_unaffiliated_company_dashboard(): void
    {
        $unaffiliatedUser = User::factory()->create([
            'role' => UserRole::Homeowner,
            'email' => 'unaffiliated@somewhere.net',
        ]);

        $company = OwnershipEntity::where('legal_name', 'ABC Holdings Limited')->firstOrFail();

        $response = $this->actingAs($unaffiliatedUser)->get('/dashboard/company/'.$company->id);
        $response->assertForbidden();
    }

    public function test_can_filter_company_dashboard_by_authorized_property(): void
    {
        $director = User::where('email', 'jane.sterling@abcholdings.com')->firstOrFail();
        $unit14 = Property::where('lot_number', 'Unit 14')->firstOrFail();

        $response = $this->actingAs($director)->get('/dashboard/company?property_id='.$unit14->id);
        $response->assertOk();

        $response->assertInertia(fn ($page) => $page
            ->component('Dashboard/Company')
            ->where('selected_property_id', $unit14->id)
            ->where('selected_property.lot_number', 'Unit 14')
            ->has('properties', 3)
            ->where('properties.0.is_selected', true)
        );
    }
}
