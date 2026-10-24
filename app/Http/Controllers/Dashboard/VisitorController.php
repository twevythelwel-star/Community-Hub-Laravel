<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Visitors\CheckInVisitorAction;
use App\Actions\Visitors\ExtendVisitorPassAction;
use App\Actions\Visitors\RegisterBulkVisitorsAction;
use App\Actions\Visitors\RegisterVisitorAction;
use App\Actions\Visitors\UpdateVisitorAction;
use App\Enums\GateId;
use App\Enums\PassCategory;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Events\VisitorCheckedInEvent;
use App\Exceptions\ScanNotConfirmable;
use App\Http\Controllers\Controller;
use App\Http\Requests\Visitors\CheckInVisitorRequest;
use App\Http\Requests\Visitors\CheckOutVisitorRequest;
use App\Http\Requests\Visitors\ExtendVisitorPassRequest;
use App\Http\Requests\Visitors\RegisterVisitorRequest;
use App\Http\Requests\Visitors\ResendVisitorPassRequest;
use App\Http\Requests\Visitors\StoreBulkVisitorsRequest;
use App\Http\Requests\Visitors\UpdateVisitorRequest;
use App\Models\AccessLogEntry;
use App\Models\BlocklistEntry;
use App\Models\GatePass;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use App\Repositories\VisitorRepository;
use App\Services\GatePassEngine;
use App\Services\GateScanner;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Backs src/app/dashboard/visitors/page.tsx.
 *
 * The blocklist check now runs on the server at registration and again at
 * check-in. Previously `isBlocked` was a field on the mock record that nothing
 * ever computed, so a blocked name could be registered and admitted freely.
 */
class VisitorController extends Controller
{
    public function __construct(
        private readonly GatePassEngine $engine,
        private readonly GateScanner $scanner,
        private readonly VisitorRepository $repository,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $isStaff = $user->can('manageSecurity');
        $tab = $request->string('tab')->toString() ?: 'all';
        $manageableProperties = $user->propertiesForVisitorManagement();

        $securityStats = $this->repository->getSecurityStats($user, $isStaff);
        $tabCounts = $this->repository->getTabCounts($user, $isStaff, $securityStats);
        $visitors = $this->repository->getPaginatedVisitors($request, $user, $isStaff, $tab);

        $userStay = null;
        if ($user->role === UserRole::TemporaryHomeowner) {
            $stay = $user->activeStay() ?? Renter::where('user_id', $user->id)->first();
            if ($stay) {
                $userStay = [
                    'stayType' => $stay->stay_type ?? 'Long-term (Renter)',
                    'leaseStart' => $stay->lease_start->toDateString(),
                    'leaseEnd' => $stay->lease_end->toDateString(),
                    'expired' => $stay->leaseHasExpired(),
                ];
            }
        }

        return Inertia::render('Dashboard/Visitors', [
            'visitors' => $visitors,
            'filters' => [
                'status' => $request->string('status')->toString(),
                'search' => $request->string('search')->toString(),
                'tab' => $tab,
            ],
            'tabCounts' => $tabCounts,
            'securityStats' => $securityStats,
            'activeTab' => $tab,
            'canManage' => $isStaff,
            'canRegister' => $user->can('registerVisitors'),
            'userStay' => $userStay,
            'graceHours' => 12,
            // An owner or authorized representative says which property a visitor is coming to.
            'hostProperties' => $manageableProperties->map(fn ($p) => ['id' => $p->id, 'label' => $p->hostLabel()])->values(),
        ]);
    }

    public function store(RegisterVisitorRequest $request, RegisterVisitorAction $action): RedirectResponse|JsonResponse
    {
        $result = $action->execute($request->validated(), $request->user());

        return $this->visitorRegistered($request, $result['visitor'], $result['pass'], $result['message']);
    }

