<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Services\Spreadsheets\EnterpriseSpreadsheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SpreadsheetApiController extends Controller
{
    /** Blueprint gates live on the service: EnterpriseSpreadsheetService::EXPORT_GATES. */
    public function __construct(
        protected EnterpriseSpreadsheetService $spreadsheetService
    ) {
        // Also on the routes; declared here so a regrouped routes/api.php cannot open it.
        $this->middleware(['auth:sanctum', 'active']);
    }

    /**
     * Get export & import blueprint catalog and queue engine status, listing
     * only the blueprints the caller may use.
     */
    public function blueprints(Request $request): JsonResponse
    {
        $allowed = fn (array $gates) => fn (string $key): bool => isset($gates[$key]) && $request->user()->can($gates[$key]);

        return ApiResponse::success(
            [
                'export_blueprints' => array_filter($this->spreadsheetService->getExportBlueprints(), $allowed(EnterpriseSpreadsheetService::EXPORT_GATES), ARRAY_FILTER_USE_KEY),
                'import_blueprints' => array_filter($this->spreadsheetService->getImportBlueprints(), $allowed(EnterpriseSpreadsheetService::IMPORT_GATES), ARRAY_FILTER_USE_KEY),
                'queue_engine' => $this->spreadsheetService->getQueueEngineStatus(),
            ],
            'Spreadsheet module blueprints and queue telemetry retrieved.'
        );
    }

    /**
     * Synchronously export a dataset to XLSX or CSV download.
     */
    public function export(Request $request, string $blueprint): Response
    {
        $blueprints = $this->spreadsheetService->getExportBlueprints();
        if (! isset($blueprints[$blueprint])) {
            abort(404, "Export blueprint [{$blueprint}] not found.");
        }

        $this->authorize($this->spreadsheetService->exportGate($blueprint));

        $format = $request->query('format', 'xlsx');
        if (! in_array(strtolower($format), ['xlsx', 'csv'], true)) {
            abort(400, 'Invalid format. Supported formats: xlsx, csv.');
        }

        $filters = $request->all();

        return $this->spreadsheetService->export($blueprint, $format, $filters);
    }

    /**
     * Enqueue an asynchronous export job to the queue.
     */
    public function queueExport(Request $request, string $blueprint): JsonResponse
    {
        $blueprints = $this->spreadsheetService->getExportBlueprints();
        if (! isset($blueprints[$blueprint])) {
            abort(404, "Export blueprint [{$blueprint}] not found.");
        }

        $this->authorize($this->spreadsheetService->exportGate($blueprint));

        // Never a caller's choice: `public` would publish the roster or the
        // ledger at a /storage URL anyone could fetch.
        $request->validate(['disk' => ['nullable', 'in:local']]);

        $format = $request->input('format', 'xlsx');
        $disk = 'local';
        $filters = $request->all();

        $result = $this->spreadsheetService->queueExport($blueprint, $format, $disk, $filters);

        return ApiResponse::success(
            $result,
            "Dataset [{$blueprint}] export enqueued successfully for background processing.",
            202
        );
    }

    /**
     * Import spreadsheet rows from uploaded CSV or XLSX file.
     */
    public function import(Request $request, string $blueprint): JsonResponse
    {
        $blueprints = $this->spreadsheetService->getImportBlueprints();
        if (! isset($blueprints[$blueprint])) {
            abort(404, "Import blueprint [{$blueprint}] not found.");
        }

        $this->authorize($this->spreadsheetService->importGate($blueprint));

        $request->validate([
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        $file = $request->file('file');
        $result = $this->spreadsheetService->import($blueprint, $file);

        return ApiResponse::success(
            $result,
            "Spreadsheet imported: {$result['imported_count']} rows processed."
        );
    }

    /**
     * Download sample CSV template.
     */
    public function sampleTemplate(string $blueprint): Response
    {
        $csv = $this->spreadsheetService->generateSampleCsv($blueprint);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"sample-{$blueprint}-template.csv\"",
        ]);
    }
}
