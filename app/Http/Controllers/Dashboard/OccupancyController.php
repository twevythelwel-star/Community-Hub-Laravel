<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\MusterSession;
use App\Models\User;
use App\Services\EmergencyMusterService;
use App\Services\Occupancy\OccupancyCategoryResolver;
use App\Services\Occupancy\OccupancyCensusQueryService;
use App\Services\Occupancy\OccupancyHierarchyBuilder;
use App\Services\Occupancy\OccupancyMetricsService;
use App\Support\Csv;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Who is on the estate right now, by unit, and the emergency muster roll.
 *
 * Security, administrators and staff see everything. Anyone else sees the
 * estate-wide totals and their own unit only: the per-unit picture is a list
 * of which homes are empty, and the roll carries every occupant's contact and
 * vehicle. unit() and both exports had no check at all, so any signed-in
 * account could ask who was inside any unit or download the whole roster.
 */
class OccupancyController extends Controller
{
    public function __construct(
        private readonly OccupancyCategoryResolver $categories,
        private readonly OccupancyCensusQueryService $census,
        private readonly OccupancyHierarchyBuilder $hierarchy,
        private readonly OccupancyMetricsService $metrics,
        private readonly EmergencyMusterService $musterService,
    ) {}

    /**
     * Display the Live "Who's On Property?" Security & Admin Dashboard.
     */
    public function index(Request $request): InertiaResponse
    {
        $user = $request->user();
        $isStaff = $this->isStaff($user);
        $ownUnit = $this->ownUnit($user);

        $selectedCategory = $request->string('category')->trim()->toString() ?: 'all';
        $search = $request->string('search')->trim()->toString() ?: null;
        $activeTab = $request->string('tab')->trim()->toString() ?: 'inside';

        // Anyone else is held to their own unit, whatever ?unit= says.
        $selectedUnit = $isStaff
            ? ($request->string('unit')->trim()->toString() ?: null)
            : $ownUnit;

        $communitySummary = $this->metrics->getSummary();
        $expectedSummary = $this->metrics->getExpectedTodaySummary();
        $unitSummary = $selectedUnit ? $this->metrics->getSummary($selectedUnit) : null;
        $unitExpectedSummary = $selectedUnit ? $this->metrics->getExpectedTodaySummary($selectedUnit) : null;

        $units = $isStaff
            ? $this->metrics->getUnitsSummary()
            : [];

        $communityHierarchy = $this->hierarchy->getCommunityHierarchy($isStaff ? null : $ownUnit);

        // No unit of their own means nothing to list, not the whole estate.
        $occupantsCollection = ($isStaff || $selectedUnit)
            ? $this->census->getAllCurrentOccupants(
                unitFilter: $selectedUnit,
                categoryFilter: $selectedCategory,
                search: $search
            )
            : collect();

        $expectedCollection = ($isStaff || $selectedUnit)
            ? $this->census->getAllExpectedToday(
                unitFilter: $selectedUnit,
                categoryFilter: $selectedCategory,
                search: $search
            )
            : collect();

        $activeList = $activeTab === 'expected' ? $expectedCollection : $occupantsCollection;

        // Paginate manually or return full list
        $perPage = 25;
        $page = max(1, (int) $request->input('page', 1));
        $totalOccupants = $activeList->count();
        $paginatedOccupants = $activeList->forPage($page, $perPage)->values();

        // Check for active Emergency Muster / Evacuation session
        $activeMuster = $this->musterService->getActiveSession();
        $musterData = null;
        if ($activeMuster) {
            $musterData = [
                'id' => $activeMuster->id,
                'incident_type' => $activeMuster->incident_type,
                'incident_label' => $activeMuster->incidentLabel(),
                'title' => $activeMuster->title,
                'assembly_point' => $activeMuster->assembly_point,
                'notes' => $activeMuster->notes,
                'started_at' => $activeMuster->started_at->toIso8601String(),
                'started_time' => $activeMuster->started_at->format('g:i A'),
                'initiator_name' => $activeMuster->initiator?->name ?? 'Security Dispatch',
                'counts' => $activeMuster->getCounts(),
                'roll_calls' => $activeMuster->rollCalls
                    ->filter(fn ($rc) => $isStaff || ($ownUnit !== null && $this->sameUnit($rc->unit, $ownUnit)))
                    ->values()
                    ->map(fn ($rc) => [
                        'id' => $rc->id,
                        'occupant_id' => $rc->occupant_id,
                        'name' => $rc->occupant_name,
                        'pass_id' => $rc->pass_id,
                        'unit' => $rc->unit,
                        'category' => $rc->category,
                        'status' => $rc->status,
                        'status_label' => $rc->statusLabel(),
                        'contact' => $rc->contact,
                        'vehicle' => $rc->vehicle,
                        'notes' => $rc->notes,
                        'marked_at' => $rc->marked_at?->toIso8601String(),
                        'marked_by_name' => $rc->marker?->name,
                    ]),
            ];
        }

        return Inertia::render('Dashboard/Occupancy', [
            'summary' => $communitySummary,
            'expectedSummary' => $expectedSummary,
            'unitSummary' => $unitSummary,
            'unitExpectedSummary' => $unitExpectedSummary,
            'units' => $units,
            'selectedUnit' => $selectedUnit,
            'selectedCategory' => $selectedCategory,
            'search' => $search,
            'activeTab' => $activeTab,
            'communityHierarchy' => $communityHierarchy,
            'occupants' => [
                'data' => $paginatedOccupants,
                'total' => $totalOccupants,
                'current_page' => $page,
                'per_page' => $perPage,
                'last_page' => (int) ceil($totalOccupants / $perPage),
            ],
            'canManage' => $isStaff,
            'userUnit' => $ownUnit,
            'activeMuster' => $musterData,
            'enclosureZones' => $isStaff ? $this->metrics->getEnclosureZonesSummary() : [],
            'lastUpdated' => now()->toIso8601String(),
        ]);
    }