    /** The page's flash, or JSON for a client that asked for it. */
    private function visitorRegistered(Request $request, Visitor $visitor, GatePass $pass, string $successMsg): RedirectResponse|JsonResponse
    {
        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => $successMsg,
                'visitor' => [
                    'id' => $visitor->id,
                    'name' => $visitor->name,
                    'status' => $visitor->status,
                    'expectedAt' => $visitor->expected_at?->toIso8601String(),
                    'passId' => $pass->pass_id,
                    'passStatus' => $pass->status->value,
                ],
                'guestPassUrl' => $visitor->guestPassUrl(),
                'shareToken' => $visitor->share_token,
            ], 201);
        }

        return back()
            ->with('success', $successMsg)
            ->with('guest_pass_url', $visitor->guestPassUrl())
            ->with('share_token', $visitor->share_token);
    }

    /**
     * Bulk register attendees for private gatherings, events, or contractors.
     */
    public function storeBulk(StoreBulkVisitorsRequest $request, RegisterBulkVisitorsAction $action): RedirectResponse
    {
        $result = $action->execute($request->validated(), $request->user());

        return back()->with('success', $result['message']);
    }

    public function update(UpdateVisitorRequest $request, Visitor $visitor, UpdateVisitorAction $action): RedirectResponse
    {
        $action->execute($visitor, $request->validated(), $request->user());

        return back()->with('success', 'Visitor updated.');
    }

    public function checkIn(CheckInVisitorRequest $request, Visitor $visitor, CheckInVisitorAction $action): RedirectResponse
    {
        try {
            $action->execute($visitor, $request->user(), $this->gateFrom($request), $request->string('gate')->toString() ?: 'Main Gate');
        } catch (ScanNotConfirmable $e) {
            return back()->withErrors(['visitor' => "Not checked in: {$e->getMessage()}"]);
        }

        return back()->with('success', "{$visitor->name} checked in.");
    }

    public function checkOut(CheckOutVisitorRequest $request, Visitor $visitor): RedirectResponse
    {
        if ($visitor->gatePass) {
            try {
                $this->scanner->manualCheckOut($visitor->gatePass, $request->user(), $this->gateFrom($request));
            } catch (ScanNotConfirmable $e) {
                return back()->withErrors(['visitor' => "Not checked out: {$e->getMessage()}"]);
            }

            return back()->with('success', "{$visitor->name} checked out.");
        }

        $visitor->checkOut();

        return back()->with('success', "{$visitor->name} checked out.");
    }

    public function destroy(Request $request, Visitor $visitor): RedirectResponse
    {
        $this->authorize('delete', $visitor);

        $user = $request->user();
        if ($user->role === UserRole::TemporaryHomeowner) {
            $stay = $user->activeStay() ?? Renter::where('user_id', $user->id)->first();
            if (! $stay || $stay->leaseHasExpired()) {
                return back()->withErrors([
                    'visitor' => 'Your temporary stay has expired. You cannot modify visitor entries.',
                ]);
            }
        }

        $visitor->delete();

        return back()->with('success', 'Visitor removed.');
    }

    /**
     * Dispatch or re-send guest pass via SMS, WhatsApp, or Email.
     */
    public function resendPass(ResendVisitorPassRequest $request, Visitor $visitor): RedirectResponse
    {
        $channel = $request->validated('channel');
        $channels = $channel === 'all'
            ? $visitor->passNotificationChannels()
            : [$channel];

        $visitor->resendPassNotification(empty($channels) ? ['sms'] : $channels);

        return back()->with('success', 'Guest pass dispatched via '.strtoupper($channel).'.');
    }

    /**
     * Extend a visitor pass validity window.
     */
    public function extendPass(ExtendVisitorPassRequest $request, Visitor $visitor, ExtendVisitorPassAction $action): RedirectResponse
    {
        $hours = (int) $request->validated('hours');
        $action->execute($visitor, $hours);

        return back()->with('success', "Visitor pass validity successfully extended by {$hours} hours.");
    }

    private function isOnBlocklist(string $name): bool
    {
        return BlocklistEntry::blocks($name);
    }

    /** The page sends a gate id or a gate name; anything unknown is the main gate. */
    private function gateFrom(Request $request): GateId
    {
        $gate = $request->string('gate')->toString();

        return GateId::tryFrom($gate)
            ?? GateId::tryFrom((string) array_search($gate, config('gatepass.gates'), true))
            ?? GateId::Gate01;
    }
}
