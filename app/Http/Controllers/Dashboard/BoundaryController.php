<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\BoundaryAuditLog;
use App\Models\BoundaryConfig;
use App\Models\Community;
use App\Services\GeofenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backs src/components/dashboard/boundary-point-manager.tsx (1,439 lines) and
 * the set-geofence dialog.
 *
 * The original persisted the boundary to localStorage, which meant each admin
 * edited a private copy and a published boundary was never actually shared. It
 * is now a versioned server record with an audit trail, so publishing takes
 * effect for everyone and every change is attributable.
 */
class BoundaryController extends Controller
{
    public function __construct(private readonly GeofenceService $geofence) {}

    public function edit(Request $request): Response
    {
        $community = Community::default();
        $draft = $community->draftBoundary() ?? $community->publishedBoundary();
        $published = $community->publishedBoundary();

        return Inertia::render('Dashboard/Boundary', [
            'community' => [
                'name' => $community->name,
                'code' => $community->code,
                'jurisdiction' => $community->jurisdiction,
                'datum' => $community->datum,
                'referenceCoord' => [
                    'lat' => $community->reference_lat,
                    'lng' => $community->reference_lng,
                    'dms' => $community->reference_dms,
                    'description' => $community->reference_description,
                ],
                'cadastralZone' => $community->cadastral_zone,
            ],
            'draft' => $draft ? [
                'id' => $draft->id,
                'version' => $draft->version,
                'status' => $draft->status,
                'points' => $draft->points->map(fn ($p) => [
                    'id' => $p->point_index,
                    'label' => $p->label,
                    'lat' => $p->lat,
                    'lng' => $p->lng,
                    'isOptional' => $p->is_optional,
                ]),
            ] : null,
            'publishedCoordinates' => $published?->published_coordinates ?? [],
            'validation' => $draft
                ? $this->geofence->validateBoundary($draft->coordinatePairs())
                : null,
            'auditHistory' => $draft
                ? $draft->auditLogs()->limit(50)->get()->map(fn (BoundaryAuditLog $l) => [
                    'id' => $l->id,
                    'community' => $l->community,
                    'action' => $l->action,
                    'changedBy' => $l->changed_by,
                    'role' => $l->role,
                    'previousVersion' => $l->previous_version,
                    'newVersion' => $l->new_version,
                    'pointsCount' => $l->points_count,
                    'timestamp' => $l->occurred_at->toIso8601String(),
                    'published' => $l->published,
                    'notes' => $l->notes,
                    'areaAcres' => $l->area_acres,
                    'perimeterMeters' => $l->perimeter_meters,
                ])
                : [],
            'previousVersions' => $community->boundaryConfigs()
                ->with('points')
                ->orderByDesc('version')
                ->get()
                ->map(fn ($b) => [
                    'id' => $b->id,
                    'version' => $b->version,
                    'status' => $b->status,
                    'pointsCount' => $b->points->count(),
                    'publishedAt' => $b->last_published_at?->toIso8601String(),
                    'publishedBy' => $b->last_published_by,
                    'points' => $b->points->map(fn ($p) => [
                        'id' => $p->point_index,
                        'label' => $p->label,
                        'lat' => $p->lat,
                        'lng' => $p->lng,
                        'isOptional' => $p->is_optional,
                    ]),
                ]),
        ]);
    }

