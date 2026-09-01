import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';

/**
 * One transport for every live Vue board.
 *
 * Visible-tab interval polling with leading+trailing throttling so an order-storm
 * can't hammer the screen 22 times in two seconds (the tables-board v4
 * lesson). Polling pauses while the tab is hidden.
 *
 *   useLiveRefresh({
 *       pollMs: 15000,
 *       onPing: (reason) => reload(),
 *   });
 *
 */
export function useLiveRefresh({ onPing, pollMs = 0, throttleMs = 2500 }) {
    let pollTimer = null;
    let trailingTimer = null;
    let lastRun = 0;
    let running = false;
    let queuedReason = null;
    let navigationBusy = false;
    let destroyed = false;
    let controller = null;
    let stopStartListener = null;
    let stopFinishListener = null;

    const canRun = () => ! destroyed
        && ! navigationBusy
        && ! document.hidden
        && navigator.onLine;

    const run = async (reason) => {
        if (! canRun()) return;

        if (running) {
            queuedReason = reason;
            return;
        }

        running = true;
        lastRun = Date.now();
        controller = new AbortController();

        try {
            await onPing(reason, controller.signal);
        } catch (error) {
            // Aborting a background pulse is expected when the user chooses a
            // destination. The foreground Inertia visit always wins.
            if (error?.name !== 'AbortError') {
                // Operational screens retry on their next visible pulse.
            }
        } finally {
            running = false;
            controller = null;

            if (queuedReason && canRun()) {
                const nextReason = queuedReason;
                queuedReason = null;
                fire(nextReason);
            }
        }
    };

    const fire = (reason) => {
        if (! canRun()) return;

        if (running) {
            queuedReason = reason;
            return;
        }

        const now = Date.now();
        if (now - lastRun >= throttleMs) {
            run(reason);
        } else if (! trailingTimer) {
            trailingTimer = setTimeout(() => {
                trailingTimer = null;
                run(reason);
            }, throttleMs - (now - lastRun));
        }
    };

    const pauseForNavigation = () => {
        navigationBusy = true;
        queuedReason = null;
        if (trailingTimer) clearTimeout(trailingTimer);
        trailingTimer = null;
        controller?.abort();
    };

    const resumeAfterNavigation = () => {
        navigationBusy = false;
        // Give the foreground page swap and its deferred props priority over
        // the next background poll on modest shared-hosting workers.
        lastRun = Date.now();
    };

    const resumeVisiblePolling = () => {
        if (! document.hidden && navigator.onLine) fire('poll');
    };

    onMounted(() => {
        stopStartListener = router.on('start', pauseForNavigation);
        stopFinishListener = router.on('finish', resumeAfterNavigation);
        document.addEventListener('visibilitychange', resumeVisiblePolling);
        window.addEventListener('online', resumeVisiblePolling);

        if (pollMs > 0) {
            pollTimer = setInterval(() => {
                fire('poll');
            }, pollMs);
        }
    });

    onBeforeUnmount(() => {
        destroyed = true;
        controller?.abort();
        if (pollTimer) clearInterval(pollTimer);
        if (trailingTimer) clearTimeout(trailingTimer);
        stopStartListener?.();
        stopFinishListener?.();
        document.removeEventListener('visibilitychange', resumeVisiblePolling);
        window.removeEventListener('online', resumeVisiblePolling);
    });

    return { fire };
}
