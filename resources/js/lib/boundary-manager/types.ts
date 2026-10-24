/**
 * Boundary editor types.
 *
 * Unchanged from the original module — these are pure type declarations shared
 * between the editor UI and the server payloads. The behaviour that used to sit
 * alongside them (localStorage load/save, hardcoded fixtures) is gone; see
 * ./service.ts.
 */

export interface BoundaryPoint {
    /** 1 to 8. Points 1-4 are mandatory. */
    id: number;
    label: string;
    lat: number;
    lng: number;
    isOptional: boolean;
}

export interface CommunityInfo {
    name: string;
    code: string;
    jurisdiction: string;
    datum: string;
    referenceCoord: {
        lat: number;
        lng: number;
        dms: string;
        description: string;
    };
    cadastralZone: string;
}

export interface BoundaryValidationResult {
    isValid: boolean;
    canPublish: boolean;
    pointCount: number;
    errors: string[];
    warnings: string[];
    areaAcres: string;
    areaHectares: string;
    areaSqMeters: number;
    perimeterMeters: number;
    center: [number, number];
}

export interface BoundaryAuditLogEntry {
    id: string | number;
    community: string;
    action:
        | 'Boundary Published'
        | 'Boundary Updated'
        | 'Point Added'
        | 'Point Removed'
        | 'Draft Saved';
    changedBy: string;
    role: string;
    previousVersion: number;
    newVersion: number;
    pointsCount: number;
    timestamp: string;
    published: boolean;
    notes?: string | null;
    areaAcres: string | null;
    perimeterMeters: number | null;
}

export interface BoundaryConfig {
    version: number;
    status: 'DRAFT' | 'PUBLISHED' | 'SUPERSEDED';
    points: BoundaryPoint[];
    publishedCoordinates: [number, number][];
    lastPublishedAt?: string | null;
    lastPublishedBy?: string | null;
    auditHistory: BoundaryAuditLogEntry[];
}
