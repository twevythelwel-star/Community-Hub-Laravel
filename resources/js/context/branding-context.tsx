import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import { router, usePage } from '@inertiajs/react';
import { debounceByKey } from '@/lib/debounce';

/**
 * Inertia-backed replacement for BrandingProvider.
 *
 * Branding used to live in React state seeded from localStorage, so every
 * browser had its own copy: an administrator changing the estate name or palette
 * changed it only for themselves. It is now a `branding_settings` row shared on
 * every Inertia response, so a change applies to everyone.
 *
 * Like the theme context, this keeps optimistic local state. BrandingSettings
 * calls setBranding on every keystroke of the app and community name and on
 * every movement of the icon-size slider; writing straight through would be one
 * request per character, and debouncing without local state would both freeze
 * the inputs and drop edits that landed inside the same window.
 */

export type ThemePreset =
    | 'triovo'
    | 'classic'
    | 'ocean'
    | 'sunset'
    | 'brutalist'
    | 'emerald'
    | 'amethyst'
    | 'crimson'
    | 'dunes'
    | 'nordic'
    | 'sage'
    | 'copper'
    | 'rose'
    | 'slate'
    | 'espresso';

export type BrandingState = {
    appName: string;
    communityName: string;
    iconUrl: string | null;
    iconSize: number;
    themePreset: ThemePreset;           // Estate community preset
    userThemePreset: ThemePreset | null; // Personal user override (null = follow estate)
    activePreset: ThemePreset;           // Active computed preset
};

/**
 * Approved palettes, as HSL triples matching the CSS custom properties in
 * resources/css/app.css. Kept client-side because they are pure presentation and
 * are applied by writing CSS variables; the server stores only which preset is
 * selected, plus any explicit colour overrides.
 */
export const THEME_PRESETS: Record<
    ThemePreset,
    { primary: string; primaryForeground: string; accent: string; accentForeground: string }
> = {
    triovo: {
        primary: '221.2 83.2% 53.3%',     // Royal Sapphire Blue (#2563eb)
        primaryForeground: '210 40% 98%',  // Crisp White (8.6:1)
        accent: '160 84% 39%',             // Rich Emerald
        accentForeground: '0 0% 100%',
    },
    classic: {
        primary: '215 25% 27%',            // Deep Navy (#334155)
        primaryForeground: '210 40% 98%',  // Crisp White (9.5:1)
        accent: '221.2 83.2% 53.3%',
        accentForeground: '210 40% 98%',
    },
    ocean: {
        primary: '199 89% 48%',            // Marine Sky Blue (#0284c7)
        primaryForeground: '0 0% 100%',    // Pure White (4.8:1)
        accent: '173 80% 40%',             // Deep Sea Teal
        accentForeground: '0 0% 100%',
    },
    sunset: {
        primary: '16 90% 50%',             // Warm Terracotta (#ea580c)
        primaryForeground: '0 0% 100%',    // Pure White (5.1:1)
        accent: '38 92% 50%',              // Amber
        accentForeground: '222.2 84% 4.9%',
    },
    brutalist: {
        primary: '222.2 47.4% 11.2%',      // Obsidian Slate (#0f172a)
        primaryForeground: '210 40% 98%',  // Crisp White (15.5:1)
        accent: '215 16.3% 46.9%',         // Slate Gray
        accentForeground: '210 40% 98%',
    },
    emerald: {
        primary: '152 76% 36%',            // Forest Emerald Green (#14804a)
        primaryForeground: '210 40% 98%',  // Crisp White (6.5:1)
        accent: '43 86% 54%',              // Warm Gold Champagne
        accentForeground: '222.2 84% 4.9%',
    },
    amethyst: {
        primary: '262 83% 50%',            // Royal Velvet Purple (#7c3aed)
        primaryForeground: '210 40% 98%',  // Crisp White (6.2:1)
        accent: '336 80% 58%',             // Rose Quartz
        accentForeground: '0 0% 100%',
    },
    crimson: {
        primary: '346 84% 42%',            // Heritage Ruby Crimson (#c5113d)
        primaryForeground: '210 40% 98%',  // Crisp White (6.2:1)
        accent: '35 92% 51%',              // Polished Warm Amber
        accentForeground: '222.2 84% 4.9%',
    },
    dunes: {
        primary: '28 85% 44%',             // Caribbean Sand / Rich Cognac (#cc6214)
        primaryForeground: '210 40% 98%',  // Crisp White (5.5:1)
        accent: '174 78% 41%',             // Coastal Turquoise
        accentForeground: '0 0% 100%',
    },
    nordic: {
        primary: '205 65% 38%',            // Arctic Steel Blue (#22699f)
        primaryForeground: '210 40% 98%',  // Crisp White (6.1:1)
        accent: '188 86% 45%',             // Glacier Ice Cyan
        accentForeground: '222.2 84% 4.9%',
    },
    sage: {
        primary: '158 42% 30%',            // Deep Botanical Sage Olive (#2d6b4f)
        primaryForeground: '210 40% 98%',  // Crisp White (6.6:1)
        accent: '84 81% 44%',              // Lush Meadow Lime (#65a30d)
        accentForeground: '0 0% 100%',
    },
    copper: {
        primary: '24 80% 40%',             // Burnished Copper (#b84f14)
        primaryForeground: '210 40% 98%',  // Crisp White (5.5:1)
        accent: '178 84% 38%',             // Verdigris Patina Turquoise (#0f9f92)
        accentForeground: '0 0% 100%',
    },
    rose: {
        primary: '338 76% 42%',            // Imperial Velvet Rose (#be185d)
        primaryForeground: '210 40% 98%',  // Crisp White (5.8:1)
        accent: '42 90% 55%',              // Champagne Gold (#eab308)
        accentForeground: '222.2 84% 4.9%',
    },
    slate: {
        primary: '218 36% 22%',            // Deep Slate Graphite (#232f3e)
        primaryForeground: '210 40% 98%',  // Crisp White (10.4:1)
        accent: '217 91% 60%',             // Vibrant Electric Cobalt (#3b82f6)
        accentForeground: '210 40% 98%',
    },
    espresso: {
        primary: '25 50% 20%',             // Dark Roast Espresso (#4c2b19)
        primaryForeground: '210 40% 98%',  // Crisp White (10.8:1)
        accent: '36 96% 53%',              // Warm Toffee Amber (#f59e0b)
        accentForeground: '222.2 84% 4.9%',
    },
};

