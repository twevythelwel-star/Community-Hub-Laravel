<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\BoundaryAuditLog;
use App\Models\BoundaryConfig;
use App\Models\Community;
use App\Models\Landmark;
use App\Services\GeofenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Backs src/app/dashboard/map/page.tsx, LeafletMapFixed.tsx,
 * add-landmark-dialog.tsx and boundary-point-manager.tsx.
 *
 * Landmarks and the boundary both moved out of localStorage: one administrator
 * adding a landmark or redrawing the perimeter is now visible to every resident,
 * where before each browser held a private copy.
 */
class MapController extends Controller
{
    public function __construct(private readonly GeofenceService $geofence) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $community = Community::default();
        $published = $community->publishedBoundary();
        $polygon = $published?->published_coordinates ?? [];

        // The editor works from the newest draft, falling back to what is live.
        $editable = $community->draftBoundary() ?? $published;

        return Inertia::render('Dashboard/Map', [
            'boundary' => [
                'coordinates' => $polygon,
                'version' => $published?->version,
                'publishedAt' => $published?->last_published_at?->toIso8601String(),
                'metrics' => $this->geofence->polygonMetrics($polygon),
            ],

            'landmarks' => $community->landmarks()
                ->orderBy('name')
                ->get()
                ->map(fn (Landmark $l) => $this->landmarkPayload($l)),

            'community' => [
                'name' => $community->name,
                'code' => $community->code,
                'jurisdiction' => $community->jurisdiction ?? '',
                'datum' => $community->datum ?? 'WGS84',
                'referenceCoord' => [
                    'lat' => (float) ($community->reference_lat ?? 18.4766),
                    'lng' => (float) ($community->reference_lng ?? -77.9257),
                    'dms' => $community->reference_dms ?? '',
                    'description' => $community->reference_description ?? '',
                ],
                'cadastralZone' => $community->cadastral_zone ?? '',
            ],

            // Shape the boundary editor expects, previously loaded from
            // localStorage['community-boundary-config-v2'].
            'boundaryConfig' => $this->boundaryConfigPayload($editable, $community->name),

            'can' => [
                'manageBoundary' => $user->can('manageBoundary'),
            ],
        ]);
    }

    public function storeLandmark(Request $request): RedirectResponse
    {
        $this->authorize('manageLandmarks');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'string', 'in:Security Gate,Community Center,Park'],
            'description' => ['nullable', 'string', 'max:1000'],
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'icon' => ['nullable', 'string', 'max:40'],
        ]);

        Community::default()->landmarks()->create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        $request->user()->recordActivity("Added map landmark {$validated['name']}");

        return back()->with('success', 'Landmark added.');
    }

    public function destroyLandmark(Request $request, Landmark $landmark): RedirectResponse
    {
        $this->authorize('manageLandmarks');

        $name = $landmark->name;
        $landmark->delete();

        $request->user()->recordActivity("Removed map landmark {$name}");

        return back()->with('success', "Removed \"{$name}\" from the community map.");
    }

    /**
     * Server-side geofence check. The browser reports a position; the server
     * decides whether it is inside the published boundary, so the answer cannot
     * be spoofed by editing client state.
     */
    public function locate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $point = [(float) $validated['lat'], (float) $validated['lng']];
        $polygon = Community::default()->publishedBoundary()?->published_coordinates ?? [];

        return response()->json([
            'point' => $point,
            'dms' => [
                'lat' => $this->geofence->toDms($point[0], true),
                'lng' => $this->geofence->toDms($point[1], false),
            ],
            'elevation' => $this->geofence->estimateElevation($point[0], $point[1]),
            'perimeter' => $this->geofence->perimeterInfo($point, $polygon),
        ]);
    }

    /** @return array<string, mixed> */
    private function landmarkPayload(Landmark $l): array
    {
        return [
            'id' => $l->id,
            'name' => $l->name,
            'category' => $l->category,
            'description' => $l->description ?? '',
            'coordinates' => [$l->lat, $l->lng],
            'elevation' => $this->geofence->estimateElevation($l->lat, $l->lng),
            'iconType' => $l->icon ?? $this->iconFor($l->category),
            'color' => $this->landmarkColor($l->category),
        ];
    }

    /** @return array<string, mixed> */
    private function boundaryConfigPayload(?BoundaryConfig $config, string $communityName): array
    {
        if ($config === null) {
            return [
                'version' => 0,
                'status' => 'DRAFT',
                'points' => [],
                'publishedCoordinates' => [],
                'lastPublishedAt' => null,
                'lastPublishedBy' => null,
                'auditHistory' => [],
            ];
        }

        return [
            'version' => $config->version,
            'status' => $config->status,
            'publishedCoordinates' => $config->published_coordinates ?? [],
            'lastPublishedAt' => $config->last_published_at?->toIso8601String(),
            'lastPublishedBy' => $config->last_published_by,

            'points' => $config->points->map(fn ($p) => [
                'id' => $p->point_index,
                'label' => $p->label,
                'lat' => (float) $p->lat,
                'lng' => (float) $p->lng,
                'isOptional' => $p->is_optional,
            ])->values(),

            'auditHistory' => $config->auditLogs()
                ->limit(50)
                ->get()
                ->map(fn (BoundaryAuditLog $l) => [
                    'id' => $l->id,
                    'community' => $l->community ?: $communityName,
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
                ->values(),
        ];
    }

    /** Pin colour per category, matching LANDMARK_PIN_CONFIGS on the client. */
    private function landmarkColor(?string $category): string
    {
        return match ($category) {
            'Security Gate' => '#10B981',
            'Community Center' => '#EF4444',
            'Park' => '#3B82F6',
            default => '#64748B',
        };
    }

    private function iconFor(?string $category): string
    {
        return match ($category) {
            'Security Gate' => 'shield',
            'Community Center' => 'building',
            'Park' => 'trees',
            default => 'shield',
        };
    }
}
