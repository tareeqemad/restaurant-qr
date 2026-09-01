<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';

let openSurfaceCount = 0;

const props = defineProps({
    open: { type: Boolean, default: false },
    variant: {
        type: String,
        default: 'dialog',
        validator: (value) => ['dialog', 'sheet-start', 'sheet-end', 'sheet-bottom'].includes(value),
    },
    dialogRole: {
        type: String,
        default: 'dialog',
        validator: (value) => ['dialog', 'alertdialog'].includes(value),
    },
    titleId: { type: String, required: true },
    descriptionId: { type: String, default: null },
    maxWidth: { type: String, default: '560px' },
    initialFocus: { type: String, default: '[autofocus], [data-autofocus]' },
    closeOnBackdrop: { type: Boolean, default: true },
    closeOnEscape: { type: Boolean, default: true },
});

const emit = defineEmits(['close']);
const panel = ref(null);
let previousFocus = null;
let ownsBodyLock = false;

const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

function visibleFocusableElements() {
    if (! panel.value) return [];

    return [...panel.value.querySelectorAll(focusableSelector)].filter((element) => (
        element.getClientRects().length > 0 && element.getAttribute('aria-hidden') !== 'true'
    ));
}

function lockPage() {
    if (ownsBodyLock) return;
    ownsBodyLock = true;
    openSurfaceCount += 1;
    document.body.classList.add('ux-surface-open');
}

function unlockPage() {
    if (! ownsBodyLock) return;
    ownsBodyLock = false;
    openSurfaceCount = Math.max(0, openSurfaceCount - 1);

    if (openSurfaceCount === 0) document.body.classList.remove('ux-surface-open');
}

async function focusSurface() {
    await nextTick();
    if (! panel.value) return;

    let preferred = null;
    try {
        preferred = props.initialFocus ? panel.value.querySelector(props.initialFocus) : null;
    } catch {
        preferred = null;
    }

    (preferred || visibleFocusableElements()[0] || panel.value).focus({ preventScroll: true });
}

function restoreFocus() {
    const target = previousFocus;
    previousFocus = null;

    nextTick(() => {
        if (target?.isConnected) target.focus({ preventScroll: true });
    });
}

function requestClose() {
    emit('close');
}

function handleBackdropClick() {
    if (props.closeOnBackdrop) requestClose();
}

function handleKeydown(event) {
    if (event.key === 'Escape' && props.closeOnEscape) {
        event.preventDefault();
        requestClose();
        return;
    }

    if (event.key !== 'Tab') return;

    const focusable = visibleFocusableElements();
    if (! focusable.length) {
        event.preventDefault();
        panel.value?.focus({ preventScroll: true });
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];

    if (event.shiftKey && (document.activeElement === first || document.activeElement === panel.value)) {
        event.preventDefault();
        last.focus();
    } else if (! event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    }
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            previousFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
            lockPage();
            focusSurface();
        } else {
            unlockPage();
            restoreFocus();
        }
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    unlockPage();
    restoreFocus();
});
</script>

<template>
    <Teleport to="body">
        <Transition name="ux-surface">
            <div
                v-if="open"
                class="ux-surface__backdrop"
                :class="`is-${variant}`"
                @click.self="handleBackdropClick"
            >
                <div
                    ref="panel"
                    class="ux-surface__panel"
                    :class="`is-${variant}`"
                    :style="{ '--ux-surface-width': maxWidth }"
                    :role="dialogRole"
                    aria-modal="true"
                    :aria-labelledby="titleId"
                    :aria-describedby="descriptionId || undefined"
                    tabindex="-1"
                    @keydown="handleKeydown"
                >
                    <slot />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style>
body.ux-surface-open {
    overflow: hidden;
    overscroll-behavior: none;
}
</style>

<style scoped>
.ux-surface__backdrop {
    position: fixed;
    inset: 0;
    z-index: 19000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
    background: rgba(9, 28, 18, .52);
    backdrop-filter: blur(2px);
}

.ux-surface__panel {
    width: min(100%, var(--ux-surface-width));
    max-height: calc(100dvh - 2rem);
    overflow: auto;
    border: 1px solid rgba(15, 71, 49, .08);
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 28px 80px -28px rgba(7, 31, 20, .52);
    outline: none;
}

.ux-surface__backdrop.is-sheet-start,
.ux-surface__backdrop.is-sheet-end {
    align-items: stretch;
    padding: 0;
}

.ux-surface__backdrop.is-sheet-start { justify-content: flex-start; }
.ux-surface__backdrop.is-sheet-end { justify-content: flex-end; }

.ux-surface__panel.is-sheet-start,
.ux-surface__panel.is-sheet-end {
    height: 100dvh;
    max-height: 100dvh;
    border-block: 0;
    border-radius: 0;
}

.ux-surface__backdrop.is-sheet-bottom {
    align-items: flex-end;
    padding: 0;
}

.ux-surface__panel.is-sheet-bottom {
    width: min(100%, var(--ux-surface-width));
    max-height: 92dvh;
    border-end-start-radius: 0;
    border-end-end-radius: 0;
}

.ux-surface-enter-active,
.ux-surface-leave-active {
    transition: opacity .18s ease;
}

.ux-surface-enter-active .ux-surface__panel,
.ux-surface-leave-active .ux-surface__panel {
    transition: transform .2s ease, opacity .18s ease;
}

.ux-surface-enter-from,
.ux-surface-leave-to {
    opacity: 0;
}

.ux-surface-enter-from .ux-surface__panel.is-dialog,
.ux-surface-leave-to .ux-surface__panel.is-dialog {
    opacity: 0;
    transform: translateY(10px) scale(.985);
}

.ux-surface-enter-from .ux-surface__panel.is-sheet-start,
.ux-surface-leave-to .ux-surface__panel.is-sheet-start {
    transform: translateX(28px);
}

.ux-surface-enter-from .ux-surface__panel.is-sheet-end,
.ux-surface-leave-to .ux-surface__panel.is-sheet-end {
    transform: translateX(-28px);
}

.ux-surface-enter-from .ux-surface__panel.is-sheet-bottom,
.ux-surface-leave-to .ux-surface__panel.is-sheet-bottom {
    transform: translateY(28px);
}

@media (max-width: 560px) {
    .ux-surface__backdrop.is-sheet-start,
    .ux-surface__backdrop.is-sheet-end {
        align-items: flex-end;
    }

    .ux-surface__panel.is-sheet-start,
    .ux-surface__panel.is-sheet-end {
        width: 100%;
        height: min(94dvh, 820px);
        border: 1px solid rgba(15, 71, 49, .08);
        border-block-end: 0;
        border-radius: 18px 18px 0 0;
    }

    .ux-surface-enter-from .ux-surface__panel.is-sheet-start,
    .ux-surface-leave-to .ux-surface__panel.is-sheet-start,
    .ux-surface-enter-from .ux-surface__panel.is-sheet-end,
    .ux-surface-leave-to .ux-surface__panel.is-sheet-end {
        transform: translateY(28px);
    }
}

@media (prefers-reduced-motion: reduce) {
    .ux-surface-enter-active,
    .ux-surface-leave-active,
    .ux-surface-enter-active .ux-surface__panel,
    .ux-surface-leave-active .ux-surface__panel {
        transition-duration: .01ms;
    }
}
</style>
