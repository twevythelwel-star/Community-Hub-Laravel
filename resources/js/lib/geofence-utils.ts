/**
 * Client-side geospatial helpers.
 *
 * These mirror App\Services\GeofenceService, and the duplication is deliberate
 * with a clear split of authority:
 *
 *   - PHP is authoritative for *decisions*. Whether a position is inside the
 *     boundary, whether a boundary may be published, and the metrics stored
 *     against a published version are all decided server-side, where they cannot
 *     be edited in memory.
 *   - TypeScript is for *live display only* — recomputing a polygon's area while
 *     an admin drags a vertex, or showing a coordinate as DMS under the cursor.
 *     A server round-trip per mouse-move would be unusable.
 *
 * Anything that grants access goes through the server. GeofenceServiceTest
 * covers the PHP side, including a horizontal-edge case that divides by zero.
 *
 * Removed from the original module: `loadCommunityLandmarks` /
 * `saveCommunityLandmarks` (landmarks are now the `landmarks` table, served as
 * props) and the hardcoded `DEFAULT_COMMUNITY_LANDMARKS` fixture.
 */

export type LandmarkCategory = 'Security Gate' | 'Community Center' | 'Park';

export type CommunityLandmark = {
    id: number | string;
    name: string;
    category: LandmarkCategory;
    coordinates: [number, number];
    elevation: number;
    description: string;
    iconType?: 'shield' | 'building' | 'trees';
    color?: string;
};

/** Pin styling per landmark category. Presentation only. */
export const LANDMARK_PIN_CONFIGS: Record<
    LandmarkCategory,
    {
        color: string;
        label: string;
        badgeLabel: string;
        hexCode: string;
        iconType: 'shield' | 'building' | 'trees';
        presetNames: string[];
        descriptionPlaceholder: string;
    }
> = {
    'Security Gate': {
        color: '#10B981',
        label: 'Security Gate',
        badgeLabel: 'GREEN PIN',
        hexCode: '#10B981',
        iconType: 'shield',
        presetNames: [
            'Main Security Gate & Access Control',
            'North Perimeter Gate',
            'West Visitor Gate',
            'South Resident Egress Gate',
            'Service & Delivery Gate',
        ],
        descriptionPlaceholder:
            '24/7 security guardhouse, barrier gate, RFID scanner, and visitor intercom checkpoint.',
    },
    'Community Center': {
        color: '#EF4444',
        label: 'Community Center',
        badgeLabel: 'RED PIN',
        hexCode: '#EF4444',
        iconType: 'building',
        presetNames: [
            'Community Center & Clubhouse',
            'HOA Administration Office',
            'Resident Recreation Hall',
            'Community Event Pavilion',
            'Fitness & Wellness Center',
        ],
        descriptionPlaceholder:
            'Central clubhouse complex, resident meeting lounge, and HOA administrative office.',
    },
    Park: {
        color: '#3B82F6',
        label: 'Park',
        badgeLabel: 'BLUE PIN',
        hexCode: '#3B82F6',
        iconType: 'trees',
        presetNames: [
            'Central Recreation Park',
            'Children Playground & Picnic Grounds',
            'Tennis & Sports Park',
            'Meadowview Nature Trail Park',
            'Dog Park & Exercise Field',
        ],
        descriptionPlaceholder:
            'Landscaped recreational green space, outdoor seating, sports facilities, and children play zones.',
    },
};

/** Shown only until an administrator publishes a real boundary. */
export const DEFAULT_GEOFENCE_COORDS: [number, number][] = [
    [18.4781, -77.9278],
    [18.4783, -77.9239],
    [18.4752, -77.9236],
    [18.4750, -77.9276],
];

/** Starting shapes offered by the geofence editor. */
export const GEOFENCE_PRESETS = [
    {
        name: 'Montego Palms Standard (Default)',
        description: 'Official surveyed community cadastral polygon (4 points)',
        coords: [
            [18.4781, -77.9278],
            [18.4783, -77.9239],
            [18.4752, -77.9236],
            [18.4750, -77.9276],
        ] as [number, number][],
    },
    {
        name: 'Extended Perimeter (+20% Buffer)',
        description: 'Includes outer greenway buffer and utility servitude (6 points)',
        coords: [
            [18.4788, -77.9284],
            [18.4791, -77.9233],
            [18.4760, -77.9228],
            [18.4744, -77.9230],
            [18.4742, -77.9282],
            [18.4765, -77.9286],
        ] as [number, number][],
    },
    {
        name: 'Central Residential Core',
        description: 'High-security inner residential enclave (4 points)',
        coords: [
            [18.4776, -77.9268],
            [18.4778, -77.9245],
            [18.4756, -77.9242],
            [18.4754, -77.9266],
        ] as [number, number][],
    },
];

const EARTH_RADIUS_M = 6371000;
const M_PER_DEG_LNG = 111320;
const M_PER_DEG_LAT = 110574;

/** Decimal degrees as degrees/minutes/seconds, e.g. 18°28'35.8"N. */
export function toDMS(deg: number, isLat: boolean): string {
    const absolute = Math.abs(deg);
    const degrees = Math.floor(absolute);
    const minutesNotTruncated = (absolute - degrees) * 60;
    const minutes = Math.floor(minutesNotTruncated);
    const seconds = ((minutesNotTruncated - minutes) * 60).toFixed(1);
    const direction = isLat ? (deg >= 0 ? 'N' : 'S') : deg >= 0 ? 'E' : 'W';

    return `${degrees}°${minutes}'${seconds}"${direction}`;
}