    /**
     * Dedicated Drill-down API endpoint: "Who's inside Unit 14?"
     */
    public function unit(Request $request, string $unit): JsonResponse
    {
        $user = $request->user();
        $ownUnit = $this->ownUnit($user);

        abort_unless(
            $this->isStaff($user) || ($ownUnit !== null && $this->sameUnit($unit, $ownUnit)),
            403,
            'You can only see who is at your own unit.',
        );

        $drilldown = $this->hierarchy->getUnitDrilldown($unit);

        return response()->json([
            'success' => true,
            'unit' => $drilldown['unit'],
            'total' => $drilldown['total'],
            'totalCurrentlyInside' => $drilldown['totalCurrentlyInside'],
            'totalExpectedToday' => $drilldown['totalExpectedToday'],
            'summary' => $drilldown['summary'],
            'expectedSummary' => $drilldown['expectedSummary'],
            'occupants' => $drilldown['occupants'],
            'expected' => $drilldown['expected'],
            'rosterByCategory' => $drilldown['rosterByCategory'],
            'ownership' => $drilldown['ownership'] ?? null,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Community Map & Spatial Hierarchy Tree API endpoint.
     */
    public function hierarchy(Request $request): JsonResponse
    {
        $user = $request->user();
        $isStaff = $this->isStaff($user);
        $ownUnit = $this->ownUnit($user);

        // Without a unit of their own a non-staff caller gets nothing: a null
        // scope is the whole estate to the service.
        if (! $isStaff && $ownUnit === null) {
            return response()->json(['success' => true, 'hierarchy' => []]);
        }

        $hierarchy = $this->hierarchy->getCommunityHierarchy($isStaff ? null : $ownUnit);

        return response()->json([
            'success' => true,
            'hierarchy' => $hierarchy,
        ]);
    }

    /**
     * Start an Emergency Muster / Evacuation session.
     */
    public function startMuster(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        if (! $user->role->isAdministrative() && ! $user->role->isSecurity() && ! $user->role->isStaff()) {
            abort(403, 'Unauthorized to initiate emergency muster operations.');
        }

        $validated = $request->validate([
            'incident_type' => ['required', 'string', 'in:fire,hurricane,earthquake,flood,security_incident,drill,other'],
            'title' => ['required', 'string', 'max:160'],
            // Required unless the estate has configured one; there is no invented default.
            'assembly_point' => [Rule::requiredIf(blank(config('occupancy.assembly_point'))), 'nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $session = $this->musterService->startSession(
                incidentType: $validated['incident_type'],
                title: $validated['title'],
                assemblyPoint: $validated['assembly_point'] ?? null,
                notes: $validated['notes'] ?? null,
                initiator: $user
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['muster' => $e->getMessage()])->status(409);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Emergency Muster session for {$session->incidentLabel()} initiated.",
                'session_id' => $session->id,
            ]);
        }

        return back()->with('success', "Emergency Muster session for {$session->incidentLabel()} initiated. Live roll-call active.");
    }

    /**
     * Update an individual's muster status: safe, missing, evacuated, needs_assistance, checked_out.
     */
    public function updateMusterStatus(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        if (! $user->role->isAdministrative() && ! $user->role->isSecurity() && ! $user->role->isStaff()) {
            abort(403, 'Unauthorized to update emergency muster statuses.');
        }

        $validated = $request->validate([
            'session_id' => ['required', 'integer', 'exists:muster_sessions,id'],
            'occupant_id' => ['required', 'string'],
            'status' => ['required', 'string', 'in:safe,missing,evacuated,needs_assistance,checked_out'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $session = MusterSession::findOrFail($validated['session_id']);

        try {
            $record = $this->musterService->updateStatus(
                session: $session,
                occupantId: $validated['occupant_id'],
                status: $validated['status'],
                notes: $validated['notes'] ?? null,
                marker: $user
            );
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['occupant_id' => $e->getMessage()]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'status' => $record->status,
                'status_label' => $record->statusLabel(),
                'counts' => $session->fresh()->getCounts(),
            ]);
        }

        return back()->with('success', "Occupant marked {$record->statusLabel()}.");
    }

    /**
     * Resolve/complete an emergency muster session.
     */
    public function resolveMuster(Request $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        if (! $user->role->isAdministrative() && ! $user->role->isSecurity() && ! $user->role->isStaff()) {
            abort(403, 'Unauthorized to resolve emergency muster operations.');
        }

        $validated = $request->validate([
            'session_id' => ['required', 'integer', 'exists:muster_sessions,id'],
            'resolution_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $session = MusterSession::findOrFail($validated['session_id']);

        $this->musterService->resolveSession(
            session: $session,
            resolver: $user,
            notes: $validated['resolution_notes'] ?? 'All muster points cleared and verified.'
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Emergency muster session concluded and archived.',
            ]);
        }

        return back()->with('success', 'Emergency muster session concluded and archived.');
    }

    /**
     * Export active Emergency Muster roll-call CSV for First Responders.
     */
    public function exportMusterRoster(Request $request): Response
    {
        abort_unless($this->isStaff($request->user()), 403, 'Only security and administration can export the muster roll.');

        $session = $this->musterService->getActiveSession();

        if (! $session) {
            $session = MusterSession::latest('id')->first();
        }

        if (! $session) {
            return $this->exportRoster($request);
        }

        $csv = $this->musterService->exportCsv($session);
        $filename = sprintf('Emergency_Muster_Roster_%s_%s.csv', $session->incident_type, now()->format('Y-m-d_Hi'));

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Emergency Evacuation Roster Export (CSV format for first responders & muster marshals).
     */
    public function exportRoster(Request $request): Response
    {
        abort_unless($this->isStaff($request->user()), 403, 'Only security and administration can export the occupancy roster.');

        $occupants = $this->census->getAllCurrentOccupants();

        $csv = "Unit,Name,Category,Role,CheckIn_Time,Gate,Host_Resident,Vehicle,Contact,Pass_ID\n";

        foreach ($occupants as $item) {
            // Csv::row neutralises formulas: visitor names come from residents and visitors.
            $csv .= Csv::row([
                $item['unit'],
                $item['name'],
                $item['categoryLabel'],
                $item['role'],
                $item['checkedInTime'],
                $item['gate'],
                $item['host'] ?? 'N/A',
                $item['vehicle'] ?? 'N/A',
                $item['contact'] ?? 'N/A',
                $item['passId'],
            ]);
        }

        $filename = sprintf('Emergency_Evacuation_Roster_%s.csv', now()->format('Y-m-d_Hi'));

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** Security, administrators and staff: the people who run the gate and a muster. */
    private function isStaff(User $user): bool
    {
        return $user->role->isAdministrative() || $user->role->isSecurity() || $user->role->isStaff();
    }

    /** The caller's own unit, normalised as the occupancy service does ("Lot 14" -> "Unit 14"). */
    private function ownUnit(User $user): ?string
    {
        return $this->categories->normalizeUnit($user->lot);
    }

    private function sameUnit(?string $a, string $b): bool
    {
        return strcasecmp((string) $this->categories->normalizeUnit($a), $b) === 0;
    }
}
