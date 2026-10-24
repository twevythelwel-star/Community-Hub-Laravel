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
 * Inertia-backed replacement for ThemeProvider.
 *
 * The original stored the palette in `localStorage['app-theme']`, so an
 * administrator customising the application's appearance changed it only in
 * their own browser — despite the Settings copy describing it as an estate-wide
 * setting. It now lives in `branding_settings.theme_tokens`, shared on every
 * Inertia response.
 *
 * Unlike the other ported contexts this one keeps real local state, for two
 * reasons that only appear once the store is remote:
 *
 *   1. ThemeCustomizer shows a live preview. Reading straight from page props
 *      would freeze that preview until the save round-tripped.
 *   2. It calls setTheme on every colour-input movement, so the save has to be
 *      debounced — and a debounced save that reads its base value from props
 *      would drop the earlier of two quick edits, because both would merge onto
 *      the same stale props value and only the last would be sent.
 *
 * So: state updates immediately, the server is written ~600ms later, and props
 * re-seed the state whenever the server's value actually changes.
 */

export type Theme = {
    primary: string;
    background: string;
    accent: string;
    font: string;
};

/** Enterprise high-contrast default. */
const DEFAULT_THEME: Theme = {
    primary: '#2563eb',    // Royal Blue
    background: '#f8fafc', // Clean Slate Light
    accent: '#0d9488',     // Teal
    font: 'Inter',
};

export const availableFonts = [
    'Inter',
    'PT Sans',
    'Roboto',
    'Open Sans',
    'Lato',
    'Montserrat',
];

type SharedProps = {
    branding?: { themeTokens?: Record<string, unknown> | null };
};

type ThemeContextValue = {
    theme: Theme;
    availableFonts: string[];
    setTheme: (theme: Theme) => void;
    resetTheme: () => void;
};

const ThemeContext = createContext<ThemeContextValue | undefined>(undefined);

/** Converts a hex colour to the `H S% L%` triple Tailwind's CSS variables expect. */
function hexToHsl(hex: string): string {
    let r = 0;
    let g = 0;
    let b = 0;

    if (hex.length === 4) {
        r = parseInt(hex[1] + hex[1], 16);
        g = parseInt(hex[2] + hex[2], 16);
        b = parseInt(hex[3] + hex[3], 16);
    } else if (hex.length === 7) {
        r = parseInt(hex.substring(1, 3), 16);
        g = parseInt(hex.substring(3, 5), 16);
        b = parseInt(hex.substring(5, 7), 16);
    }

    r /= 255;
    g /= 255;
    b /= 255;

    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    let h = 0;
    let s = 0;
    const l = (max + min) / 2;

    if (max !== min) {
        const d = max - min;
        s = l > 0.5 ? d / (2 - max - min) : d / (max + min);

        switch (max) {
            case r:
                h = (g - b) / d + (g < b ? 6 : 0);
                break;
            case g:
                h = (b - r) / d + 2;
                break;
            default:
                h = (r - g) / d + 4;
        }

        h /= 6;
    }

    return `${Math.round(h * 360)} ${Math.round(s * 100)}% ${Math.round(l * 100)}%`;
}

function readTheme(tokens: Record<string, unknown> | null | undefined): Theme {
    const t = (tokens ?? {}) as Partial<Record<keyof Theme, string>>;

    return {
        primary: typeof t.primary === 'string' ? t.primary : DEFAULT_THEME.primary,
        background: typeof t.background === 'string' ? t.background : DEFAULT_THEME.background,
        accent: typeof t.accent === 'string' ? t.accent : DEFAULT_THEME.accent,
        font: typeof t.font === 'string' ? t.font : DEFAULT_THEME.font,
    };
}

/**
 * Applies the theme to CSS custom properties, loads the chosen font, and holds
 * the optimistic state described above. Mounted once by DashboardLayout.
 */
export function ThemeProvider({ children }: { children: ReactNode }) {
    const page = usePage<SharedProps>();
    const tokens = page.props.branding?.themeTokens ?? {};
    const serverTheme = readTheme(tokens);

    const [theme, setLocalTheme] = useState<Theme>(serverTheme);

    // Re-seed when the server's value genuinely changes (another admin saved, or
    // our own save came back). Compared by value so an unrelated prop refresh
    // does not stomp an edit the user is still making.
    const serverKey = `${serverTheme.primary}|${serverTheme.background}|${serverTheme.accent}|${serverTheme.font}`;

    useEffect(() => {
        setLocalTheme(serverTheme);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [serverKey]);

    const setTheme = useCallback(
        (next: Theme) => {
            // Immediate for the preview…
            setLocalTheme(next);

            // …debounced for the server, so dragging a colour slider is one save.
            debounceByKey('theme', () => {
                router.patch(
                    '/dashboard/settings',
                    // Merged into the existing token blob so the branding preset
                    // and icon size written by useBranding() are not clobbered.
                    { theme_tokens: { ...tokens, ...next } },
                    { preserveScroll: true, preserveState: true },
                );
            });
        },
        [tokens],
    );

    const resetTheme = useCallback(() => setTheme(DEFAULT_THEME), [setTheme]);

    // ── Apply to the document ────────────────────────────────────────

    useEffect(() => {
        const root = document.documentElement;

        root.style.setProperty('--font-family', theme.font);

        if (theme.primary && theme.primary !== DEFAULT_THEME.primary) {
            root.style.setProperty('--primary', hexToHsl(theme.primary));
        }

        if (theme.accent && theme.accent !== DEFAULT_THEME.accent) {
            root.style.setProperty('--accent', hexToHsl(theme.accent));
        }

        /*
         * A customised light background must never survive into dark mode. The
         * original watched the class attribute for exactly this reason: the
         * inline --background/--card overrides would otherwise win over the
         * .dark rules and leave unreadable white panels.
         */
        const observer = new MutationObserver(() => {
            if (root.classList.contains('dark')) {
                root.style.removeProperty('--background');
                root.style.removeProperty('--card');
                root.style.removeProperty('--sidebar-background');
                root.style.removeProperty('--sidebar-accent');
            }
        });

        observer.observe(root, { attributes: true, attributeFilter: ['class'] });

        return () => observer.disconnect();
    }, [theme.font, theme.primary, theme.accent]);

    // Font loading, previously the FontLoader component in providers.tsx.
    useEffect(() => {
        if (!theme.font) return;

        const href = `https://fonts.googleapis.com/css2?family=${theme.font.replace(/ /g, '+')}:wght@400;700&display=swap`;
        let link = document.getElementById('app-font') as HTMLLinkElement | null;

        if (!link) {
            link = document.createElement('link');
            link.id = 'app-font';
            link.rel = 'stylesheet';
            document.head.appendChild(link);
        }

        link.href = href;
    }, [theme.font]);

    const value = useMemo(
        () => ({ theme, availableFonts, setTheme, resetTheme }),
        [theme, setTheme, resetTheme],
    );

    return <ThemeContext.Provider value={value}>{children}</ThemeContext.Provider>;
}

export function useTheme(): ThemeContextValue {
    const context = useContext(ThemeContext);

    if (context === undefined) {
        throw new Error('useTheme must be used within a ThemeProvider');
    }

    return context;
}
