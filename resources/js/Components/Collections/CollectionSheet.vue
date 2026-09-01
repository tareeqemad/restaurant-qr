<script setup>
defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    eyebrow: { type: String, default: '' },
    icon: { type: String, default: 'bi-cash-coin' },
    danger: { type: Boolean, default: false },
});

defineEmits(['close']);
</script>

<template>
    <Teleport to="body">
        <Transition name="collection-sheet">
            <div v-if="open" class="collection-sheet__backdrop" @click.self="$emit('close')">
                <section class="collection-sheet" :class="{ danger }" role="dialog" aria-modal="true" :aria-label="title">
                    <header>
                        <span class="collection-sheet__icon"><i class="bi" :class="icon"></i></span>
                        <div><small v-if="eyebrow">{{ eyebrow }}</small><h2>{{ title }}</h2></div>
                        <button type="button" aria-label="إغلاق" @click="$emit('close')"><i class="bi bi-x-lg"></i></button>
                    </header>
                    <div class="collection-sheet__body"><slot /></div>
                    <footer v-if="$slots.footer"><slot name="footer" /></footer>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.collection-sheet__backdrop { position: fixed; inset: 0; z-index: 1090; display: grid; place-items: center; padding: 1rem; background: rgba(13, 29, 20, .48); backdrop-filter: blur(2px); }
.collection-sheet { width: min(520px, 96vw); max-height: min(760px, 92vh); display: flex; flex-direction: column; overflow: hidden; border-radius: 18px; background: #fff; box-shadow: 0 28px 80px rgba(0, 0, 0, .24); }
.collection-sheet > header { display: grid; grid-template-columns: auto minmax(0, 1fr) auto; align-items: center; gap: .7rem; padding: .9rem 1rem; border-bottom: 1px solid #e5ebe7; }
.collection-sheet__icon { width: 42px; height: 42px; display: grid; place-items: center; border-radius: 12px; background: #eaf6ef; color: #11733e; font-size: 1.05rem; }
.collection-sheet.danger .collection-sheet__icon { background: #fff0f0; color: #b83232; }
.collection-sheet header small { color: #839188; font-size: .66rem; }
.collection-sheet h2 { margin: .08rem 0 0; color: #173528; font-size: 1rem; }
.collection-sheet header button { width: 40px; height: 40px; border: 0; border-radius: 10px; background: #f2f5f3; color: #53665c; }
.collection-sheet__body { padding: 1rem; overflow-y: auto; }
.collection-sheet > footer { display: flex; gap: .55rem; justify-content: flex-end; padding: .8rem 1rem; border-top: 1px solid #e5ebe7; background: #fbfcfb; }
.collection-sheet > footer :deep(.btn) { min-height: 44px; }
.collection-sheet-enter-active, .collection-sheet-leave-active { transition: opacity .16s ease; }
.collection-sheet-enter-from, .collection-sheet-leave-to { opacity: 0; }
@media (max-width: 575.98px) {
    .collection-sheet__backdrop { place-items: end center; padding: 0; }
    .collection-sheet { width: 100%; max-height: 92vh; border-radius: 20px 20px 0 0; }
}
</style>
