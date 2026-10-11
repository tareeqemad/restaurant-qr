<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, required: true },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
    result: { type: Object, default: null },
});

const emit = defineEmits({
    close: () => true,
    submit: (payload) => Boolean(payload?.name && payload?.phone),
});

const name = ref('');
const phone = ref('');

watch(() => props.open, (open) => {
    if (! open) return;
    name.value = '';
    phone.value = '';
});

function submit() {
    if (props.busy || ! name.value.trim() || ! phone.value.trim()) return;
    emit('submit', { name: name.value.trim(), phone: phone.value.trim() });
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="sheet-layer" @click.self="emit('close')">
            <section class="customer-sheet" role="dialog" aria-modal="true" aria-labelledby="customer-create-title">
                <header>
                    <div><span>سجل الزبائن</span><h2 id="customer-create-title">{{ result ? 'تم حفظ الزبون' : 'إضافة زبون' }}</h2></div>
                    <button type="button" aria-label="إغلاق" :disabled="busy" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
                </header>

                <div v-if="result" class="saved-card">
                    <span class="saved-icon"><i class="bi bi-person-check-fill"></i></span>
                    <strong>{{ result.name }}</strong>
                    <small>{{ result.phone }}</small>
                    <p>{{ result.created ? 'تم إنشاء ملف الزبون وربطه بسجل الطلبات والنقاط.' : 'هذا الرقم مسجّل مسبقاً؛ استُخدم الملف نفسه.' }}</p>
                    <button type="button" class="primary" @click="emit('close')">تم</button>
                </div>

                <form v-else @submit.prevent="submit">
                    <p class="hint">الرقم هو هوية الزبون في كل الفروع؛ إذا كان موجوداً لن ننشئ نسخة ثانية.</p>
                    <div v-if="error" class="sheet-error"><i class="bi bi-exclamation-circle"></i> {{ error }}</div>
                    <label><span>اسم الزبون</span><input v-model="name" maxlength="120" autocomplete="name" autofocus><small v-if="errors.name">{{ errors.name[0] }}</small><small v-if="errors.customer_name">{{ errors.customer_name[0] }}</small></label>
                    <label><span>رقم الجوال</span><input v-model="phone" type="tel" maxlength="32" inputmode="tel" autocomplete="tel" placeholder="0599…"><small v-if="errors.phone">{{ errors.phone[0] }}</small><small v-if="errors.customer_phone">{{ errors.customer_phone[0] }}</small></label>
                    <footer><button type="button" class="secondary" :disabled="busy" @click="emit('close')">إلغاء</button><button type="submit" class="primary" :disabled="busy || !name.trim() || !phone.trim()">{{ busy ? 'جاري الحفظ…' : 'حفظ الزبون' }}</button></footer>
                </form>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.sheet-layer { position: fixed; z-index: 1200; inset: 0; display: grid; place-items: center; padding: 1rem; background: rgba(15, 27, 19, .48); backdrop-filter: none; }
.customer-sheet { width: min(480px, 100%); max-height: calc(100dvh - 2rem); box-sizing: border-box; padding: 1rem; border: 1px solid var(--line, var(--line)); border-radius: 12px; background: var(--surface, #FFFDFA); box-shadow: none; overflow-y: auto; }
header { display: flex; align-items: center; justify-content: space-between; } header > div { display: flex; flex-direction: column; } header span { color: var(--muted, var(--muted)); font-size: .875rem; font-weight: 750; } header h2 { margin: .08rem 0 0; color: var(--ink, var(--ink)); font-size: 1rem; } header button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid var(--line, var(--line)); border-radius: 8px; color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); }
.hint { margin: .8rem 0 0; padding: .7rem; border-radius: 11px; color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); font-size: .875rem; line-height: 1.7; }
.sheet-error { margin-top: .6rem; padding: .55rem; border-radius: 9px; color: #922d36; background: #fff0f1; font-size: .875rem; }
label { display: grid; gap: .3rem; margin-top: .7rem; color: var(--muted, var(--muted)); font-size: .875rem; font-weight: 800; } input { min-height: 46px; box-sizing: border-box; padding: .55rem .65rem; border: 1px solid var(--line, var(--line)); border-radius: 8px; background: var(--surface, #FFFDFA); font: inherit; } label small { color: #a62f38; }
footer { display: flex; gap: .5rem; margin-top: 1rem; } button { min-height: 44px; font: inherit; font-weight: 800; cursor: pointer; } footer button, .saved-card > button { flex: 1; padding-inline: .9rem; border-radius: 8px; } .secondary { border: 1px solid var(--line, var(--line)); color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); } .primary { border: 1px solid var(--brand, #A3422B); color: #FFFDFA; background: var(--brand, #A3422B); } button:disabled { opacity: .5; }
.saved-card { display: grid; justify-items: center; gap: .35rem; margin-top: .8rem; padding: 1rem; border-radius: 12px; color: var(--ink, var(--ink)); background: var(--surface, #FFFDFA); text-align: center; }.saved-icon { display: grid; width: 54px; height: 54px; place-items: center; border-radius: 12px; color: #FFFDFA; background: var(--brand, #A3422B); font-size: 1.35rem; }.saved-card strong { margin-top: .3rem; font-size: 1rem; }.saved-card small { color: var(--muted, var(--muted)); }.saved-card p { margin: .45rem 0; color: var(--muted, var(--muted)); font-size: .875rem; line-height: 1.65; }.saved-card > button { width: 100%; margin-top: .6rem; }
@media (max-width: 560px) { .sheet-layer { align-items: end; padding: 0; }.customer-sheet { width: 100%; border-radius: 12px 12px 0 0; } }

/* Task-specific component layout. */
.sheet-layer{padding:0;display:flex;align-items:stretch;justify-content:flex-end;background:rgb(37 52 58 / 42%);backdrop-filter:none}.customer-sheet{width:min(560px,100%);max-width:100%;height:100dvh;max-height:100dvh;min-height:0;margin:0;border:0;border-radius:0;overflow-y:auto;background:var(--surface);box-shadow:none;padding:0}.customer-sheet>header{position:sticky;top:0;z-index:3;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 24px;border-bottom:1px solid var(--line);background:var(--surface);margin:0}.customer-sheet>header h2{font-size:23px;line-height:1.4;margin:4px 0 0;color:var(--ink)}.customer-sheet>header span,.customer-sheet>header small{font-size:14px;color:var(--muted)}.customer-sheet>header button{min-width:44px;min-height:44px;border-radius:8px;background:var(--canvas);color:var(--ink)}.customer-sheet>form{padding:22px 24px;display:grid;gap:18px}.customer-sheet>form>footer,.customer-sheet>footer{position:sticky;bottom:0;z-index:2;display:flex;gap:12px;justify-content:flex-end;padding:16px 24px;margin:0;background:var(--surface);border-top:1px solid var(--line)}.customer-sheet footer button{min-height:48px;border-radius:8px;font-size:15px;flex:1}.customer-sheet label{font-size:14px;line-height:1.6}.customer-sheet input:not([type=checkbox]):not([type=radio]),.customer-sheet select{min-height:48px;border-radius:6px;font-size:16px}.customer-sheet textarea{border-radius:6px;font-size:15px;min-height:90px}.sheet-error{margin:16px 24px;padding:12px;border-radius:6px;font-size:14px;line-height:1.7}.method-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.method-list button{min-height:62px;border-radius:6px;font-size:15px;padding:12px}.method-list button.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}.amount-field input,.money-input input{font-size:26px!important;min-height:60px!important;font-variant-numeric:tabular-nums}.balance-banner,.refund-limit,.total-banner,.discount-context,.debt-grid{margin:20px 24px;padding:18px;border:1px solid var(--line);border-radius:8px;background:var(--canvas)}.balance-banner strong,.refund-limit strong,.total-banner strong{font-size:30px;line-height:1.35;color:var(--ink)}.amount-choice,.type-switch{margin:18px 24px 0;border-radius:6px;gap:8px}.amount-choice button,.type-switch button{min-height:46px;border-radius:6px;font-size:14px}.optional-tool{border:1px solid var(--line);border-radius:6px;padding:12px}.optional-tool summary{font-size:14px;min-height:28px}.warning,.accounting-note,.explanation,.explain,.inventory-note,.method-hint{font-size:14px;line-height:1.7;border-radius:6px}.transfer-list,.split-manager,.split-builder,.return-items{padding:18px 24px}.transfer-list>article,.split-manager>article{padding:14px;border-radius:6px;border:1px solid var(--line);margin-bottom:10px}.transfer-actions button{min-height:44px;border-radius:6px}.decision-card,.confirm-card,.payment-confirm{padding:20px;margin:16px 24px;border-radius:8px;border:1px solid var(--line)}@media(max-width:560px){.customer-sheet>header{padding:18px}.customer-sheet>form{padding:18px}.customer-sheet>form>footer,.customer-sheet>footer{padding:14px 18px}.balance-banner,.refund-limit,.total-banner,.discount-context,.debt-grid{margin:18px}.method-list{grid-template-columns:1fr 1fr}}
</style>
