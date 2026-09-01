<script setup>
import { computed, ref, watch } from 'vue';
import { formatMoney } from '../../Composables/useMoney';

const props = defineProps({
    open: { type: Boolean, required: true },
    invoice: { type: Object, default: null },
    methods: { type: Array, required: true },
    currency: { type: Object, required: true },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits({
    close: () => true,
    submit: (payload) => Number(payload?.amount) > 0 && Boolean(payload?.reason),
});

const amount = ref('');
const method = ref('cash');
const reason = ref('');
const reference = ref('');
const notes = ref('');
const confirming = ref(false);
const selected = ref({});

const selectedLines = computed(() =>
    (props.invoice?.refund_items ?? [])
        .filter((item) => selected.value[item.id]?.enabled)
        .map((item) => ({
            order_item_id: item.id,
            quantity: Number(selected.value[item.id].quantity),
            disposition: selected.value[item.id].disposition,
        })),
);
const selectedAmount = computed(() =>
    (props.invoice?.refund_items ?? []).reduce((total, item) => {
        const row = selected.value[item.id];
        return total + (row?.enabled ? Number(row.quantity || 0) * Number(item.unit_total || 0) : 0);
    }, 0),
);
const chosenMethod = computed(() => props.methods.find((option) => option.code === method.value));

watch(
    () => [props.open, props.invoice?.id],
    ([open]) => {
        if (!open) return;
        amount.value = '';
        method.value = props.methods[0]?.code ?? 'cash';
        reason.value = '';
        reference.value = '';
        notes.value = '';
        confirming.value = false;
        selected.value = Object.fromEntries((props.invoice?.refund_items ?? []).map((item) => [item.id, {
            enabled: false,
            quantity: Math.min(1, Number(item.available_quantity)),
            disposition: 'none',
        }]));
    },
    { immediate: true },
);

watch(selectedAmount, (value) => {
    if (selectedLines.value.length) amount.value = value.toFixed(2);
});

function toggleItem(item) {
    const row = selected.value[item.id];
    row.enabled = !row.enabled;
    confirming.value = false;
    if (!selectedLines.value.length) amount.value = '';
}

function arm() {
    if (Number(amount.value) <= 0 || !reason.value.trim()) return;
    confirming.value = true;
}

function submit() {
    emit('submit', {
        amount: Number(amount.value),
        method: method.value,
        reason: reason.value.trim(),
        reference: reference.value.trim() || null,
        notes: notes.value.trim() || null,
        lines: selectedLines.value,
    });
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="sheet-layer" @click.self="emit('close')">
            <section class="refund-sheet" role="dialog" aria-modal="true" aria-labelledby="refund-title">
                <header>
                    <div><span>إجراء مالي حساس</span><h2 id="refund-title">استرداد من {{ invoice?.number }}</h2></div>
                    <button type="button" aria-label="إغلاق" :disabled="busy" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
                </header>

                <div class="refund-limit">
                    <span>أقصى مبلغ قابل للاسترداد</span>
                    <strong>{{ formatMoney(invoice?.refundable_balance, currency) }}</strong>
                </div>
                <div v-if="error" class="sheet-error"><i class="bi bi-exclamation-circle"></i> {{ error }}</div>

                <section v-if="invoice?.refund_items?.length" class="return-items">
                    <div class="section-heading"><strong>ما الذي عاد؟</strong><small>اختياري — الاختيار يحسب المبلغ والضريبة تلقائياً</small></div>
                    <article v-for="item in invoice.refund_items" :key="item.id" :class="{ selected: selected[item.id]?.enabled }">
                        <button type="button" class="item-toggle" @click="toggleItem(item)">
                            <i :class="selected[item.id]?.enabled ? 'bi bi-check-square-fill' : 'bi bi-square'"></i>
                            <span><strong>{{ item.name }}</strong><small>{{ item.order_number }} · المتاح {{ item.available_quantity }} · {{ formatMoney(item.unit_total, currency) }} للوحدة</small></span>
                        </button>
                        <div v-if="selected[item.id]?.enabled" class="item-controls">
                            <label><span>الكمية</span><input v-model="selected[item.id].quantity" type="number" min="0.01" :max="item.available_quantity" step="0.01"></label>
                            <label><span>توثيق حالة المرتجع</span><select v-model="selected[item.id].disposition"><option value="none">تصحيح مالي فقط</option><option value="waste">تالف / هدر — توثيق</option><option value="restock">قابل لإعادة البيع — توثيق</option></select></label>
                        </div>
                    </article>
                    <small v-if="errors.lines" class="line-error">{{ errors.lines[0] }}</small>
                    <p class="inventory-note"><i class="bi bi-info-circle"></i> هذا التصنيف للسجل والمراجعة؛ لا يعيد مواد الوصفة الخام للمخزون تلقائياً. أي حركة مخزون تُسجّل من شاشة المخزون.</p>
                </section>

                <label class="field">
                    <span>{{ selectedLines.length ? 'قيمة البنود المحتسبة' : 'مبلغ مرن بدون تحديد أصناف' }} *</span>
                    <input v-model="amount" type="number" min="0.01" :max="invoice?.refundable_balance" step="0.01" inputmode="decimal" placeholder="0.00" :readonly="selectedLines.length > 0">
                    <small v-if="errors.amount">{{ errors.amount[0] }}</small>
                </label>

                <span class="method-title">أين نعيد المبلغ؟</span>
                <div class="method-list">
                    <button v-for="option in methods" :key="option.code" type="button" :class="{ active: method === option.code }" @click="method = option.code; confirming = false">
                        {{ option.label }}
                    </button>
                </div>
                <p v-if="method === 'original'" class="method-hint"><i class="bi bi-arrow-counterclockwise"></i> سيقسّم النظام المبلغ تلقائياً على الدفعات الأصلية ويمنع تجاوز أي دفعة.</p>
                <p v-else-if="method === 'customer_advance'" class="method-hint"><i class="bi bi-wallet2"></i> سيضاف المبلغ إلى رصيد الزبون دون حركة نقدية.</p>

                <label class="field">
                    <span>سبب الاسترداد *</span>
                    <textarea v-model="reason" maxlength="500" rows="2" placeholder="اكتب سبباً واضحاً للمراجعة المحاسبية"></textarea>
                    <small v-if="errors.reason">{{ errors.reason[0] }}</small>
                </label>
                <label class="field"><span>رقم المرجع <em>اختياري</em></span><input v-model="reference" maxlength="100" placeholder="مرجع التحويل إن وجد"></label>
                <label class="field"><span>ملاحظات <em>اختياري</em></span><input v-model="notes" maxlength="1000" placeholder="تفاصيل إضافية للسجل"></label>

                <footer v-if="!confirming">
                    <button type="button" class="secondary" :disabled="busy" @click="emit('close')">إلغاء</button>
                    <button type="button" class="danger" :disabled="busy || Number(amount) <= 0 || !reason.trim()" @click="arm">مراجعة الاسترداد</button>
                </footer>
                <div v-else class="confirm-card">
                    <p>سيصدر إشعار دائن ثم يُعاد <strong>{{ formatMoney(amount, currency) }}</strong> عبر <strong>{{ chosenMethod?.label }}</strong>. العملية قابلة للعكس بصلاحية ومسار تدقيق.</p>
                    <div>
                        <button type="button" class="secondary" :disabled="busy" @click="confirming = false">رجوع</button>
                        <button type="button" class="danger" :disabled="busy" @click="submit">{{ busy ? 'جاري التسجيل…' : 'تأكيد الاسترداد' }}</button>
                    </div>
                </div>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.sheet-layer { position: fixed; z-index: 1100; inset: 0; display: grid; align-items: end; justify-items: center; padding: 1rem; background: rgba(15, 27, 19, .46); backdrop-filter: blur(3px); }
.refund-sheet { width: min(520px, 100%); max-height: calc(100dvh - 2rem); box-sizing: border-box; padding: 1rem; border: 1px solid #ead8da; border-radius: 18px; background: #fff; box-shadow: 0 24px 70px -28px rgba(0, 0, 0, .55); overflow-y: auto; }
header { display: flex; align-items: center; justify-content: space-between; }
header > div { display: flex; flex-direction: column; }
header span { color: #a13c46; font-size: .64rem; font-weight: 750; }
header h2 { margin: .1rem 0 0; color: #3f282b; font-size: .92rem; }
header button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid #eadfe1; border-radius: 10px; color: #71565a; background: #fff; }
.refund-limit { display: flex; align-items: center; justify-content: space-between; margin-top: .7rem; padding: .6rem .7rem; border-radius: 10px; color: #8f2d37; background: #fff1f2; }
.refund-limit span { font-size: .68rem; }
.refund-limit strong { font-size: .9rem; }
.sheet-error { margin-top: .55rem; padding: .5rem; border-radius: 9px; color: #922d36; background: #fff0f1; font-size: .68rem; }
.return-items { display: grid; gap: .4rem; margin-top: .7rem; }
.section-heading { display: flex; flex-direction: column; color: #514448; }
.section-heading strong { font-size: .72rem; }
.section-heading small { color: #88777b; font-size: .6rem; }
.return-items article { padding: .5rem; border: 1px solid #e7dfe1; border-radius: 11px; background: #fff; }
.return-items article.selected { border-color: #d8989f; background: #fff8f8; }
.item-toggle { display: flex; width: 100%; gap: .5rem; align-items: center; border: 0; color: #6f5b5f; background: transparent; text-align: start; font: inherit; }
.item-toggle > i { color: #ae3540; font-size: .9rem; }
.item-toggle > span { display: flex; min-width: 0; flex: 1; flex-direction: column; }
.item-toggle strong { font-size: .7rem; }
.item-toggle small { color: #918185; font-size: .58rem; }
.item-controls { display: grid; grid-template-columns: 1fr 1.5fr; gap: .4rem; margin-top: .45rem; padding-top: .45rem; border-top: 1px dashed #eadfe1; }
.item-controls label { display: flex; flex-direction: column; gap: .2rem; color: #716166; font-size: .58rem; }
.item-controls input, .item-controls select { min-height: 38px; padding: .35rem .45rem; border: 1px solid #e1d5d8; border-radius: 8px; background: #fff; font: inherit; font-size: .66rem; }
.line-error { color: #b02a37; font-size: .61rem; }
.inventory-note { display: flex; gap: .35rem; margin: .05rem 0 0; padding: .45rem .55rem; border-radius: 8px; color: #665f48; background: #fff9e8; font-size: .59rem; line-height: 1.55; }
.field { display: flex; margin-top: .65rem; flex-direction: column; gap: .27rem; }
.field > span { color: #5d4c4f; font-size: .67rem; font-weight: 750; }
.field em { color: #958588; font-size: .59rem; font-style: normal; }
.field input, .field textarea { width: 100%; min-height: 44px; box-sizing: border-box; padding: .6rem .7rem; border: 1px solid #e3dadd; border-radius: 10px; outline: none; resize: vertical; font: inherit; font-size: .76rem; }
.field small { color: #b02a37; font-size: .61rem; }
.method-list { display: grid; grid-template-columns: repeat(2, 1fr); gap: .4rem; margin-top: .65rem; }
.method-list button { min-height: 44px; border: 1px solid #e3dadd; border-radius: 10px; color: #655357; background: #fff; font: inherit; font-size: .7rem; font-weight: 750; }
.method-list button.active { border-color: #b02a37; color: #9f2531; background: #fff1f2; }
.method-title { display: block; margin-top: .65rem; color: #5d4c4f; font-size: .67rem; font-weight: 750; }
.method-hint { display: flex; gap: .35rem; margin: .4rem 0 0; padding: .45rem .55rem; border-radius: 8px; color: #5c596c; background: #f5f4fb; font-size: .61rem; line-height: 1.55; }
footer, .confirm-card > div { display: flex; gap: .45rem; margin-top: .8rem; }
footer button, .confirm-card button { min-height: 46px; flex: 1; border-radius: 11px; font: inherit; font-size: .72rem; font-weight: 800; }
.secondary { border: 1px solid #e0d9db; color: #64575a; background: #fff; }
.danger { border: 1px solid #b02a37; color: #fff; background: #b02a37; }
button:disabled { opacity: .45; }
.confirm-card { padding: .65rem; border-radius: 10px; background: #fff4f5; }
.confirm-card p { margin: 0; color: #6d343a; text-align: center; font-size: .71rem; }
.confirm-card p strong { color: #9f2531; }
@media (min-width: 700px) { .sheet-layer { align-items: center; } }
@media (max-width: 520px) { .sheet-layer { padding: 0; } .refund-sheet { max-height: 94dvh; border-radius: 18px 18px 0 0; } }
</style>
