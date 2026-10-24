<?php

namespace Tests\Feature;

use App\Enums\AuthorizedPersonRole;
use App\Enums\OwnershipEntityType;
use App\Enums\RepresentativePermission;
use App\Enums\UserRole;
use App\Models\OwnershipEntity;
use App\Models\Property;
use App\Models\User;
use App\Services\Properties\OwnershipEntityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may see and act for a company, trust or estate. Each case below was
 * open before review: an entity's records were readable by any signed-in
 * account, its directors could take a neighbour's house or verify
 * themselves, and appointments outlived their dates and scopes.
 */
class OwnershipEntityAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private OwnershipEntityService $service;

    private OwnershipEntity $company;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(OwnershipEntityService::class);

        $this->company = $this->service->createEntity([
            'entity_type' => OwnershipEntityType::Company,
            'legal_name' => 'Harbour Holdings Ltd.',
        ]);
        $this->director = User::factory()->role(UserRole::Homeowner)->create();
        $this->service->addAuthorizedPerson($this->company, [
            'user_id' => $this->director->id,
            'role' => AuthorizedPersonRole::Director,
            'full_name' => 'Dana Director',
            'email' => 'dana@harbour.example',
            'is_primary_contact' => true,
        ]);
    }

    private function companyProperty(string $lot): Property
    {
        return Property::create(['property_code' => "P-{$lot}", 'lot_number' => $lot, 'ownership_entity_id' => $this->company->id]);
    }

    public function test_an_unrelated_resident_cannot_read_an_entity(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Homeowner)->create())
            ->getJson("/dashboard/ownership-entities/{$this->company->id}")
            ->assertForbidden();
    }

    public function test_a_director_reads_their_entity_with_contact_details(): void
    {
        $this->actingAs($this->director)
            ->getJson("/dashboard/ownership-entities/{$this->company->id}")
            ->assertOk()
            ->assertJsonPath('representatives.0.email', 'dana@harbour.example');
    }

    public function test_a_property_manager_does_not_see_the_directors_contact_details(): void
    {
        $manager = User::factory()->role(UserRole::Homeowner)->create();
        $this->service->addAuthorizedPerson($this->company, [
            'user_id' => $manager->id,
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'Pat Manager',
        ]);

        $this->actingAs($manager)
            ->getJson("/dashboard/ownership-entities/{$this->company->id}")
            ->assertOk()
            ->assertJsonPath('representatives.0.email', null);
    }

    public function test_a_director_cannot_take_someone_elses_property(): void
    {
        $neighbour = User::factory()->role(UserRole::Homeowner)->create();
        $house = Property::create(['property_code' => 'P-9', 'lot_number' => 'Lot 9', 'owner_user_id' => $neighbour->id]);

        $this->actingAs($this->director)
            ->postJson("/dashboard/ownership-entities/{$this->company->id}/properties/{$house->id}")
            ->assertForbidden();

        $this->assertNull($house->fresh()->ownership_entity_id);
        $this->assertSame($neighbour->id, $house->fresh()->owner_user_id);
    }

    public function test_an_entity_cannot_verify_itself(): void
    {
        $this->actingAs($this->director)
            ->postJson("/dashboard/ownership-entities/{$this->company->id}/verify", ['notes' => 'We are who we say.'])
            ->assertForbidden();

        $this->assertFalse($this->company->fresh()->isVerified());
    }

    public function test_an_administrator_can_verify_an_entity(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create())
            ->postJson("/dashboard/ownership-entities/{$this->company->id}/verify", ['registration_number' => 'COJ-1'])
            ->assertOk();
    }

    public function test_a_role_the_entity_cannot_appoint_is_a_validation_error(): void
    {
        $this->actingAs($this->director)
            ->postJson("/dashboard/ownership-entities/{$this->company->id}/representatives", [
                'role' => AuthorizedPersonRole::Executor->value,
                'full_name' => 'Ed Executor',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role');
    }

    public function test_an_unknown_permission_is_a_validation_error(): void
    {
        $this->actingAs($this->director)
            ->postJson("/dashboard/ownership-entities/{$this->company->id}/representatives", [
                'role' => AuthorizedPersonRole::PropertyManager->value,
                'full_name' => 'Pat Manager',
                'permissions' => ['open_every_gate'],
            ])
            ->assertJsonValidationErrors('permissions.0');
    }

    public function test_a_representative_cannot_be_scoped_to_another_owners_property(): void
    {
        $house = Property::create(['property_code' => 'P-9', 'lot_number' => 'Lot 9']);

        $this->actingAs($this->director)
            ->post('/dashboard/ownership/representatives', [
                'ownership_entity_id' => $this->company->id,
                'role' => AuthorizedPersonRole::PropertyManager->value,
                'full_name' => 'Pat Manager',
                'property_ids' => [$house->id],
            ])
            ->assertSessionHasErrors('property_ids.0');
    }

    public function test_a_scoped_representative_manages_visitors_only_where_scoped(): void
    {
        $unitA = $this->companyProperty('Unit A');
        $unitB = $this->companyProperty('Unit B');
        $manager = User::factory()->role(UserRole::Homeowner)->create();

        $this->actingAs($this->director)
            ->post('/dashboard/ownership/representatives', [
                'ownership_entity_id' => $this->company->id,
                'user_id' => $manager->id,
                'role' => AuthorizedPersonRole::PropertyManager->value,
                'full_name' => 'Pat Manager',
                'permissions' => [RepresentativePermission::ManageVisitors->value],
                'property_ids' => [$unitA->id],
            ])
            ->assertSessionHasNoErrors();

        $this->assertTrue($manager->canManageVisitorsForProperty($unitA));
        $this->assertFalse($manager->canManageVisitorsForProperty($unitB));
    }

    public function test_an_expired_appointment_carries_no_authority(): void
    {
        $unit = $this->companyProperty('Unit A');
        $former = User::factory()->role(UserRole::Homeowner)->create();
        $this->service->addAuthorizedPerson($this->company, [
            'user_id' => $former->id,
            'role' => AuthorizedPersonRole::PropertyManager,
            'full_name' => 'Former Manager',
            'permissions' => [RepresentativePermission::ManageVisitors->value],
            'valid_until' => now()->subDay()->toDateString(),
        ]);

        $this->assertFalse($this->company->hasAuthority($former));
        $this->assertFalse($former->canManageVisitorsForProperty($unit));
        $this->assertTrue($former->representedEntities()->isEmpty());
    }

    public function test_revoking_the_primary_contact_takes_back_the_account_holder_role(): void
    {
        $unit = $this->companyProperty('Unit A');
        $this->service->assignProperty($this->company, $unit);
        $this->assertSame($this->director->id, $unit->fresh()->owner_user_id);

        $appointment = $this->company->activeAuthorizedPeople()->where('user_id', $this->director->id)->sole();
        $this->service->revokeAuthorizedPerson($appointment);

        $this->assertNull($unit->fresh()->owner_user_id);
        $this->assertFalse($this->director->fresh()->canManageVisitorsForProperty($unit));
    }

    public function test_opening_the_ownership_page_does_not_create_records_for_a_guard(): void
    {
        $guard = User::factory()->role(UserRole::Security)->create();
        $entities = OwnershipEntity::count();

        $this->actingAs($guard)->get('/dashboard/ownership')->assertOk();

        $this->assertSame($entities, OwnershipEntity::count());
        $this->assertDatabaseMissing('households', ['owner_id' => $guard->id]);
    }

    public function test_security_cannot_open_a_companys_dashboard(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Security)->create())
            ->get("/dashboard/company/{$this->company->id}")
            ->assertForbidden();
    }
}
