<script setup>
/**
 * The single modal behind useConfirm() — mounted once by AdminLayout.
 * Backdrop tap and Escape answer "no"; the promise always settles.
 */
import { useConfirm } from '../../Composables/useConfirm';
import DialogSurface from './DialogSurface.vue';

const { state, accept, dismiss } = useConfirm();
</script>

<template>
    <DialogSurface
        :open="state.open"
        dialog-role="alertdialog"
        title-id="confirm-dialog-title"
        :description-id="state.message ? 'confirm-dialog-message' : null"
        max-width="380px"
        :initial-focus="state.danger ? '[data-confirm-cancel]' : '[data-confirm-primary]'"
        @close="dismiss"
    >
        <div class="cfm-card">
                    <div class="cfm-icon" :class="{ 'is-danger': state.danger }">
                        <i class="bi" :class="state.danger ? 'bi-exclamation-triangle-fill' : 'bi-question-circle-fill'"></i>
                    </div>
                    <h3 id="confirm-dialog-title" class="cfm-title">{{ state.title }}</h3>
                    <p v-if="state.message" id="confirm-dialog-message" class="cfm-message">{{ state.message }}</p>
                    <div class="cfm-actions">
                        <button type="button" class="cfm-btn cfm-btn--cancel" data-confirm-cancel @click="dismiss">
                            {{ state.cancelLabel }}
                        </button>
                        <button type="button" class="cfm-btn"
                                :class="state.danger ? 'cfm-btn--danger' : 'cfm-btn--confirm'"
                                data-confirm-primary
                                @click="accept">
                            {{ state.confirmLabel }}
                        </button>
                    </div>
        </div>
    </DialogSurface>
</template>

<style scoped>
.cfm-card {
    width: 100%;
    padding: 1.5rem 1.4rem 1.2rem;
    text-align: center;
}
.cfm-icon {
    width: 56px;
    height: 56px;
    margin: 0 auto .8rem;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.6rem;
    background: rgba(var(--primary-rgb), .1);
    color: rgb(var(--primary-rgb));
}
.cfm-icon.is-danger { background: #fef2f2; color: #dc2626; }
.cfm-title {
    margin: 0 0 .35rem;
    font-size: 1.05rem;
    font-weight: 800;
    color: #111827;
}
.cfm-message {
    margin: 0 0 1rem;
    font-size: .88rem;
    color: #6b7280;
    line-height: 1.55;
}
.cfm-actions { display: flex; gap: 10px; margin-top: 1rem; }
.cfm-btn {
    flex: 1;
    min-height: 46px;
    border: 0;
    border-radius: 11px;
    font: inherit;
    font-size: .92rem;
    font-weight: 800;
    cursor: pointer;
    transition: filter .12s, background-color .12s;
}
.cfm-btn:hover { filter: brightness(.96); }
.cfm-btn--cancel { background: #f3f4f6; color: #374151; }
.cfm-btn--confirm { background: rgb(var(--primary-rgb)); color: #fff; }
.cfm-btn--danger { background: #dc2626; color: #fff; }

</style>
