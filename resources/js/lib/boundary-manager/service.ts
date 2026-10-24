import { router } from '@inertiajs/react';
import { haversineDistance } from '@/lib/geofence-utils';
import { debounceByKey } from '@/lib/debounce';
import type { BoundaryPoint, BoundaryValidationResult } from './types';

/**
 * Boundary editor helpers.
 *
 * What survived from the original module, and why:
 *
 *   - The coordinate parsers. Turning whatever an administrator pasted — a
 *     Google Maps URL, a DMS string, a bulk list — into numbers is input
 *     handling, and belongs next to the input.
 *   - `validateBoundary`, for live feedback while editing. The server runs the
 *     same checks in GeofenceService::validateBoundary() and its verdict is the
 *     one that counts; this only avoids a round-trip per keystroke.
 *
 * What was removed:
 *
 *   - `loadBoundaryConfig` / `saveBoundaryConfig`, which read and wrote
 *     `localStorage['community-boundary-config-v2']`. A boundary saved there was
 *     visible to exactly one browser, so "publishing" changed nothing for anyone
 *     else. Replaced by `saveDraft` / `publish` below.
 *   - `COMMUNITY_INFO` and `INITIAL_AUDIT_LOG`, which were hardcoded fixtures.
 *     Both now come from the server.
 *   - `INITIAL_BOUNDARY_POINTS` survives, but demoted: it was the value the
 *     editor booted from, which made a template look like the estate's real
 *     boundary. It is now only a preset an administrator can choose.
 */

/**
 * Starting shape offered by the editor's "load preset" control.
 *
 * Retained from the original `INITIAL_BOUNDARY_POINTS`, but its role has
 * changed: it used to be the seed value the editor booted from when
 * localStorage was empty, which made it look like real estate data. It is now
 * only a template an administrator can choose to start from — the actual
 * boundary always comes from the server.
 */
export const BOUNDARY_PRESET_MONTEGO: BoundaryPoint[] = [
    { id: 1, label: 'Point 1', lat: 18.476861, lng: -77.926028, isOptional: false },
    { id: 2, label: 'Point 2', lat: 18.478350, lng: -77.927200, isOptional: false },
    { id: 3, label: 'Point 3', lat: 18.479100, lng: -77.924800, isOptional: false },
    { id: 4, label: 'Point 4', lat: 18.478400, lng: -77.923200, isOptional: false },
    { id: 5, label: 'Point 5', lat: 18.475800, lng: -77.923500, isOptional: true },
    { id: 6, label: 'Point 6', lat: 18.474900, lng: -77.925500, isOptional: true },
];

/** Back-compat alias for the editor's existing preset handler. */
export const INITIAL_BOUNDARY_POINTS = BOUNDARY_PRESET_MONTEGO;

// ── Parsing ──────────────────────────────────────────────────────────

