<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Livewire\UniversalSearch;
use App\Models\GatePass;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Warning;
use App\Services\Search\UniversalSearchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Scout\Searchable;
use Livewire\Livewire;
use Tests\TestCase;

class UniversalSearchModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_scout_configuration_is_valid_and_supports_enterprise_drivers(): void
    {
        $this->assertNotNull(config('scout.driver'));

        // Verify driver configurations exist for all 4 enterprise drivers
        $this->assertArrayHasKey('algolia', config('scout'));
        $this->assertArrayHasKey('meilisearch', config('scout'));
        $this->assertArrayHasKey('typesense', config('scout'));

        $service = app(UniversalSearchService::class);
        $supported = $service->getSupportedDrivers();

        $this->assertContains('database', $supported);
        $this->assertContains('meilisearch', $supported);
        $this->assertContains('algolia', $supported);
        $this->assertContains('typesense', $supported);
        $this->assertContains('collection', $supported);
    }

    public function test_models_implement_scout_searchable_trait_and_index_structures(): void
    {
        $models = [
            User::class,
            GatePass::class,
            Warning::class,
            Transaction::class,
            Visitor::class,
        ];

        foreach ($models as $modelClass) {
            $traits = class_uses_recursive($modelClass);
            $this->assertArrayHasKey(
                Searchable::class,
                $traits,
                "Model {$modelClass} must implement Laravel\Scout\Searchable trait."
            );
        }

        // Test User toSearchableArray
        $user = User::factory()->make([
            'name' => 'John Scout',
            'email' => 'john.scout@community.io',
            'phone' => '+18765551234',
            'role' => UserRole::Homeowner,
            'lot' => '42',
            'street' => 'Highland Ave',
        ]);
        $userSearchable = $user->toSearchableArray();
        $this->assertEquals('John Scout', $userSearchable['name']);
        $this->assertEquals('john.scout@community.io', $userSearchable['email']);
        $this->assertEquals('42', $userSearchable['lot']);

        // Test GatePass toSearchableArray
        $gatePass = GatePass::factory()->make([
            'pass_id' => 'GP-7777',
            'holder_name' => 'Sarah Connor',
            'property' => 'Lot 10',
            'category' => PassCategory::Visitor,
            'status' => PassStatus::Active,
        ]);
        $passSearchable = $gatePass->toSearchableArray();
        $this->assertEquals('GP-7777', $passSearchable['pass_id']);
        $this->assertEquals('Sarah Connor', $passSearchable['holder_name']);

        // Test Warning toSearchableArray
        $warning = new Warning([
            'title' => 'Gate 2 Maintenance Notice',
            'description' => 'Expect minor delays during motor overhaul.',
            'author_name' => 'Chief Guard',
        ]);
        $warning->id = 101;
        $warningSearchable = $warning->toSearchableArray();
        $this->assertEquals('Gate 2 Maintenance Notice', $warningSearchable['title']);
        $this->assertEquals('Chief Guard', $warningSearchable['author_name']);

        // Test Transaction toSearchableArray
        $transaction = new Transaction([
            'transaction_id' => 'TX-998877',
            'reference' => 'REF-12345',
            'purpose' => 'Quarterly HOA Assessment',
            'status' => 'settled',
            'user_code' => 'USR-001',
            'property_code' => 'LOT-042',
        ]);
        $transaction->id = 202;
        $txSearchable = $transaction->toSearchableArray();
        $this->assertEquals('TX-998877', $txSearchable['transaction_id']);
        $this->assertEquals('Quarterly HOA Assessment', $txSearchable['purpose']);

        // Test Visitor toSearchableArray
        $visitor = new Visitor([
            'name' => 'Michael Knight',
            'contact' => '+18765558888',
            'vehicle' => 'KITT-2000',
            'id_number' => 'ID-999',
            'type' => 'Contractor',
            'homeowner_name' => 'Devon Miles',
        ]);
        $visitor->id = 303;
        $visitorSearchable = $visitor->toSearchableArray();
        $this->assertEquals('Michael Knight', $visitorSearchable['name']);
        $this->assertEquals('KITT-2000', $visitorSearchable['vehicle']);
    }

    public function test_universal_search_service_executes_cross_entity_search(): void
    {
        $admin = User::factory()->create([
            'name' => 'Marcus Aurelius',
            'email' => 'marcus@stoic.io',
            'role' => UserRole::Admin,
        ]);

        $gatePass = GatePass::factory()->create([
            'pass_id' => 'GP-AURELIUS',
            'holder_name' => 'Marcus Aurelius Visitor',
            'category' => PassCategory::Visitor,
            'status' => PassStatus::Active,
            'user_id' => $admin->id,
        ]);

        $warning = Warning::create([
            'title' => 'Marcus Aurelius Advisory Notice',
            'description' => 'Security review scheduled for north perimeter.',
            'author_name' => 'Security Team',
            'author_id' => $admin->id,
            'issued_at' => now(),
        ]);

        $service = app(UniversalSearchService::class);
        $searchResponse = $service->search('Marcus Aurelius', user: $admin);

        $this->assertEquals('Marcus Aurelius', $searchResponse['query']);
        $this->assertGreaterThanOrEqual(1, $searchResponse['total']);
        $this->assertNotEmpty($searchResponse['driver']);
        $this->assertArrayHasKey('results', $searchResponse);
        $this->assertArrayHasKey('users', $searchResponse['results']);
        $this->assertArrayHasKey('gate_passes', $searchResponse['results']);
        $this->assertArrayHasKey('warnings', $searchResponse['results']);
    }

    public function test_universal_search_service_filters_by_specific_entity_type(): void
    {
        $user = User::factory()->create([
            'name' => 'Cleopatra Queen',
            'email' => 'cleo@nile.io',
        ]);

        $warning = Warning::create([
            'title' => 'Cleopatra Roadwork Notice',
            'description' => 'Maintenance on Nile Boulevard.',
            'author_name' => 'Public Works',
            'author_id' => $user->id,
            'issued_at' => now(),
        ]);

        $service = app(UniversalSearchService::class);
        $admin = User::factory()->role(UserRole::Admin)->create();

        // Search only warnings
        $warningsOnly = $service->search(query: 'Cleopatra', types: ['warnings'], user: $admin);
        $this->assertNotEmpty($warningsOnly['results']['warnings']);
        $this->assertEmpty($warningsOnly['results']['users'] ?? []);

        // Search only users
        $usersOnly = $service->search(query: 'Cleopatra', types: ['users'], user: $admin);
        $this->assertNotEmpty($usersOnly['results']['users']);
        $this->assertEmpty($usersOnly['results']['warnings'] ?? []);
    }

    public function test_universal_search_api_endpoints_return_structured_json(): void
    {
        $user = User::factory()->create([
            'name' => 'Seraphina Vance',
            'email' => 'seraphina@vance.io',
        ]);

        $this->actingAs($user);

        // Test /api/search
        $response = $this->getJson('/api/search?q=Seraphina');
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'query',
                    'total',
                    'driver',
                    'results',
                    'flattened',
                ],
            ]);

        // Test /api/search/driver
        $driverResponse = $this->getJson('/api/search/driver');
        $driverResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'active_driver',
                    'is_external_engine',
                    'supported_drivers',
                    'driver_configurations',
                ],
            ]);
    }

    public function test_search_hub_web_routes_and_livewire_component_render_correctly(): void
    {
        $user = User::factory()->role(UserRole::Admin)->create(['name' => 'Leonidas Spartan', 'email' => 'leo@sparta.io']);
        $this->actingAs($user);

        // Test dedicated /search page
        $response = $this->get('/search');
        $response->assertOk();
        $response->assertSee('Universal Search');
        $response->assertSee('Laravel Scout');

        // Test Livewire component reactivity
        Livewire::test(UniversalSearch::class)
            ->set('query', 'Leonidas')
            ->assertSet('query', 'Leonidas')
            ->call('setCategory', 'users')
            ->assertSet('selectedCategory', 'users')
            ->call('clearQuery')
            ->assertSet('query', '');
    }
}
