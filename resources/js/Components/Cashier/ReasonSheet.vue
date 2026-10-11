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
.sheet-layer { position: fixed; z-index: 1100; inset: 0; display: grid; align-items: end; justify-items: center; padding: 1rem; background: rgba(15, 27, 19, .46); backdrop-filter: none; }.reason-sheet { width: min(500px, 100%); max-height: calc(100dvh - 2rem); box-sizing: border-box; padding: 1rem; border: 1px solid var(--line); border-radius: 12px; background: var(--surface, #FFFDFA); box-shadow: none; overflow-y: auto; }header { display: flex; align-items: center; justify-content: space-between; }header > div { display: flex; flex-direction: column; }header span { color: #9b4a52; font-size: .875rem; font-weight: 750; }header h2 { margin: .1rem 0 0; color: var(--ink, var(--ink)); font-size: .92rem; }header button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid var(--line); border-radius: 8px; color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); }.message { margin: .7rem 0 0; padding: .6rem; border-radius: 9px; color: var(--ink); background: #fff4f5; font-size: .875rem; line-height: 1.65; }.sheet-error { margin-top: .55rem; padding: .5rem; border-radius: 9px; color: #922d36; background: #fff0f1; font-size: .875rem; }label { display: flex; margin-top: .7rem; flex-direction: column; gap: .28rem; color: var(--muted, var(--muted)); font-size: .875rem; font-weight: 750; }textarea { width: 100%; min-height: 86px; box-sizing: border-box; padding: .65rem; border: 1px solid var(--line, var(--line)); border-radius: 10px; outline: none; resize: vertical; font: inherit; font-size: .875rem; }label small { color: #b02a37; font-size: .875rem; }footer { display: flex; gap: .45rem; margin-top: .8rem; }footer button { min-height: 46px; flex: 1; border-radius: 8px; font: inherit; font-size: .875rem; font-weight: 800; }.secondary { border: 1px solid var(--line, var(--line)); color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); }.danger { border: 1px solid #a92e38; color: #FFFDFA; background: #a92e38; }button:disabled { opacity: .45; }@media (min-width: 700px) { .sheet-layer { align-items: center; } }@media (max-width: 520px) { .sheet-layer { padding: 0; }.reason-sheet { border-radius: 12px 12px 0 0; } }

/* Task-specific component layout. */
.sheet-layer{padding:0;display:flex;align-items:stretch;justify-content:flex-end;background:rgb(37 52 58 / 42%);backdrop-filter:none}.reason-sheet{width:min(560px,100%);max-width:100%;height:100dvh;max-height:100dvh;min-height:0;margin:0;border:0;border-radius:0;overflow-y:auto;background:var(--surface);box-shadow:none;padding:0}.reason-sheet>header{position:sticky;top:0;z-index:3;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 24px;border-bottom:1px solid var(--line);background:var(--surface);margin:0}.reason-sheet>header h2{font-size:23px;line-height:1.4;margin:4px 0 0;color:var(--ink)}.reason-sheet>header span,.reason-sheet>header small{font-size:14px;color:var(--muted)}.reason-sheet>header button{min-width:44px;min-height:44px;border-radius:8px;background:var(--canvas);color:var(--ink)}.reason-sheet>form{padding:22px 24px;display:grid;gap:18px}.reason-sheet>form>footer,.reason-sheet>footer{position:sticky;bottom:0;z-index:2;display:flex;gap:12px;justify-content:flex-end;padding:16px 24px;margin:0;background:var(--surface);border-top:1px solid var(--line)}.reason-sheet footer button{min-height:48px;border-radius:8px;font-size:15px;flex:1}.reason-sheet label{font-size:14px;line-height:1.6}.reason-sheet input:not([type=checkbox]):not([type=radio]),.reason-sheet select{min-height:48px;border-radius:6px;font-size:16px}.reason-sheet textarea{border-radius:6px;font-size:15px;min-height:90px}.sheet-error{margin:16px 24px;padding:12px;border-radius:6px;font-size:14px;line-height:1.7}.method-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.method-list button{min-height:62px;border-radius:6px;font-size:15px;padding:12px}.method-list button.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}.amount-field input,.money-input input{font-size:26px!important;min-height:60px!important;font-variant-numeric:tabular-nums}.balance-banner,.refund-limit,.total-banner,.discount-context,.debt-grid{margin:20px 24px;padding:18px;border:1px solid var(--line);border-radius:8px;background:var(--canvas)}.balance-banner strong,.refund-limit strong,.total-banner strong{font-size:30px;line-height:1.35;color:var(--ink)}.amount-choice,.type-switch{margin:18px 24px 0;border-radius:6px;gap:8px}.amount-choice button,.type-switch button{min-height:46px;border-radius:6px;font-size:14px}.optional-tool{border:1px solid var(--line);border-radius:6px;padding:12px}.optional-tool summary{font-size:14px;min-height:28px}.warning,.accounting-note,.explanation,.explain,.inventory-note,.method-hint{font-size:14px;line-height:1.7;border-radius:6px}.transfer-list,.split-manager,.split-builder,.return-items{padding:18px 24px}.transfer-list>article,.split-manager>article{padding:14px;border-radius:6px;border:1px solid var(--line);margin-bottom:10px}.transfer-actions button{min-height:44px;border-radius:6px}.decision-card,.confirm-card,.payment-confirm{padding:20px;margin:16px 24px;border-radius:8px;border:1px solid var(--line)}@media(max-width:560px){.reason-sheet>header{padding:18px}.reason-sheet>form{padding:18px}.reason-sheet>form>footer,.reason-sheet>footer{padding:14px 18px}.balance-banner,.refund-limit,.total-banner,.discount-context,.debt-grid{margin:18px}.method-list{grid-template-columns:1fr 1fr}}
</style>
