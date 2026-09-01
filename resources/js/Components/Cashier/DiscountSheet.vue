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
.sheet-layer { position: fixed; z-index: 1100; inset: 0; display: grid; align-items: end; justify-items: center; padding: 1rem; background: rgba(15, 27, 19, .44); backdrop-filter: blur(3px); }
.discount-sheet { width: min(520px, 100%); max-height: calc(100dvh - 2rem); box-sizing: border-box; padding: 1rem; border: 1px solid #dce5df; border-radius: 18px; background: #fff; box-shadow: 0 24px 70px -28px rgba(0, 0, 0, .55); overflow-y: auto; }
header { display: flex; align-items: center; justify-content: space-between; }
header > div { display: flex; flex-direction: column; }
header span { color: #63756a; font-size: .64rem; font-weight: 750; }
header h2 { margin: .1rem 0 0; color: #263b2e; font-size: .92rem; }
header button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid #dfe6e2; border-radius: 10px; color: #617067; background: #fff; }
.discount-context { display: grid; grid-template-columns: 1fr 1fr; gap: .4rem; margin-top: .7rem; }
.discount-context span { display: flex; flex-direction: column; gap: .15rem; padding: .55rem .65rem; border-radius: 10px; color: #66746b; background: #f3f7f4; font-size: .62rem; }
.discount-context strong { color: #1f6939; font-size: .78rem; }
.sheet-error { margin-top: .55rem; padding: .5rem; border-radius: 9px; color: #922d36; background: #fff0f1; font-size: .68rem; }
.type-switch { display: grid; grid-template-columns: 1fr 1fr; gap: .4rem; margin-top: .7rem; }
.type-switch button { min-height: 44px; border: 1px solid #dce4df; border-radius: 10px; color: #56665c; background: #fff; font: inherit; font-size: .7rem; font-weight: 800; }
.type-switch button.active { border-color: rgb(var(--primary-rgb, 22 101 52)); color: rgb(var(--primary-rgb, 22 101 52)); background: #eff8f1; }
.field { display: flex; margin-top: .65rem; flex-direction: column; gap: .27rem; }
.field > span { color: #526158; font-size: .67rem; font-weight: 750; }
.field em { color: #8a968e; font-size: .59rem; font-style: normal; font-weight: 500; }
.field input, .field select, .field textarea { width: 100%; min-height: 44px; box-sizing: border-box; padding: .6rem .7rem; border: 1px solid #dce4df; border-radius: 10px; outline: none; resize: vertical; font: inherit; font-size: .75rem; }
.field small { color: #b02a37; font-size: .61rem; }
.accounting-note { display: flex; align-items: flex-start; gap: .4rem; margin: .7rem 0 0; padding: .55rem; border-radius: 9px; color: #65520d; background: #fff9e7; font-size: .64rem; line-height: 1.6; }
footer { display: flex; gap: .45rem; margin-top: .8rem; }
footer button { min-height: 46px; flex: 1; border-radius: 11px; font: inherit; font-size: .72rem; font-weight: 800; }
.secondary { border: 1px solid #dce4df; color: #536159; background: #fff; }
.primary { border: 1px solid rgb(var(--primary-rgb, 22 101 52)); color: #fff; background: rgb(var(--primary-rgb, 22 101 52)); }
button:disabled { opacity: .45; }
@media (min-width: 700px) { .sheet-layer { align-items: center; } }
@media (max-width: 520px) { .sheet-layer { padding: 0; } .discount-sheet { max-height: 94dvh; border-radius: 18px 18px 0 0; } }
</style>
