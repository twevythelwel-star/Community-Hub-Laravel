import { router, usePage } from '@inertiajs/react';
import type { UserRole } from '@/types';

/**
 * Inertia-backed replacement for the original AuthProvider.
 *
 * The old context (src/context/auth-context.tsx) kept the signed-in user in
 * React state, hydrated it from localStorage, and matched login attempts against
 * a hardcoded object while ignoring the password. There is no client-side user
 * state any more: the server shares the authenticated user on every Inertia
 * response via App\Http\Middleware\HandleInertiaRequests, and this hook reads it.
 *
 * The `useAuth()` signature is kept so the 26 components that already call it
 * need no changes.
 */

export type User = {
    uid: string;
    /** Legal name — not user-editable, matching the original contract. */
    name: string;
    displayName: string;
    email: string;
    phone: string | null;
    role: UserRole;
    lot?: string | null;
    street?: string | null;
    title?: string | null;
    avatarUrl?: string | null;
    aiConsent: boolean;
};

/**
 * Server-evaluated permissions. These come from the gates in
 * App\Providers\AuthServiceProvider, so the menu and the API agree on what a
 * role may do — the original checked `user.role === 'Admin'` inline in JSX,
 * which only hid the UI.
 */
export type Permissions = {
    manageUsers: boolean;
    manageSecurity: boolean;
    scanPasses: boolean;
    manageBoundary: boolean;
    manageBilling: boolean;
    reviewFeedback: boolean;
    manageBlocklist: boolean;
    broadcastNotices: boolean;
    manageFundraisers: boolean;
    registerVisitors: boolean;
    registerStaff: boolean;
};

export type SharedAuth = {
    user: User | null;
    can: Partial<Permissions>;
    justSignedIn?: boolean;
};

type SharedProps = {
    auth?: SharedAuth;
};

const NO_PERMISSIONS: Permissions = {
    manageUsers: false,
    manageSecurity: false,
    scanPasses: false,
    manageBoundary: false,
    manageBilling: false,
    reviewFeedback: false,
    manageBlocklist: false,
    broadcastNotices: false,
    manageFundraisers: false,
    registerVisitors: false,
    registerStaff: false,
};

export function useAuth() {
    // `props.auth` is absent on pages rendered before the middleware shares it
    // (and in isolated component tests), so every read is defensive.
    const page = usePage<SharedProps>();
    const auth = page.props.auth;

    const user = auth?.user ?? null;
    const can: Permissions = { ...NO_PERMISSIONS, ...(auth?.can ?? {}) };
    const justSignedIn = Boolean(auth?.justSignedIn);

    return {
        user,
        can,
        justSignedIn,

        /** Retained for call-site compatibility; Inertia resolves props before render. */
        loading: false,

        /** True when the viewer holds any of the given roles. */
        hasRole: (...roles: UserRole[]) => (user ? roles.includes(user.role) : false),

        logout: () => {
            router.post('/logout', {}, {
                onFinish: () => {
                    window.location.href = '/';
                },
                onError: () => {
                    // /logout is POST-only (a GET could be fired by any page linking
                    // to it), so the fallback is a plain form post, not a navigation.
                    const form = document.createElement('form');
                    form.method = 'POST';
                    form.action = '/logout';
                    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
                    if (token) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = '_token';
                        input.value = token;
                        form.appendChild(input);
                    }
                    document.body.appendChild(form);
                    form.submit();
                },
            });
        },

        /**
         * Persists a profile change. The original mutated React state and wrote
         * to localStorage, so edits were per-browser and never reached the server.
         */
        updateUser: (updates: Partial<Pick<User, 'displayName' | 'phone'>>) =>
            router.patch('/dashboard/profile', {
                display_name: updates.displayName,
                phone: updates.phone,
            }, { preserveScroll: true }),
    };
}
