// Leave breathing room after a slow response instead of immediately catching
// the next interval tick. One bell owns at most one request and one timer.
export function createNotificationPolling({
    refresh,
    canPoll,
    intervalMs = 3000,
    now = () => Date.now(),
    setTimer = setTimeout,
    clearTimer = clearTimeout,
}) {
    let active = false;
    let paused = false;
    let timer = null;
    let controller = null;

    const allowed = () => active && !paused && canPoll();
    const cancelTimer = () => {
        if (timer !== null) clearTimer(timer);
        timer = null;
    };

    async function request() {
        if (!allowed() || controller) return;
        cancelTimer();
        const current = new AbortController();
        controller = current;
        const started = now();
        try {
            await refresh(current.signal);
        } catch {
            // Read-only polling can retry after a failed/aborted connection.
        } finally {
            controller = null;
            if (allowed()) {
                const delay = Math.max(intervalMs, Math.min(30000, now() - started));
                timer = setTimer(() => {
                    timer = null;
                    void request();
                }, delay);
            }
        }
    }

    function pause() {
        paused = true;
        cancelTimer();
        controller?.abort();
    }

    return {
        request,
        start() { active = true; paused = false; void request(); },
        pause,
        resume() { paused = false; void request(); },
        stop() { active = false; pause(); },
    };
}
