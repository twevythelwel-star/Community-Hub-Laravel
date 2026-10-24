<?php

namespace App\Http\Controllers\Dashboard;

use App\Enums\PassStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Delegation\ActivateEmergencyRequest;
use App\Http\Requests\Delegation\ApproveDelegationRequest;
use App\Http\Requests\Delegation\DeactivateEmergencyRequest;
use App\Http\Requests\Delegation\RejectDelegationRequest;
use App\Http\Requests\Delegation\ReissuePassRequest;
use App\Http\Requests\Delegation\RevokeDelegationRequest;
use App\Http\Requests\Delegation\SaveContinuityPlanRequest;
use App\Http\Requests\Delegation\StoreDelegatedAccessRequest;
use App\Http\Requests\Delegation\UpdateDelegationRulesRequest;
use App\Models\DelegatedAccess;
use App\Models\GatePass;
use App\Services\Delegation\DelegatedAccessService;
use App\Services\GatePassEngine;
use App\Services\People\DelegatedAccessPresenter;
use App\Services\People\PropertyPeopleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DelegatedAccessController extends Controller
{
    public function __construct(
        protected GatePassEngine $engine,
        protected PropertyPeopleService $propertyPeopleService,
        protected DelegatedAccessPresenter $presenter
    ) {}

    /**
     * Display the Delegated Access Center ("Unit 14 — People & Access" or "Unit 14 — My Access Network").
     */
    public function index(Request $request): Response|JsonResponse
    {
        $data = $this->presenter->present($request);

        if ($request->wantsJson()) {
            return response()->json($data);
        }

        return Inertia::render('Dashboard/Delegation/Index', $data);
    }

    /**
     * Store a new emergency contact, occupant, or delegated access grant.
     */
    public function store(StoreDelegatedAccessRequest $request, DelegatedAccessService $service): RedirectResponse|JsonResponse
    {
        $delegation = $service->createDelegation($request->user(), $request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Authorization successfully created for {$delegation->name}.",
                'delegation' => $delegation->load(['property', 'delegate', 'gatePasses']),
            ], 201);
        }

        return back()->with('success', "Authorization created for {$delegation->name}.");
    }

    /**
     * Update access rules and permissions for an authorized person.
     */
    public function updateRules(UpdateDelegationRulesRequest $request, DelegatedAccess $delegation): RedirectResponse|JsonResponse
    {
        $this->authorizeDelegation($request->user(), $delegation);

        $validated = $request->validated();
        $inputRules = $validated['access_rules'];
        if (! empty($inputRules['entry_start_time']) && ! empty($inputRules['entry_end_time'])) {
            $inputRules['time_window'] = [
                'start' => $inputRules['entry_start_time'],
                'end' => $inputRules['entry_end_time'],
            ];
        }
        if (! empty($inputRules['allowed_days'])) {
            $inputRules['days_permitted'] = $inputRules['allowed_days'];
        }

        $updates = [
            'access_rules' => array_merge($delegation->access_rules ?? [], $inputRules),
            'permissions' => $validated['permissions'] ?? $delegation->permissions,
        ];

        if (! empty($validated['duration_type'])) {
            $updates['duration_type'] = $validated['duration_type'];
            $startsAt = ! empty($validated['starts_at']) ? Carbon::parse($validated['starts_at']) : ($delegation->starts_at ?? now());
            $updates['starts_at'] = $startsAt;
            $updates['expires_at'] = match ($validated['duration_type']) {
                '2_hours' => (clone $startsAt)->addHours(2),
                '1_day' => (clone $startsAt)->isToday() ? (clone $startsAt)->endOfDay() : (clone $startsAt)->addDay(),
                '1_week' => (clone $startsAt)->addDays(7)->endOfDay(),
                'manual_revocation' => null,
                'recurring' => ! empty($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : (clone $startsAt)->endOfYear(),
                default => ! empty($validated['expires_at']) ? Carbon::parse($validated['expires_at']) : $delegation->expires_at,
            };
        }

        $delegation->update($updates);

        $newValidUntil = $delegation->passValidUntil();
        $delegation->gatePasses()
            ->whereIn('status', [PassStatus::Active, PassStatus::Issued, PassStatus::CheckedIn])
            ->get()
            ->each(function (GatePass $pass) use ($newValidUntil, $delegation) {
                $pass->update([
                    'valid_until' => $newValidUntil,
                    'metadata' => array_merge($pass->metadata ?? [], [
                        'duration_type' => $delegation->duration_type,
                        'access_rules' => $delegation->access_rules,
                    ]),
                ]);
            });

        $delegation->logEvent('rules_updated', "Access permissions & operational rules updated for {$delegation->name}", [
            'access_rules' => $delegation->access_rules,
            'permissions' => $delegation->permissions,
            'duration_type' => $delegation->duration_type,
        ], $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Access rules updated for {$delegation->name}.",
                'delegation' => $delegation->fresh(['gatePasses']),
            ]);
        }

        return back()->with('success', "Access rules updated for {$delegation->name}.");
    }

    /**
     * Reissue or rotate a fresh QR credential pass for an authorized person.
     */
    public function reissuePass(ReissuePassRequest $request, DelegatedAccess $delegation): RedirectResponse|JsonResponse
    {
        $this->authorizeDelegation($request->user(), $delegation);

        $delegation->revokePasses($request->user());
        $pass = $delegation->issueDelegateGatePass($this->engine, $request->user());

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Fresh QR credential ({$pass->pass_id}) issued for {$delegation->name}.",
                'pass' => $pass,
            ]);
        }

        return back()->with('success', "Fresh QR credential issued for {$delegation->name}.");
    }

    /**
     * Activate emergency mode for an authorized delegate.
     */
    public function activateEmergency(
        ActivateEmergencyRequest $request,
        DelegatedAccess $delegation,
        DelegatedAccessService $service
    ): RedirectResponse|JsonResponse {
        $this->authorizeDelegation($request->user(), $delegation);

        $validated = $request->validated();
        $service->activateEmergencyAccess(
            delegation: $delegation,
            reason: $validated['reason'],
            actor: $request->user(),
            durationDays: $validated['duration_days'] ?? 7,
            notes: $validated['notes'] ?? null
        );

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Emergency access activated for {$delegation->name}.",
                'delegation' => $delegation->fresh(['events', 'gatePasses']),
            ]);
        }

        return back()->with('success', "Emergency access activated for {$delegation->name}.");
    }

    /**
     * Deactivate emergency mode for an authorized delegate.
     */
    public function deactivateEmergency(
        DeactivateEmergencyRequest $request,
        DelegatedAccess $delegation,
        DelegatedAccessService $service
    ): RedirectResponse|JsonResponse {
        $this->authorizeDelegation($request->user(), $delegation);

        $validated = $request->validated();
        $service->deactivateEmergencyAccess($delegation, $request->user(), $validated['reason'] ?? null);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Emergency access deactivated for {$delegation->name}.",
                'delegation' => $delegation->fresh(['events', 'gatePasses']),
            ]);
        }

        return back()->with('success', "Emergency access deactivated for {$delegation->name}.");
    }

    /**
     * Revoke a delegation permanently.
     */
    public function revoke(
        RevokeDelegationRequest $request,
        DelegatedAccess $delegation,
        DelegatedAccessService $service
    ): RedirectResponse|JsonResponse {
        $this->authorizeDelegation($request->user(), $delegation);

        $validated = $request->validated();
        $service->revokeDelegation($delegation, $request->user(), $validated['reason'] ?? null);

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => "Delegation for {$delegation->name} has been revoked.",
                'delegation' => $delegation->fresh(),
            ]);
        }

        return back()->with('success', "Delegation for {$delegation->name} has been revoked.");
    }

    /**
     * Configure the homeowner's Emergency & Incapacity Continuity Plan.
     */
    public function saveContinuityPlan(SaveContinuityPlanRequest $request, DelegatedAccessService $service): RedirectResponse|JsonResponse
    {
        $plan = $service->saveContinuityPlan($request->user(), $request->validated());

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Emergency Continuity Plan saved successfully.',
                'plan' => $plan->fresh(['primaryDelegate', 'secondaryDelegate']),
            ]);
        }

        return back()->with('success', 'Emergency Continuity Plan updated.');
    }

    /**
     * Retrieve the audit trail events for a delegation.
     */
    public function auditEvents(Request $request, DelegatedAccess $delegation): JsonResponse
    {
        $this->authorizeDelegation($request->user(), $delegation);

        $events = $delegation->events()->with('actor:id,name,email')->get();

        return response()->json([
            'delegation_id' => $delegation->id,
            'name' => $delegation->name,
            'events' => $events,
        ]);
    }

    /**
     * Ensure the actor is the grantor homeowner or has platform administrative authority.
     */
    protected function authorizeDelegation($user, DelegatedAccess $delegation): void
    {
        $isGrantor = $user->id === $delegation->grantor_user_id;
        $isAdmin = $user->role->isAdministrative();

        if (! $isGrantor && ! $isAdmin) {
            abort(403, 'Unauthorized access to this delegation boundary.');
        }
    }

    /**
     * An administrator approves a delegation awaiting approval; only then is its pass issued.
     */
    public function approve(ApproveDelegationRequest $request, DelegatedAccess $delegation, DelegatedAccessService $service): RedirectResponse|JsonResponse
    {
        abort_unless($delegation->approval_status === 'pending', 422, 'This delegation is not awaiting approval.');

        $service->approveDelegation($delegation, $request->user());

        return $request->wantsJson()
            ? response()->json(['success' => true, 'delegation' => $delegation->fresh(['gatePasses'])])
            : back()->with('success', "Delegation for {$delegation->name} approved.");
    }

    /**
     * An administrator rejects a delegation awaiting approval.
     */
    public function reject(RejectDelegationRequest $request, DelegatedAccess $delegation, DelegatedAccessService $service): RedirectResponse|JsonResponse
    {
        abort_unless($delegation->approval_status === 'pending', 422, 'This delegation is not awaiting approval.');

        $validated = $request->validated();
        $service->rejectDelegation($delegation, $request->user(), $validated['reason'] ?? null);

        return $request->wantsJson()
            ? response()->json(['success' => true])
            : back()->with('success', "Delegation for {$delegation->name} rejected.");
    }

    /**
     * The invitee, signed in, accepts the invitation sent to their email.
     */
    public function accept(Request $request, string $token, DelegatedAccessService $service): RedirectResponse|JsonResponse
    {
        try {
            $delegation = $service->acceptInvite($token, $request->user());
        } catch (\DomainException $e) {
            abort(403, $e->getMessage());
        }

        return $request->wantsJson()
            ? response()->json(['success' => true, 'delegation_id' => $delegation->id])
            : redirect()->route('dashboard.delegation')->with('success', "You are now linked to {$delegation->grantor?->name}'s authorization.");
    }
}