/** Approximate elevation from the coastal ridge interpolation, clamped 15–180 m. */
export function estimateElevation(lat: number, lng: number): number {
    const baseLat = 18.475;
    const baseLng = -77.927;
    const dLat = (lat - baseLat) * 111000;
    const dLng = (lng - baseLng) * 105000;
    const elevation = Math.round(38 + dLat * 0.08 + dLng * 0.04);

    return Math.max(15, Math.min(180, elevation));
}

/** Great-circle distance in metres between two [lat, lng] points. */
export function haversineDistance(c1: [number, number], c2: [number, number]): number {
    const dLat = ((c2[0] - c1[0]) * Math.PI) / 180;
    const dLng = ((c2[1] - c1[1]) * Math.PI) / 180;

    const a =
        Math.sin(dLat / 2) ** 2 +
        Math.cos((c1[0] * Math.PI) / 180) *
            Math.cos((c2[0] * Math.PI) / 180) *
            Math.sin(dLng / 2) ** 2;

    return Math.round(EARTH_RADIUS_M * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a)));
}

/**
 * Ray-casting point-in-polygon.
 *
 * Display only — for "you are inside the community" hints on the map. Any access
 * decision must use the server, which cannot be edited from the console.
 */
export function isPointInGeofence(point: [number, number], polygon: [number, number][]): boolean {
    if (polygon.length < 3) return false;

    const [lat, lng] = point;
    let inside = false;

    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
        const [latI, lngI] = polygon[i];
        const [latJ, lngJ] = polygon[j];

        if (lngI > lng !== lngJ > lng) {
            const denominator = lngJ - lngI;

            // A horizontal edge cannot be crossed by the ray. JavaScript would
            // yield Infinity here rather than throwing, which silently flipped
            // the result; the PHP port guards the same case explicitly.
            if (denominator === 0) continue;

            if (lat < ((latJ - latI) * (lng - lngI)) / denominator + latI) {
                inside = !inside;
            }
        }
    }

    return inside;
}

export type PolygonMetrics = {
    center: [number, number];
    areaAcres: string;
    areaHectares: string;
    areaSqMeters: number;
    perimeterMeters: number;
};

/** Centroid, area and perimeter via the shoelace formula on a local projection. */
export function calculatePolygonMetrics(polygon: [number, number][]): PolygonMetrics {
    if (polygon.length === 0) {
        return {
            center: [18.4766, -77.9257],
            areaAcres: '0.00',
            areaHectares: '0.00',
            areaSqMeters: 0,
            perimeterMeters: 0,
        };
    }

    const count = polygon.length;
    const center: [number, number] = [
        polygon.reduce((sum, c) => sum + c[0], 0) / count,
        polygon.reduce((sum, c) => sum + c[1], 0) / count,
    ];

    if (count < 3) {
        return {
            center,
            areaAcres: '0.00',
            areaHectares: '0.00',
            areaSqMeters: 0,
            perimeterMeters: 0,
        };
    }

    let area = 0;
    let perimeter = 0;
    const latScale = Math.cos((center[0] * Math.PI) / 180);

    for (let i = 0; i < count; i++) {
        const p1 = polygon[i];
        const p2 = polygon[(i + 1) % count];

        perimeter += haversineDistance(p1, p2);

        const x1 = (p1[1] - center[1]) * M_PER_DEG_LNG * latScale;
        const y1 = (p1[0] - center[0]) * M_PER_DEG_LAT;
        const x2 = (p2[1] - center[1]) * M_PER_DEG_LNG * latScale;
        const y2 = (p2[0] - center[0]) * M_PER_DEG_LAT;

        area += x1 * y2 - x2 * y1;
    }

    const sqMeters = Math.abs(area) / 2;

    return {
        center,
        areaAcres: (sqMeters * 0.000247105).toFixed(2),
        areaHectares: (sqMeters * 0.0001).toFixed(2),
        areaSqMeters: sqMeters,
        perimeterMeters: Math.round(perimeter),
    };
}

/** Distance to the nearest vertex and the closest gate. */
export function getGeofencePerimeterInfo(
    point: [number, number],
    polygon: [number, number][],
    gates: { name: string; coordinates: [number, number] }[] = [
        { name: 'Main Security Gate', coordinates: [18.475, -77.9257] },
        { name: 'North Perimeter Checkpoint', coordinates: [18.4782, -77.9258] },
    ],
) {
    const isInside = isPointInGeofence(point, polygon);

    let minDistance = Number.POSITIVE_INFINITY;
    let nearestVertexIndex = 0;

    polygon.forEach((vertex, index) => {
        const distance = haversineDistance(point, vertex);
        if (distance < minDistance) {
            minDistance = distance;
            nearestVertexIndex = index;
        }
    });

    const nearestGate = gates
        .map((gate) => ({ ...gate, distance: haversineDistance(point, gate.coordinates) }))
        .sort((a, b) => a.distance - b.distance)[0];

    return {
        isInside,
        distanceMeters: Number.isFinite(minDistance) ? minDistance : 0,
        nearestVertexIndex,
        nearestGate,
    };
}
