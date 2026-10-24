<?php

namespace App\Repositories;

use App\Enums\PassStatus;
use App\Enums\VisitorStatus;
use App\Models\AccessLogEntry;
use App\Models\GatePass;
use App\Models\User;
use App\Models\Visitor;
use App\Services\GatePassEngine;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class VisitorRepository
{
    public function __construct(
        protected GatePassEngine $engine
    ) {}

    /**
     * Compute security KPI counters for estate guards and residents.
     *
     * @return array<string, int>
     */
    public function getSecurityStats(User $user, bool $isStaff): array
    {
        $manageableProperties = $user->propertiesForVisitorManagement();
        $scopeForResidentOrRep = fn ($q) => $q->manageableBy($user, $manageableProperties->pluck('id'));

        $countsQuery = Visitor::query()->when(! $isStaff, $scopeForResidentOrRep);

        $todayQuery = (clone $countsQuery)->where(function ($q) {
            $q->whereDate('expected_at', today())
                ->orWhereDate('checked_in_at', today())
                ->orWhereDate('checked_out_at', today());
        });
        $visitorsTodayCount = (clone $todayQuery)->count();

        $expectedCount = (clone $countsQuery)
            ->where('status', VisitorStatus::Expected->value)
            ->whereNull('expired_at')
            ->count();

        $insideCount = (clone $countsQuery)
            ->where('status', VisitorStatus::CheckedIn->value)
            ->count();

        $checkedOutCount = (clone $countsQuery)
            ->where('status', VisitorStatus::CheckedOut->value)
            ->count();

        $rejectedCount = (clone $countsQuery)
            ->where(function ($q) {
                $q->where('is_blocked', true)->orWhereNotNull('expired_at');
            })
            ->count() + ($isStaff ? AccessLogEntry::where('result', 'DENY')->count() : 0);

        $suspiciousAttemptsCount = $isStaff ? AccessLogEntry::where('result', 'DENY')->where(function ($q) {
            $q->where('deny_reason', 'like', '%Replay%')
                ->orWhere('deny_reason', 'like', '%Signature%')
                ->orWhere('deny_reason', 'like', '%Forged%')
                ->orWhere('deny_reason', 'like', '%Blocklist%')
                ->orWhere('deny_reason', 'like', '%Revoked%')
                ->orWhere('deny_reason', 'like', '%NotAGpeGatePass%');
        })->count() : 0;

        return [
            'visitorsToday' => $visitorsTodayCount,
            'expected' => $expectedCount,
            'currentlyInside' => $insideCount,
            'checkedOut' => $checkedOutCount,
            'rejected' => $rejectedCount,
            'suspiciousAttempts' => $suspiciousAttemptsCount,
        ];
    }

    /**
     * Compute counters for tabs.
     *
     * @return array<string, int>
     */
    public function getTabCounts(User $user, bool $isStaff, array $securityStats): array
    {
        $manageableProperties = $user->propertiesForVisitorManagement();
        $scopeForResidentOrRep = fn ($q) => $q->manageableBy($user, $manageableProperties->pluck('id'));
        $countsQuery = Visitor::query()->when(! $isStaff, $scopeForResidentOrRep);

        return [
            'scan' => 0,
            'expected' => $securityStats['expected'],
            'inside' => $securityStats['currentlyInside'],
            'checkedOut' => $securityStats['checkedOut'],
            'rejected' => $securityStats['rejected'],
            'history' => (clone $countsQuery)->count(),
        ];
    }

    /**
     * Retrieve paginated visitor clearances for active sub-tab.
     */
    public function getPaginatedVisitors(Request $request, User $user, bool $isStaff, string $tab): LengthAwarePaginator
    {
        $manageableProperties = $user->propertiesForVisitorManagement();
        $scopeForResidentOrRep = fn ($q) => $q->manageableBy($user, $manageableProperties->pluck('id'));

        $query = Visitor::query()
            ->with(['homeowner:id,name,display_name,lot,street', 'gatePass'])
            ->when(! $isStaff, $scopeForResidentOrRep)
            ->when(
                $request->string('search')->isNotEmpty(),
                fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%')
            );

        switch ($tab) {
            case 'expected':
                $query->where('status', VisitorStatus::Expected->value)->whereNull('expired_at');
                break;
            case 'inside':
                $query->where('status', VisitorStatus::CheckedIn->value);
                break;
            case 'checked-out':
                $query->where('status', VisitorStatus::CheckedOut->value);
                break;
            case 'rejected':
                $query->where(function ($q) {
                    $q->where('is_blocked', true)->orWhereNotNull('expired_at');
                });
                break;
            case 'history':
                // All records unconstrained
                break;
            default:
                // 'all' or 'scan'
                if ($isStaff && $request->boolean('today', true)) {
                    $query->whereDate('expected_at', today());
                }
                if ($request->string('status')->isNotEmpty()) {
                    $query->where('status', $request->string('status'));
                }
                break;
        }

        return $query
            ->latest('expected_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Visitor $v) => $this->formatVisitorRow($v, $user));
    }

    /**
     * Format a single visitor row for front-end consumption.
     *
     * @return array<string, mixed>
     */
    public function formatVisitorRow(Visitor $v, User $viewer): array
    {
        $hostLot = null;
        if ($v->homeowner && filled($v->homeowner->lot)) {
            $hostLot = '#'.ltrim($v->homeowner->lot, '#');
        } elseif (preg_match('/Lot\s*(\d+)/i', $v->homeowner_name ?? '', $matches)) {
            $hostLot = '#'.$matches[1];
        } else {
            $hostLot = $v->homeowner_name ?: '#Unassigned';
        }

        $timeDisplay = match ($v->status) {
            VisitorStatus::CheckedIn => $v->checked_in_at ? $v->checked_in_at->format('g:i A') : $v->expected_at->format('g:i A'),
            VisitorStatus::CheckedOut => $v->checked_out_at ? $v->checked_out_at->format('g:i A') : $v->expected_at->format('g:i A'),
            default => $v->expected_at->format('g:i A'),
        };

        return [
            'id' => $v->id,
            'name' => $v->name,
            'contact' => $v->contact,
            'vehicle' => $v->vehicle,
            'idType' => $v->id_type,
            'type' => $v->type,
            'status' => $v->status->value,
            'expectedAt' => $v->expected_at->toIso8601String(),
            'dateRange' => $v->date_range,
            'homeowner' => $v->homeowner_name,
            'hostLot' => $hostLot,
            'timeDisplay' => $timeDisplay,
            'idImageUrl' => $v->id_image_url,
            'isBlocked' => $v->is_blocked,
            'expired' => $v->expired_at !== null,
            'checkedInAt' => $v->checked_in_at?->toIso8601String(),
            'checkedOutAt' => $v->checked_out_at?->toIso8601String(),
            'shareToken' => $v->share_token,
            'guestPassUrl' => $v->share_token ? route('guest-pass.show', $v->share_token) : null,
            'arrivalWindow' => $v->arrivalWindowLabel(),
            'arrivalWindowStart' => $v->arrival_window_start,
            'arrivalWindowEnd' => $v->arrival_window_end,
            'parkingInstructions' => $v->parkingInstructions(),
            'communityRules' => $v->communityRules(),
            'emergencyInfo' => $v->emergencyInfo(),
            'notes' => $v->notes,
            'notify_email' => $v->notify_email,
            'notify_sms' => $v->notify_sms,
            'notify_whatsapp' => $v->notify_whatsapp,
            'pass' => $v->gatePass ? $this->passPayload($v->gatePass, $viewer) : null,
        ];
    }

    /**
     * Format the pass payload with allowable lifecycle actions for the viewer.
     *
     * @return array<string, mixed>
     */
    public function passPayload(GatePass $pass, User $viewer): array
    {
        $isSecurity = $viewer->can('manageSecurity');
        $manual = [PassStatus::Approved, PassStatus::Rejected, PassStatus::Suspended, PassStatus::Active, PassStatus::Cancelled, PassStatus::Revoked];

        $actions = array_values(array_filter($manual, fn (PassStatus $to) => $pass->canTransitionTo($to)
            && ($isSecurity || ($to === PassStatus::Cancelled && ($pass->visitor?->homeowner_id === $viewer->id || ($pass->visitor?->property_id && $viewer->canManageVisitorsForProperty($pass->visitor->property_id)))))));

        return [
            'id' => $pass->id,
            'passId' => $pass->pass_id,
            'category' => $pass->category->value,
            'status' => $pass->status->value,
            'statusLabel' => $pass->status->label(),
            'validFrom' => $pass->valid_from?->toIso8601String(),
            'validUntil' => $pass->valid_until?->toIso8601String(),
            'singleEntry' => $pass->single_entry,
            'color' => $this->engine->variantFor($pass),
            'actions' => array_map(fn (PassStatus $s) => ['status' => $s->value, 'label' => match ($s) {
                PassStatus::Approved => 'Approve',
                PassStatus::Rejected => 'Reject',
                PassStatus::Suspended => 'Suspend',
                PassStatus::Active => 'Reinstate',
                PassStatus::Cancelled => 'Cancel pass',
                PassStatus::Revoked => 'Revoke',
                default => $s->label(),
            }], $actions),
        ];
    }
}