    /** Stateless validation, so the editor can check a shape before saving. */
    public function validateBoundary(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'points' => ['required', 'array', 'min:1', 'max:8'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $polygon = array_map(
            fn (array $p) => [(float) $p['lat'], (float) $p['lng']],
            $validated['points'],
        );

        return response()->json($this->geofence->validateBoundary($polygon));
    }

    /** Export active or draft boundary as standard GeoJSON */
    public function exportGeoJson(Request $request): StreamedResponse|JsonResponse
    {
        $this->authorize('viewBoundary');
        $community = Community::default();
        $target = $community->publishedBoundary() ?? $community->draftBoundary();

        if (! $target) {
            return response()->json(['error' => 'No boundary configuration found to export.'], 404);
        }

        $coordinates = $target->points->map(fn ($p) => [(float) $p->lng, (float) $p->lat])->values()->all();

        // GeoJSON polygon must close with identical start and end point
        if (count($coordinates) >= 3) {
            $coordinates[] = $coordinates[0];
        }

        $metrics = $this->geofence->polygonMetrics($target->coordinatePairs());

        $geoJson = [
            'type' => 'FeatureCollection',
            'features' => [
                [
                    'type' => 'Feature',
                    'geometry' => [
                        'type' => 'Polygon',
                        'coordinates' => [$coordinates],
                    ],
                    'properties' => [
                        'community' => $community->name,
                        'code' => $community->code,
                        'version' => $target->version,
                        'status' => $target->status,
                        'pointCount' => $target->points->count(),
                        'areaAcres' => $metrics['areaAcres'],
                        'perimeterMeters' => $metrics['perimeterMeters'],
                        'exportedAt' => now()->toIso8601String(),
                    ],
                ],
            ],
        ];

        $filename = Str::slug($community->code ?: 'community')."-boundary-v{$target->version}.geojson";

        return response()->streamDownload(function () use ($geoJson) {
            echo json_encode($geoJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }, $filename, [
            'Content-Type' => 'application/geo+json',
        ]);
    }

    /** Import polygon coordinates from uploaded or pasted GeoJSON */
    public function importGeoJson(Request $request): JsonResponse
    {
        $this->authorize('manageBoundary');

        $geojsonRaw = null;
        if ($request->hasFile('file')) {
            $geojsonRaw = file_get_contents($request->file('file')->getRealPath());
        } elseif ($request->filled('geojson')) {
            $geojsonRaw = is_string($request->input('geojson'))
                ? $request->input('geojson')
                : json_encode($request->input('geojson'));
        }

        if (! $geojsonRaw) {
            return response()->json([
                'success' => false,
                'message' => 'No GeoJSON file or payload provided.',
            ], 422);
        }

        $data = json_decode($geojsonRaw, true);
        if (! is_array($data)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid JSON structure.',
            ], 422);
        }

        $rings = null;
        if (isset($data['type'])) {
            if ($data['type'] === 'FeatureCollection' && ! empty($data['features'])) {
                $geom = $data['features'][0]['geometry'] ?? null;
                if ($geom && $geom['type'] === 'Polygon') {
                    $rings = $geom['coordinates'][0] ?? null;
                }
            } elseif ($data['type'] === 'Feature' && isset($data['geometry'])) {
                if ($data['geometry']['type'] === 'Polygon') {
                    $rings = $data['geometry']['coordinates'][0] ?? null;
                }
            } elseif ($data['type'] === 'Polygon') {
                $rings = $data['coordinates'][0] ?? null;
            }
        }

        if (! is_array($rings) || count($rings) < 4) {
            return response()->json([
                'success' => false,
                'message' => 'GeoJSON does not contain a valid polygon ring with at least 4 coordinates.',
            ], 422);
        }

        $first = $rings[0];
        $last = end($rings);
        if (count($rings) > 3 && is_array($first) && is_array($last) && $first[0] == $last[0] && $first[1] == $last[1]) {
            array_pop($rings);
        }

        if (count($rings) > 8) {
            $step = count($rings) / 8;
            $sampled = [];
            for ($i = 0; $i < 8; $i++) {
                $sampled[] = $rings[(int) floor($i * $step)];
            }
            $rings = $sampled;
        }

        $points = [];
        $polygon = [];
        foreach ($rings as $index => $coord) {
            if (! is_array($coord) || count($coord) < 2) {
                continue;
            }
            $lng = (float) $coord[0];
            $lat = (float) $coord[1];
            $pointIndex = $index + 1;

            $points[] = [
                'id' => $pointIndex,
                'label' => "Imported P{$pointIndex}",
                'lat' => $lat,
                'lng' => $lng,
                'isOptional' => $pointIndex > 4,
            ];
            $polygon[] = [$lat, $lng];
        }

        if (count($points) < 4) {
            return response()->json([
                'success' => false,
                'message' => 'Could not extract at least 4 valid coordinates.',
            ], 422);
        }

        $validation = $this->geofence->validateBoundary($polygon);

        return response()->json([
            'success' => true,
            'points' => $points,
            'validation' => $validation,
            'message' => 'GeoJSON boundary imported successfully.',
        ]);
    }

