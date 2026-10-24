<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Search\UniversalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UniversalSearchController extends Controller
{
    /**
     * The middleware is declared here as well as on the routes, so search
     * stays behind sign-in however routes/web.php groups it. What a signed-in
     * account can find is narrowed by EntityAccess inside the service.
     */
    public function __construct(
        protected UniversalSearchService $searchService
    ) {
        $this->middleware(['auth', 'active']);
    }

    /**
     * Display the universal search hub view.
     */
    public function index(Request $request): View
    {
        return view('search.index', [
            'initialQuery' => (string) $request->query('q', ''),
            'driverInfo' => $this->searchService->getDriverInfo(),
        ]);
    }

    /**
     * Execute a universal search across models.
     */
    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'types' => ['nullable', 'array'],
            'types.*' => ['string', 'in:users,gate_passes,warnings,transactions,visitors'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = (string) ($validated['q'] ?? '');
        $types = (array) ($validated['types'] ?? []);
        $limit = (int) ($validated['limit'] ?? 10);

        $results = $this->searchService->search(
            query: $query,
            types: $types,
            limit: $limit,
            user: $request->user()
        );

        return response()->json([
            'success' => true,
            'data' => $results,
        ]);
    }

    /**
     * Retrieve active Scout driver and engine information.
     */
    public function driverInfo(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->searchService->getDriverInfo(),
        ]);
    }
}
