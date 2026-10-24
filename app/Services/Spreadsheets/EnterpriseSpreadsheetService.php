<?php

declare(strict_types=1);

namespace App\Services\Spreadsheets;

use App\Exports\GatePassesExport;
use App\Exports\QueuedDatasetExport;
use App\Exports\ResidentsExport;
use App\Exports\TransactionsExport;
use App\Imports\ResidentsImport;
use App\Models\GatePass;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Horizon\Horizon;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class EnterpriseSpreadsheetService
{
    /**
     * The gate for each blueprint: that of the dashboard page that already
     * lists the same records — the roster is the directory's, the ledger is
     * billing's, passes are the security desk's. Shared by the API and the
     * Livewire hub so the two cannot drift; anything unlisted is the System
     * Admin's (see exportGate() and importGate()).
     */
    public const EXPORT_GATES = [
        'residents' => 'manageUsers',
        'transactions' => 'manageBilling',
        'gate_passes' => 'manageSecurity',
    ];

    public const IMPORT_GATES = [
        'residents' => 'manageUsers',
    ];

    public function exportGate(string $blueprint): string
    {
        return self::EXPORT_GATES[$blueprint] ?? 'operatePlatform';
    }

    public function importGate(string $blueprint): string
    {
        return self::IMPORT_GATES[$blueprint] ?? 'operatePlatform';
    }

    /**
     * Catalog of export blueprints.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getExportBlueprints(): array
    {
        return [
            'residents' => [
                'name' => 'Residents & Homeowners Roster',
                'description' => 'Comprehensive directory including contact details, lot numbers, roles, and account statuses.',
                'model' => User::class,
                'count' => User::count(),
                'formats' => ['xlsx', 'csv'],
                'supports_queue' => true,
                'chunk_size' => 500,
                'default_filename' => 'community-residents-roster',
            ],
            'transactions' => [
                'name' => 'Financial Transactions & Dues Ledger',
                'description' => 'Complete double-entry accounting records, payment provider references, fees, and currency settlements.',
                'model' => Transaction::class,
                'count' => Transaction::count(),
                'formats' => ['xlsx', 'csv'],
                'supports_queue' => true,
                'chunk_size' => 500,
                'default_filename' => 'financial-ledger-transactions',
            ],
            'gate_passes' => [
                'name' => 'Security Checkpoint Passes & Access Logs',
                'description' => 'Visitor, resident, and contractor gate passes with designated zones, validity dates, and check-in statuses.',
                'model' => GatePass::class,
                'count' => GatePass::count(),
                'formats' => ['xlsx', 'csv'],
                'supports_queue' => true,
                'chunk_size' => 500,
                'default_filename' => 'gate-passes-registry',
            ],
        ];
    }

    /**
     * Catalog of supported import blueprints.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getImportBlueprints(): array
    {
        return [
            'residents' => [
                'name' => 'Resident Roster Import',
                'description' => 'Batch ingest new property owners, tenants, and staff from CSV or XLSX spreadsheets.',
                'accepted_extensions' => ['csv', 'xlsx', 'xls'],
                'heading_row' => 1,
                'batch_size' => 100,
                'chunk_size' => 500,
                'columns' => [
                    'full_legal_name' => ['required' => true, 'example' => 'Marcus Vance', 'description' => 'Primary account holder name'],
                    'email_address' => ['required' => true, 'example' => 'marcus.vance@example.com', 'description' => 'Unique email for authentication'],
                    'contact_phone' => ['required' => false, 'example' => '(876) 555-0142', 'description' => 'Primary mobile contact'],
                    'assigned_role' => ['required' => false, 'example' => 'Homeowner', 'description' => 'Homeowner, Temporary Homeowner, Security, Staff'],
                    'property_lot' => ['required' => false, 'example' => 'Lot 42', 'description' => 'Assigned lot identifier'],
                    'street_address' => ['required' => false, 'example' => 'Palmetto Way', 'description' => 'Street or boulevard'],
                ],
            ],
        ];
    }

    /**
     * Synchronously export a dataset to XLSX or CSV download.
     *
     * @param  array<string, mixed>  $filters
     */
    public function export(string $blueprint, string $format = 'xlsx', array $filters = []): BinaryFileResponse
    {
        $normalizedFormat = strtolower($format) === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX;
        $extension = strtolower($format) === 'csv' ? 'csv' : 'xlsx';
        $filename = ($filters['filename'] ?? $blueprint).'-'.date('Ymd-His').'.'.$extension;

        $exportable = match ($blueprint) {
            'residents' => new ResidentsExport($filters['role'] ?? null, $filters['status'] ?? null),
            'transactions' => new TransactionsExport($filters['provider'] ?? null, $filters['status'] ?? null),
            'gate_passes' => new GatePassesExport($filters['status'] ?? null, $filters['category'] ?? null),
            'large_dataset_benchmark' => new QueuedDatasetExport('residents', 1000),
            default => throw new InvalidArgumentException("Unknown export blueprint [{$blueprint}]."),
        };

        return Excel::download($exportable, $filename, $normalizedFormat);
    }

    /**
     * Queue an export job for asynchronous background processing via Laravel Queues.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function queueExport(string $blueprint, string $format = 'xlsx', string $disk = 'local', array $filters = []): array
    {
        $extension = strtolower($format) === 'csv' ? 'csv' : 'xlsx';
        $normalizedFormat = strtolower($format) === 'csv' ? \Maatwebsite\Excel\Excel::CSV : \Maatwebsite\Excel\Excel::XLSX;
        $filename = "exports/{$blueprint}-queued-".date('Ymd-His').'-'.Str::random(6).'.'.$extension;

        $exportable = match ($blueprint) {
            'residents' => new QueuedDatasetExport('residents', 500),
            'transactions' => new QueuedDatasetExport('transactions', 500),
            'gate_passes' => new QueuedDatasetExport('gate_passes', 500),
            'large_dataset_benchmark' => new QueuedDatasetExport('benchmark', 1000),
            default => throw new InvalidArgumentException("Unknown export blueprint [{$blueprint}]."),
        };

        // Dispatch queued export to the exports queue
        $job = $exportable->queue($filename, $disk, $normalizedFormat)->allOnQueue('exports');

        return [
            'status' => 'queued',
            'blueprint' => $blueprint,
            'format' => $extension,
            'disk' => $disk,
            'path' => $filename,
            'queue' => 'exports',
            'queued_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Import spreadsheet rows from uploaded file or local path.
     *
     * @return array<string, mixed>
     */
    public function import(string $blueprint, UploadedFile|string $file): array
    {
        if ($blueprint !== 'residents') {
            throw new InvalidArgumentException("Unsupported import blueprint [{$blueprint}]. Supported: residents");
        }

        $importer = new ResidentsImport;
        Excel::import($importer, $file);

        $failures = [];
        foreach ($importer->failures() as $failure) {
            $failures[] = [
                'row' => $failure->row(),
                'attribute' => $failure->attribute(),
                'errors' => $failure->errors(),
                'values' => $failure->values(),
            ];
        }

        return [
            'status' => 'success',
            'blueprint' => $blueprint,
            'imported_count' => $importer->getImportedCount(),
            'failures_count' => count($failures),
            'errors_count' => count($importer->errors()),
            'failures' => $failures,
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Generate sample CSV template for import.
     */
    public function generateSampleCsv(string $blueprint): string
    {
        $blueprints = $this->getImportBlueprints();
        if (! isset($blueprints[$blueprint])) {
            throw new InvalidArgumentException("Unknown import blueprint [{$blueprint}].");
        }

        $columns = array_keys($blueprints[$blueprint]['columns']);
        $csv = implode(',', $columns)."\n";

        // Add 2 mock sample rows
        $csv .= "Alexander Vance,alex.vance@example.org,(876) 555-0199,Homeowner,Lot 42,Palmetto Way\n";
        $csv .= "Dr. Simone Chen,simone.chen@example.org,(876) 555-0218,Homeowner,Lot 108,Ocean View Terrace\n";

        return $csv;
    }

    /**
     * Get queue telemetry and chunk size configuration.
     *
     * @return array<string, mixed>
     */
    public function getQueueEngineStatus(): array
    {
        return [
            'default_driver' => config('queue.default'),
            'excel_chunk_size' => config('excel.exports.chunk_size', 1000),
            'csv_delimiter' => config('excel.exports.csv.delimiter', ','),
            'csv_enclosure' => config('excel.exports.csv.enclosure', '"'),
            'temp_path' => config('excel.temporary_files.local_path'),
            'horizon_enabled' => class_exists(Horizon::class),
        ];
    }
}
