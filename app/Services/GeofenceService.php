<?php

namespace App\Services;

/**
 * Geospatial helpers — port of src/lib/geofence-utils.ts.
 *
 * Coordinates are [lat, lng] pairs throughout, matching both the original
 * TypeScript and the Leaflet components that consume the output.
 */
class GeofenceService
{
    private const EARTH_RADIUS_M = 6371000;

    /** Metres per degree, used for the local equirectangular area projection. */
    private const M_PER_DEG_LNG = 111320;

    private const M_PER_DEG_LAT = 110574;

    /** Formats a decimal degree as degrees/minutes/seconds, e.g. 18°28'30.0"N. */
    public function toDms(float $deg, bool $isLat): string
    {
        $absolute = abs($deg);
        $degrees = (int) floor($absolute);
        $minutesNotTruncated = ($absolute - $degrees) * 60;
        $minutes = (int) floor($minutesNotTruncated);
        $seconds = number_format(($minutesNotTruncated - $minutes) * 60, 1);

        $direction = $isLat
            ? ($deg >= 0 ? 'N' : 'S')
            : ($deg >= 0 ? 'E' : 'W');

        return sprintf('%d°%d\'%s"%s', $degrees, $minutes, $seconds, $direction);
    }

    /**
     * Approximate elevation from the Montego Bay coastal ridge interpolation
     * used by the original estimateElevation(). Clamped to 15..180 m.
     */
    public function estimateElevation(float $lat, float $lng): int
    {
        $baseLat = 18.4750;
        $baseLng = -77.9270;

        $dLat = ($lat - $baseLat) * 111000;
        $dLng = ($lng - $baseLng) * 105000;

        $elevation = (int) round(38 + ($dLat * 0.08) + ($dLng * 0.04));

        return max(15, min(180, $elevation));
    }

    /**
     * Great-circle distance in metres between two [lat, lng] points.
     *
     * @param  array{0: float, 1: float}  $from
     * @param  array{0: float, 1: float}  $to
     */
    public function haversineDistance(array $from, array $to): int
    {
        $dLat = deg2rad($to[0] - $from[0]);
        $dLng = deg2rad($to[1] - $from[1]);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($from[0])) * cos(deg2rad($to[0])) * sin($dLng / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return (int) round(self::EARTH_RADIUS_M * $c);
    }

    /**
     * Ray-casting point-in-polygon test.
     *
     * @param  array{0: float, 1: float}  $point
     * @param  array<int, array{0: float, 1: float}>  $polygon
     */
    public function isPointInGeofence(array $point, array $polygon): bool
    {
        $count = count($polygon);
        if ($count < 3) {
            return false;
        }

        [$lat, $lng] = $point;
        $inside = false;

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            [$latI, $lngI] = $polygon[$i];
            [$latJ, $lngJ] = $polygon[$j];

            // Guard the division the original performed unguarded: a horizontal
            // edge (lngJ === lngI) cannot be crossed by the ray anyway, and in
            // PHP the division would raise a DivisionByZeroError rather than
            // silently yielding Infinity as it does in JavaScript.
            if (($lngI > $lng) !== ($lngJ > $lng)) {
                $denominator = $lngJ - $lngI;

                if ($denominator == 0.0) {
                    continue;
                }

                if ($lat < (($latJ - $latI) * ($lng - $lngI)) / $denominator + $latI) {
                    $inside = ! $inside;
                }
            }
        }

