<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Enums\UserRole;
use App\Livewire\Spreadsheets\SpreadsheetOperationsHub;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Spreadsheet exports hand out the resident roster and the ledger. They were
 * public; each now follows the roles that already see the same records on
 * the dashboard.
 */
class DocumentsAndDataExportAccessTest extends TestCase
{
    use RefreshDatabase;

    private function as(UserRole $role): User
    {
        return User::factory()->role($role)->create();
    }

    /**
     * Forgetting the guards matters when one test sends several tokens: the
     * test client otherwise keeps the first request's user for the rest.
     */
    private function bearer(User $user): array
    {
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$user->createToken('Device', TokenAbility::forUser($user))->plainTextToken];
    }

    // ── No token, no access ─────────────────────────────────────────

    /** @return array<string, array{string, string}> */
    public static function apiRoutes(): array
    {
        return [
            'export roster' => ['GET', '/api/v1/spreadsheets/export/residents'],
            'export ledger' => ['GET', '/api/v1/spreadsheets/export/transactions'],
            'export passes' => ['GET', '/api/v1/spreadsheets/export/gate_passes'],
            'queue export' => ['POST', '/api/v1/spreadsheets/export-queue/residents'],
            'import roster' => ['POST', '/api/v1/spreadsheets/import/residents'],
            'blueprints' => ['GET', '/api/v1/spreadsheets/blueprints'],
            'sample template' => ['GET', '/api/v1/spreadsheets/sample-template/residents'],
        ];
    }

    #[DataProvider('apiRoutes')]
    public function test_the_api_needs_a_token(string $method, string $uri): void
    {
        $this->json($method, $uri)->assertUnauthorized();
    }

    // ── Spreadsheets ────────────────────────────────────────────────

    /** @return array<string, array{string, UserRole, UserRole}> */
    public static function exports(): array
    {
        // blueprint, a role refused, a role allowed
        return [
            'roster' => ['residents', UserRole::Security, UserRole::Admin],
            'ledger' => ['transactions', UserRole::Security, UserRole::Admin],
            'passes' => ['gate_passes', UserRole::Homeowner, UserRole::Security],
        ];
    }

    #[DataProvider('exports')]
    public function test_each_export_follows_its_dashboard_gate(string $blueprint, UserRole $refused, UserRole $allowed): void
    {
        $this->get("/api/v1/spreadsheets/export/{$blueprint}?format=csv", $this->bearer($this->as(UserRole::Homeowner)))->assertForbidden();
        $this->get("/api/v1/spreadsheets/export/{$blueprint}?format=csv", $this->bearer($this->as($refused)))->assertForbidden();
        $this->get("/api/v1/spreadsheets/export/{$blueprint}?format=csv", $this->bearer($this->as($allowed)))->assertOk();
    }

    public function test_a_resident_cannot_import_the_roster(): void
    {
        $this->post('/api/v1/spreadsheets/import/residents', [
            'file' => UploadedFile::fake()->createWithContent('roster.csv', "full_legal_name,email_address,assigned_role\nMallory,m@example.com,admin\n"),
        ], $this->bearer($this->as(UserRole::Homeowner)) + ['Accept' => 'application/json'])->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'm@example.com']);
    }

    public function test_a_queued_export_cannot_be_written_to_the_public_disk(): void
    {
        $this->postJson('/api/v1/spreadsheets/export-queue/residents', ['disk' => 'public'], $this->bearer($this->as(UserRole::Admin)))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('disk');
    }

    public function test_the_catalog_lists_only_usable_blueprints(): void
    {
        $response = $this->getJson('/api/v1/spreadsheets/blueprints', $this->bearer($this->as(UserRole::Security)))->assertOk();

        $this->assertSame(['gate_passes'], array_keys($response->json('data.export_blueprints')));
        $this->assertSame([], $response->json('data.import_blueprints'));
    }

    // ── Livewire page ───────────────────────────────────────────────

    public function test_a_resident_cannot_open_the_spreadsheet_page(): void
    {
        $resident = $this->as(UserRole::Homeowner);

        $this->actingAs($resident)->get('/operations/spreadsheets')->assertForbidden();
        Livewire::actingAs($resident)->test(SpreadsheetOperationsHub::class)->assertForbidden();
    }

    public function test_security_cannot_export_the_roster_by_switching_blueprint(): void
    {
        Livewire::actingAs($this->as(UserRole::Security))
            ->test(SpreadsheetOperationsHub::class)
            ->set('selectedBlueprint', 'residents')
            ->call('downloadExport')
            ->assertForbidden();
    }

    public function test_security_cannot_import_residents_from_the_page(): void
    {
        Livewire::actingAs($this->as(UserRole::Security))
            ->test(SpreadsheetOperationsHub::class)
            ->call('processImport')
            ->assertForbidden();
    }
}
