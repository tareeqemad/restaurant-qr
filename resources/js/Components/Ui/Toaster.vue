<script setup>
/**
 * Renders the useToast() queue — mounted once by AdminLayout.
 * Top-center stack, auto-dismissing, tap to dismiss early.
 */
import { useToast } from '../../Composables/useToast';

const { toasts, dismiss } = useToast();

const ICONS = {
    success: 'bi-check-circle-fill',
    error: 'bi-x-circle-fill',
    warning: 'bi-exclamation-triangle-fill',
    info: 'bi-info-circle-fill',
};
</script>

<template>
    <Teleport to="body">
        <TransitionGroup name="toast" tag="div" class="toaster" aria-live="polite">
            <button v-for="t in toasts" :key="t.id" type="button"
                    class="toast-chip" :class="`is-${t.tone}`" @click="dismiss(t.id)">
                <i class="bi" :class="ICONS[t.tone] ?? ICONS.info"></i>
                <span>{{ t.message }}</span>
            </button>
        </TransitionGroup>
    </Teleport>
</template>

<style scoped>
.toaster {
    position: fixed;
    top: 14px;
    inset-inline-start: 50%;
    transform: translateX(50%);
    z-index: 20000;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    pointer-events: none;
    width: min(92vw, 460px);
}
.toast-chip {
    pointer-events: auto;
    display: inline-flex;
    align-items: center;
    gap: 9px;
    max-width: 100%;
    padding: 11px 16px;
    border: 0;
    border-radius: 12px;
    font: inherit;
    font-size: .9rem;
    font-weight: 700;
    color: #fff;
    cursor: pointer;
    box-shadow: 0 10px 26px -8px rgba(15, 23, 42, .35);
    text-align: start;
}
.toast-chip > i { font-size: 1.05rem; flex-shrink: 0; }
.toast-chip > span { min-width: 0; }

.is-success { background: #059669; }
.is-error   { background: #dc2626; }
.is-warning { background: #b45309; }
.is-info    { background: #1d4ed8; }

.toast-enter-active, .toast-leave-active { transition: opacity .2s, transform .2s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(-8px); }
</style>
