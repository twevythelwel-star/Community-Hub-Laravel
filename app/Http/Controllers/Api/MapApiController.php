<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Community;
use App\Models\Landmark;
use App\Services\GeofenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MapApiController extends Controller
{
    public function __construct(private readonly GeofenceService $geofence) {}

    public function boundary(): JsonResponse
    {
        $community = Community::default();
        $published = $community->publishedBoundary();
        $polygon = $published?->published_coordinates ?? [];

        return response()->json([
            'coordinates' => $polygon,
            'version' => $published?->version,
            'publishedAt' => $published?->last_published_at?->toIso8601String(),
            'metrics' => $this->geofence->polygonMetrics($polygon),
        ]);
    }

    public function landmarks(): JsonResponse
    {
        return response()->json([
            'landmarks' => Community::default()->landmarks->map(fn (Landmark $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'category' => $l->category,
                'description' => $l->description,
                'coordinates' => [$l->lat, $l->lng],
                'elevation' => $this->geofence->estimateElevation($l->lat, $l->lng),
                'icon' => $l->icon,
            ]),
        ]);
    }

    /** Authoritative inside/outside answer for a reported device position. */
    public function checkPosition(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $point = [(float) $validated['lat'], (float) $validated['lng']];
        $polygon = Community::default()->publishedBoundary()?->published_coordinates ?? [];

        $perimeter = $this->geofence->perimeterInfo($point, $polygon);

        return response()->json([
            'point' => $point,
            'isInside' => $perimeter['isInside'],
            'perimeter' => $perimeter,
            'elevation' => $this->geofence->estimateElevation($point[0], $point[1]),
            'dms' => [
                'lat' => $this->geofence->toDms($point[0], true),
                'lng' => $this->geofence->toDms($point[1], false),
            ],
        ]);
    }
}
