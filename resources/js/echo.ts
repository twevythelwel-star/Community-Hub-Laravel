import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo?: Echo<'reverb'>;
    }
}

window.Pusher = Pusher;

/**
 * Enterprise Laravel Echo Client Configuration for Laravel Reverb.
 *
 * Automatically connects to the local or cloud Reverb WebSocket infrastructure
 * using Pusher protocol and handles public, private, and presence channels.
 *
 * Only when VITE_REVERB_APP_KEY is set: there is no fallback key, and
 * Pusher throws on construction without one. This module loads on every page,
 * so an unconfigured build must leave realtime off rather than break the page.
 * Callers check for `echo` (or `window.Echo`) before subscribing.
 */
const key = import.meta.env.VITE_REVERB_APP_KEY;

export const echo: Echo<'reverb'> | null = key
    ? new Echo({
          broadcaster: 'reverb',
          key,
          wsHost: import.meta.env.VITE_REVERB_HOST ?? window.location.hostname,
          wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
          wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
          forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https',
          enabledTransports: ['ws', 'wss'],
      })
    : null;

if (echo) {
    window.Echo = echo;
}

export default echo;
