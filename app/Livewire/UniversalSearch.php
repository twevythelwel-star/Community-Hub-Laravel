<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Services\Search\UniversalSearchService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('layouts.livewire')]
class UniversalSearch extends Component
{
    #[Url(as: 'q')]
    public string $query = '';

    #[Url(as: 'category')]
    public string $selectedCategory = 'all';

    public int $limit = 8;

    public bool $isModal = false;

    /**
     * Active search driver info cache.
     *
     * @var array<string, mixed>
     */
    public array $driverInfo = [];

    public function mount(UniversalSearchService $searchService, bool $isModal = false): void
    {
        abort_unless(Auth::user()?->isActive(), 403);

        $this->isModal = $isModal;
        $this->driverInfo = $searchService->getDriverInfo();
    }

    public function updatedQuery(): void
    {
        // Triggers re-render with updated results
    }

    public function setCategory(string $category): void
    {
        $allowed = ['all', 'users', 'gate_passes', 'warnings', 'transactions', 'visitors'];
        if (in_array($category, $allowed, true)) {
            $this->selectedCategory = $category;
        }
    }

    public function clearQuery(): void
    {
        $this->query = '';
    }

    public function render(UniversalSearchService $searchService): View
    {
        $types = $this->selectedCategory === 'all' ? [] : [$this->selectedCategory];

        $searchData = $searchService->search(
            query: $this->query,
            types: $types,
            limit: $this->limit,
            user: Auth::user(),
        );

        return view('livewire.universal-search', [
            'searchData' => $searchData,
            'driver' => $searchService->getDriver(),
            'supportedDrivers' => $searchService->getSupportedDrivers(),
        ]);
    }
}
