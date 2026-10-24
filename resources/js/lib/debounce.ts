/**
 * Trailing-edge debounce, keyed so unrelated callers do not cancel each other.
 *
 * The settings forms call their context setters on every keystroke and every
 * colour-picker movement. Against `localStorage` that cost nothing; against the
 * server it would be one PATCH per character typed into the community name, and
 * a burst of them while dragging a colour slider.
 *
 * Timers are module-level rather than per-hook because the context hooks are
 * re-created on every render — a ref inside the hook would be reset by the
 * re-render that each saved change triggers.
 */

const timers = new Map<string, ReturnType<typeof setTimeout>>();

export function debounceByKey(key: string, fn: () => void, waitMs = 600): void {
    const existing = timers.get(key);

    if (existing) {
        clearTimeout(existing);
    }

    timers.set(
        key,
        setTimeout(() => {
            timers.delete(key);
            fn();
        }, waitMs),
    );
}

/** Runs any pending call for a key immediately — e.g. on form submit or unmount. */
export function flushDebounced(key: string): void {
    const existing = timers.get(key);

    if (existing) {
        clearTimeout(existing);
        timers.delete(key);
    }
}

/** True when a save for this key is still waiting to fire. */
export function hasPendingDebounce(key: string): boolean {
    return timers.has(key);
}