const DEFAULT_BRANDING: BrandingState = {
    appName: 'Community Hub',
    communityName: 'THE OAKS',
    iconUrl: null,
    iconSize: 24,
    themePreset: 'triovo',
    userThemePreset: null,
    activePreset: 'triovo',
};

/** Shape shared by HandleInertiaRequests::branding(). */
type SharedBranding = {
    appName?: string;
    communityName?: string;
    logoUrl?: string | null;
    primaryColor?: string | null;
    accentColor?: string | null;
    backgroundColor?: string | null;
    defaultTheme?: string;
    themeTokens?: Record<string, unknown> | null;
    userThemePreset?: string | null;
};

type SharedProps = { branding?: SharedBranding };

type BrandingContextValue = {
    branding: BrandingState;
    setBranding: (updates: Partial<BrandingState>) => void;
    setUserThemePreset: (preset: ThemePreset | null) => void;
    resetBranding: () => void;
};

const BrandingContext = createContext<BrandingContextValue | undefined>(undefined);

function isThemePreset(value: unknown): value is ThemePreset {
    return typeof value === 'string' && value in THEME_PRESETS;
}

function readBranding(shared: SharedBranding | undefined): BrandingState {
    const tokens = (shared?.themeTokens ?? {}) as Record<string, unknown>;
    const communityPreset = isThemePreset(tokens.themePreset)
        ? tokens.themePreset
        : DEFAULT_BRANDING.themePreset;

    // The server is the only source: a localStorage copy outlived sign-out,
    // so the next account on a shared device inherited the previous theme.
    const userPreset: ThemePreset | null = isThemePreset(shared?.userThemePreset)
        ? shared.userThemePreset
        : null;

    return {
        appName: shared?.appName || DEFAULT_BRANDING.appName,
        communityName:
            typeof shared?.communityName === 'string' && shared.communityName
                ? shared.communityName
                : typeof tokens.communityName === 'string'
                    ? tokens.communityName
                    : DEFAULT_BRANDING.communityName,
        iconUrl: shared?.logoUrl ?? DEFAULT_BRANDING.iconUrl,
        iconSize: typeof tokens.iconSize === 'number' ? tokens.iconSize : DEFAULT_BRANDING.iconSize,
        themePreset: communityPreset,
        userThemePreset: userPreset,
        activePreset: userPreset ?? communityPreset,
    };
}

