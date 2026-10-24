<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\GatePass;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Query\ApiQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;
use Tests\TestCase;

class SpatieQueryBuilderModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_builder_service_builds_all_registered_models(): void
    {
        $service = app(ApiQueryService::class);

        $entities = ['gate_passes', 'visitors', 'transactions', 'users', 'warnings'];

        foreach ($entities as $entity) {
            $builder = $service->forEntity($entity);
            $this->assertInstanceOf(QueryBuilder::class, $builder);
        }

        $meta = $service->getEntitiesMeta();
        $this->assertArrayHasKey('gate_passes', $meta);
        $this->assertArrayHasKey('visitors', $meta);
        $this->assertArrayHasKey('transactions', $meta);
        $this->assertArrayHasKey('users', $meta);
        $this->assertArrayHasKey('warnings', $meta);

        $this->assertNotEmpty($meta['gate_passes']['allowed_filters']);
        $this->assertNotEmpty($meta['gate_passes']['allowed_sorts']);
        $this->assertNotEmpty($meta['gate_passes']['allowed_includes']);
    }

    public function test_gate_passes_filtering_sorting_and_relationship_includes(): void
    {
        $userA = User::factory()->create(['name' => 'Alice Walker', 'role' => UserRole::Homeowner]);
        $userB = User::factory()->create(['name' => 'Bob Marley', 'role' => UserRole::Homeowner]);

        $activePass = GatePass::factory()->create([
            'pass_id' => 'GP-ACT-001',
            'holder_name' => 'Alice Guest',
            'status' => PassStatus::Active,
            'category' => PassCategory::Visitor,
            'user_id' => $userA->id,
            'created_at' => now()->subDay(),
        ]);

        $expiredPass = GatePass::factory()->create([
            'pass_id' => 'GP-EXP-002',
            'holder_name' => 'Bob Contractor',
            'status' => PassStatus::Expired,
            'category' => PassCategory::Contractor,
            'user_id' => $userB->id,
            'created_at' => now(),
        ]);

        $service = app(ApiQueryService::class);

        // 1. Filter exact status: ACTIVE
        $requestStatus = Request::create('/api/v1/gate-passes', 'GET', [
            'filter' => ['status' => 'ACTIVE'],
        ]);
        $resultsStatus = $service->forGatePasses($requestStatus)->get();
        $this->assertTrue($resultsStatus->contains('id', $activePass->id));
        $this->assertFalse($resultsStatus->contains('id', $expiredPass->id));

        // 2. Filter partial holder_name
        $requestName = Request::create('/api/v1/gate-passes', 'GET', [
            'filter' => ['holder_name' => 'Contractor'],
        ]);
        $resultsName = $service->forGatePasses($requestName)->get();
        $this->assertCount(1, $resultsName);
        $this->assertEquals('GP-EXP-002', $resultsName->first()->pass_id);

        // 3. Eager load relationship: include=user
        $requestInclude = Request::create('/api/v1/gate-passes', 'GET', [
            'filter' => ['pass_id' => 'GP-ACT-001'],
            'include' => 'user',
        ]);
        $resultWithUser = $service->forGatePasses($requestInclude)->first();
        $this->assertNotNull($resultWithUser);
        $this->assertTrue($resultWithUser->relationLoaded('user'));
        $this->assertEquals('Alice Walker', $resultWithUser->user->name);

        // 4. Sort by pass_id descending
        $requestSort = Request::create('/api/v1/gate-passes', 'GET', [
            'sort' => '-pass_id',
        ]);
        $sortedResults = $service->forGatePasses($requestSort)->get();
        $this->assertEquals('GP-EXP-002', $sortedResults->first()->pass_id);
    }

    public function test_visitors_filtering_sorting_and_scopes(): void
    {
        $homeowner = User::factory()->create(['name' => 'Host User', 'role' => UserRole::Homeowner]);

        $expectedVisitor = Visitor::create([
            'name' => 'Charlie Chaplin',
            'contact' => '+18765551111',
            'type' => 'Guest',
            'status' => VisitorStatus::Expected,
            'expected_at' => now()->addHours(2),
            'homeowner_id' => $homeowner->id,
            'homeowner_name' => $homeowner->name,
        ]);

        $checkedInVisitor = Visitor::create([
            'name' => 'David Copperfield',
            'contact' => '+18765552222',
            'type' => 'Guest',
            'status' => VisitorStatus::CheckedIn,
            'expected_at' => now()->subHour(),
            'checked_in_at' => now()->subMinutes(30),
            'homeowner_id' => $homeowner->id,
            'homeowner_name' => $homeowner->name,
        ]);

        $service = app(ApiQueryService::class);

        // 1. Filter by status: Expected
        $requestStatus = Request::create('/api/v1/visitors', 'GET', [
            'filter' => ['status' => VisitorStatus::Expected->value],
        ]);
        $results = $service->forVisitors($requestStatus)->get();
        $this->assertTrue($results->contains('id', $expectedVisitor->id));
        $this->assertFalse($results->contains('id', $checkedInVisitor->id));

        // 2. Include homeowner relationship
        $requestInclude = Request::create('/api/v1/visitors', 'GET', [
            'include' => 'homeowner',
            'filter' => ['name' => 'Charlie'],
        ]);
        $visitor = $service->forVisitors($requestInclude)->first();
        $this->assertNotNull($visitor);
        $this->assertTrue($visitor->relationLoaded('homeowner'));
        $this->assertEquals('Host User', $visitor->homeowner->name);
    }

    public function test_transactions_filtering_and_sorting(): void
    {
        $settledTx = Transaction::create([
            'transaction_id' => 'TX-SETTLED-001',
            'purpose' => 'Annual Security Fee',
            'amount_minor' => 15000,
            'currency' => 'USD',
            'payment_channel' => 'card',
            'status' => 'settled',
        ]);

        $pendingTx = Transaction::create([
            'transaction_id' => 'TX-PENDING-002',
            'purpose' => 'Clubhouse Rental',
            'amount_minor' => 5000,
            'currency' => 'USD',
            'payment_channel' => 'card',
            'status' => 'pending',
        ]);

        $service = app(ApiQueryService::class);

        // Filter status: settled
        $request = Request::create('/api/v1/transactions', 'GET', [
            'filter' => ['status' => 'settled'],
        ]);
        $results = $service->forTransactions($request)->get();
        $this->assertTrue($results->contains('id', $settledTx->id));
        $this->assertFalse($results->contains('id', $pendingTx->id));

        // Sort descending by amount_minor
        $requestSort = Request::create('/api/v1/transactions', 'GET', [
            'sort' => '-amount_minor',
        ]);
        $sorted = $service->forTransactions($requestSort)->get();
        $this->assertEquals('TX-SETTLED-001', $sorted->first()->transaction_id);
    }

    public function test_query_builder_api_endpoints_return_standardized_json(): void
    {
        $user = User::factory()->create(['name' => 'API Consumer', 'role' => UserRole::Admin]);
        $this->actingAs($user);

        GatePass::factory()->create([
            'pass_id' => 'GP-REST-01',
            'holder_name' => 'API Holder',
            'status' => PassStatus::Active,
            'category' => PassCategory::Visitor,
            'user_id' => $user->id,
        ]);

        // Test GET /api/v1/gate-passes with query builder params
        $response = $this->getJson('/api/v1/gate-passes?filter[status]=ACTIVE&sort=-created_at&per_page=5');
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('entity', 'gate_passes')
            ->assertJsonStructure([
                'success',
                'entity',
                'data',
                'pagination' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                    'has_more',
                ],
            ]);

        // Test GET /api/v1/query-meta
        $metaResponse = $this->getJson('/api/v1/query-meta');
        $metaResponse->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'meta',
                'supported_entities',
                'conventions',
            ]);
    }
}
