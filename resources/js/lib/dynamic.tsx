import {
    Suspense,
    lazy,
    type ComponentType,
    type ReactNode,
} from 'react';

/**
 * Stand-in for `next/dynamic`.
 *
 * Three components lazy-load Leaflet this way, because Leaflet touches `window`
 * at import time and would crash during Next's server render. Inertia renders on
 * the client, so the `ssr: false` guard is no longer load-bearing — but the
 * code-splitting is still worth keeping: Leaflet plus react-leaflet is a large
 * dependency that only two pages need.
 *
 * React.lazy + Suspense gives the same deferred-import behaviour, so the call
 * sites keep working unchanged:
 *
 *   const Map = dynamic(() => import('./Map'), {
 *     ssr: false,
 *     loading: () => <Spinner />,
 *   });
 */

type DynamicOptions = {
    /**
     * Accepted for source compatibility with next/dynamic and ignored — there is
     * no server render to opt out of.
     */
    ssr?: boolean;
    /** Rendered while the chunk downloads. */
    loading?: () => ReactNode;
};

export default function dynamic<P extends object>(
    loader: () => Promise<{ default: ComponentType<P> }>,
    options: DynamicOptions = {},
): ComponentType<P> {
    const LazyComponent = lazy(loader);
    const Fallback = options.loading;

    function DynamicComponent(props: P) {
        /*
         | `lazy()` returns LazyExoticComponent<ComponentType<P>>, and TS cannot
         | prove an unconstrained generic P satisfies its PropsWithRef<P>
         | parameter at the spread. The cast is the standard escape hatch: the
         | props are the loader's own props by construction.
         */
        const Loaded = LazyComponent as unknown as ComponentType<P>;

        return (
            <Suspense fallback={Fallback ? <Fallback /> : null}>
                <Loaded {...props} />
            </Suspense>
        );
    }

    DynamicComponent.displayName = 'Dynamic';

    return DynamicComponent;
}