/** Parses a single DMS component, e.g. `18°28'36.7"N`, to decimal degrees. */
export function parseSingleDMS(dmsStr: string): number | null {
    if (!dmsStr) return null;

    const clean = dmsStr.trim().replace(/[°'"]/g, ' ').replace(/\s+/g, ' ');
    const match = clean.match(
        /^(-?\d+(?:\.\d+)?)\s*(?:(\d+(?:\.\d+)?))?\s*(?:(\d+(?:\.\d+)?))?\s*([NSEW])?$/i,
    );

    if (!match) return null;

    const degrees = parseFloat(match[1]);
    const minutes = match[2] ? parseFloat(match[2]) : 0;
    const seconds = match[3] ? parseFloat(match[3]) : 0;
    const dir = match[4] ? match[4].toUpperCase() : null;

    if (Number.isNaN(degrees)) return null;

    let decimal = Math.abs(degrees) + minutes / 60 + seconds / 3600;

    if (degrees < 0 || dir === 'S' || dir === 'W') {
        decimal = -decimal;
    }

    return +decimal.toFixed(6);
}

/**
 * Parses one coordinate from free text. Accepts decimal pairs, space-separated
 * pairs, DMS, and Google Maps URLs (`.../@18.44976,-77.9144841,15.96z`).
 */
export function parseCoordinateString(input: string): { lat: number; lng: number } | null {
    if (!input || !input.trim()) return null;

    let trimmed = input.trim();

    // Strip a Google Maps URL prefix or a bare "@" prefix.
    if (trimmed.includes('@')) {
        trimmed = trimmed.substring(trimmed.indexOf('@') + 1);
    }

    // Drop a trailing zoom parameter such as ",15.96z" and anything after it.
    trimmed = trimmed.replace(/,\s*\d+(?:\.\d+)?z.*$/i, '');

    const inRange = (lat: number, lng: number) =>
        !Number.isNaN(lat) && !Number.isNaN(lng)
        && lat >= -90 && lat <= 90
        && lng >= -180 && lng <= 180;

    // "18.476861, -77.926028"
    if (trimmed.includes(',')) {
        const parts = trimmed.split(',');

        if (parts.length >= 2) {
            const lat = parseFloat(parts[0].trim());
            const lng = parseFloat(parts[1].trim());

            if (inRange(lat, lng)) {
                return { lat: +lat.toFixed(6), lng: +lng.toFixed(6) };
            }
        }
    }

    // "18.476861 -77.926028"
    const spaceParts = trimmed.split(/\s+/);

    if (spaceParts.length >= 2) {
        const lat = parseFloat(spaceParts[0]);
        const lng = parseFloat(spaceParts[1]);

        if (inRange(lat, lng)) {
            return { lat: +lat.toFixed(6), lng: +lng.toFixed(6) };
        }
    }

    // `18°28'36.7"N 77°55'33.7"W`
    const dmsMatch = trimmed.match(
        /(\d+°\d+['′][\d.]+["″]?\s*[NS])[,|\s]+(\d+°\d+['′][\d.]+["″]?\s*[EW])/i,
    );

    if (dmsMatch) {
        const lat = parseSingleDMS(dmsMatch[1]);
        const lng = parseSingleDMS(dmsMatch[2]);

        if (lat !== null && lng !== null) return { lat, lng };
    }

    // Looser N/S + E/W pattern.
    const nsMatch = trimmed.match(
        /([\d.]+\s*(?:°|deg)?\s*(?:[\d.]+\s*['m′]?)?\s*(?:[\d.]+\s*["s″]?)?\s*[NS])/i,
    );
    const ewMatch = trimmed.match(
        /([\d.]+\s*(?:°|deg)?\s*(?:[\d.]+\s*['m′]?)?\s*(?:[\d.]+\s*["s″]?)?\s*[EW])/i,
    );

    if (nsMatch && ewMatch) {
        const lat = parseSingleDMS(nsMatch[1]);
        const lng = parseSingleDMS(ewMatch[1]);

        if (lat !== null && lng !== null) return { lat, lng };
    }

    return null;
}

/** Parses a pasted block of coordinates, one per line, into up to 8 points. */
export function parseBulkCoordinates(text: string): {
    points: BoundaryPoint[];
    errors: string[];
} {
    const lines = text.split(/\r?\n/).map((l) => l.trim()).filter(Boolean);
    const points: BoundaryPoint[] = [];
    const errors: string[] = [];

    lines.forEach((line, index) => {
        // Strip prefixes like "Point 1:", "P1:", "1." or "1 -".
        const cleanLine = line.replace(/^(?:Point\s*\d+|P\d+|\d+)\s*[:.-]\s*/i, '').trim();
        const parsed = parseCoordinateString(cleanLine);

        if (!parsed) {
            errors.push(`Line ${index + 1} ("${line}"): Could not recognize coordinate format.`);
            return;
        }

        if (points.length < 8) {
            const id = points.length + 1;

            points.push({
                id,
                label: `Point ${id}`,
                lat: parsed.lat,
                lng: parsed.lng,
                isOptional: id > 4,
            });
        }
    });

    if (lines.length > 8) {
        errors.push(`Only the first 8 recognised points were kept; ${lines.length} lines were supplied.`);
    }

    return { points, errors };
}

// ── Validation (live feedback; the server decides) ───────────────────

function doSegmentsIntersect(
    p1: [number, number],
    p2: [number, number],
    p3: [number, number],
    p4: [number, number],
): boolean {
    const ccw = (a: [number, number], b: [number, number], c: [number, number]) =>
        (c[1] - a[1]) * (b[0] - a[0]) > (b[1] - a[1]) * (c[0] - a[0]);

    return ccw(p1, p3, p4) !== ccw(p2, p3, p4) && ccw(p1, p2, p3) !== ccw(p1, p2, p4);
}

export function validateBoundary(points: BoundaryPoint[]): BoundaryValidationResult {
    const errors: string[] = [];
    const warnings: string[] = [];
    const pointCount = points.length;

    // 1. Point count: 4 to 8.
    if (pointCount < 4) {
        errors.push(
            `Requires 4–8 points (currently ${pointCount}). At least 4 points are needed to form a usable community polygon.`,
        );
    } else if (pointCount > 8) {
        errors.push(`Maximum 8 points allowed (currently ${pointCount}). 9+ points cannot be added.`);
    }

    // 2. Coordinate ranges.
    points.forEach((pt) => {
        if (Number.isNaN(pt.lat) || pt.lat < -90 || pt.lat > 90) {
            errors.push(`${pt.label}: Latitude must be between -90° and 90° (received ${pt.lat}).`);
        }

        if (Number.isNaN(pt.lng) || pt.lng < -180 || pt.lng > 180) {
            errors.push(`${pt.label}: Longitude must be between -180° and 180° (received ${pt.lng}).`);
        }
    });

    // 3. Vertices that would collapse together.
    for (let i = 0; i < pointCount; i++) {
        for (let j = i + 1; j < pointCount; j++) {
            const dist = haversineDistance(
                [points[i].lat, points[i].lng],
                [points[j].lat, points[j].lng],
            );

            if (dist < 2) {
                warnings.push(
                    `${points[i].label} and ${points[j].label} are within 2m of each other; may cause vertex collapse.`,
                );
            }
        }
    }

    // 4. Self-intersection. A bow-tie has no well-defined inside, so
    //    point-in-polygon — and therefore any access decision — is arbitrary.
    if (pointCount >= 4 && errors.length === 0) {
        const coords: [number, number][] = points.map((p) => [p.lat, p.lng]);
        const n = coords.length;

        outer: for (let i = 0; i < n; i++) {
            for (let j = i + 1; j < n; j++) {
                // Adjacent edges share a vertex; edge 0 meets the closing edge.
                if (Math.abs(i - j) <= 1 || (i === 0 && j === n - 1)) continue;

                if (doSegmentsIntersect(coords[i], coords[(i + 1) % n], coords[j], coords[(j + 1) % n])) {
                    errors.push(
                        `Boundary self-intersects between ${points[i].label}–${points[(i + 1) % n].label} and ${points[j].label}–${points[(j + 1) % n].label}. Boundary must form a non-crossing perimeter.`,
                    );
                    break outer;
                }
            }
        }
    }

    // 5. Area, perimeter and centroid.
    let center: [number, number] = [18.476861, -77.926028];
    let areaSqMeters = 0;
    let perimeterMeters = 0;

    if (pointCount > 0) {
        center = [
            points.reduce((sum, p) => sum + p.lat, 0) / pointCount,
            points.reduce((sum, p) => sum + p.lng, 0) / pointCount,
        ];
    }

    if (pointCount >= 3) {
        let area = 0;
        const latScale = Math.cos((center[0] * Math.PI) / 180);

        for (let i = 0; i < pointCount; i++) {
            const p1 = points[i];
            const p2 = points[(i + 1) % pointCount];

            perimeterMeters += haversineDistance([p1.lat, p1.lng], [p2.lat, p2.lng]);

            const x1 = (p1.lng - center[1]) * 111320 * latScale;
            const y1 = (p1.lat - center[0]) * 110574;
            const x2 = (p2.lng - center[1]) * 111320 * latScale;
            const y2 = (p2.lat - center[0]) * 110574;

            area += x1 * y2 - x2 * y1;
        }

        areaSqMeters = Math.abs(area) / 2;
        perimeterMeters = Math.round(perimeterMeters);
    }

    const isValid = errors.length === 0;

    return {
        isValid,
        canPublish: isValid && pointCount >= 4 && pointCount <= 8,
        pointCount,
        errors,
        warnings,
        areaAcres: (areaSqMeters * 0.000247105).toFixed(2),
        areaHectares: (areaSqMeters * 0.0001).toFixed(2),
        areaSqMeters: Math.round(areaSqMeters),
        perimeterMeters,
        center,
    };
}

// ── Persistence (server) ─────────────────────────────────────────────

type SaveOptions = {
    notes?: string;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

function toPayload(points: BoundaryPoint[], notes?: string) {
    return {
        points: points.map((p) => ({ label: p.label, lat: p.lat, lng: p.lng })),
        notes,
    };
}

/**
 * Saves a working draft. Visible to other administrators but not yet in force
 * at the gates — only `publish` changes what residents and guards see.
 */
export function saveDraft(points: BoundaryPoint[], options: SaveOptions = {}): void {
    router.post('/dashboard/map/boundary/draft', toPayload(points, options.notes), {
        preserveScroll: true,
        onSuccess: options.onSuccess,
        onError: options.onError,
    });
}

/** Publishes a new boundary version for the whole community. */
export function publishBoundary(points: BoundaryPoint[], options: SaveOptions = {}): void {
    router.post('/dashboard/map/boundary/publish', toPayload(points, options.notes), {
        preserveScroll: true,
        onSuccess: options.onSuccess,
        onError: options.onError,
    });
}

/**
 * Debounced draft save, for use during editing.
 *
 * The editor persisted on every keystroke, every drag frame and every point
 * add or remove. Writing to localStorage that often cost nothing; issuing a
 * POST that often would flood the server and race its own responses. The draft
 * is saved once the administrator pauses instead.
 *
 * A draft is not in force at the gates — only `publishBoundary` changes what
 * residents and guards see — so deferring it by a second is harmless.
 */
export function saveDraftDebounced(points: BoundaryPoint[], waitMs = 1000): void {
    // Points below the publishable minimum are still worth keeping as a draft,
    // but the server rejects fewer than 4, so skip the round-trip.
    if (points.length < 4) return;

    debounceByKey('boundary-draft', () => saveDraft(points), waitMs);
}