    public function saveDraft(Request $request): RedirectResponse
    {
        $validated = $this->validatePoints($request);

        $community = Community::default();
        $user = $request->user();

        DB::transaction(function () use ($validated, $community, $user) {
            $latestVersion = $community->boundaryConfigs()->max('version') ?? 0;

            $draft = $community->boundaryConfigs()
                ->where('status', 'DRAFT')
                ->orderByDesc('version')
                ->first();

            $previousVersion = $draft->version ?? $latestVersion;

            if (! $draft) {
                $draft = $community->boundaryConfigs()->create([
                    'version' => $latestVersion + 1,
                    'status' => 'DRAFT',
                ]);
            }

            $draft->points()->delete();

            foreach ($validated['points'] as $index => $point) {
                $pointIndex = $index + 1;

                $draft->points()->create([
                    'point_index' => $pointIndex,
                    'label' => $point['label'] ?? "Point {$pointIndex}",
                    'lat' => $point['lat'],
                    'lng' => $point['lng'],
                    // Points 1-4 are mandatory, 5-8 optional.
                    'is_optional' => $pointIndex > 4,
                ]);
            }

            $metrics = $this->geofence->polygonMetrics(
                array_map(fn ($p) => [(float) $p['lat'], (float) $p['lng']], $validated['points'])
            );

            $draft->auditLogs()->create([
                'community' => $community->name,
                'action' => 'Draft Saved',
                'changed_by' => $user->display_name,
                'role' => $user->role->value,
                'previous_version' => $previousVersion,
                'new_version' => $draft->version,
                'points_count' => count($validated['points']),
                'published' => false,
                'notes' => $validated['notes'] ?? null,
                'area_acres' => $metrics['areaAcres'],
                'perimeter_meters' => $metrics['perimeterMeters'],
                'occurred_at' => now(),
            ]);
        });

        return back()->with('success', 'Boundary draft saved.');
    }

    public function publish(Request $request): RedirectResponse
    {
        $validated = $this->validatePoints($request);

        $polygon = array_map(
            fn (array $p) => [(float) $p['lat'], (float) $p['lng']],
            $validated['points'],
        );

        $check = $this->geofence->validateBoundary($polygon);

        if (! $check['canPublish']) {
            return back()->withErrors(['points' => implode(' ', $check['errors'])]);
        }

        $community = Community::default();
        $user = $request->user();

        DB::transaction(function () use ($community, $user, $polygon, $validated, $check) {
            $previous = $community->publishedBoundary();

            // Supersede the previous published version rather than mutating it,
            // so the history stays intact.
            $previous?->update(['status' => 'SUPERSEDED']);

            $nextVersion = ($community->boundaryConfigs()->max('version') ?? 0) + 1;

            $config = $community->boundaryConfigs()->create([
                'version' => $nextVersion,
                'status' => 'PUBLISHED',
                'published_coordinates' => $polygon,
                'last_published_at' => now(),
                'last_published_by' => $user->display_name,
            ]);

            foreach ($validated['points'] as $index => $point) {
                $pointIndex = $index + 1;

                $config->points()->create([
                    'point_index' => $pointIndex,
                    'label' => $point['label'] ?? "Point {$pointIndex}",
                    'lat' => $point['lat'],
                    'lng' => $point['lng'],
                    'is_optional' => $pointIndex > 4,
                ]);
            }

            $config->auditLogs()->create([
                'community' => $community->name,
                'action' => 'Boundary Published',
                'changed_by' => $user->display_name,
                'role' => $user->role->value,
                'previous_version' => $previous->version ?? 0,
                'new_version' => $nextVersion,
                'points_count' => count($validated['points']),
                'published' => true,
                'notes' => $validated['notes'] ?? null,
                'area_acres' => $check['areaAcres'],
                'perimeter_meters' => $check['perimeterMeters'],
                'occurred_at' => now(),
            ]);

            // Discard any stale drafts now that a newer boundary is live.
            $community->boundaryConfigs()
                ->where('status', 'DRAFT')
                ->where('version', '<', $nextVersion)
                ->delete();
        });

        return back()->with('success', 'Boundary published to the whole community.');
    }

