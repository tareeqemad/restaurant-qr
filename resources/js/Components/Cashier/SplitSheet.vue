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
    save: (splits) => Array.isArray(splits) && splits.length >= 2,
    pay: (payload) => Number(payload?.split?.id) > 0,
    clear: () => true,
});

const rows = ref([]);
const paymentTarget = ref(null);
const reference = ref('');
const clearConfirm = ref(false);

const existing = computed(() => props.invoice?.splits ?? []);
const hasPaid = computed(() => existing.value.some((split) => split.paid));
const editable = computed(() => !hasPaid.value && Number(props.invoice?.paid_total || 0) <= 0.001);
const sum = computed(() => rows.value.reduce((total, row) => total + Number(row.amount || 0), 0));
const balanced = computed(() => Math.abs(sum.value - Number(props.invoice?.total || 0)) <= 0.001);

watch(
    () => [props.open, props.invoice?.id, props.invoice?.splits],
    ([open]) => {
        if (!open) return;
        paymentTarget.value = null;
        reference.value = '';
        clearConfirm.value = false;

        if (existing.value.length && !hasPaid.value) {
            rows.value = existing.value.map((split) => ({
                label: split.label || '',
                amount: Number(split.amount).toFixed(2),
                method: split.method,
            }));
        } else if (!existing.value.length) {
            rows.value = [blankRow('الشخص 1'), blankRow('الشخص 2')];
            equalize();
        }
    },
    { immediate: true, deep: true },
);

function blankRow(label = '') {
    return { label, amount: '', method: props.methods[0]?.code ?? 'cash' };
}

function equalize() {
    const count = rows.value.length;
    if (!count) return;
    const cents = Math.round(Number(props.invoice?.total || 0) * 100);
    const share = Math.floor(cents / count);
    rows.value = rows.value.map((row, index) => ({
        ...row,
        amount: ((index === count - 1 ? cents - share * (count - 1) : share) / 100).toFixed(2),
    }));
}

function addRow() {
    if (rows.value.length >= 8) return;
    rows.value.push(blankRow(`الشخص ${rows.value.length + 1}`));
    equalize();
}

function removeRow(index) {
    if (rows.value.length <= 2) return;
    rows.value.splice(index, 1);
    equalize();
}

function save() {
    if (!balanced.value || rows.value.some((row) => Number(row.amount) <= 0)) return;
    emit('save', rows.value.map((row, index) => ({
        label: row.label.trim() || `الشخص ${index + 1}`,
        amount: Number(row.amount),
        method: row.method,
    })));
}

