import type { PassCategory, QRShape } from './types';

/**
 * Client-side visual config for gate passes.
 *
 * The original CATEGORY_CONFIGS / CATEGORY_PALETTES / CATEGORY_POLICIES constants
 * lived in src/lib/gate-pass-engine/engine.ts alongside the signing key. That
 * whole module is now PHP (App\Services\GatePassEngine + config/gatepass.php).
 *
 * Rather than keeping a second copy of the palette data here — which would
 * silently drift from the PHP config — the server sends the visual half as page
 * props and the page hydrates this registry once on mount. Nothing secret and
 * nothing authoritative lives here: colour assignment, policy evaluation and
 * token signing all happen server-side.
 */

export type CategoryVisualConfig = {
    displayName: string;
    shape: QRShape;
    shapeLabel: string;
    themeColor: string;
    contrastBg: string;
    accentColor: string;
    badgeBorder: string;
    gradient: string;
    iconName: string;
    description: string;
};

export type ColorVariant = {
    id: string;
    name: string;
    hex: string;
    accentHex: string;
    contrastRatio: number;
    wcagPass: boolean;
};

export type OperationalHours = {
    is24Hours: boolean;
    startHour?: number;
    endHour?: number;
    daysOfWeek?: number[];
};

export type AccessPolicy = {
    title: string;
    description: string;
    authorizedZones: string[];
    allowedGates: string[];
    operationalHours: OperationalHours;
    privileges: {
        canManageGuests: boolean;
        canAssociateVehicles: boolean;
        hasEmergencyOverride: boolean;
        hasGateOperationOverride: boolean;
        restrictedFromHomeownerFunctions: boolean;
    };
};

export const PASS_CATEGORIES: PassCategory[] = [
    'SYSADMIN',
    'ADMIN',
    'HOMEOWNER',
    'RENTER',
    'STAFF',
    'SECURITY',
    'HOMEOWNER_STAFF',
    'VISITOR',
    'CONTRACTOR',
];

/**
 * Category-to-shape mapping.
 *
 * This is the one piece deliberately duplicated from PHP (PassCategory::shape()),
 * because a shape is a stable identity marker rather than styling — it must not
 * change, and rendering a placeholder shape before hydration would show the
 * wrong profile badge. GatePassEngineTest asserts these stay one-to-one.
 */
export const CATEGORY_SHAPES: Record<PassCategory, QRShape> = {
    SYSADMIN: 'STAR_8',
    ADMIN: 'OCTAGON',
    HOMEOWNER: 'HEXAGON',
    RENTER: 'ROUNDED_SQUARE',
    STAFF: 'DIAMOND',
    SECURITY: 'SHIELD',
    HOMEOWNER_STAFF: 'HOUSE_HEX',
    VISITOR: 'CIRCLE',
    CONTRACTOR: 'PENTAGON',
    DELEGATE: 'TICKET',
    LONG_TERM_OCCUPANT: 'ARCH',
};

/** Neutral styling used only if a component renders before hydration. */
const FALLBACK: Omit<CategoryVisualConfig, 'shape'> = {
    displayName: 'Community Member',
    shapeLabel: 'Profile Frame',
    themeColor: '#475569',
    contrastBg: '#1E293B',
    accentColor: '#94A3B8',
    badgeBorder: 'border-slate-500/40',
    gradient: 'from-slate-700 via-slate-800 to-slate-950',
    iconName: 'User',
    description: 'Loading credential profile…',
};

let registry: Partial<Record<PassCategory, CategoryVisualConfig>> = {};

/**
 * Loads the server's visual config. Call once per page that renders pass UI,
 * passing the `categoryConfigs` prop.
 */
export function hydrateCategoryConfigs(
    configs: Partial<Record<PassCategory, CategoryVisualConfig>> | undefined | null,
): void {
    if (configs) {
        registry = { ...registry, ...configs };
    }
}

/** Visual config for a category, falling back to neutral styling pre-hydration. */
export function getCategoryConfig(category: PassCategory): CategoryVisualConfig {
    return (
        registry[category] ?? {
            ...FALLBACK,
            shape: CATEGORY_SHAPES[category] ?? 'HEXAGON',
        }
    );
}

/** True once the server config has been loaded for this category. */
export function hasCategoryConfig(category: PassCategory): boolean {
    return registry[category] !== undefined;
}

/**
 * Formats a pass ID for display, mirroring GatePassEngine::formatPassId().
 * Display only — the server issues the real IDs.
 */
export function formatPassIdForDisplay(category: PassCategory, rawId: string): string {
    const prefixes: Record<PassCategory, string> = {
        SYSADMIN: 'GP-SYS',
        ADMIN: 'GP-ADM',
        HOMEOWNER: 'GP-HO',
        RENTER: 'GP-RNT',
        STAFF: 'GP-STF',
        SECURITY: 'GP-SEC',
        HOMEOWNER_STAFF: 'GP-HST',
        VISITOR: 'GP-VIS',
        CONTRACTOR: 'GP-CON',
        DELEGATE: 'GP-DEL',
        LONG_TERM_OCCUPANT: 'GP-LTO',
    };

    const digits = rawId.replace(/\D/g, '') || '0';

    return `${prefixes[category] ?? 'GP'}-${digits.padStart(4, '0')}`;
}

/** Human-readable operating window, e.g. "06:00 – 19:00, Mon–Sat" or "24 hours". */
export function describeHours(hours: OperationalHours | undefined): string {
    if (!hours || hours.is24Hours) {
        return '24 hours, every day';
    }

    const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const window =
        hours.startHour !== undefined && hours.endHour !== undefined
            ? `${String(hours.startHour).padStart(2, '0')}:00 – ${String(hours.endHour).padStart(2, '0')}:00`
            : 'Scheduled shifts';

    if (!hours.daysOfWeek?.length) {
        return window;
    }

    const days = hours.daysOfWeek.map((d) => dayNames[d]).filter(Boolean);
    const contiguous = hours.daysOfWeek.every(
        (d, i) => i === 0 || d === hours.daysOfWeek![i - 1] + 1,
    );

    return contiguous && days.length > 2
        ? `${window}, ${days[0]}–${days[days.length - 1]}`
        : `${window}, ${days.join(', ')}`;
}
