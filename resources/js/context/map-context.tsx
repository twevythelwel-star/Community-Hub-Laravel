import { router, usePage } from '@inertiajs/react';

/**
 * Inertia-backed replacement for MapProvider.
 *
 * The original kept the geofence polygon in React state seeded from
 * `localStorage['map-coordinates']`. That meant the estate boundary was
 * per-browser: an administrator redrawing it changed it only on their own
 * machine, and a resident's map could disagree with the gatehouse indefinitely.
 *
 * The polygon is now the published `boundary_configs` row, shared on every
 * Inertia response by HandleInertiaRequests. The `useMap()` shape is preserved
 * so Overview, Map, Settings and the geofence dialogs need no changes.
 */

/** [latitude, longitude] pairs, matching Leaflet's convention. */
export type MapCoordinates = [number, number][];

export type BoundaryMetrics = {
    center: [number, number];
    areaAcres: string;
    areaHectares: string;
    areaSqMeters: number;
    perimeterMeters: number;
};

type SharedBoundary = {
    coordinates?: MapCoordinates;
    version?: number | null;
    publishedAt?: string | null;
    metrics?: BoundaryMetrics;
    community?: { name: string | null; code: string | null; datum: string | null };
};

type SharedProps = { boundary?: SharedBoundary };

/**
 * Fallback polygon, used only before a boundary has been published.
 * Matches the DEFAULT_COORDS the original provider started from.
 */
const DEFAULT_COORDS: MapCoordinates = [
    [18.4781, -77.9278],
    [18.4783, -77.9239],
    [18.4752, -77.9236],
    [18.4750, -77.9276],
];

const EMPTY_METRICS: BoundaryMetrics = {
    center: [18.4766, -77.9257],
    areaAcres: '0.00',
    areaHectares: '0.00',
    areaSqMeters: 0,
    perimeterMeters: 0,
};

export function useMap() {
    const page = usePage<SharedProps>();
    const shared = page.props.boundary;

    const coordinates: MapCoordinates =
        shared?.coordinates && shared.coordinates.length >= 3
            ? shared.coordinates
            : DEFAULT_COORDS;

    return {
        coordinates,

        /** Area, perimeter and centroid, computed server-side by GeofenceService. */
        metrics: shared?.metrics ?? EMPTY_METRICS,

        /** Null until an administrator publishes a boundary. */
        version: shared?.version ?? null,
        publishedAt: shared?.publishedAt ?? null,
        community: shared?.community ?? { name: null, code: null, datum: null },

        /** True when showing the fallback rather than a published boundary. */
        isProvisional: !shared?.coordinates || shared.coordinates.length < 3,

        /**
         * Publishes a new boundary for the whole community.
         *
         * Requires the `manageBoundary` gate; BoundaryController rejects anyone
         * else. The server re-validates the polygon, so a malformed shape cannot
         * be published by posting directly.
         */
        setCoordinates: (
            coords: MapCoordinates,
            options?: { notes?: string; onSuccess?: () => void; onError?: () => void },
        ) =>
            router.post(
                '/dashboard/map/boundary/publish',
                {
                    points: coords.map(([lat, lng], index) => ({
                        label: `Point ${index + 1}`,
                        lat,
                        lng,
                    })),
                    notes: options?.notes,
                },
                {
                    preserveScroll: true,
                    onSuccess: options?.onSuccess,
                    onError: options?.onError,
                },
            ),
    };
}
