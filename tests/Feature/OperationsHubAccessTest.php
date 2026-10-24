<?php

namespace Tests\Feature;

use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\GatePass;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The search pages and the query API reach residents, the ledger and the
 * estate's passes. Each is limited to the roles that already see the same
 * records on the dashboard.
 */
class OperationsHubAccessTest extends TestCase
{
    use RefreshDatabase;

    private function as(UserRole $role): User
    {
        return User::factory()->role($role)->create();
    }

    private function pass(): GatePass
    {
        return GatePass::create([
            'pass_id' => 'VIS-'.strtoupper(fake()->unique()->bothify('????####')),
            'user_id' => $this->as(UserRole::Homeowner)->id,
            'category' => PassCategory::Visitor,
            'holder_name' => 'Ms. Test Visitor',
            'property' => 'Lot 7',
            'access_zone' => 'ZONE-HOST-RESIDENCE',
            'designated_gate' => GateId::Gate01,
            'valid_from' => now()->subHour(),
            'valid_until' => now()->addDay(),
            'single_entry' => false,
            'color_variant' => 'blue',
            'rotation_seq' => 1,
            'status' => PassStatus::Active,
        ]);
    }

    // ── Routes ──────────────────────────────────────────────────────

    /** @return array<string, array{string}> */
    public static function signedInPages(): array
    {
        return [
            'realtime' => ['/operations/realtime'],
            'spreadsheets' => ['/operations/spreadsheets'],
            'search' => ['/search'],
            'search api' => ['/api/search?q=a'],
            'search driver' => ['/api/search/driver'],
        ];
    }

    #[DataProvider('signedInPages')]
    public function test_a_guest_is_sent_to_sign_in(string $uri): void
    {
        $this->get($uri)->assertRedirect(route('login'));
    }

    #[DataProvider('signedInPages')]
    public function test_a_deactivated_account_is_signed_out(string $uri): void
    {
        $user = User::factory()->role(UserRole::SystemAdmin)->create(['status' => 'Inactive', 'deactivated_at' => now()]);

        $this->actingAs($user)->get($uri)->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_component_updates_also_check_the_account_is_active(): void
    {
        $this->assertContains(
            EnsureUserIsActive::class,
            app(PersistentMiddleware::class)->getPersistentMiddleware(),
        );
    }

    // ── Search and the query API ────────────────────────────────────

    public function test_a_resident_searching_finds_no_other_residents_or_passes(): void
    {
        User::factory()->role(UserRole::Homeowner)->create(['name' => 'Zelda Neighbour', 'email' => 'zelda@example.com']);
        $this->pass();

        $response = $this->actingAs($this->as(UserRole::Homeowner))
            ->getJson('/api/search?q=Zelda')
            ->assertOk();

        $this->assertSame([], $response->json('data.results.users'));
        $this->assertSame([], $response->json('data.results.gate_passes'));
        $this->assertSame([], $response->json('data.results.transactions'));
        $this->assertSame([], $response->json('data.results.visitors'));
    }

    public function test_an_administrator_can_search_residents(): void
    {
        User::factory()->role(UserRole::Homeowner)->create(['name' => 'Zelda Neighbour', 'email' => 'zelda@example.com']);

        $this->actingAs($this->as(UserRole::Admin))
            ->getJson('/api/search?q=Zelda&types[]=users')
            ->assertOk()
            ->assertJsonPath('data.results.users.0.title', 'Zelda Neighbour');
    }

    public function test_the_query_api_needs_a_token(): void
    {
        $this->getJson('/api/v1/users')->assertUnauthorized();
        $this->getJson('/api/v1/query-meta')->assertUnauthorized();
    }

    /** @return array<string, array{string}> */
    public static function restrictedEntities(): array
    {
        return [
            'users' => ['/api/v1/users'],
            'transactions' => ['/api/v1/transactions'],
            'gate passes' => ['/api/v1/gate-passes'],
            'visitors' => ['/api/v1/visitors'],
            'users by entity' => ['/api/v1/query/users'],
        ];
    }

    #[DataProvider('restrictedEntities')]
    public function test_a_residents_token_cannot_list_estate_records(string $uri): void
    {
        $resident = $this->as(UserRole::Homeowner);
        $token = $resident->createToken('Phone')->plainTextToken;

        $this->getJson($uri, ['Authorization' => "Bearer {$token}"])->assertForbidden();
    }

    public function test_a_residents_token_can_list_community_alerts(): void
    {
        $token = $this->as(UserRole::Homeowner)->createToken('Phone')->plainTextToken;

        $this->getJson('/api/v1/warnings', ['Authorization' => "Bearer {$token}"])->assertOk();
    }

    public function test_query_meta_lists_only_what_the_token_may_query(): void
    {
        $token = $this->as(UserRole::Homeowner)->createToken('Phone')->plainTextToken;

        $this->getJson('/api/v1/query-meta', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('supported_entities', ['warnings']);
    }
}
