<script setup>
defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, required: true },
    message: { type: String, required: true },
    confirmLabel: { type: String, required: true },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    tone: { type: String, default: 'primary' },
});

const emit = defineEmits({
    close: () => true,
    confirm: () => true,
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="confirm-layer" @click.self="!busy && emit('close')">
            <section class="confirm-sheet" role="dialog" aria-modal="true" :aria-label="title">
                <span class="confirm-icon" :class="`is-${tone}`"><i class="bi bi-receipt"></i></span>
                <h2>{{ title }}</h2>
                <p>{{ message }}</p>
                <div v-if="error" class="confirm-error"><i class="bi bi-exclamation-circle"></i> {{ error }}</div>
                <footer>
                    <button type="button" class="secondary" :disabled="busy" @click="emit('close')">رجوع</button>
                    <button type="button" class="confirm" :class="`is-${tone}`" :disabled="busy" @click="emit('confirm')">
                        <i v-if="busy" class="bi bi-arrow-clockwise spinning"></i>
                        {{ busy ? 'جاري التنفيذ…' : confirmLabel }}
                    </button>
                </footer>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.confirm-layer { position: fixed; z-index: 1100; inset: 0; display: grid; place-items: center; padding: 1rem; background: rgba(15, 27, 19, .42); backdrop-filter: none; }
.confirm-sheet { width: min(420px, 100%); box-sizing: border-box; padding: 1.2rem; border: 1px solid var(--line, var(--line)); border-radius: 8px; background: var(--surface, #FFFDFA); box-shadow: none; text-align: center; }
.confirm-icon { display: grid; width: 50px; height: 50px; margin: 0 auto; place-items: center; border-radius: 8px; color: var(--brand, #A3422B); background: var(--surface, #FFFDFA); font-size: 1.1rem; }
h2 { margin: .7rem 0 .25rem; color: var(--ink, var(--ink)); font-size: 1rem; }
p { margin: 0; color: var(--muted, var(--muted)); font-size: .875rem; line-height: 1.75; }
.confirm-error { display: flex; align-items: flex-start; gap: .35rem; margin-top: .65rem; padding: .5rem; border-radius: 8px; color: #922d36; background: #fff0f1; text-align: start; font-size: .875rem; }
footer { display: flex; gap: .45rem; margin-top: .9rem; }
button { min-height: 46px; flex: 1; border-radius: 8px; font: inherit; font-size: .875rem; font-weight: 800; }
.secondary { border: 1px solid var(--line, var(--line)); color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); }
.confirm { border: 1px solid var(--brand, #A3422B); color: #FFFDFA; background: var(--brand, #A3422B); }
button:disabled { opacity: .48; }
.spinning { animation: spin .75s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (max-width: 520px) { .confirm-layer { align-items: end; padding: 0; } .confirm-sheet { border-radius: 8px 8px 0 0; } }
@media (prefers-reduced-motion: reduce) { .spinning { animation: none; } }

/* Task-specific component layout. */
.confirm-sheet{width:min(100%,480px);border-radius:10px;padding:24px;border-top:4px solid var(--brand);box-shadow:none}.confirm-icon{width:40px;height:40px;border-radius:8px;margin-bottom:16px}.confirm-sheet h2{font-size:23px;margin-bottom:12px}.confirm-sheet p{font-size:15px;line-height:1.8}.confirm-sheet footer{border-top:1px solid var(--line);padding-top:18px;margin-top:22px;gap:12px}.confirm-sheet footer button{min-height:48px;border-radius:8px}.confirm-error{font-size:14px;border-radius:6px;padding:12px}
</style>
