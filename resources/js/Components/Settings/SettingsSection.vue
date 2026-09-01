<script setup>
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    icon: { type: String, default: 'bi-sliders' },
    tone: { type: String, default: 'green' },
})
</script>

<template>
    <section class="settings-section" :data-tone="tone">
        <header class="settings-section__header">
            <span class="settings-section__icon" aria-hidden="true"><i class="bi" :class="icon"></i></span>
            <div>
                <h2>{{ title }}</h2>
                <p v-if="description">{{ description }}</p>
            </div>
            <div v-if="$slots.actions" class="settings-section__actions"><slot name="actions" /></div>
        </header>
        <div class="settings-section__body"><slot /></div>
        <footer v-if="$slots.footer" class="settings-section__footer"><slot name="footer" /></footer>
    </section>
</template>

<style scoped>
.settings-section { overflow: hidden; border: 1px solid #dfe7e2; border-radius: 18px; background: #fff; box-shadow: 0 8px 28px rgba(15, 44, 30, .045); }
.settings-section__header { display: flex; align-items: flex-start; gap: 12px; padding: 18px 20px; border-bottom: 1px solid #edf1ee; background: linear-gradient(135deg, #fbfdfb 0%, #fff 72%); }
.settings-section__icon { display: grid; flex: 0 0 40px; width: 40px; height: 40px; place-items: center; border-radius: 12px; color: #166534; background: #eaf5ee; font-size: 1.1rem; }
[data-tone="amber"] .settings-section__icon { color: #a85700; background: #fff3df; }
[data-tone="blue"] .settings-section__icon { color: #175e96; background: #edf6fc; }
.settings-section__header h2 { margin: 0; color: #17251d; font-size: 1.05rem; font-weight: 800; }
.settings-section__header p { margin: 4px 0 0; color: #728078; font-size: .83rem; line-height: 1.6; }
.settings-section__actions { margin-inline-start: auto; }
.settings-section__body { padding: 20px; }
.settings-section__footer { padding: 14px 20px; border-top: 1px solid #edf1ee; background: #fbfcfb; }
@media (max-width: 640px) { .settings-section { border-radius: 15px; } .settings-section__header, .settings-section__body { padding: 16px; } }
</style>