        return $inside;
    }

    /**
     * Where a point sits relative to the perimeter, plus the closest gate.
     *
     * @param  array{0: float, 1: float}  $point
     * @param  array<int, array{0: float, 1: float}>  $polygon
     * @return array{isInside: bool, distanceMeters: int, nearestVertexIndex: int, nearestGate: array}
     */
    public function perimeterInfo(array $point, array $polygon, array $gates = []): array
    {
        $isInside = $this->isPointInGeofence($point, $polygon);
        $minDistance = PHP_INT_MAX;
        $nearestVertexIndex = 0;

        foreach ($polygon as $index => $vertex) {
            $distance = $this->haversineDistance($point, $vertex);

            if ($distance < $minDistance) {
                $minDistance = $distance;
                $nearestVertexIndex = $index;
            }
        }

        $gates = $gates ?: [
            ['name' => 'Main Security Gate',          'coordinates' => [18.4750, -77.9257]],
            ['name' => 'North Perimeter Checkpoint',  'coordinates' => [18.4782, -77.9258]],
        ];

        $nearestGate = null;
        foreach ($gates as $gate) {
            $distance = $this->haversineDistance($point, $gate['coordinates']);

            if ($nearestGate === null || $distance < $nearestGate['distance']) {
                $nearestGate = [
                    'name' => $gate['name'],
                    'distance' => $distance,
                    'coordinates' => $gate['coordinates'],
                ];
            }
        }

        return [
            'isInside' => $isInside,
            'distanceMeters' => $minDistance === PHP_INT_MAX ? 0 : $minDistance,
            'nearestVertexIndex' => $nearestVertexIndex,
            'nearestGate' => $nearestGate,
        ];
    }

    /**
     * Centroid, area and perimeter for a polygon, using the shoelace formula on
     * a local equirectangular projection about the centroid.
     *
     * @param  array<int, array{0: float, 1: float}>  $polygon
     * @return array{center: array{0: float, 1: float}, areaAcres: string, areaHectares: string, areaSqMeters: float, perimeterMeters: int}
     */
    public function polygonMetrics(array $polygon): array
    {
        if ($polygon === []) {
            return [
                'center' => [18.4766, -77.9257],
                'areaAcres' => '0.00',
                'areaHectares' => '0.00',
                'areaSqMeters' => 0.0,
                'perimeterMeters' => 0,
            ];
        }

        $count = count($polygon);
        $sumLat = array_sum(array_column($polygon, 0));
        $sumLng = array_sum(array_column($polygon, 1));
        $center = [$sumLat / $count, $sumLng / $count];

        if ($count < 3) {
            return [
                'center' => $center,
                'areaAcres' => '0.00',
                'areaHectares' => '0.00',
                'areaSqMeters' => 0.0,
                'perimeterMeters' => 0,
            ];
        }

        $area = 0.0;
        $perimeter = 0;
        $latScale = cos(deg2rad($center[0]));

        for ($i = 0; $i < $count; $i++) {
            $p1 = $polygon[$i];
            $p2 = $polygon[($i + 1) % $count];

            $perimeter += $this->haversineDistance($p1, $p2);

            $x1 = ($p1[1] - $center[1]) * self::M_PER_DEG_LNG * $latScale;
            $y1 = ($p1[0] - $center[0]) * self::M_PER_DEG_LAT;
            $x2 = ($p2[1] - $center[1]) * self::M_PER_DEG_LNG * $latScale;
            $y2 = ($p2[0] - $center[0]) * self::M_PER_DEG_LAT;

            $area += ($x1 * $y2 - $x2 * $y1);
        }

        $sqMeters = abs($area) / 2;

        return [
            'center' => $center,
            'areaAcres' => number_format($sqMeters * 0.000247105, 2, '.', ''),
            'areaHectares' => number_format($sqMeters * 0.0001, 2, '.', ''),
            'areaSqMeters' => $sqMeters,
            'perimeterMeters' => $perimeter,
        ];
    }

    /**
     * True when any two non-adjacent edges of the closed polygon cross.
     *
     * @param  array<int, array{0: float, 1: float}>  $polygon
     */
    private function selfIntersects(array $polygon): bool
    {
        $n = count($polygon);

        for ($i = 0; $i < $n; $i++) {
            $a1 = $polygon[$i];
            $a2 = $polygon[($i + 1) % $n];

            for ($j = $i + 1; $j < $n; $j++) {
                // Adjacent edges share a vertex, and edge 0 meets the closing
                // edge, so neither pair counts as an intersection.
                if (abs($i - $j) <= 1 || ($i === 0 && $j === $n - 1)) {
                    continue;
                }

                if ($this->segmentsIntersect($a1, $a2, $polygon[$j], $polygon[($j + 1) % $n])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Counter-clockwise orientation test for segment intersection. */
    private function segmentsIntersect(array $p1, array $p2, array $p3, array $p4): bool
    {
        $ccw = fn (array $a, array $b, array $c): bool => ($c[1] - $a[1]) * ($b[0] - $a[0])
            > ($b[1] - $a[1]) * ($c[0] - $a[0]);

        return $ccw($p1, $p3, $p4) !== $ccw($p2, $p3, $p4)
            && $ccw($p1, $p2, $p3) !== $ccw($p1, $p2, $p4);
    }

    /**
     * Validates a candidate boundary, mirroring BoundaryValidationResult.
     * Points 1-4 are mandatory; 5-8 are optional.
     *
     * @param  array<int, array{0: float, 1: float}>  $polygon
     */
    public function validateBoundary(array $polygon): array
    {
        $errors = [];
        $warnings = [];
        $count = count($polygon);

        if ($count < 4) {
            $errors[] = sprintf('A boundary needs at least 4 points; %d supplied.', $count);
        }

        if ($count > 8) {
            $errors[] = sprintf('A boundary supports at most 8 points; %d supplied.', $count);
        }

        foreach ($polygon as $index => $point) {
            if (! isset($point[0], $point[1])) {
                $errors[] = sprintf('Point %d is missing a coordinate.', $index + 1);

                continue;
            }

            if ($point[0] < -90 || $point[0] > 90) {
                $errors[] = sprintf('Point %d latitude %s is out of range.', $index + 1, $point[0]);
            }

            if ($point[1] < -180 || $point[1] > 180) {
                $errors[] = sprintf('Point %d longitude %s is out of range.', $index + 1, $point[1]);
            }
        }

        // Vertices closer than 2m collapse into each other on the map.
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                if (! isset($polygon[$i][0], $polygon[$j][0])) {
                    continue;
                }

                if ($this->haversineDistance($polygon[$i], $polygon[$j]) < 2) {
                    $warnings[] = sprintf(
                        'Point %d and Point %d are within 2m of each other; may cause vertex collapse.',
                        $i + 1,
                        $j + 1,
                    );
                }
            }
        }

        /*
         * Self-intersection.
         *
         * A bow-tie polygon has no well-defined inside, so point-in-polygon
         * results become arbitrary — which for this application means gate
         * decisions become arbitrary. The client editor already refused these;
         * the server has to as well, or the check is only a suggestion.
         */
        if ($errors === [] && $count >= 4 && $this->selfIntersects($polygon)) {
            $errors[] = 'Boundary self-intersects. The perimeter must not cross itself.';
        }

        $metrics = $this->polygonMetrics($polygon);

        if ($metrics['areaSqMeters'] > 0 && $metrics['areaSqMeters'] < 1000) {
            $warnings[] = 'Enclosed area is under 1,000 m², which is unusually small for an estate boundary.';
        }

        $isValid = $errors === [];

        return [
            'isValid' => $isValid,
            'canPublish' => $isValid && $count >= 4,
            'pointCount' => $count,
            'errors' => $errors,
            'warnings' => $warnings,
            'areaAcres' => $metrics['areaAcres'],
            'areaHectares' => $metrics['areaHectares'],
            'areaSqMeters' => $metrics['areaSqMeters'],
            'perimeterMeters' => $metrics['perimeterMeters'],
            'center' => $metrics['center'],
        ];
    }
}
