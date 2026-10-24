<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Exports\QueuedDatasetExport;
use App\Livewire\Spreadsheets\SpreadsheetOperationsHub;
use App\Models\GatePass;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Spreadsheets\EnterpriseSpreadsheetService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel;
use Tests\TestCase;

class EnterpriseSpreadsheetModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_maatwebsite_excel_is_registered_and_bound_in_container(): void
    {
        $this->assertTrue(app()->bound('excel'));
        $excel = app('excel');
        $this->assertInstanceOf(Excel::class, $excel);
    }

    public function test_spreadsheet_service_provides_export_and_import_blueprints(): void
    {
        $service = app(EnterpriseSpreadsheetService::class);

        $exportBlueprints = $service->getExportBlueprints();
        $this->assertArrayHasKey('residents', $exportBlueprints);
        $this->assertArrayHasKey('transactions', $exportBlueprints);
        $this->assertArrayHasKey('gate_passes', $exportBlueprints);
        $this->assertArrayNotHasKey('large_dataset_benchmark', $exportBlueprints);

        $importBlueprints = $service->getImportBlueprints();
        $this->assertArrayHasKey('residents', $importBlueprints);
        $this->assertArrayHasKey('full_legal_name', $importBlueprints['residents']['columns']);
        $this->assertArrayHasKey('email_address', $importBlueprints['residents']['columns']);
    }

    public function test_spreadsheet_service_reports_queue_engine_telemetry(): void
    {
        $service = app(EnterpriseSpreadsheetService::class);
        $telemetry = $service->getQueueEngineStatus();

        $this->assertArrayHasKey('excel_chunk_size', $telemetry);
        $this->assertArrayHasKey('default_driver', $telemetry);
        $this->assertSame(1000, $telemetry['excel_chunk_size']);
    }

    public function test_synchronous_export_generates_valid_xlsx_and_csv_responses(): void
    {
        User::factory()->create([
            'name' => 'Lord Ronald Stirling',
            'email' => 'ronald.stirling@example.org',
            'role' => UserRole::Homeowner,
            'lot' => 'Lot 01',
        ]);

        $service = app(EnterpriseSpreadsheetService::class);

        // 1. XLSX Export
        $xlsxResponse = $service->export('residents', 'xlsx');
        $this->assertSame(200, $xlsxResponse->getStatusCode());
        $this->assertFileExists($xlsxResponse->getFile()->getPathname());
        $this->assertStringContainsString('.xlsx', (string) $xlsxResponse->headers->get('Content-Disposition'));

        // 2. CSV Export
        $csvResponse = $service->export('residents', 'csv');
        $this->assertSame(200, $csvResponse->getStatusCode());
        $this->assertFileExists($csvResponse->getFile()->getPathname());
        $this->assertStringContainsString('.csv', (string) $csvResponse->headers->get('Content-Disposition'));
    }

    public function test_transactions_and_gate_passes_exports_compile_properly(): void
    {
        Transaction::create([
            'transaction_id' => 'tx_test_sample_8819',
            'provider' => 'stripe',
            'provider_status' => 'succeeded',
            'status' => 'settled',
            'amount_minor' => 125000,
            'currency' => 'usd',
            'purpose' => 'Quarterly HOA Assessment',
            'payment_channel' => 'card',
        ]);

        GatePass::create([
            'pass_id' => 'PASS-2026-0042',
            'holder_name' => 'Seraphina Vance',
            'property' => 'Lot 42',
            'access_zone' => 'Full Estate',
            'category' => PassCategory::Homeowner,
            'status' => PassStatus::Active,
        ]);

        $service = app(EnterpriseSpreadsheetService::class);

        $txResponse = $service->export('transactions', 'xlsx');
        $this->assertSame(200, $txResponse->getStatusCode());
        $this->assertFileExists($txResponse->getFile()->getPathname());

        $passResponse = $service->export('gate_passes', 'csv');
        $this->assertSame(200, $passResponse->getStatusCode());
        $this->assertFileExists($passResponse->getFile()->getPathname());
    }

    public function test_queued_export_dispatches_to_queue(): void
    {
        Queue::fake();

        $service = app(EnterpriseSpreadsheetService::class);
        $result = $service->queueExport('residents', 'xlsx');

        $this->assertSame('queued', $result['status']);
        $this->assertSame('residents', $result['blueprint']);
        $this->assertSame('exports', $result['queue']);
        $this->assertStringContainsString('.xlsx', $result['path']);
    }

    public function test_large_dataset_export_has_chunking_configured(): void
    {
        $export = new QueuedDatasetExport('residents', 1000);
        $this->assertSame(1000, $export->chunkSize());

        $headings = $export->headings();
        $this->assertContains('Full Name', $headings);
        $this->assertContains('Email Address', $headings);
    }

    public function test_residents_csv_import_creates_records_and_updates_existing(): void
    {
        $csvContent = "full_legal_name,email_address,contact_phone,assigned_role,property_lot,street_address\n";
        $csvContent .= "Alastair Sterling,alastair.sterling@example.org,(876) 555-1042,Homeowner,Lot 104,Highland Ridge\n";
        $csvContent .= "Beatrice Fontaine,beatrice.fontaine@example.org,(876) 555-1043,Staff,Lot 12,Cypress Way\n";

        $tempFile = UploadedFile::fake()->createWithContent('residents-import.csv', $csvContent);

        $service = app(EnterpriseSpreadsheetService::class);
        $result = $service->import('residents', $tempFile);

        $this->assertSame('success', $result['status']);
        $this->assertSame(2, $result['imported_count']);
        $this->assertSame(0, $result['failures_count']);

        $this->assertDatabaseHas('users', [
            'email' => 'alastair.sterling@example.org',
            'name' => 'Alastair Sterling',
            'lot' => 'Lot 104',
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'beatrice.fontaine@example.org',
            'name' => 'Beatrice Fontaine',
        ]);
    }

    public function test_api_v1_spreadsheets_blueprints_and_export_endpoints(): void
    {
        $this->actingAs(User::factory()->role(UserRole::Admin)->create()); // issuing documents and exports needs an administrator
        $res = $this->getJson(route('api.v1.spreadsheets.blueprints'));
        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.queue_engine.excel_chunk_size', 1000);

        // Export CSV via API
        $exportRes = $this->get(route('api.v1.spreadsheets.export', ['blueprint' => 'residents', 'format' => 'csv']));
        $exportRes->assertOk();
        $this->assertStringContainsString('.csv', (string) $exportRes->headers->get('Content-Disposition'));

        // Export XLSX via API
        $exportXlsx = $this->get(route('api.v1.spreadsheets.export', ['blueprint' => 'residents', 'format' => 'xlsx']));
        $exportXlsx->assertOk();
        $this->assertStringContainsString('.xlsx', (string) $exportXlsx->headers->get('Content-Disposition'));

        // Sample Template download
        $sampleRes = $this->get(route('api.v1.spreadsheets.sample-template', ['blueprint' => 'residents']));
        $sampleRes->assertOk();
        $this->assertStringContainsString('full_legal_name', $sampleRes->getContent());

        // Unknown blueprint returns 404
        $unknownRes = $this->get(route('api.v1.spreadsheets.export', ['blueprint' => 'nonexistent_dataset']));
        $unknownRes->assertNotFound();
    }

    public function test_operations_spreadsheets_web_route_requires_authentication(): void
    {
        $response = $this->get(route('operations.spreadsheets'));
        $response->assertRedirect('/login');
    }

    public function test_authenticated_user_can_access_spreadsheets_operations_hub(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $response = $this->actingAs($user)->get(route('operations.spreadsheets'));
        $response->assertOk();
        $response->assertSee('Excel &amp; CSV Data Processing Hub', false);
        $response->assertSee('Maatwebsite');
    }

    public function test_livewire_spreadsheet_operations_hub_renders_and_handles_actions(): void
    {
        $user = User::factory()->create([
            'role' => UserRole::Admin,
        ]);

        $component = Livewire::actingAs($user)
            ->test(SpreadsheetOperationsHub::class)
            ->assertSee('Data Exporter (XLSX & CSV)')
            ->assertSee('Residents & Homeowners Roster')
            ->call('selectTab', 'importer')
            ->assertSee('Resident Roster Ingestion')
            ->call('selectTab', 'large_datasets')
            ->assertSee('Large Dataset Stream & Queue Architecture')
            ->call('selectTab', 'specifications')
            ->assertSee('Supported Schemas & Data Dictionaries');

        // Test sample template download
        $templateCall = $component->call('downloadSampleCsv');
        $this->assertNotNull($templateCall);

        // Test queued export call
        $component->call('queueExportJob');
        $this->assertNotNull($component->get('feedbackMessage'));
    }
}
