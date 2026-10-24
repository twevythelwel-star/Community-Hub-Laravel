<?php

namespace Tests\Unit;

use App\Services\GeofenceService;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the ported polygon maths against the behaviour of
 * src/lib/geofence-utils.ts, plus the edge case the JavaScript version would
 * have divided by zero on.
 */
class GeofenceServiceTest extends TestCase
{
    private GeofenceService $geofence;

    protected function setUp(): void
    {
        parent::setUp();
        $this->geofence = new GeofenceService;
    }

    /** @return array<int, array{0: float, 1: float}> */
    private function estatePolygon(): array
    {
        return [
            [18.4742, -77.9284],
            [18.4748, -77.9241],
            [18.4771, -77.9232],
            [18.4790, -77.9246],
            [18.4793, -77.9271],
            [18.4779, -77.9291],
            [18.4760, -77.9296],
            [18.4748, -77.9293],
        ];
    }

    public function test_it_formats_coordinates_as_degrees_minutes_seconds(): void
    {
        $this->assertSame('18°28\'35.8"N', $this->geofence->toDms(18.4766, true));
        $this->assertSame('77°55\'32.5"W', $this->geofence->toDms(-77.9257, false));
    }

    public function test_haversine_distance_is_zero_for_identical_points(): void
    {
        $this->assertSame(0, $this->geofence->haversineDistance([18.4766, -77.9257], [18.4766, -77.9257]));
    }

    public function test_haversine_distance_matches_a_known_separation(): void
    {
        // One degree of latitude is approximately 111 km.
        $distance = $this->geofence->haversineDistance([18.0, -77.9257], [19.0, -77.9257]);

        $this->assertGreaterThan(110_000, $distance);
        $this->assertLessThan(112_000, $distance);
    }

    public function test_a_point_inside_the_estate_is_detected(): void
    {
        $this->assertTrue($this->geofence->isPointInGeofence([18.4766, -77.9260], $this->estatePolygon()));
    }

    public function test_a_point_outside_the_estate_is_detected(): void
    {
        $this->assertFalse($this->geofence->isPointInGeofence([18.5100, -77.9000], $this->estatePolygon()));
    }

    public function test_a_polygon_with_fewer_than_three_points_contains_nothing(): void
    {
        $this->assertFalse($this->geofence->isPointInGeofence([18.4766, -77.9257], [
            [18.4742, -77.9284],
            [18.4748, -77.9241],
        ]));
    }

    public function test_a_horizontal_edge_does_not_cause_a_division_error(): void
    {
        // Two vertices sharing a longitude produced a zero denominator. In JS that
        // silently yielded Infinity; in PHP it would throw, so the port guards it.
        $polygon = [
            [18.470, -77.930],
            [18.480, -77.930],
            [18.480, -77.920],
            [18.470, -77.920],
        ];

        $this->assertTrue($this->geofence->isPointInGeofence([18.475, -77.925], $polygon));
        $this->assertFalse($this->geofence->isPointInGeofence([18.500, -77.925], $polygon));
    }

    public function test_polygon_metrics_return_a_plausible_centroid_and_area(): void
    {
        $metrics = $this->geofence->polygonMetrics($this->estatePolygon());

        $this->assertEqualsWithDelta(18.4766, $metrics['center'][0], 0.002);
        $this->assertEqualsWithDelta(-77.9267, $metrics['center'][1], 0.002);
        $this->assertGreaterThan(0, $metrics['areaSqMeters']);
        $this->assertGreaterThan(0, $metrics['perimeterMeters']);
        $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $metrics['areaAcres']);
    }

    public function test_an_empty_polygon_returns_the_documented_fallback(): void
    {
        $metrics = $this->geofence->polygonMetrics([]);

        $this->assertSame([18.4766, -77.9257], $metrics['center']);
        $this->assertSame('0.00', $metrics['areaAcres']);
        $this->assertSame(0, $metrics['perimeterMeters']);
    }

    public function test_elevation_is_clamped_to_the_supported_band(): void
    {
        $this->assertGreaterThanOrEqual(15, $this->geofence->estimateElevation(-89.0, 0.0));
        $this->assertLessThanOrEqual(180, $this->geofence->estimateElevation(89.0, 179.0));
    }

    public function test_boundary_validation_requires_at_least_four_points(): void
    {
        $result = $this->geofence->validateBoundary([
            [18.470, -77.930],
            [18.480, -77.930],
            [18.480, -77.920],
        ]);

        $this->assertFalse($result['isValid']);
        $this->assertFalse($result['canPublish']);
        $this->assertNotEmpty($result['errors']);
    }

    public function test_boundary_validation_rejects_more_than_eight_points(): void
    {
        $polygon = array_fill(0, 9, [18.475, -77.925]);

        $result = $this->geofence->validateBoundary($polygon);

        $this->assertFalse($result['isValid']);
    }

    public function test_boundary_validation_accepts_the_estate_perimeter(): void
    {
        $result = $this->geofence->validateBoundary($this->estatePolygon());

        $this->assertTrue($result['isValid']);
        $this->assertTrue($result['canPublish']);
        $this->assertSame(8, $result['pointCount']);
        $this->assertEmpty($result['errors']);
    }

    public function test_boundary_validation_rejects_out_of_range_coordinates(): void
    {
        $result = $this->geofence->validateBoundary([
            [118.470, -77.930],
            [18.480, -277.930],
            [18.480, -77.920],
            [18.470, -77.920],
        ]);

        $this->assertFalse($result['isValid']);
        $this->assertCount(2, $result['errors']);
    }

    public function test_perimeter_info_reports_the_nearest_gate(): void
    {
        $info = $this->geofence->perimeterInfo([18.4751, -77.9258], $this->estatePolygon());

        $this->assertSame('Main Security Gate', $info['nearestGate']['name']);
        $this->assertLessThan(100, $info['nearestGate']['distance']);
    }

    // ── Self-intersection ────────────────────────────────────────────

    public function test_a_self_intersecting_boundary_is_rejected(): void
    {
        // A bow-tie: edge 1-2 and edge 3-4 are the rectangle's two diagonals,
        // so they cross. Such a polygon has no well-defined inside, which makes
        // every point-in-polygon result — and so every gate decision — arbitrary.
        $result = $this->geofence->validateBoundary([
            [18.4742, -77.9284],
            [18.4790, -77.9241],
            [18.4742, -77.9241],
            [18.4790, -77.9284],
        ]);

        $this->assertFalse($result['isValid']);
        $this->assertFalse($result['canPublish']);
        $this->assertNotEmpty(array_filter(
            $result['errors'],
            fn (string $e) => str_contains($e, 'self-intersects'),
        ));
    }

    public function test_a_simple_convex_boundary_is_not_flagged_as_self_intersecting(): void
    {
        $result = $this->geofence->validateBoundary($this->estatePolygon());

        $this->assertTrue($result['isValid']);
        $this->assertEmpty(array_filter(
            $result['errors'],
            fn (string $e) => str_contains($e, 'self-intersects'),
        ));
    }

    public function test_near_duplicate_vertices_produce_a_warning_not_an_error(): void
    {
        // Two points about 1m apart: publishable, but worth flagging because the
        // vertices collapse into each other on screen.
        $result = $this->geofence->validateBoundary([
            [18.470000, -77.930000],
            [18.470005, -77.930000],
            [18.480000, -77.920000],
            [18.470000, -77.920000],
        ]);

        $this->assertNotEmpty(array_filter(
            $result['warnings'],
            fn (string $w) => str_contains($w, 'within 2m'),
        ));
    }
}
