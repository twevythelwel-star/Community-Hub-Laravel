<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\GatePass\ConfirmScanAction;
use App\Actions\GatePass\RevokePassAction;
use App\Actions\GatePass\ScanGatePassAction;
use App\Actions\GatePass\SyncOfflineScansAction;
use App\Actions\GatePass\TransitionPassAction;
use App\Enums\GateId;
use App\Enums\PassStatus;
use App\Exceptions\InvalidPassTransition;
use App\Exceptions\ScanNotConfirmable;
use App\Http\Controllers\Controller;
use App\Http\Requests\GatePass\ConfirmScanRequest;
use App\Http\Requests\GatePass\RevokePassRequest;
use App\Http\Requests\GatePass\ScanGatePassRequest;
use App\Http\Requests\GatePass\SyncOfflineScansRequest;
use App\Http\Requests\GatePass\TransitionPassRequest;
use App\Models\AccessLogEntry;
use App\Models\GatePass;
use App\Models\User;
use App\Repositories\GatePassRepository;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GatePassController handles passes and gate security interactions.
 * Refactored to delegate validation to Form Requests, mutations to Actions,
 * and data aggregation to GatePassRepository.
 */
class GatePassController extends Controller
{
    public function __construct(
        private readonly GatePassEngine $engine,
        private readonly GateScanner $scanner,
        private readonly GatePassRepository $repository,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $pass = $this->engine->issuePassFor($user);

        $category = $pass->category;
        $canManage = $user->can('manageSecurity');

        return Inertia::render('Dashboard/GatePass', [
            'pass' => [
                'id' => $pass->id,
                'passId' => $pass->pass_id,
                'category' => $category->value,
                'holderName' => $pass->holder_name,
                'property' => $pass->property,
                'accessZone' => $pass->access_zone,
                'gate' => $pass->designated_gate->value,
                'rotationSeq' => $pass->rotation_seq,
                'status' => $pass->status->value,
            ],

            'visual' => [
                'variant' => $this->engine->variantFor($pass),
                'shape' => $category->shape()->value,
            ],

            'policy' => $this->repository->camelPolicy($this->engine->policyFor($category)),
            'categoryConfigs' => $this->repository->getCategoryConfigs(),
            'directory' => $canManage ? $this->repository->getDirectory() : [],
            'staff' => $canManage ? $this->repository->getStaffList() : [],

            'windowSeconds' => (int) config('gatepass.window_seconds'),
            'gates' => config('gatepass.gates'),

            'can' => [
                'scan' => $user->can('scanPasses'),
                'manageSecurity' => $canManage,
                'manageUsers' => $user->can('manageUsers'),
            ],
        ]);
    }

    /**
     * Returns the current rolling token.
     */
    public function token(Request $request): JsonResponse
    {
        $pass = $this->engine->issuePassFor($request->user());

        if (! $pass->isActive()) {
            return response()->json([
                'message' => "Pass {$pass->pass_id} is {$pass->status->label()}. Contact estate security.",
                'status' => $pass->status->value,
            ], 423);
        }

        $issued = $this->engine->issueToken($pass);

        return response()->json([
            'token' => $issued['token'],
            'validFrom' => $issued['valid_from']->toIso8601String(),
            'validUntil' => $issued['valid_until']->toIso8601String(),
            'secondsRemaining' => max(0, $issued['valid_until']->getTimestamp() - now()->getTimestamp()),
        ]);
    }

    public function rotate(Request $request): JsonResponse
    {
        $pass = $this->engine->issuePassFor($request->user());

        $rotation = $this->engine->rotateVisualIdentity($pass);

        $request->user()->recordActivity('Rotated gate pass visual identity');

        return response()->json([
            'rotationSeq' => $rotation['next_seq'],
            'variant' => $rotation['variant'],
            'rotatedAt' => $rotation['rotated_at'],
        ]);
    }

    /**
     * Validates and evaluates a scanned QR token.
     */
    public function scan(ScanGatePassRequest $request, ScanGatePassAction $action): JsonResponse
    {
        $result = $action->execute(
            $request->validated('token'),
            $request->validated('gate'),
            $request->user()
        );

        return response()->json($result);
    }

    /**
     * Confirms or refuses a scanned decision.
     */
    public function confirmScan(ConfirmScanRequest $request, ConfirmScanAction $action, string $scan): JsonResponse
    {
        try {
            $result = $action->execute(
                $scan,
                $request->user(),
                (bool) $request->validated('accept'),
                $request->validated('reason')
            );
        } catch (ScanNotConfirmable $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['success' => true, ...$result]);
    }

    /**
     * Reconcile queued offline scans recorded by gate guards during connectivity loss.
     */
    public function syncOfflineScans(SyncOfflineScansRequest $request, SyncOfflineScansAction $action): JsonResponse
    {
        return response()->json($action->execute(
            $request->validated('scans'),
            $request->validated('gate'),
            $request->user()
        ));
    }

    public function revoke(RevokePassRequest $request, RevokePassAction $action, GatePass $gatePass): RedirectResponse
    {
        try {
            $action->execute($gatePass, $request->user(), $request->validated('reason'));
        } catch (InvalidPassTransition $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return back()->with('success', "Pass {$gatePass->pass_id} has been revoked.");
    }

    /**
     * Issues a new pass to an account whose last one was revoked or expired.
     */
    public function reissue(Request $request, User $user): RedirectResponse
    {
        try {
            $pass = $this->engine->reissuePassFor($user, $request->user());
        } catch (\RuntimeException $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        Log::channel('security')->notice('Gate pass reissued', [
            'pass_id' => $pass->pass_id,
            'holder' => $user->uid,
            'by' => $request->user()->uid,
        ]);

        return back()->with('success', "Pass {$pass->pass_id} issued to {$user->display_name}.");
    }

    /**
     * Moves a pass through its lifecycle by hand.
     */
    public function transition(TransitionPassRequest $request, TransitionPassAction $action, GatePass $gatePass): RedirectResponse
    {
        $target = PassStatus::from($request->validated('status'));

        try {
            $action->execute($gatePass, $target, $request->user(), $request->validated('reason'));
        } catch (InvalidPassTransition $e) {
            return back()->withErrors(['status' => $e->getMessage()]);
        }

        return back()->with('success', "Pass {$gatePass->pass_id} is now {$gatePass->status->label()}.");
    }

    /**
     * Dedicated Gate Scanner Kiosk view for security officers.
     */
    public function scanner(Request $request): Response
    {
        $user = $request->user();
        $gates = array_map(fn (GateId $g) => [
            'id' => $g->value,
            'name' => $g->label(),
        ], GateId::cases());

        $recentScans = AccessLogEntry::latest('occurred_at')
            ->take(12)
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'userName' => $e->user_name,
                'userRole' => $e->user_role,
                'result' => $e->result,
                'gate' => $e->gate,
                'occurredAt' => $e->occurred_at?->diffForHumans() ?? 'Just now',
                'denyReason' => $e->deny_reason,
            ]);

        return Inertia::render('Dashboard/GateScanner', [
            'gates' => $gates,
            'recentScans' => $recentScans,
            'guard' => [
                'name' => $user->display_name,
                'role' => $user->role->value,
            ],
        ]);
    }
}
