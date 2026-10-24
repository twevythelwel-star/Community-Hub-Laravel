<?php

namespace App\Services\Occupancy;

use Illuminate\Support\Collection;

class OccupancyMetricsService
{
    public function __construct(
        protected readonly OccupancyCensusQueryService $censusQueryService,
    ) {}

    public function getSummary(?string $unit = null): array
    {
        $occupants = $this->censusQueryService->getAllCurrentOccupants($unit);

        $counts = [
            'residents' => 0,
            'long_term_guests' => 0,
            'short_term_guests' => 0,
            'visitors' => 0,
            'staff' => 0,
            'contractors' => 0,
            'legacy_contacts' => 0,
        ];

        foreach ($occupants as $item) {
            $cat = $item['categoryKey'];
            if (isset($counts[$cat])) {
                $counts[$cat]++;
            }
        }

        $counts['total'] = array_sum($counts);

        return $counts;
    }

    public function getExpectedTodaySummary(?string $unit = null): array
    {
        $expected = $this->censusQueryService->getAllExpectedToday($unit);

        $counts = [
            'residents' => 0,
            'long_term_guests' => 0,
            'short_term_guests' => 0,
            'visitors' => 0,
            'staff' => 0,
            'contractors' => 0,
            'legacy_contacts' => 0,
        ];

        foreach ($expected as $item) {
            $cat = $item['categoryKey'];
            if (isset($counts[$cat])) {
                $counts[$cat]++;
            }
        }

        $counts['total'] = array_sum($counts);

        return $counts;
    }

    public function getDualSummary(?string $unit = null): array
    {
        return [
            'inside' => $this->getSummary($unit),
            'expected' => $this->getExpectedTodaySummary($unit),
        ];
    }

    public function getUnitsSummary(): array
    {
        $allInside = $this->censusQueryService->getAllCurrentOccupants();
        $allExpected = $this->censusQueryService->getAllExpectedToday();

        $allUnits = $allInside->pluck('unit')->merge($allExpected->pluck('unit'))->unique()->filter()->values();

        $result = [];
        foreach ($allUnits as $unitName) {
            $insideForUnit = $allInside->where('unit', $unitName);
            $expectedForUnit = $allExpected->where('unit', $unitName);

            $unitCounts = [
                'unit' => $unitName,
                'total' => $insideForUnit->count(),
                'currentlyInside' => $insideForUnit->count(),
                'expectedToday' => $expectedForUnit->count(),
                'residents' => $insideForUnit->where('categoryKey', 'residents')->count(),
                'long_term_guests' => $insideForUnit->where('categoryKey', 'long_term_guests')->count(),
                'short_term_guests' => $insideForUnit->where('categoryKey', 'short_term_guests')->count(),
                'visitors' => $insideForUnit->where('categoryKey', 'visitors')->count(),
                'staff' => $insideForUnit->where('categoryKey', 'staff')->count(),
                'contractors' => $insideForUnit->where('categoryKey', 'contractors')->count(),
                'legacy_contacts' => $insideForUnit->where('categoryKey', 'legacy_contacts')->count(),
            ];
            $result[] = $unitCounts;
        }

        usort($result, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $result;
    }

    public function getEnclosureZonesSummary(): array
    {
        $all = $this->censusQueryService->getAllCurrentOccupants();

        $perimeterCount = $all->count();
        $residentialCount = $all->filter(function ($o) {
            $unitLower = strtolower($o['unit'] ?? '');

            return in_array($o['categoryKey'], ['residents', 'long_term_guests', 'short_term_guests'], true) ||
                (! str_contains($unitLower, 'common') && ! str_contains($unitLower, 'gate') && ! str_contains($unitLower, 'park'));
        })->count();

        $amenityCount = $all->filter(function ($o) {
            $unitLower = strtolower($o['unit'] ?? '');

            return str_contains($unitLower, 'clubhouse') ||
                str_contains($unitLower, 'pool') ||
                str_contains($unitLower, 'park') ||
                str_contains($unitLower, 'common') ||
                $o['categoryKey'] === 'visitors';
        })->count();

        $contractorCount = $all->where('categoryKey', 'contractors')->count();
        $staffCount = $all->where('categoryKey', 'staff')->count();

        return [
            [
                'id' => 'outer_perimeter',
                'name' => 'Outer Perimeter Boundary Enclosure',
                'type' => 'Primary Boundary',
                'description' => 'Main Cadastral Security Fence Line & Automated Gates',
                'icon' => 'Shield',
                'status' => 'Fully Secured',
                'badge' => 'Active Containment',
                'occupant_count' => $perimeterCount,
            ],
            [
                'id' => 'residential_enclosures',
                'name' => 'Residential Parcel Enclosures',
                'type' => 'Private Compounds',
                'description' => 'Gated Villas, Townhomes & Private Lots (e.g. Unit 14)',
                'icon' => 'Home',
                'status' => 'Monitored',
                'badge' => 'Access Restricted',
                'occupant_count' => $residentialCount,
            ],
            [
                'id' => 'amenity_enclosure',
                'name' => 'Clubhouse & Amenity Enclosure',
                'type' => 'Recreational Zone',
                'description' => 'Swimming Pool, Gymnasium, Central Park & Pavilion',
                'icon' => 'Waves',
                'status' => 'Operational',
                'badge' => 'Keycard Access',
                'occupant_count' => $amenityCount,
            ],
            [
                'id' => 'contractor_enclosure',
                'name' => 'Trade & Construction Enclosure',
                'type' => 'Industrial Zone',
                'description' => 'Designated Worksites, Utility Compound & Maintenance Bays',
                'icon' => 'HardHat',
                'status' => 'Permit Required',
                'badge' => 'Authorized Hours',
                'occupant_count' => $contractorCount,
            ],
            [
                'id' => 'muster_enclosure',
                'name' => 'Emergency Assembly Muster Enclosure',
                'type' => 'Safety Zone',
                'description' => 'Central Park East Muster Field (Gate 01 Ingress)',
                'icon' => 'Flame',
                'status' => 'Cleared & Standby',
                'badge' => 'First Responders',
                'occupant_count' => 0,
            ],
        ];
    }

    public function staleCheckIns(?string $unitFilter = null): Collection
    {
        return $this->censusQueryService->getAllCurrentOccupants(unitFilter: $unitFilter, includeStale: true)
            ->where('stale', true)
            ->values();
    }
}
