import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'>;
    }
}

// This module is also imported by the SSR bundle (via app.tsx), which runs
// in Node.js and has no `window` — Echo only makes sense in a real browser.
if (typeof window !== 'undefined') {
    window.Pusher = Pusher;

    window.Echo = new Echo({
        broadcaster: 'reverb',
        key: import.meta.env.VITE_REVERB_APP_KEY,
        // Reverb is always reachable on the same domain the page was loaded
        // from (proxied via a path rule, not a separate subdomain), so this
        // avoids baking in a specific hostname that has to match prod's
        // apex-vs-www setup.
        wsHost: window.location.hostname,
        wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
        wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });
}
