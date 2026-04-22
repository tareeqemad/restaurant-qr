import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/* ─────────────────────────────────────────────────────────────────────
   Real-time strategy
   ─────────────────────────────────────────────────────────────────────
   We support two modes, controlled by VITE_REVERB_ENABLED in .env:

     • VITE_REVERB_ENABLED=true   → connect to Reverb websocket server
                                    for instant updates (requires running
                                    `php artisan reverb:start`).
     • VITE_REVERB_ENABLED=false  → no websocket. Livewire's `wire:poll`
                                    on the Kitchen/Cashier/Tables boards
                                    refreshes them every 5-15s. Good
                                    enough for a restaurant workflow and
                                    zero infrastructure to maintain.

   Either way, `window.Echo` is always defined so existing
   `Echo.channel('x').listen(...)` calls never throw.
   ───────────────────────────────────────────────────────────────────── */

const reverbEnabled = (import.meta.env.VITE_REVERB_ENABLED ?? 'false') === 'true';

// A no-op stub that mimics Echo's chainable API without doing any network work.
// Covers every method Livewire/user code might call so nothing throws.
function makeEchoStub() {
    const fakeChannel = {
        listen:        () => fakeChannel,
        listenToAll:   () => fakeChannel,
        notification:  () => fakeChannel,
        stopListening: () => fakeChannel,
        subscribed:    () => fakeChannel,
        error:         () => fakeChannel,
        here:          () => fakeChannel,
        joining:       () => fakeChannel,
        leaving:       () => fakeChannel,
        whisper:       () => fakeChannel,
        listenForWhisper: () => fakeChannel,
    };
    return {
        channel:      () => fakeChannel,
        private:      () => fakeChannel,
        encrypted:    () => fakeChannel,
        join:         () => fakeChannel,
        leave:        () => {},
        leaveChannel: () => {},
        leaveAllChannels: () => {},
        // Livewire reads this to attach X-Socket-ID to requests (so broadcasts
        // can skip echoing back to the originator). Null = "no socket".
        socketId:     () => null,
        // Livewire also probes .connector in some paths — return a minimal obj
        connector:    { pusher: { connection: { bind: () => {}, state: 'disconnected' } } },
    };
}

if (!reverbEnabled) {
    window.Echo = makeEchoStub();
    console.info('[Echo] real-time via websocket DISABLED — using Livewire polling (VITE_REVERB_ENABLED=false).');
} else {
    // Only import Echo + Pusher when we actually want to connect.
    // Dynamic import keeps the bundle slim when real-time is off.
    const [{ default: Echo }, { default: Pusher }] = await Promise.all([
        import('laravel-echo'),
        import('pusher-js'),
    ]);

    Pusher.logToConsole = false;
    window.Pusher = Pusher;

    const isSecure = (import.meta.env.VITE_REVERB_SCHEME ?? 'http') === 'https';

    try {
        window.Echo = new Echo({
            broadcaster: 'reverb',
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: isSecure,
            enabledTransports: isSecure ? ['wss'] : ['ws'],
            activityTimeout: 30000,
            pongTimeout: 8000,
            unavailableTimeout: 6000,
        });

        // Silence noisy error events
        window.Echo.connector.pusher.connection.bind('error', () => {});
        window.Echo.connector.pusher.connection.bind('unavailable', () => {
            console.info('[Echo] real-time offline — Livewire polling will cover it.');
        });
        window.Echo.connector.pusher.connection.bind('connected', () => {
            console.info('[Echo] real-time connected ✓');
        });
    } catch (e) {
        console.warn('[Echo] init failed — falling back to polling:', e.message);
        window.Echo = makeEchoStub();
    }
}
