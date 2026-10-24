<?php

namespace App\Services\Occupancy;

use App\Enums\VisitorStatus;
use App\Models\Property;
use App\Models\Visitor;

class OccupancyHierarchyBuilder
{
    public function __construct(
        protected readonly OccupancyCategoryResolver $categoryResolver,
        protected readonly OccupancyCensusQueryService $censusQueryService,
    ) {}

    /**
     * Dedicated Drill-down API endpoint: "Who's inside Unit 14?"
     * Returns structured roster by the 7 categories with IN / OUT / EXPECTED statuses.
     */
    public function getUnitDrilldown(string $unit): array
    {
        $normalized = $this->categoryResolver->normalizeUnit($unit) ?: $unit;
        $inside = $this->censusQueryService->getAllCurrentOccupants(unitFilter: $unit);
        $expected = $this->censusQueryService->getAllExpectedToday(unitFilter: $unit);

        // Also check any checked-out visitors today for complete context
        $checkedOutVisitors = Visitor::with('homeowner')
            ->where('status', VisitorStatus::CheckedOut)
            ->get()
            ->filter(function ($v) use ($normalized) {
                if ($v->homeowner?->lot) {
                    return strtolower((string) $this->categoryResolver->normalizeUnit($v->homeowner->lot)) === strtolower($normalized);
                }
                $prop = $v->homeowner_name ?: null;
                if ($prop && strtolower((string) $this->categoryResolver->normalizeUnit($prop)) === strtolower($normalized)) {
                    return true;
                }
                if ($prop && str_contains(strtolower($prop), strtolower($normalized))) {
                    return true;
                }

                return false;
            });

        $categories = [
            'residents',
            'long_term_guests',
            'short_term_guests',
            'visitors',
            'staff',
            'contractors',
            'legacy_contacts',
        ];

        $rosterByCategory = [];
        $insideSummary = [];
        $expectedSummary = [];

        foreach ($categories as $cat) {
            $catInside = $inside->where('categoryKey', $cat)->values()->map(function ($item) {
                $item['status'] = 'IN';
                $item['checkInStatus'] = 'INSIDE';

                return $item;
            })->all();

            $catExpected = $expected->where('categoryKey', $cat)->values()->map(function ($item) {
                $item['status'] = 'EXPECTED';
                $item['checkInStatus'] = 'EXPECTED';

                return $item;
            })->all();

            $items = array_merge($catInside, $catExpected);

            // If category is visitors, append checked-out visitors as 'OUT'
            if ($cat === 'visitors') {
                foreach ($checkedOutVisitors as $co) {
                    $items[] = [
                        'id' => 'co_vis_'.$co->id,
                        'name' => $co->name,
                        'categoryKey' => 'visitors',
                        'categoryLabel' => 'Visitors',
                        'role' => 'Visitor',
                        'property' => $normalized,
                        'unit' => $normalized,
                        'status' => 'OUT',
                        'checkInStatus' => 'OUT',
                        'authorizedBy' => $co->homeowner_name ?? $co->homeowner?->name ?? 'Host',
                        'accessType' => 'Visitor Pass',
                        'qrStatus' => 'Checked Out',
                        'checkInTime' => $co->checked_in_at?->format('g:i A') ?? 'Earlier Today',
                        'gate' => 'Main Gate',
                        'expiration' => 'Expired',
                        'relationshipChain' => $this->categoryResolver->buildRelationshipChain([
                            'name' => $co->name,
                            'categoryKey' => 'visitors',
                            'property' => $normalized,
                            'host' => $co->homeowner_name ?? $co->homeowner?->name,
                        ]),
                    ];
                }
            }

            $rosterByCategory[$cat] = $items;
            $insideSummary[$cat] = count($catInside);
            $expectedSummary[$cat] = count($catExpected);
        }

        $insideSummary['total'] = $inside->count();
        $expectedSummary['total'] = $expected->count();

        preg_match('/(\d+)/', $normalized, $m);
        $lotNumber = $m[1] ?? '0';
        $prop = Property::where('lot_number', $lotNumber)
            ->orWhere('lot_number', $normalized)
            ->orWhere('lot_number', "Unit {$lotNumber}")
            ->first();

        return [
            'unit' => $normalized,
            'requestedQuery' => $unit,
            'summary' => $insideSummary,
            'expectedSummary' => $expectedSummary,
            'total' => $inside->count(),
            'totalCurrentlyInside' => $inside->count(),
            'totalExpectedToday' => $expected->count(),
            'occupants' => $inside->values()->all(),
            'expected' => $expected->values()->all(),
            'rosterByCategory' => $rosterByCategory,
            'ownership' => $prop ? $prop->ownershipDetails() : null,
            'propertyEcosystem' => $prop ? $prop->propertyEcosystemTree() : null,
        ];
    }

