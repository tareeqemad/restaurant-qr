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
        <div v-if="open" class="confirm-layer" @click.self="emit('close')">
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
.confirm-layer { position: fixed; z-index: 1100; inset: 0; display: grid; place-items: center; padding: 1rem; background: rgba(15, 27, 19, .42); backdrop-filter: blur(3px); }
.confirm-sheet { width: min(420px, 100%); box-sizing: border-box; padding: 1.2rem; border: 1px solid #dce5df; border-radius: 18px; background: #fff; box-shadow: 0 24px 70px -28px rgba(0, 0, 0, .55); text-align: center; }
.confirm-icon { display: grid; width: 50px; height: 50px; margin: 0 auto; place-items: center; border-radius: 15px; color: rgb(var(--primary-rgb, 22 101 52)); background: #edf7ef; font-size: 1.1rem; }
h2 { margin: .7rem 0 .25rem; color: #24362a; font-size: 1rem; }
p { margin: 0; color: #6d7b72; font-size: .73rem; line-height: 1.75; }
.confirm-error { display: flex; align-items: flex-start; gap: .35rem; margin-top: .65rem; padding: .5rem; border-radius: 9px; color: #922d36; background: #fff0f1; text-align: start; font-size: .68rem; }
footer { display: flex; gap: .45rem; margin-top: .9rem; }
button { min-height: 46px; flex: 1; border-radius: 11px; font: inherit; font-size: .73rem; font-weight: 800; }
.secondary { border: 1px solid #dce4df; color: #536159; background: #fff; }
.confirm { border: 1px solid rgb(var(--primary-rgb, 22 101 52)); color: #fff; background: rgb(var(--primary-rgb, 22 101 52)); }
button:disabled { opacity: .48; }
.spinning { animation: spin .75s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (max-width: 520px) { .confirm-layer { align-items: end; padding: 0; } .confirm-sheet { border-radius: 18px 18px 0 0; } }
@media (prefers-reduced-motion: reduce) { .spinning { animation: none; } }
</style>
