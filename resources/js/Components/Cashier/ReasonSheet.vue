<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, required: true },
    message: { type: String, default: '' },
    label: { type: String, default: 'السبب' },
    placeholder: { type: String, default: 'اكتب سبباً واضحاً للمراجعة' },
    confirmLabel: { type: String, default: 'تأكيد' },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits({
    close: () => true,
    submit: (reason) => typeof reason === 'string' && reason.length > 0,
});

const reason = ref('');

watch(() => props.open, (open) => {
    if (open) reason.value = '';
}, { immediate: true });
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="sheet-layer" @click.self="emit('close')">
            <section class="reason-sheet" role="dialog" aria-modal="true" aria-labelledby="reason-title">
                <header><div><span>إجراء يحتاج سبباً مسجلاً</span><h2 id="reason-title">{{ title }}</h2></div><button type="button" aria-label="إغلاق" :disabled="busy" @click="emit('close')"><i class="bi bi-x-lg"></i></button></header>
                <p class="message">{{ message }}</p>
                <div v-if="error" class="sheet-error"><i class="bi bi-exclamation-circle"></i> {{ error }}</div>
                <label><span>{{ label }} *</span><textarea v-model="reason" maxlength="500" rows="3" :placeholder="placeholder"></textarea><small v-if="errors.reason">{{ errors.reason[0] }}</small></label>
                <footer><button type="button" class="secondary" :disabled="busy" @click="emit('close')">رجوع</button><button type="button" class="danger" :disabled="busy || !reason.trim()" @click="emit('submit', reason.trim())">{{ busy ? 'جاري التنفيذ…' : confirmLabel }}</button></footer>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.sheet-layer { position: fixed; z-index: 1100; inset: 0; display: grid; align-items: end; justify-items: center; padding: 1rem; background: rgba(15, 27, 19, .46); backdrop-filter: blur(3px); }.reason-sheet { width: min(500px, 100%); max-height: calc(100dvh - 2rem); box-sizing: border-box; padding: 1rem; border: 1px solid #eadadd; border-radius: 18px; background: #fff; box-shadow: 0 24px 70px -28px rgba(0, 0, 0, .55); overflow-y: auto; }header { display: flex; align-items: center; justify-content: space-between; }header > div { display: flex; flex-direction: column; }header span { color: #9b4a52; font-size: .63rem; font-weight: 750; }header h2 { margin: .1rem 0 0; color: #3d292c; font-size: .92rem; }header button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid #eadfe1; border-radius: 10px; color: #71565a; background: #fff; }.message { margin: .7rem 0 0; padding: .6rem; border-radius: 9px; color: #6c454a; background: #fff4f5; font-size: .68rem; line-height: 1.65; }.sheet-error { margin-top: .55rem; padding: .5rem; border-radius: 9px; color: #922d36; background: #fff0f1; font-size: .68rem; }label { display: flex; margin-top: .7rem; flex-direction: column; gap: .28rem; color: #5d4c4f; font-size: .67rem; font-weight: 750; }textarea { width: 100%; min-height: 86px; box-sizing: border-box; padding: .65rem; border: 1px solid #e3dadd; border-radius: 10px; outline: none; resize: vertical; font: inherit; font-size: .75rem; }label small { color: #b02a37; font-size: .61rem; }footer { display: flex; gap: .45rem; margin-top: .8rem; }footer button { min-height: 46px; flex: 1; border-radius: 11px; font: inherit; font-size: .72rem; font-weight: 800; }.secondary { border: 1px solid #e0d9db; color: #64575a; background: #fff; }.danger { border: 1px solid #a92e38; color: #fff; background: #a92e38; }button:disabled { opacity: .45; }@media (min-width: 700px) { .sheet-layer { align-items: center; } }@media (max-width: 520px) { .sheet-layer { padding: 0; }.reason-sheet { border-radius: 18px 18px 0 0; } }
</style>