    /**
     * Community Map & Spatial Hierarchy Tree.
     * If $scopedUnit is provided (non-staff user), ONLY their single authorized unit is returned.
     */
    public function getCommunityHierarchy(?string $scopedUnit = null): array
    {
        $allInside = $this->censusQueryService->getAllCurrentOccupants($scopedUnit);
        $allExpected = $this->censusQueryService->getAllExpectedToday($scopedUnit);

        $distinctUnits = $allInside->pluck('unit')->merge($allExpected->pluck('unit'))->unique()->filter()->values();

        if ($scopedUnit) {
            $norm = $this->categoryResolver->normalizeUnit($scopedUnit) ?: $scopedUnit;
            $distinctUnits = collect([$norm]);
        }

        $addresses = Property::query()
            ->get(['lot_number', 'street_address'])
            ->mapWithKeys(fn (Property $p) => [$this->categoryResolver->normalizeUnit($p->lot_number) => $p->label()]);

        $propertyNodes = [];

        foreach ($distinctUnits as $unitName) {
            $insideForUnit = $allInside->where('unit', $unitName)->values();
            $expectedForUnit = $allExpected->where('unit', $unitName)->values();

            $insideCounts = [
                'residents' => $insideForUnit->where('categoryKey', 'residents')->count(),
                'long_term_guests' => $insideForUnit->where('categoryKey', 'long_term_guests')->count(),
                'short_term_guests' => $insideForUnit->where('categoryKey', 'short_term_guests')->count(),
                'visitors' => $insideForUnit->where('categoryKey', 'visitors')->count(),
                'staff' => $insideForUnit->where('categoryKey', 'staff')->count(),
                'contractors' => $insideForUnit->where('categoryKey', 'contractors')->count(),
                'legacy_contacts' => $insideForUnit->where('categoryKey', 'legacy_contacts')->count(),
            ];

            $expectedCounts = [
                'residents' => $expectedForUnit->where('categoryKey', 'residents')->count(),
                'long_term_guests' => $expectedForUnit->where('categoryKey', 'long_term_guests')->count(),
                'short_term_guests' => $expectedForUnit->where('categoryKey', 'short_term_guests')->count(),
                'visitors' => $expectedForUnit->where('categoryKey', 'visitors')->count(),
                'staff' => $expectedForUnit->where('categoryKey', 'staff')->count(),
                'contractors' => $expectedForUnit->where('categoryKey', 'contractors')->count(),
                'legacy_contacts' => $expectedForUnit->where('categoryKey', 'legacy_contacts')->count(),
            ];

            $breakdown = [];
            foreach ($insideCounts as $k => $cnt) {
                if ($cnt > 0 || ($expectedCounts[$k] ?? 0) > 0) {
                    $shortLabel = match ($k) {
                        'residents' => ($cnt === 1 ? 'Resident' : 'Residents'),
                        'long_term_guests' => ($cnt === 1 ? 'Renter' : 'Renters'),
                        'short_term_guests' => ($cnt === 1 ? 'Short-Term Guest' : 'Short-Term Guests'),
                        'visitors' => ($cnt === 1 ? 'Visitor' : 'Visitors'),
                        'staff' => 'Staff',
                        'contractors' => ($cnt === 1 ? 'Contractor' : 'Contractors'),
                        'legacy_contacts' => ($cnt === 1 ? 'Legacy Contact' : 'Legacy Contacts'),
                        default => $this->categoryResolver->categoryLabel($k),
                    };

                    $breakdown[] = [
                        'key' => $k,
                        'label' => $this->categoryResolver->categoryLabel($k),
                        'shortLabel' => $shortLabel,
                        'branchLabel' => "{$cnt} {$shortLabel}",
                        'inside' => $cnt,
                        'expected' => $expectedCounts[$k] ?? 0,
                    ];
                }
            }

            preg_match('/(\d+)/', $unitName, $m);
            $lotNumber = $m[1] ?? '0';

            $prop = Property::where('lot_number', $lotNumber)
                ->orWhere('lot_number', $unitName)
                ->orWhere('lot_number', "Unit {$lotNumber}")
                ->first();
            $ownerName = $prop ? $prop->ownerName() : 'Unassigned';
            $ownershipType = $prop ? $prop->ownershipType() : 'Individual';
            $representatives = $prop ? $prop->ownershipDetails()['representatives'] : [];

            $propertyNodes[] = [
                'id' => 'prop_'.strtolower(str_replace(' ', '_', $unitName)),
                'unit' => $unitName,
                'lot' => $lotNumber,
                'address' => $addresses[$unitName] ?? $unitName,
                'owner' => $ownerName,
                'ownershipType' => $ownershipType,
                'isCorporate' => $prop?->ownershipEntity?->entity_type?->value === 'company',
                'primaryOccupant' => $prop ? $prop->occupantName() : null,
                'isOwnerOccupied' => $prop ? ($prop->owner_user_id !== null && $prop->primary_occupant_id === $prop->owner_user_id) : false,
                'ecosystem' => $prop ? $prop->propertyEcosystemTree() : null,
                'representatives' => $representatives,
                'insideCount' => $insideForUnit->count(),
                'expectedCount' => $expectedForUnit->count() + $insideForUnit->count(),
                'categoriesInside' => $insideCounts,
                'categoriesExpected' => $expectedCounts,
                'breakdown' => $breakdown,
                'occupants' => $insideForUnit->take(5)->map(fn ($o) => [
                    'name' => $o['name'],
                    'category' => $o['categoryLabel'],
                    'status' => 'IN',
                ])->all(),
            ];
        }

        usort($propertyNodes, fn ($a, $b) => $b['insideCount'] <=> $a['insideCount']);

        return [
            'communityName' => 'Community Access Command Center',
            'isScoped' => ! empty($scopedUnit),
            'scopedUnit' => $scopedUnit,
            'totalInside' => $allInside->count(),
            'totalExpected' => $allExpected->count(),
            'properties' => $propertyNodes,
        ];
    }
}
