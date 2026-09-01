import { nextTick, onBeforeUnmount, onMounted, unref } from 'vue';
import { router } from '@inertiajs/vue3';
import { useConfirm } from './useConfirm';

const defaultLeaveMessage = 'لديك تغييرات لم تُحفظ. هل تريد مغادرة الصفحة وتجاهلها؟';
const fieldSelector = 'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), button:not([disabled])';

function optionValue(value) {
    return typeof value === 'function' ? value() : unref(value);
}

function attributeValue(value) {
    return String(value).replace(/\\/g, '\\\\').replace(/"/g, '\\"');
}

function isVisible(element) {
    return Boolean(element?.isConnected && element.getClientRects().length);
}

function fieldNearMarker(marker, boundary) {
    let container = marker?.closest('label, [data-form-field], .field, .form-group, .mf-recipe-row');

    while (container && container !== boundary) {
        const candidate = container.querySelector(fieldSelector);
        if (isVisible(candidate)) return candidate;
        container = container.parentElement;
    }

    return null;
}

/**
 * Shared form behaviour for staff screens:
 * - protects dirty forms from accidental GET navigation / browser unload;
 * - focuses and announces the first server-side validation error;
 * - offers the app-styled discard confirmation to sheets and dialogs.
 */
export function useFormUx(form, options = {}) {
    const { ask } = useConfirm();
    const root = options.root ?? null;
    const guard = options.guard ?? true;
    const leaveMessage = options.leaveMessage ?? defaultLeaveMessage;
    let stopBeforeListener = null;

    const formRoot = () => unref(root) || document;
    const guardEnabled = () => Boolean(optionValue(guard));
    const hasUnsavedChanges = () => guardEnabled() && Boolean(form.isDirty) && ! form.processing;

    function handleBeforeUnload(event) {
        if (! hasUnsavedChanges()) return;
        event.preventDefault();
        event.returnValue = '';
    }

    function handleInertiaBefore(event) {
        const method = String(event.detail?.visit?.method || 'get').toLowerCase();
        if (method !== 'get' || ! hasUnsavedChanges()) return true;
        return window.confirm(leaveMessage);
    }

    async function confirmDiscard() {
        if (! hasUnsavedChanges()) return true;

        return ask({
            title: 'تجاهل التغييرات؟',
            message: 'لم يتم حفظ التعديلات التي أدخلتها، وسيؤدي الإغلاق إلى فقدانها.',
            confirmLabel: 'تجاهل التغييرات',
            cancelLabel: 'متابعة التعديل',
            danger: true,
        });
    }

    async function focusFirstError(errors = form.errors) {
        const keys = Object.keys(errors || {});
        if (! keys.length) return null;

        await nextTick();
        const boundary = formRoot();
        const previous = boundary.querySelectorAll?.('[data-ux-invalid="true"]') || [];
        previous.forEach((element) => {
            element.removeAttribute('data-ux-invalid');
            element.removeAttribute('aria-invalid');
        });

        let target = null;

        for (const key of keys) {
            const escaped = attributeValue(key);
            const rootKey = attributeValue(key.split('.')[0]);
            target = boundary.querySelector?.([
                `[name="${escaped}"]`,
                `[data-form-field="${escaped}"]`,
                `[name="${rootKey}"]`,
                `[data-form-field="${rootKey}"]`,
            ].join(', '));

            if (isVisible(target)) break;
            target = null;
        }

        if (! target) {
            const messages = Object.values(errors)
                .flatMap((message) => Array.isArray(message) ? message : [message])
                .filter((message) => typeof message === 'string' && message.trim())
                .map((message) => message.trim());
            const markers = boundary.querySelectorAll?.(
                '[data-form-error], .error, .invalid-feedback, .section-error, .mf-error, .mf-row-error, small, em',
            ) || [];

            const marker = [...markers].find((element) => {
                const text = element.textContent?.trim() || '';
                return isVisible(element) && messages.some((message) => text.includes(message));
            });

            target = fieldNearMarker(marker, boundary);
        }

        if (! target) {
            target = boundary.querySelector?.('[aria-invalid="true"], .is-invalid, .invalid');
        }

        if (! isVisible(target)) {
            const summary = boundary.querySelector?.('[data-form-error-summary], [role="alert"]');
            if (summary) {
                summary.setAttribute('tabindex', '-1');
                target = summary;
            }
        }

        if (! target) return null;

        target.setAttribute('aria-invalid', 'true');
        target.setAttribute('data-ux-invalid', 'true');
        target.scrollIntoView({
            behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
            block: 'center',
        });
        target.focus({ preventScroll: true });
        return target;
    }

    function markSaved() {
        form.defaults?.();
    }

    onMounted(() => {
        window.addEventListener('beforeunload', handleBeforeUnload);
        stopBeforeListener = router.on('before', handleInertiaBefore);
    });

    onBeforeUnmount(() => {
        window.removeEventListener('beforeunload', handleBeforeUnload);
        stopBeforeListener?.();
    });

    return {
        confirmDiscard,
        focusFirstError,
        hasUnsavedChanges,
        markSaved,
    };
}