    public function replace(Request $request, BoundaryConfig $boundaryConfig): RedirectResponse
    {
        $this->authorize('manageBoundary');
        $community = Community::default();
        $user = $request->user();

        if ($boundaryConfig->community_id !== $community->id) {
            abort(404, 'Boundary configuration does not belong to this community.');
        }

        DB::transaction(function () use ($boundaryConfig, $community, $user) {
            $latestVersion = ($community->boundaryConfigs()->max('version') ?? 0) + 1;
            $currentPublished = $community->publishedBoundary();
            $currentPublished?->update(['status' => 'SUPERSEDED']);

            $newConfig = $community->boundaryConfigs()->create([
                'version' => $latestVersion,
                'status' => 'PUBLISHED',
                'published_coordinates' => $boundaryConfig->coordinatePairs(),
                'last_published_at' => now(),
                'last_published_by' => $user->display_name,
            ]);

            foreach ($boundaryConfig->points as $p) {
                $newConfig->points()->create([
                    'point_index' => $p->point_index,
                    'label' => $p->label,
                    'lat' => $p->lat,
                    'lng' => $p->lng,
                    'is_optional' => $p->is_optional,
                ]);
            }

            $metrics = $this->geofence->polygonMetrics($boundaryConfig->coordinatePairs());

            $newConfig->auditLogs()->create([
                'community' => $community->name,
                'action' => 'Boundary Replaced / Restored',
                'changed_by' => $user->display_name,
                'role' => $user->role->value,
                'previous_version' => $currentPublished?->version ?? $boundaryConfig->version,
                'new_version' => $latestVersion,
                'points_count' => $boundaryConfig->points()->count(),
                'published' => true,
                'notes' => "Active boundary replaced/restored with snapshot from version {$boundaryConfig->version}",
                'area_acres' => $metrics['areaAcres'],
                'perimeter_meters' => $metrics['perimeterMeters'],
                'occurred_at' => now(),
            ]);

            // Discard any stale drafts now that replacement is published
            $community->boundaryConfigs()
                ->where('status', 'DRAFT')
                ->where('version', '<', $latestVersion)
                ->delete();
        });

        return back()->with('success', "Boundary successfully replaced with Version {$boundaryConfig->version}.");
    }

    public function destroy(Request $request, BoundaryConfig $boundaryConfig): RedirectResponse
    {
        $this->authorize('manageBoundary');
        $community = Community::default();
        $user = $request->user();

        if ($boundaryConfig->community_id !== $community->id) {
            abort(404, 'Boundary configuration does not belong to this community.');
        }

        if ($boundaryConfig->status === 'PUBLISHED') {
            return back()->withErrors(['boundary' => 'The active published boundary cannot be deleted.']);
        }

        DB::transaction(function () use ($boundaryConfig, $community, $user) {
            $version = $boundaryConfig->version;
            $status = $boundaryConfig->status;

            BoundaryAuditLog::create([
                'boundary_config_id' => null,
                'community' => $community->name,
                'action' => "Boundary {$status} v{$version} Deleted",
                'changed_by' => $user->display_name,
                'role' => $user->role->value,
                'previous_version' => $version,
                'new_version' => $version,
                'points_count' => $boundaryConfig->points()->count(),
                'published' => false,
                'notes' => "Deleted boundary configuration v{$version} ({$status})",
                'area_acres' => null,
                'perimeter_meters' => null,
                'occurred_at' => now(),
            ]);

            $boundaryConfig->points()->delete();
            $boundaryConfig->delete();
        });

        return back()->with('success', 'Boundary configuration deleted.');
    }

    private function validatePoints(Request $request): array
    {
        return $request->validate([
            'points' => ['required', 'array', 'min:4', 'max:8'],
            'points.*.label' => ['nullable', 'string', 'max:60'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
