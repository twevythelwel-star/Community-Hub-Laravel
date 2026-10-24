<?php

declare(strict_types=1);

namespace App\Livewire\Spreadsheets;

use App\Services\Spreadsheets\EnterpriseSpreadsheetService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SpreadsheetOperationsHub extends Component
{
    use WithFileUploads;

    public string $activeTab = 'exporter'; // exporter, importer, large_datasets, specifications

    // Exporter State
    public string $selectedBlueprint = 'residents';

    public string $selectedFormat = 'xlsx'; // xlsx, csv

    public string $roleFilter = '';

    public string $statusFilter = '';

    public string $search = '';

    // Importer State
    public $importFile = null;

    public ?array $importSummary = null;

    // Feedback
    public ?string $feedbackMessage = null;

    /**
     * Open to anyone who may use at least one blueprint; each action then
     * checks the blueprint it acts on, because `selectedBlueprint` is a
     * public property the browser can set to anything. boot() runs on the
     * first load and on every action request.
     */
    public function boot(): void
    {
        $user = Auth::user();

        abort_unless($user && collect(array_unique([
            ...array_values(EnterpriseSpreadsheetService::EXPORT_GATES),
            ...array_values(EnterpriseSpreadsheetService::IMPORT_GATES),
        ]))->contains(fn (string $gate) => $user->can($gate)), 403);
    }

    public function selectTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function selectBlueprint(string $blueprint): void
    {
        $this->selectedBlueprint = $blueprint;
    }

    public function downloadExport(EnterpriseSpreadsheetService $service): BinaryFileResponse
    {
        $this->authorize($service->exportGate($this->selectedBlueprint));

        $filters = array_filter([
            'role' => $this->roleFilter ?: null,
            'status' => $this->statusFilter ?: null,
        ]);

        $this->feedbackMessage = "Synchronous [{$this->selectedBlueprint}] export generated in [{$this->selectedFormat}] format.";

        return $service->export($this->selectedBlueprint, $this->selectedFormat, $filters);
    }

    public function queueExportJob(EnterpriseSpreadsheetService $service): void
    {
        $this->authorize($service->exportGate($this->selectedBlueprint));

        $filters = array_filter([
            'role' => $this->roleFilter ?: null,
            'status' => $this->statusFilter ?: null,
        ]);

        $result = $service->queueExport($this->selectedBlueprint, $this->selectedFormat, 'local', $filters);

        $this->feedbackMessage = "Queued export for [{$this->selectedBlueprint}] ({$result['format']}) dispatched to queue [{$result['queue']}]. File will be stored at: {$result['path']}";
    }

    public function processImport(EnterpriseSpreadsheetService $service): void
    {
        $this->authorize($service->importGate('residents'));

        $this->validate([
            'importFile' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ]);

        $result = $service->import('residents', $this->importFile);
        $this->importSummary = $result;

        $this->feedbackMessage = "Import completed: {$result['imported_count']} rows processed, {$result['failures_count']} validation failures.";
        $this->importFile = null;
    }

    public function downloadSampleCsv(EnterpriseSpreadsheetService $service): StreamedResponse
    {
        $csv = $service->generateSampleCsv('residents');

        return response()->streamDownload(
            fn () => print ($csv),
            'sample-residents-import-template.csv',
            ['Content-Type' => 'text/csv']
        );
    }

    public function render(EnterpriseSpreadsheetService $service): View
    {
        $exportBlueprints = $service->getExportBlueprints();
        $importBlueprints = $service->getImportBlueprints();
        $queueEngine = $service->getQueueEngineStatus();

        return view('livewire.spreadsheets.spreadsheet-operations-hub', [
            'exportBlueprints' => $exportBlueprints,
            'importBlueprints' => $importBlueprints,
            'queueEngine' => $queueEngine,
            'selectedMeta' => $exportBlueprints[$this->selectedBlueprint] ?? null,
        ])->layout('layouts.app', ['header' => 'Excel & CSV Data Processing Hub']);
    }
}
