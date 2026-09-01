<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    icon: { type: String, default: 'bi-journal-text' },
    tone: { type: String, default: 'green' },
    compact: { type: Boolean, default: false },
})
</script>

<template>
    <section class="acc-panel" :class="{ 'acc-panel--compact': compact }" :data-tone="tone">
        <header class="acc-panel__header">
            <span class="acc-panel__icon"><i class="bi" :class="icon"></i></span>
            <div><h2>{{ title }}</h2><p v-if="description">{{ description }}</p></div>
            <div v-if="$slots.actions" class="acc-panel__actions"><slot name="actions" /></div>
        </header>
        <div class="acc-panel__body"><slot /></div>
        <footer v-if="$slots.footer" class="acc-panel__footer"><slot name="footer" /></footer>
    </section>
</template>

<style scoped>
.acc-panel { overflow: hidden; border: 1px solid #dfe7e2; border-radius: 18px; background: #fff; box-shadow: 0 8px 26px rgba(19,48,30,.045); }.acc-panel__header { display: flex; align-items: flex-start; gap: 11px; padding: 16px 18px; border-bottom: 1px solid #edf1ee; background: linear-gradient(130deg,#f8fbf9,#fff 72%); }.acc-panel__icon { display: grid; flex: 0 0 38px; width: 38px; height: 38px; place-items: center; border-radius: 11px; color:#1f6b50; background:#e8f3ec; font-size:1rem; }.acc-panel[data-tone="amber"] .acc-panel__icon { color: #a65b00; background: #fff1db; }.acc-panel[data-tone="blue"] .acc-panel__icon { color: #17618e; background: #eaf5fb; }.acc-panel__header h2 { margin: 0; color: #192a20; font-size: 1.02rem; font-weight: 900; }.acc-panel__header p { margin: 3px 0 0; color: #6f7f75; font-size: .78rem; line-height: 1.55; }.acc-panel__actions { margin-inline-start: auto; }.acc-panel__body { padding: 18px; }.acc-panel__footer { padding: 12px 18px; border-top: 1px solid #edf1ee; background: #fbfcfb; }.acc-panel--compact .acc-panel__body { padding: 0; }
@media(max-width:640px){.acc-panel{border-radius:14px}.acc-panel__header,.acc-panel__body{padding:14px}}
</style>
