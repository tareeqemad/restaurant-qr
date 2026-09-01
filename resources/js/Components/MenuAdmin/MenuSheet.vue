<script setup>
defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    icon: { type: String, default: 'bi-pencil-square' },
    wide: { type: Boolean, default: false },
    mobileBottom: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);
const close = () => emit('close');
</script>

<template>
    <Teleport to="body">
        <Transition name="menu-sheet">
            <div v-if="open" class="ms-backdrop" @click.self="!busy && close()" @keydown.escape.window="!busy && close()">
                <section class="ms-panel" :class="{ wide, 'mobile-bottom': mobileBottom }" role="dialog" aria-modal="true" :aria-label="title">
                    <header class="ms-header">
                        <span class="ms-icon"><i class="bi" :class="icon"></i></span>
                        <div>
                            <h2>{{ title }}</h2>
                            <p v-if="subtitle">{{ subtitle }}</p>
                        </div>
                        <button type="button" class="ms-close" :disabled="busy" aria-label="إغلاق" @click="close">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </header>
                    <div class="ms-body"><slot /></div>
                    <footer v-if="$slots.footer" class="ms-footer"><slot name="footer" /></footer>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.ms-backdrop {
    position: fixed;
    inset: 0;
    z-index: 19000;
    display: flex;
    justify-content: flex-start;
    background: rgba(15, 23, 42, .55);
    backdrop-filter: blur(3px);
}
.ms-panel {
    width: min(620px, 100%);
    height: 100%;
    display: grid;
    grid-template-rows: auto minmax(0, 1fr) auto;
    background: #fff;
    box-shadow: 24px 0 55px rgba(15, 23, 42, .2);
}
.ms-panel.wide { width: min(920px, 100%); }
.ms-header {
    display: flex;
    align-items: center;
    gap: .75rem;
    padding: 1rem 1.15rem;
    border-bottom: 1px solid #e8eeea;
    background: linear-gradient(145deg, #f8fcf9, #fff);
}
.ms-icon {
    width: 43px;
    height: 43px;
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    border-radius: 13px;
    color: #0f6940;
    background: #eaf7ef;
    font-size: 1.12rem;
}
.ms-header > div { flex: 1; min-width: 0; }
.ms-header h2 { margin: 0; color: #10251c; font-size: 1.05rem; font-weight: 900; }
.ms-header p { margin: .14rem 0 0; color: #718078; font-size: .76rem; }
.ms-close {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border: 1px solid #dfe8e2;
    border-radius: 12px;
    background: #fff;
    color: #52645a;
}
.ms-close:hover { color: #a21f2d; border-color: #f1c5ca; }
.ms-body { min-height: 0; overflow-y: auto; padding: 1.1rem; }
.ms-footer {
    display: flex;
    gap: .6rem;
    justify-content: flex-end;
    padding: .85rem 1.1rem;
    border-top: 1px solid #e8eeea;
    background: #fbfcfb;
}
.menu-sheet-enter-active, .menu-sheet-leave-active { transition: opacity .2s ease; }
.menu-sheet-enter-active .ms-panel, .menu-sheet-leave-active .ms-panel { transition: transform .22s ease; }
.menu-sheet-enter-from, .menu-sheet-leave-to { opacity: 0; }
.menu-sheet-enter-from .ms-panel, .menu-sheet-leave-to .ms-panel { transform: translateX(100%); }
@media (max-width: 575.98px) {
    .ms-panel, .ms-panel.wide { width: 100%; }
    .ms-backdrop:has(.mobile-bottom) { align-items: flex-end; }
    .ms-panel.mobile-bottom {
        height: min(94dvh, 900px);
        border-radius: 22px 22px 0 0;
        box-shadow: 0 -22px 55px rgba(15, 23, 42, .2);
    }
    .menu-sheet-enter-from .ms-panel.mobile-bottom,
    .menu-sheet-leave-to .ms-panel.mobile-bottom { transform: translateY(100%); }
    .ms-header, .ms-body, .ms-footer { padding-inline: .85rem; }
}
@media (prefers-reduced-motion: reduce) {
    .menu-sheet-enter-active, .menu-sheet-leave-active,
    .menu-sheet-enter-active .ms-panel, .menu-sheet-leave-active .ms-panel { transition: none; }
}
</style>
