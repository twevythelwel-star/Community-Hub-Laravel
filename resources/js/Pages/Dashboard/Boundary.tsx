import { Head } from '@inertiajs/react';
import DashboardLayout from '@/Layouts/DashboardLayout';
import BoundaryPointManager from '@/components/dashboard/boundary-point-manager';
import type { BoundaryConfig } from '@/lib/boundary-manager/types';
import { useAuth } from '@/context/auth-context';

/**
 * Community boundary editor.
 *
 * In the Next.js app the boundary editor was only ever a component rendered
 * inside the map page, and it persisted to localStorage. It now has its own
 * route backed by BoundaryController, so a published boundary is a versioned
 * server record shared by every resident.
 *
 * BoundaryPointManager now receives the server's draft, the published polygon
 * and the community metadata as props. Its own saves go to:
 *
 *   dashboard.boundary.draft     POST { points: [{label, lat, lng}], notes? }
 *   dashboard.boundary.publish   POST { points: [...], notes? }
 *   dashboard.boundary.validate  POST { points: [...] } -> validation result
 */
import { ChevronRight } from 'lucide-react';
import { Link } from '@inertiajs/react';

export type BoundaryPoint = {
    id: number;
    label: string;
    lat: number;
    lng: number;
    isOptional: boolean;
};

export type BoundaryValidation = {
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
};

export type BoundaryAuditEntry = {
    id: number;
    community: string;
    action: string;
    changedBy: string;
    role: string;
    previousVersion: number;
    newVersion: number;
    pointsCount: number;
    timestamp: string;
    published: boolean;
    notes: string | null;
    areaAcres: string | null;
    perimeterMeters: number | null;
};

export type BoundaryPreviousVersion = {
    id: number;
    version: number;
    status: string;
    pointsCount: number;
    publishedAt: string | null;
    publishedBy: string | null;
    points: BoundaryPoint[];
};

type Props = {
    community: {
        name: string;
        code: string;
        jurisdiction: string | null;
        datum: string | null;
        referenceCoord: {
            lat: number | null;
            lng: number | null;
            dms: string | null;
            description: string | null;
        };
        cadastralZone: string | null;
    };
    draft: {
        id: number;
        version: number;
        status: string;
        points: BoundaryPoint[];
    } | null;
    publishedCoordinates: [number, number][];
    validation: BoundaryValidation | null;
    auditHistory: BoundaryAuditEntry[];
    previousVersions?: BoundaryPreviousVersion[];
};

export default function Boundary({
    community,
    draft,
    publishedCoordinates,
    auditHistory,
    previousVersions = [],
}: Props) {
    const { can } = useAuth();

    return (
        <DashboardLayout>
            <Head title="Community Boundary" />

            <div className="space-y-6">
                {/* SysAdmin Breadcrumb Navigation */}
                <nav className="flex items-center gap-1.5 text-xs text-muted-foreground font-medium" aria-label="Breadcrumb">
                    <span className="text-primary font-bold">System Admin</span>
                    <ChevronRight className="w-3.5 h-3.5 text-muted-foreground/60" />
                    <span>Community Management</span>
                    <ChevronRight className="w-3.5 h-3.5 text-muted-foreground/60" />
                    <Link href="/map" className="hover:text-foreground transition-colors">
                        Community Map
                    </Link>
                    <ChevronRight className="w-3.5 h-3.5 text-muted-foreground/60" />
                    <span className="text-foreground font-semibold">Boundary Configuration</span>
                </nav>

                <header className="space-y-1">
                    <h1 className="text-2xl font-bold tracking-tight">Community Boundary</h1>
                    <p className="text-sm text-muted-foreground">
                        Define and publish the estate perimeter for {community.name}. Points 1–4 are
                        required; points 5–8 are optional. Minimum: 4, Maximum: 8.
                    </p>
                </header>

                {/*
                  BoundaryPointManager takes its state from the server. This
                  call site passed nothing, which type-checked as `{}` and would
                  have left `initialConfig` undefined at runtime.
                */}
                <BoundaryPointManager
                    initialConfig={{
                        version: draft?.version ?? 0,
                        status: (draft?.status as BoundaryConfig['status']) ?? 'DRAFT',
                        points: draft?.points ?? [],
                        publishedCoordinates,
                        auditHistory: auditHistory as unknown as BoundaryConfig['auditHistory'],
                    }}
                    community={{
                        name: community.name,
                        code: community.code,
                        jurisdiction: community.jurisdiction ?? '',
                        datum: community.datum ?? '',
                        referenceCoord: {
                            lat: community.referenceCoord.lat ?? 0,
                            lng: community.referenceCoord.lng ?? 0,
                            dms: community.referenceCoord.dms ?? '',
                            description: community.referenceCoord.description ?? '',
                        },
                        cadastralZone: community.cadastralZone ?? '',
                    }}
                    canManage={can.manageBoundary}
                    previousVersions={previousVersions}
                />
            </div>
        </DashboardLayout>
    );
}
