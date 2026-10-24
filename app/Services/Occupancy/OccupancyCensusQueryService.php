<?php

namespace App\Services\Occupancy;

use App\Enums\GateId;
use App\Enums\PassStatus;
use App\Enums\UserRole;
use App\Enums\VisitorStatus;
use App\Models\GatePass;
use App\Models\Renter;
use App\Models\User;
use App\Models\Visitor;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class OccupancyCensusQueryService
{
    public const ESTATE_OPERATIONS = 'Estate Operations';

    public function __construct(
        protected readonly OccupancyCategoryResolver $categoryResolver,
    ) {}

    /**
     * Get all occupants CURRENTLY INSIDE the community with rich details.
     * Everyone checked in and not checked out. A transient check-in older than
     * config('occupancy.stale_after_hours') is marked `stale` and left out
     * unless $includeStale — see staleCheckIns() and the muster roll call.
     */
    public function getAllCurrentOccupants(?string $unitFilter = null, ?string $categoryFilter = null, ?string $search = null, bool $includeStale = false): Collection
    {
        $records = collect();
        $seenPassIds = [];
        $seenVisitorIds = [];
        $seenUserIds = [];

        // 1. GatePass records that are CheckedIn
        $passes = GatePass::with(['user.properties', 'visitor', 'delegatedAccess'])
            ->where('status', PassStatus::CheckedIn)
            ->get();

        foreach ($passes as $pass) {
            $catKey = $this->categoryResolver->resolveCategory($pass);
            $property = $pass->property ?: ($pass->user?->lot ?: $pass->visitor?->homeowner?->lot);
            $normalizedUnit = $this->categoryResolver->normalizeUnit($property) ?: ($pass->user?->role->isResident() && ! $pass->visitor_id ? 'Unassigned' : 'Common Grounds');
            if ($this->categoryResolver->isEstatePersonnel($pass)) {
                $property = $normalizedUnit = self::ESTATE_OPERATIONS;
            }

            $hostName = $pass->metadata['host_name'] ?? $pass->metadata['authorized_by'] ?? $pass->metadata['grantor_name'] ?? ($pass->visitor?->homeowner_name ?? $pass->visitor?->homeowner?->name);

            $seenPassIds[] = $pass->id;
            if ($pass->visitor_id) {
                $seenVisitorIds[] = $pass->visitor_id;
            }
            if ($pass->user_id) {
                $seenUserIds[] = $pass->user_id;
            }

            $accessType = $pass->metadata['access_type'] ?? match ($catKey) {
                'legacy_contacts' => ($pass->metadata['access_level'] ?? 'Emergency'),
                'short_term_guests' => 'Airbnb Guest',
                'long_term_guests' => 'Long-Term Lease',
                'contractors' => 'Contractor Pass',
                'staff' => 'Staff Clearance',
                default => 'Visitor Pass',
            };

            $expiration = $pass->valid_until ? $pass->valid_until->format('M j, Y') : ($pass->metadata['expiration'] ?? 'No end date');

            $role = match ($catKey) {
                'legacy_contacts' => ($pass->metadata['role'] ?? $pass->metadata['relationship'] ?? 'Legacy Contact'),
                default => ($pass->metadata['role'] ?? $pass->metadata['access_level'] ?? $pass->metadata['relationship'] ?? $this->categoryResolver->categoryLabel($catKey)),
            };

            $record = [
                'id' => 'pass_'.$pass->id,
                'type' => 'gate_pass',
                'passId' => $pass->pass_id,
                'name' => $pass->holder_name ?: ($pass->user?->name ?? $pass->visitor?->name ?? 'Occupant'),
                'categoryKey' => $catKey,
                'categoryLabel' => $this->categoryResolver->categoryLabel($catKey),
                'role' => $role,
                'property' => $property ?: $normalizedUnit,
                'unit' => $normalizedUnit,
                'authorizedFor' => $normalizedUnit,
                'host' => $hostName,
                'authorizedBy' => $hostName ?: 'Property Owner',
                'accessType' => $accessType,
                'qrStatus' => 'Active',
                'checkedInAt' => $pass->checked_in_at?->toIso8601String() ?? $pass->updated_at->toIso8601String(),
                'checkedInTime' => $pass->checked_in_at?->format('g:i A') ?? $pass->updated_at->format('g:i A'),
                'checkedInDuration' => $pass->checked_in_at ? $pass->checked_in_at->diffForHumans(['parts' => 2, 'short' => true]) : 'Active',
                'gate' => ($pass->designated_gate === GateId::Gate01 || $pass->designated_gate?->value === 'GATE-01') ? 'Main Gate' : ($pass->metadata['gate'] ?? 'Main Gate'),
                'vehicle' => $pass->metadata['vehicle'] ?? $pass->visitor?->vehicle ?? null,
                'contact' => $pass->metadata['contact'] ?? $pass->user?->phone ?? $pass->visitor?->contact ?? null,
                'avatarUrl' => $pass->user?->avatar_url ?? null,
                'status' => 'Checked In',
                'checkInStatus' => 'INSIDE',
                'expiration' => $expiration,
            ];

            $record['relationshipChain'] = $this->categoryResolver->buildRelationshipChain($record);
            $records->push($record);
        }

        // 2. Visitors with status 'Checked In' not already captured by pass
        $visitors = Visitor::with('homeowner')
            ->where('status', VisitorStatus::CheckedIn)
            ->whereNotIn('id', $seenVisitorIds)
            ->get();

        foreach ($visitors as $v) {
            $catKey = 'visitors';
            $property = $v->homeowner?->lot ?: null;
            $normalizedUnit = $this->categoryResolver->normalizeUnit($property) ?: 'Common Grounds';
            $host = $v->homeowner_name ?? $v->homeowner?->name;

            $record = [
                'id' => 'visitor_'.$v->id,
                'type' => 'visitor',
                'passId' => sprintf('VIS-%04d', $v->id),
                'name' => $v->name,
                'categoryKey' => $catKey,
                'categoryLabel' => 'Visitors',
                'role' => 'Visitor',
                'property' => $property ?: $normalizedUnit,
                'unit' => $normalizedUnit,
                'authorizedFor' => $normalizedUnit,
                'host' => $host,
                'authorizedBy' => $host ?: 'Homeowner',
                'accessType' => 'Guest Pass',
                'qrStatus' => 'Active',
                'checkedInAt' => $v->checked_in_at?->toIso8601String() ?? $v->updated_at->toIso8601String(),
                'checkedInTime' => $v->checked_in_at?->format('g:i A') ?? $v->updated_at->format('g:i A'),
                'checkedInDuration' => $v->checked_in_at ? $v->checked_in_at->diffForHumans(['parts' => 2, 'short' => true]) : 'Active',
                'gate' => 'Main Gate',
                'vehicle' => $v->vehicle,
                'contact' => $v->contact,
                'avatarUrl' => null,
                'status' => 'Checked In',
                'checkInStatus' => 'INSIDE',
                'expiration' => now()->endOfDay()->format('M j, Y'),
            ];

            $record['relationshipChain'] = $this->categoryResolver->buildRelationshipChain($record);
            $records->push($record);
        }

        // 3. Resident users who have active gate pass or resident on-site record
        $residentUsers = User::query()
            ->whereIn('role', [UserRole::Homeowner, UserRole::TemporaryHomeowner])
            ->where('status', 'Active')
            ->whereNotIn('id', $seenUserIds)
            ->whereHas('gatePasses', function ($q) {
                $q->where('status', PassStatus::CheckedIn);
            })
            ->get();

        foreach ($residentUsers as $resUser) {
            $pass = $resUser->gatePasses()->whereIn('status', [PassStatus::CheckedIn, PassStatus::Active])->first();
            $property = $resUser->lot ?: 'Unassigned';
            $normalizedUnit = $resUser->lot ? $this->categoryResolver->normalizeUnit($resUser->lot) : 'Unassigned';
            $isRenter = $resUser->role === UserRole::TemporaryHomeowner;
            $catKey = $isRenter ? 'long_term_guests' : 'residents';

            $record = [
                'id' => 'user_'.$resUser->id,
                'type' => 'resident',
                'passId' => $pass?->pass_id ?? sprintf('RES-%04d', $resUser->id),
                'name' => $resUser->name,
                'categoryKey' => $catKey,
                'categoryLabel' => $this->categoryResolver->categoryLabel($catKey),
                'role' => $isRenter ? 'Renter' : 'Homeowner',
                'property' => $property,
                'unit' => $normalizedUnit,
                'authorizedFor' => $normalizedUnit,
                'host' => $resUser->name,
                'authorizedBy' => $resUser->name,
                'accessType' => $isRenter ? 'Residential Lease' : 'Homeowner Resident',
                'qrStatus' => 'Active',
                'checkedInAt' => $pass?->checked_in_at?->toIso8601String() ?? $resUser->created_at->toIso8601String(),
                'checkedInTime' => $pass?->checked_in_at?->format('g:i A') ?? 'On-Site',
                'checkedInDuration' => 'Permanent Resident',
                'gate' => 'Residents Lane',
                'vehicle' => null,
                'contact' => $resUser->phone ?? $resUser->email,
                'avatarUrl' => $resUser->avatar_url,
                'status' => 'On-Site',
                'checkInStatus' => 'INSIDE',
                'expiration' => 'Permanent',
            ];

            $record['relationshipChain'] = $this->categoryResolver->buildRelationshipChain($record);
            $records->push($record);
        }

        $records = $records->map(fn (array $item) => $item + ['stale' => $this->categoryResolver->isStaleCheckIn($item)]);
        if (! $includeStale) {
            $records = $records->reject(fn (array $item) => $item['stale']);
        }

        // Filter by unit if requested
        if (! empty($unitFilter)) {
            $wanted = strtolower((string) $this->categoryResolver->normalizeUnit($unitFilter));

            $records = $records->filter(fn ($item) => in_array($wanted, [
                strtolower((string) $this->categoryResolver->normalizeUnit($item['unit'] ?? null)),
                strtolower((string) $this->categoryResolver->normalizeUnit($item['property'] ?? null)),
            ], true));
        }

        // Filter by category if requested
        if (! empty($categoryFilter) && $categoryFilter !== 'all') {
            $records = $records->where('categoryKey', $categoryFilter);
        }

        // Filter by search string if requested
        if (! empty($search)) {
            $term = strtolower(trim($search));
            $records = $records->filter(function ($item) use ($term) {
                return str_contains(strtolower($item['name'] ?? ''), $term) ||
                    str_contains(strtolower($item['passId'] ?? ''), $term) ||
                    str_contains(strtolower($item['property'] ?? ''), $term) ||
                    str_contains(strtolower($item['unit'] ?? ''), $term) ||
                    str_contains(strtolower($item['vehicle'] ?? ''), $term) ||
                    str_contains(strtolower($item['contact'] ?? ''), $term) ||
                    str_contains(strtolower($item['host'] ?? ''), $term);
            });
        }

        return $records->values();
    }

    /**
     * Get all people EXPECTED TODAY who have valid credentials but have not checked in yet,
     * or are scheduled for arrival today.
     */
    public function getAllExpectedToday(?string $unitFilter = null, ?string $categoryFilter = null, ?string $search = null): Collection
    {
        $records = collect();

        // 1. Visitors scheduled or expected today with status 'Expected'
        $expectedVisitors = Visitor::with('homeowner')
            ->whereIn('status', [VisitorStatus::Expected])
            ->get();

        foreach ($expectedVisitors as $v) {
            $catKey = 'visitors';
            $property = $v->homeowner?->lot ?: null;
            $normalizedUnit = $this->categoryResolver->normalizeUnit($property) ?: 'Common Grounds';
            $host = $v->homeowner_name ?? $v->homeowner?->name;

            $record = [
                'id' => 'exp_vis_'.$v->id,
                'type' => 'expected_visitor',
                'passId' => sprintf('VIS-%04d', $v->id),
                'name' => $v->name,
                'categoryKey' => $catKey,
                'categoryLabel' => 'Visitors',
                'role' => 'Scheduled Visitor',
                'property' => $property ?: $normalizedUnit,
                'unit' => $normalizedUnit,
                'host' => $host,
                'authorizedBy' => $host ?: 'Homeowner',
                'accessType' => 'Expected Guest Pass',
                'qrStatus' => 'Scheduled',
                'checkedInAt' => null,
                'checkedInTime' => 'Expected Today',
                'checkedInDuration' => 'Pending Entry',
                'gate' => 'Main Gate',
                'vehicle' => $v->vehicle,
                'contact' => $v->contact,
                'avatarUrl' => null,
                'status' => 'Expected Today',
                'checkInStatus' => 'EXPECTED',
                'expiration' => now()->endOfDay()->format('M j, Y'),
            ];

            $record['relationshipChain'] = $this->categoryResolver->buildRelationshipChain($record);
            $records->push($record);
        }

        // 2. Active gate passes not currently checked in
        $activePasses = GatePass::with(['user.properties', 'visitor', 'delegatedAccess'])
            ->whereIn('status', [PassStatus::Active, PassStatus::Issued, PassStatus::Approved])
            ->get();

        foreach ($activePasses as $pass) {
            $catKey = $this->categoryResolver->resolveCategory($pass);
            $property = $pass->property ?: ($pass->user?->lot ?: $pass->visitor?->homeowner?->lot);
            $normalizedUnit = $this->categoryResolver->normalizeUnit($property) ?: ($pass->user?->role->isResident() && ! $pass->visitor_id ? 'Unassigned' : 'Common Grounds');
            if ($this->categoryResolver->isEstatePersonnel($pass)) {
                $property = $normalizedUnit = self::ESTATE_OPERATIONS;
            }

            $hostName = $pass->metadata['host_name'] ?? $pass->metadata['authorized_by'] ?? ($pass->visitor?->homeowner_name ?? $pass->visitor?->homeowner?->name);

            $record = [
                'id' => 'exp_pass_'.$pass->id,
                'type' => 'expected_pass',
                'passId' => $pass->pass_id,
                'name' => $pass->holder_name ?: ($pass->user?->name ?? $pass->visitor?->name ?? 'Expected Occupant'),
                'categoryKey' => $catKey,
                'categoryLabel' => $this->categoryResolver->categoryLabel($catKey),
                'role' => $pass->metadata['access_level'] ?? $pass->metadata['relationship'] ?? $this->categoryResolver->categoryLabel($catKey),
                'property' => $property ?: $normalizedUnit,
                'unit' => $normalizedUnit,
                'host' => $hostName,
                'authorizedBy' => $hostName ?: 'Property Owner',
                'accessType' => $pass->metadata['access_level'] ?? 'Credential Clearance',
                'qrStatus' => 'Active',
                'checkedInAt' => null,
                'checkedInTime' => 'Not Checked In',
                'checkedInDuration' => 'Valid Today',
                'gate' => $pass->designated_gate?->value ?? 'Main Gate',
                'vehicle' => $pass->metadata['vehicle'] ?? null,
                'contact' => $pass->metadata['contact'] ?? null,
                'avatarUrl' => null,
                'status' => 'Expected Today',
                'checkInStatus' => 'EXPECTED',
                'expiration' => $pass->valid_until ? $pass->valid_until->format('M j, Y') : 'Valid Today',
            ];

            $record['relationshipChain'] = $this->categoryResolver->buildRelationshipChain($record);
            $records->push($record);
        }

        // 3. Active Renters (Lease or Short-Term) not already checked in
        $activeRenters = Renter::where('lease_end', '>=', now())
            ->where('lease_start', '<=', now())
            ->with('homeowner')
            ->get();

        foreach ($activeRenters as $r) {
            $catKey = $this->categoryResolver->resolveCategory($r);
            $lot = $r->lot ?: $r->homeowner?->lot;
            $normalizedUnit = $this->categoryResolver->normalizeUnit($lot) ?: 'Common Grounds';
            $property = $lot ? $normalizedUnit : 'Unassigned';

            $record = [
                'id' => 'exp_renter_'.$r->id,
                'type' => 'expected_renter',
                'passId' => sprintf('RNT-%04d', $r->id),
                'name' => $r->name,
                'categoryKey' => $catKey,
                'categoryLabel' => $this->categoryResolver->categoryLabel($catKey),
                'role' => $catKey === 'short_term_guests' ? 'Airbnb Guest' : 'Lease Tenant',
                'property' => $property,
                'unit' => $normalizedUnit,
                'host' => 'Property Owner',
                'authorizedBy' => 'Property Owner',
                'accessType' => $catKey === 'short_term_guests' ? 'Airbnb Reservation' : 'Active Lease',
                'qrStatus' => 'Active',
                'checkedInAt' => null,
                'checkedInTime' => 'Expected Today',
                'checkedInDuration' => 'Lease Current',
                'gate' => 'Main Gate',
                'vehicle' => null,
                'contact' => $r->contact,
                'avatarUrl' => null,
                'status' => 'Expected Today',
                'checkInStatus' => 'EXPECTED',
                'expiration' => $r->lease_end ? Carbon::parse($r->lease_end)->format('M j, Y') : 'Current Lease',
            ];

            $record['relationshipChain'] = $this->categoryResolver->buildRelationshipChain($record);
            $records->push($record);
        }

        // Filter by unit if requested
        if (! empty($unitFilter)) {
            $wanted = strtolower((string) $this->categoryResolver->normalizeUnit($unitFilter));

            $records = $records->filter(fn ($item) => in_array($wanted, [
                strtolower((string) $this->categoryResolver->normalizeUnit($item['unit'] ?? null)),
                strtolower((string) $this->categoryResolver->normalizeUnit($item['property'] ?? null)),
            ], true));
        }

        // Filter by category if requested
        if (! empty($categoryFilter) && $categoryFilter !== 'all') {
            $records = $records->where('categoryKey', $categoryFilter);
        }

        // Filter by search string if requested
        if (! empty($search)) {
            $term = strtolower(trim($search));
            $records = $records->filter(function ($item) use ($term) {
                return str_contains(strtolower($item['name'] ?? ''), $term) ||
                    str_contains(strtolower($item['passId'] ?? ''), $term) ||
                    str_contains(strtolower($item['property'] ?? ''), $term) ||
                    str_contains(strtolower($item['unit'] ?? ''), $term);
            });
        }

        return $records->values();
    }
}