function startPayment(split) {
    paymentTarget.value = split;
    reference.value = '';
    clearConfirm.value = false;
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="sheet-layer" @click.self="emit('close')">
            <section class="split-sheet" role="dialog" aria-modal="true" aria-labelledby="split-title">
                <header>
                    <div><span>تقسيم وتحصيل من مكان واحد</span><h2 id="split-title">{{ invoice?.number }}</h2></div>
                    <button type="button" aria-label="إغلاق" :disabled="busy" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
                </header>

                <div class="total-banner"><span>إجمالي الفاتورة</span><strong>{{ formatMoney(invoice?.total, currency) }}</strong></div>
                <div v-if="error" class="sheet-error"><i class="bi bi-exclamation-circle"></i> {{ error }}</div>

                <div v-if="existing.length && hasPaid" class="split-manager">
                    <p class="locked-note"><i class="bi bi-lock"></i> بدأ التحصيل؛ لا يمكن تغيير التقسيم الآن.</p>
                    <article v-for="split in existing" :key="split.id" :class="{ paid: split.paid }">
                        <span><strong>{{ split.label || 'جزء' }}</strong><small>{{ methods.find((method) => method.code === split.method)?.label || split.method }}</small></span>
                        <b>{{ formatMoney(split.amount, currency) }}</b>
                        <span v-if="split.paid" class="paid-mark"><i class="bi bi-check2"></i> مدفوع</span>
                        <button v-else type="button" :disabled="busy" @click="startPayment(split)">تحصيل</button>
                    </article>
                </div>

                <div v-if="paymentTarget" class="payment-confirm">
                    <p>تأكيد تحصيل <strong>{{ formatMoney(paymentTarget.amount, currency) }}</strong> عن {{ paymentTarget.label }}؟</p>
                    <label v-if="['card', 'transfer'].includes(paymentTarget.method)">
                        <span>رقم المرجع <em>اختياري</em></span>
                        <input v-model="reference" maxlength="255" placeholder="رقم الحوالة أو الإيصال">
                    </label>
                    <div>
                        <button type="button" class="secondary" :disabled="busy" @click="paymentTarget = null">رجوع</button>
                        <button type="button" class="primary" :disabled="busy" @click="emit('pay', { split: paymentTarget, reference: reference.trim() || null })">
                            {{ busy ? 'جاري التحصيل…' : 'تأكيد التحصيل' }}
                        </button>
                    </div>
                </div>

                <div v-else-if="editable" class="split-builder">
                    <div class="builder-head">
                        <span>{{ existing.length ? 'تعديل الأجزاء' : 'أجزاء الفاتورة' }}</span>
                        <div><button type="button" @click="equalize">توزيع متساوٍ</button><button type="button" :disabled="rows.length >= 8" @click="addRow">+ جزء</button></div>
                    </div>
                    <div class="row-list">
                        <div v-for="(row, index) in rows" :key="index" class="split-row">
                            <span class="row-number">{{ index + 1 }}</span>
                            <input v-model="row.label" maxlength="255" aria-label="اسم الجزء" placeholder="الاسم">
                            <input v-model="row.amount" type="number" min="0.01" step="0.01" inputmode="decimal" aria-label="قيمة الجزء">
                            <select v-model="row.method" aria-label="طريقة الدفع"><option v-for="method in methods" :key="method.code" :value="method.code">{{ method.label }}</option></select>
                            <button type="button" class="remove-row" :disabled="rows.length <= 2" aria-label="حذف الجزء" @click="removeRow(index)"><i class="bi bi-x"></i></button>
                        </div>
                    </div>
                    <div class="sum-check" :class="{ invalid: !balanced }">
                        <span>مجموع الأجزاء</span><strong>{{ formatMoney(sum, currency) }}</strong>
                        <small v-if="!balanced">يجب أن يساوي إجمالي الفاتورة تماماً.</small>
                    </div>
                    <small v-if="errors.splits" class="field-error">{{ errors.splits[0] }}</small>
                </div>

                <div v-if="clearConfirm" class="clear-confirm">
                    <p>إلغاء التقسيم وإعادة الفاتورة إلى التحصيل العادي؟</p>
                    <div><button type="button" class="secondary" @click="clearConfirm = false">رجوع</button><button type="button" class="danger" :disabled="busy" @click="emit('clear')">تأكيد الإلغاء</button></div>
                </div>

                <footer v-if="!paymentTarget && !clearConfirm">
                    <button type="button" class="secondary" :disabled="busy" @click="emit('close')">إغلاق</button>
                    <button v-if="existing.length && editable" type="button" class="danger-outline" :disabled="busy" @click="clearConfirm = true">إلغاء التقسيم</button>
                    <button v-if="editable" type="button" class="primary" :disabled="busy || !balanced" @click="save">{{ existing.length ? 'حفظ التعديل' : 'حفظ التقسيم' }}</button>
                </footer>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.sheet-layer { position: fixed; z-index: 1100; inset: 0; display: grid; align-items: end; justify-items: center; padding: 1rem; background: rgba(15, 27, 19, .44); backdrop-filter: blur(3px); }
.split-sheet { width: min(680px, 100%); max-height: calc(100dvh - 2rem); box-sizing: border-box; padding: 1rem; border: 1px solid #dce5df; border-radius: 18px; background: #fff; box-shadow: 0 24px 70px -28px rgba(0, 0, 0, .55); overflow-y: auto; }
header { display: flex; align-items: center; justify-content: space-between; }
header > div { display: flex; flex-direction: column; }
header span { color: #63756a; font-size: .64rem; font-weight: 750; }
header h2 { margin: .1rem 0 0; color: #263b2e; font-size: .92rem; }
header button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid #dfe6e2; border-radius: 10px; color: #617067; background: #fff; }
.total-banner { display: flex; align-items: center; justify-content: space-between; margin-top: .7rem; padding: .6rem .7rem; border-radius: 10px; color: #24613a; background: #eff8f2; font-size: .68rem; }
.total-banner strong { font-size: .9rem; }
.sheet-error, .locked-note { margin: .55rem 0 0; padding: .5rem; border-radius: 9px; font-size: .67rem; }
.sheet-error { color: #922d36; background: #fff0f1; }
.locked-note { color: #765307; background: #fff8e7; }
.split-manager { display: flex; flex-direction: column; gap: .4rem; }
.split-manager article { display: grid; grid-template-columns: minmax(0, 1fr) auto auto; gap: .5rem; align-items: center; padding: .55rem; border: 1px solid #e2e8e4; border-radius: 10px; }
.split-manager article > span:first-child { display: flex; flex-direction: column; }
.split-manager strong { font-size: .72rem; }.split-manager small { color: #7e8a82; font-size: .59rem; }.split-manager b { font-size: .73rem; }
.split-manager article > button { min-height: 38px; padding-inline: .7rem; border: 0; border-radius: 8px; color: #fff; background: rgb(var(--primary-rgb, 22 101 52)); font: inherit; font-size: .66rem; font-weight: 800; }
.paid-mark { color: #24733e; font-size: .65rem; font-weight: 800; }
.builder-head { display: flex; align-items: center; justify-content: space-between; margin-top: .7rem; color: #4e5f55; font-size: .67rem; font-weight: 800; }
.builder-head > div { display: flex; gap: .3rem; }.builder-head button { min-height: 36px; padding-inline: .55rem; border: 1px solid #dce4df; border-radius: 8px; color: #3e624b; background: #fff; font: inherit; font-size: .62rem; font-weight: 750; }
.row-list { display: flex; flex-direction: column; gap: .35rem; margin-top: .45rem; }
.split-row { display: grid; grid-template-columns: 28px minmax(90px, 1fr) 105px 115px 34px; gap: .35rem; align-items: center; }
.row-number { display: grid; height: 32px; place-items: center; border-radius: 8px; color: #4a6754; background: #edf5ef; font-size: .64rem; font-weight: 800; }
.split-row input, .split-row select { width: 100%; min-height: 42px; box-sizing: border-box; padding-inline: .5rem; border: 1px solid #dce4df; border-radius: 9px; font: inherit; font-size: .67rem; }
.remove-row { display: grid; width: 34px; height: 34px; place-items: center; border: 0; border-radius: 8px; color: #a02d37; background: #fff0f1; }
.sum-check { display: flex; align-items: center; gap: .45rem; margin-top: .5rem; padding: .5rem .6rem; border-radius: 9px; color: #23643a; background: #f0f8f2; font-size: .66rem; }.sum-check strong { margin-inline-start: auto; }.sum-check small { font-size: .58rem; }.sum-check.invalid { color: #952d36; background: #fff1f2; }
.field-error { display: block; margin-top: .25rem; color: #a72e38; font-size: .61rem; }
.payment-confirm, .clear-confirm { margin-top: .7rem; padding: .7rem; border-radius: 11px; background: #f5f8f6; }
.payment-confirm p, .clear-confirm p { margin: 0; color: #425348; text-align: center; font-size: .71rem; }.payment-confirm label { display: flex; flex-direction: column; gap: .25rem; margin-top: .55rem; font-size: .64rem; }.payment-confirm em { color: #8b958e; font-style: normal; }.payment-confirm input { min-height: 42px; padding-inline: .6rem; border: 1px solid #dce4df; border-radius: 9px; font: inherit; }
.payment-confirm > div, .clear-confirm > div, footer { display: flex; gap: .4rem; margin-top: .65rem; }
footer button, .payment-confirm button, .clear-confirm button { min-height: 44px; flex: 1; border-radius: 10px; font: inherit; font-size: .69rem; font-weight: 800; }
.secondary { border: 1px solid #dce4df; color: #536159; background: #fff; }.primary { border: 1px solid rgb(var(--primary-rgb, 22 101 52)); color: #fff; background: rgb(var(--primary-rgb, 22 101 52)); }.danger { border: 1px solid #aa303a; color: #fff; background: #aa303a; }.danger-outline { border: 1px solid #e0aeb3; color: #9b2b35; background: #fff7f8; }
button:disabled { opacity: .45; }
@media (min-width: 700px) { .sheet-layer { align-items: center; } }
@media (max-width: 620px) { .sheet-layer { padding: 0; }.split-sheet { max-height: 94dvh; border-radius: 18px 18px 0 0; }.split-row { grid-template-columns: 28px 1fr 90px 34px; }.split-row select { grid-column: 2 / 4; }.builder-head { align-items: flex-start; gap: .4rem; }.builder-head > div { flex-wrap: wrap; justify-content: flex-end; } }
</style>
