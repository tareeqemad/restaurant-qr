<script setup>
import { computed, ref, watch } from 'vue';
import { formatMoney } from '../../Composables/useMoney';

const props = defineProps({
    open: { type: Boolean, required: true },
    workspace: { type: Object, default: null },
    categories: { type: Array, default: () => [] },
    cap: { type: Object, default: null },
    currency: { type: Object, required: true },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits({
    close: () => true,
    submit: (payload) => Number(payload?.value) > 0 && Boolean(payload?.reason),
});

const type = ref('percent');
const value = ref('');
const reason = ref('');
const name = ref('');
const categoryId = ref('');

const subtotal = computed(() => props.workspace?.invoice?.subtotal
    ?? props.workspace?.orders?.reduce((sum, order) => sum + Number(order.total || 0), 0)
    ?? 0);
const preview = computed(() => type.value === 'percent'
    ? Math.min(subtotal.value, subtotal.value * Number(value.value || 0) / 100)
    : Math.min(subtotal.value, Number(value.value || 0)));
const capLabel = computed(() => {
    if (!props.cap) return 'بدون حد إضافي لهذا الدور';
    return type.value === 'percent'
        ? `حد دورك ${Number(props.cap.percent || 0)}%`
        : `حد دورك ${formatMoney(props.cap.fixed || 0, props.currency)}`;
});

watch(
    () => [props.open, props.workspace?.kind, props.workspace?.id],
    ([open]) => {
        if (!open) return;
        type.value = 'percent';
        value.value = '';
        reason.value = '';
        name.value = '';
        categoryId.value = '';
    },
    { immediate: true },
);

function submit() {
    emit('submit', {
        type: type.value,
        value: Number(value.value),
        reason: reason.value.trim(),
        name: name.value.trim() || null,
        category_lookup_id: categoryId.value ? Number(categoryId.value) : null,
    });
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="sheet-layer" @click.self="emit('close')">
            <section class="discount-sheet" role="dialog" aria-modal="true" aria-labelledby="discount-title">
                <header>
                    <div><span>تعديل محسوب ومسجّل</span><h2 id="discount-title">إضافة خصم · {{ workspace?.label }}</h2></div>
                    <button type="button" aria-label="إغلاق" :disabled="busy" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
                </header>

                <div class="discount-context">
                    <span>القيمة قبل الخصم <strong>{{ formatMoney(subtotal, currency) }}</strong></span>
                    <span>الخصم المتوقع <strong>−{{ formatMoney(preview, currency) }}</strong></span>
                </div>
                <div v-if="error" class="sheet-error"><i class="bi bi-exclamation-circle"></i> {{ error }}</div>

                <div class="type-switch" aria-label="نوع الخصم">
                    <button type="button" :class="{ active: type === 'percent' }" @click="type = 'percent'; value = ''">نسبة %</button>
                    <button type="button" :class="{ active: type === 'fixed' }" @click="type = 'fixed'; value = ''">مبلغ ثابت</button>
                </div>

                <label class="field">
                    <span>القيمة * <em>{{ capLabel }}</em></span>
                    <input v-model="value" type="number" min="0.01" :max="type === 'percent' ? 100 : subtotal" step="0.01" inputmode="decimal" placeholder="0.00">
                    <small v-if="errors.value">{{ errors.value[0] }}</small>
                </label>

                <label v-if="categories.length" class="field">
                    <span>التصنيف <em>اختياري</em></span>
                    <select v-model="categoryId">
                        <option value="">أخرى</option>
                        <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.label }}</option>
                    </select>
                </label>
                <label class="field"><span>اسم مختصر <em>اختياري</em></span><input v-model="name" maxlength="120" placeholder="مثال: تعويض تأخير"></label>
                <label class="field">
                    <span>سبب الخصم *</span>
                    <textarea v-model="reason" maxlength="500" rows="2" placeholder="سبب واضح يظهر في سجل المراجعة"></textarea>
                    <small v-if="errors.reason">{{ errors.reason[0] }}</small>
                </label>

                <p class="accounting-note"><i class="bi bi-journal-check"></i> إذا كانت الفاتورة صادرة سيُعكس قيدها السابق ويُعاد ترحيلها بالمبلغ الجديد تلقائياً.</p>

                <footer>
                    <button type="button" class="secondary" :disabled="busy" @click="emit('close')">إلغاء</button>
                    <button type="button" class="primary" :disabled="busy || Number(value) <= 0 || !reason.trim()" @click="submit">
                        {{ busy ? 'جاري التطبيق…' : 'تطبيق الخصم' }}
                    </button>
                </footer>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.sheet-layer { position: fixed; z-index: 1100; inset: 0; display: grid; align-items: end; justify-items: center; padding: 1rem; background: rgba(15, 27, 19, .44); backdrop-filter: none; }
.discount-sheet { width: min(520px, 100%); max-height: calc(100dvh - 2rem); box-sizing: border-box; padding: 1rem; border: 1px solid var(--line, var(--line)); border-radius: 12px; background: var(--surface, #FFFDFA); box-shadow: none; overflow-y: auto; }
header { display: flex; align-items: center; justify-content: space-between; }
header > div { display: flex; flex-direction: column; }
header span { color: var(--muted, var(--muted)); font-size: .875rem; font-weight: 750; }
header h2 { margin: .1rem 0 0; color: var(--ink, var(--ink)); font-size: .92rem; }
header button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid var(--line, var(--line)); border-radius: 8px; color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); }
.discount-context { display: grid; grid-template-columns: 1fr 1fr; gap: .4rem; margin-top: .7rem; }
.discount-context span { display: flex; flex-direction: column; gap: .15rem; padding: .55rem .65rem; border-radius: 10px; color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); font-size: .875rem; }
.discount-context strong { color: var(--ink, var(--ink)); font-size: .875rem; }
.sheet-error { margin-top: .55rem; padding: .5rem; border-radius: 9px; color: #922d36; background: #fff0f1; font-size: .875rem; }
.type-switch { display: grid; grid-template-columns: 1fr 1fr; gap: .4rem; margin-top: .7rem; }
.type-switch button { min-height: 44px; border: 1px solid var(--line, var(--line)); border-radius: 8px; color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); font: inherit; font-size: .875rem; font-weight: 800; }
.type-switch button.active { border-color: var(--brand, #A3422B); color: var(--brand, #A3422B); background: var(--surface, #FFFDFA); }
.field { display: flex; margin-top: .65rem; flex-direction: column; gap: .27rem; }
.field > span { color: var(--muted, var(--muted)); font-size: .875rem; font-weight: 750; }
.field em { color: var(--muted, var(--muted)); font-size: .875rem; font-style: normal; font-weight: 500; }
.field input, .field select, .field textarea { width: 100%; min-height: 44px; box-sizing: border-box; padding: .6rem .7rem; border: 1px solid var(--line, var(--line)); border-radius: 8px; outline: none; resize: vertical; font: inherit; font-size: .875rem; }
.field small { color: #b02a37; font-size: .875rem; }
.accounting-note { display: flex; align-items: flex-start; gap: .4rem; margin: .7rem 0 0; padding: .55rem; border-radius: 9px; color: #65520d; background: #fff9e7; font-size: .875rem; line-height: 1.6; }
footer { display: flex; gap: .45rem; margin-top: .8rem; }
footer button { min-height: 46px; flex: 1; border-radius: 8px; font: inherit; font-size: .875rem; font-weight: 800; }
.secondary { border: 1px solid var(--line, var(--line)); color: var(--muted, var(--muted)); background: var(--surface, #FFFDFA); }
.primary { border: 1px solid var(--brand, #A3422B); color: #FFFDFA; background: var(--brand, #A3422B); }
button:disabled { opacity: .45; }
@media (min-width: 700px) { .sheet-layer { align-items: center; } }
@media (max-width: 520px) { .sheet-layer { padding: 0; } .discount-sheet { max-height: 94dvh; border-radius: 12px 12px 0 0; } }

/* Task-specific component layout. */
.sheet-layer{padding:0;display:flex;align-items:stretch;justify-content:flex-end;background:rgb(37 52 58 / 42%);backdrop-filter:none}.discount-sheet{width:min(560px,100%);max-width:100%;height:100dvh;max-height:100dvh;min-height:0;margin:0;border:0;border-radius:0;overflow-y:auto;background:var(--surface);box-shadow:none;padding:0}.discount-sheet>header{position:sticky;top:0;z-index:3;display:flex;justify-content:space-between;align-items:center;gap:16px;padding:22px 24px;border-bottom:1px solid var(--line);background:var(--surface);margin:0}.discount-sheet>header h2{font-size:23px;line-height:1.4;margin:4px 0 0;color:var(--ink)}.discount-sheet>header span,.discount-sheet>header small{font-size:14px;color:var(--muted)}.discount-sheet>header button{min-width:44px;min-height:44px;border-radius:8px;background:var(--canvas);color:var(--ink)}.discount-sheet>form{padding:22px 24px;display:grid;gap:18px}.discount-sheet>form>footer,.discount-sheet>footer{position:sticky;bottom:0;z-index:2;display:flex;gap:12px;justify-content:flex-end;padding:16px 24px;margin:0;background:var(--surface);border-top:1px solid var(--line)}.discount-sheet footer button{min-height:48px;border-radius:8px;font-size:15px;flex:1}.discount-sheet label{font-size:14px;line-height:1.6}.discount-sheet input:not([type=checkbox]):not([type=radio]),.discount-sheet select{min-height:48px;border-radius:6px;font-size:16px}.discount-sheet textarea{border-radius:6px;font-size:15px;min-height:90px}.sheet-error{margin:16px 24px;padding:12px;border-radius:6px;font-size:14px;line-height:1.7}.method-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.method-list button{min-height:62px;border-radius:6px;font-size:15px;padding:12px}.method-list button.active{background:var(--brand-soft);border-color:var(--brand);color:var(--brand)}.amount-field input,.money-input input{font-size:26px!important;min-height:60px!important;font-variant-numeric:tabular-nums}.balance-banner,.refund-limit,.total-banner,.discount-context,.debt-grid{margin:20px 24px;padding:18px;border:1px solid var(--line);border-radius:8px;background:var(--canvas)}.balance-banner strong,.refund-limit strong,.total-banner strong{font-size:30px;line-height:1.35;color:var(--ink)}.amount-choice,.type-switch{margin:18px 24px 0;border-radius:6px;gap:8px}.amount-choice button,.type-switch button{min-height:46px;border-radius:6px;font-size:14px}.optional-tool{border:1px solid var(--line);border-radius:6px;padding:12px}.optional-tool summary{font-size:14px;min-height:28px}.warning,.accounting-note,.explanation,.explain,.inventory-note,.method-hint{font-size:14px;line-height:1.7;border-radius:6px}.transfer-list,.split-manager,.split-builder,.return-items{padding:18px 24px}.transfer-list>article,.split-manager>article{padding:14px;border-radius:6px;border:1px solid var(--line);margin-bottom:10px}.transfer-actions button{min-height:44px;border-radius:6px}.decision-card,.confirm-card,.payment-confirm{padding:20px;margin:16px 24px;border-radius:8px;border:1px solid var(--line)}@media(max-width:560px){.discount-sheet>header{padding:18px}.discount-sheet>form{padding:18px}.discount-sheet>form>footer,.discount-sheet>footer{padding:14px 18px}.balance-banner,.refund-limit,.total-banner,.discount-context,.debt-grid{margin:18px}.method-list{grid-template-columns:1fr 1fr}}
</style>
