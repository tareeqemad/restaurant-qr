import { reactive } from 'vue';

/**
 * App-wide toast queue — the SweetAlert2 replacement for Vue pages.
 * Module-level singleton: every caller pushes into the same stack that
 * Toaster.vue (mounted once by AdminLayout) renders.
 */
let seq = 0;
const toasts = reactive([]);

function push(tone, message, timeout) {
    if (! message) return null;
    const id = ++seq;
    toasts.push({ id, tone, message });
    if (timeout) setTimeout(() => dismiss(id), timeout);
    return id;
}

function dismiss(id) {
    const i = toasts.findIndex((t) => t.id === id);
    if (i !== -1) toasts.splice(i, 1);
}

export function useToast() {
    return {
        toasts,
        dismiss,
        success: (m) => push('success', m, 3800),
        error: (m) => push('error', m, 6000),
        warning: (m) => push('warning', m, 5000),
        info: (m) => push('info', m, 3800),
    };
}