export function BrandingProvider({ children }: { children: ReactNode }) {
    const page = usePage<SharedProps>();
    const shared = page.props.branding;
    const serverBranding = readBranding(shared);

    const [branding, setLocalBranding] = useState<BrandingState>(serverBranding);

    // Re-seed when the server's value genuinely changes, compared by value so an
    // unrelated prop refresh does not stomp an edit in progress.
    const serverKey = [
        serverBranding.appName,
        serverBranding.communityName,
        serverBranding.iconUrl,
        serverBranding.iconSize,
        serverBranding.themePreset,
    ].join('|');

    useEffect(() => {
        setLocalBranding(serverBranding);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [serverKey]);

    const setBranding = useCallback(
        (updates: Partial<BrandingState>) => {
            setLocalBranding((current) => {
                const next = { ...current, ...updates };

                debounceByKey('branding', () => {
                    router.patch(
                        '/dashboard/settings',
                        {
                            app_name: next.appName,
                            community_name: next.communityName,
                            logo_url: next.iconUrl,
                            // Preset, icon size and community name are
                            // presentation-only, so they ride in theme_tokens.
                            theme_tokens: {
                                ...((shared?.themeTokens ?? {}) as Record<string, unknown>),
                                themePreset: next.themePreset,
                                iconSize: next.iconSize,
                                communityName: next.communityName,
                            },
                        },
                        { preserveScroll: true, preserveState: true },
                    );
                });

                return next;
            });
        },
        [shared?.themeTokens],
    );

    const setUserThemePreset = useCallback((preset: ThemePreset | null) => {
        setLocalBranding((current) => ({
            ...current,
            userThemePreset: preset,
            activePreset: preset ?? current.themePreset,
        }));

        debounceByKey('user_theme_preset', () => {
            router.post(
                '/dashboard/profile/theme-preset',
                { theme_preset: preset ?? 'default' },
                { preserveScroll: true, preserveState: true },
            );
        });
    }, []);

    const resetBranding = useCallback(() => setBranding(DEFAULT_BRANDING), [setBranding]);

    // Apply the preset's palette to CSS custom properties.
    useEffect(() => {
        const activeKey = branding.activePreset || branding.themePreset;
        const preset = THEME_PRESETS[activeKey] ?? THEME_PRESETS.triovo;
        const root = document.documentElement;

        root.style.setProperty('--primary', preset.primary);
        root.style.setProperty('--primary-foreground', preset.primaryForeground);
        root.style.setProperty('--accent', preset.accent);
        root.style.setProperty('--accent-foreground', preset.accentForeground);

        // Explicit estate colour overrides apply if the user hasn't selected their own personal preset
        if (!branding.userThemePreset) {
            if (shared?.primaryColor) root.style.setProperty('--brand-primary', shared.primaryColor);
            if (shared?.accentColor) root.style.setProperty('--brand-accent', shared.accentColor);
            if (shared?.backgroundColor) {
                root.style.setProperty('--brand-background', shared.backgroundColor);
            }
        } else {
            root.style.removeProperty('--brand-primary');
            root.style.removeProperty('--brand-accent');
            root.style.removeProperty('--brand-background');
        }
    }, [
        branding.activePreset,
        branding.themePreset,
        branding.userThemePreset,
        shared?.primaryColor,
        shared?.accentColor,
        shared?.backgroundColor,
    ]);

    const value = useMemo(
        () => ({ branding, setBranding, setUserThemePreset, resetBranding }),
        [branding, setBranding, setUserThemePreset, resetBranding],
    );

    return <BrandingContext.Provider value={value}>{children}</BrandingContext.Provider>;
}

export function useBranding(): BrandingContextValue {
    const context = useContext(BrandingContext);

    if (context === undefined) {
        throw new Error('useBranding must be used within a BrandingProvider');
    }

    return context;
}
