import { useCallback, useEffect, useRef } from 'react';
import axios from 'axios';

/** How long the QR code is held to raise silent assistance (config/safety.php `qr_hold_seconds`). */
export const DEFAULT_HOLD_MS = 3000;

/**
 * The resident's location, but only if the browser already allows it.
 * Asking would put a permission prompt on screen, which is exactly what the
 * discreet path must not do.
 */
async function silentLocation(): Promise<{ latitude?: number; longitude?: number }> {
  try {
    if (!('geolocation' in navigator) || !navigator.permissions) return {};
    const state = await navigator.permissions.query({ name: 'geolocation' as PermissionName });
    if (state.state !== 'granted') return {};

    return await new Promise((resolve) => {
      navigator.geolocation.getCurrentPosition(
        (pos) => resolve({ latitude: pos.coords.latitude, longitude: pos.coords.longitude }),
        () => resolve({}),
        { maximumAge: 60_000, timeout: 4_000 },
      );
    });
  } catch {
    return {};
  }
}

/**
 * Raises silent assistance without anything on screen changing: no toast, no
 * vibration, no visible state. Failures are swallowed for the same reason.
 */
export async function sendSilentHold(): Promise<void> {
  try {
    await axios.post('/dashboard/safety/hold', await silentLocation());
  } catch {
    // Deliberately silent.
  }
}

/**
 * Pointer handlers for the discreet QR gesture: holding for `holdMs` raises
 * silent assistance once. Releasing early, moving off or cancelling does
 * nothing. The long-press context menu is suppressed so nothing pops up.
 */
export function useDiscreetHold(enabled: boolean, holdMs: number = DEFAULT_HOLD_MS) {
  const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

  const cancel = useCallback(() => {
    if (timer.current) {
      clearTimeout(timer.current);
      timer.current = null;
    }
  }, []);

  useEffect(() => cancel, [cancel]);

  const start = useCallback(() => {
    if (!enabled) return;
    cancel();
    timer.current = setTimeout(() => {
      timer.current = null;
      void sendSilentHold();
    }, holdMs);
  }, [enabled, holdMs, cancel]);

  return {
    onPointerDown: start,
    onPointerUp: cancel,
    onPointerLeave: cancel,
    onPointerCancel: cancel,
    onContextMenu: (e: React.MouseEvent) => {
      if (enabled) e.preventDefault();
    },
  };
}
