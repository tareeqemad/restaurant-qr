import { reactive } from 'vue';

/**
 * Promise-based confirm dialog — replaces window.confirm and the old
 * SweetAlert form-confirm interceptor. One host (ConfirmHost.vue, mounted
 * by AdminLayout) serves every caller:
 *
 *   const { ask } = useConfirm();
 *   if (await ask({ title: 'إنهاء الدوام؟', danger: true })) { … }
 */
const state = reactive({
    open: false,
    title: '',
    message: '',
    confirmLabel: 'تأكيد',
    cancelLabel: 'إلغاء',
    danger: false,
    resolver: null,
});

function settle(answer) {
    state.open = false;
    const resolve = state.resolver;
    state.resolver = null;
    resolve?.(answer);
}

export function useConfirm() {
    const ask = (opts = {}) => new Promise((resolve) => {
        // A second ask while one is open answers the first with "no" —
        // never leave a caller's promise hanging.
        if (state.resolver) settle(false);

        state.title = opts.title ?? 'تأكيد الإجراء';
        state.message = opts.message ?? '';
        state.confirmLabel = opts.confirmLabel ?? 'تأكيد';
        state.cancelLabel = opts.cancelLabel ?? 'إلغاء';
        state.danger = Boolean(opts.danger);
        state.resolver = resolve;
        state.open = true;
    });

    return {
        state,
        ask,
        accept: () => settle(true),
        dismiss: () => settle(false),
    };
}
