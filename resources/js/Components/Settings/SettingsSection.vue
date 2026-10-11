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
.settings-section { overflow: hidden; border: 1px solid var(--brand-soft, #F3E3D8); border-radius: 12px; background: var(--surface, #FFFDFA); box-shadow: none; }
.settings-section__header { display: flex; align-items: flex-start; gap: 12px; padding: 18px 20px; border-bottom: 1px solid var(--canvas, #F7F3ED); background: var(--surface, #FFFDFA); }
.settings-section__icon { display: grid; flex: 0 0 40px; width: 40px; height: 40px; place-items: center; border-radius: 12px; color: var(--ink, #25343A); background: var(--canvas, #F7F3ED); font-size: 1.1rem; }
[data-tone="amber"] .settings-section__icon { color: #a85700; background: #fff3df; }
[data-tone="blue"] .settings-section__icon { color: #175e96; background: #edf6fc; }
.settings-section__header h2 { margin: 0; color: var(--ink, #25343A); font-size: 1.05rem; font-weight: 800; }
.settings-section__header p { margin: 4px 0 0; color: var(--muted, #626B6D); font-size: .875rem; line-height: 1.6; }
.settings-section__actions { margin-inline-start: auto; }
.settings-section__body { padding: 20px; }
.settings-section__footer { padding: 14px 20px; border-top: 1px solid var(--canvas, #F7F3ED); background: var(--surface, #FFFDFA); }
@media (max-width: 640px) { .settings-section { border-radius: 12px; } .settings-section__header, .settings-section__body { padding: 16px; } }

/* Dar Al Sarw management layout */
.settings-section { border-radius: 12px; background: var(--surface); } .settings-section__header { padding: 20px 22px; background: var(--canvas); border-bottom: 1px solid var(--line); } .settings-section__header h2 { color: var(--ink); font-size: 1.125rem; } .settings-section__body { padding: 22px; } .settings-section__header p { color: var(--muted); font-size: .875rem; max-width: 75ch; }
</style>
