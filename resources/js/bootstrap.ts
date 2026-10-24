import axios from 'axios';

declare global {
    interface Window {
        axios: typeof axios;
    }
}

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

// Laravel issues the CSRF token in a meta tag; attach it to every request so
// the JSON endpoints (gate-pass token polling, scanning, geofence checks) pass
// the same CSRF check as Inertia form submissions.
const token = document.head.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');

if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token.content;
}

window.axios.defaults.withCredentials = true;

// Initialize Laravel Echo real-time WebSockets
import './echo';
