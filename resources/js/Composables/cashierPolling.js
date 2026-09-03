import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Shared-hosting refresh loop: one cheap pulse read every six seconds while
 * a bill is open (ten seconds on the queue), one
 * forced snapshot each minute for clock-derived urgency, and exponential
 * backoff when PHP/MySQL is unavailable. Hidden tabs do no polling at all.
 */
export function useCashierPolling(store) {
    const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
    let timer = null;
    let quietPolls = 0;
    let failures = 0;
    let stopped = false;

    function schedule() {
        if (stopped) return;

        const delay = failures === 0
            ? (store.selection ? 6_000 : 10_000)
            : Math.min(60_000, 10_000 * (2 ** failures));
        timer = window.setTimeout(tick, delay);
    }

    async function tick() {
        if (document.hidden || !online.value) {
            schedule();
            return;
        }

        try {
            const forceAfter = store.selection ? 9 : 5;
            const result = await store.refresh({ force: quietPolls >= forceAfter, source: 'poll' });
            failures = 0;
            quietPolls = result.changed ? 0 : quietPolls + 1;
        } catch {
            failures = Math.min(3, failures + 1);
        } finally {
            schedule();
        }
    }

    function connectionChanged() {
        online.value = navigator.onLine;
        if (online.value) {
            failures = 0;
            store.refresh({ force: true, source: 'poll' }).catch(() => {});
        }
    }

    function visibilityChanged() {
        if (!document.hidden) store.refresh({ force: true, source: 'poll' }).catch(() => {});
    }

    onMounted(() => {
        window.addEventListener('online', connectionChanged);
        window.addEventListener('offline', connectionChanged);
        document.addEventListener('visibilitychange', visibilityChanged);
        schedule();
    });

    onBeforeUnmount(() => {
        stopped = true;
        if (timer) window.clearTimeout(timer);
        window.removeEventListener('online', connectionChanged);
        window.removeEventListener('offline', connectionChanged);
        document.removeEventListener('visibilitychange', visibilityChanged);
    });

    return { online };
}
