<script setup>
/**
 * استلام بضاعة — Wave 4. Partial receipts are normal: enter what actually
 * arrived and the rest stays open on the PO.
 *
 * The price column matters beyond this screen — whatever is entered here
 * becomes the weighted-average cost and the supplier's price history, so
 * it is pre-filled from the PO and only overridden deliberately.
 */
import { computed, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { useToast } from '../../../Composables/useToast';
import { localDateInput } from '../../../Utils/dateInput';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    po: { type: Object, required: true },
    lines: { type: Array, required: true },
    locations: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
});

const toast = useToast();

const today = localDateInput();
const plusDays = (days) => {
    const d = new Date();
    d.setDate(d.getDate() + Number(days || 0));
    return localDateInput(d);
};

const locationId = ref(props.locations.find((l) => l.isDefault)?.id ?? (props.locations[0]?.id ?? ''));

// One editable row per PO line; qty starts empty so nothing is received
// by accident — the operator types what physically arrived.
const rows = reactive(props.lines.map((l) => ({
    id: l.id,
    qty: '',
    price: l.unitPrice,
    batch: '',
    // Pre-date expiry from the ingredient's shelf life when it has one —
    // saves typing on the common case and is still editable.
    expiry: l.tracksExpiry && l.shelfLifeDays ? plusDays(l.shelfLifeDays) : '',
})));

const lineById = computed(() => new Map(props.lines.map((l) => [l.id, l])));

const receiveAll = () => {
    for (const r of rows) {
        const line = lineById.value.get(r.id);
        r.qty = line.outstanding > 0 ? String(line.outstanding) : '';
    }
};

const overOrdered = (r) => Number(r.qty || 0) > (lineById.value.get(r.id)?.outstanding ?? 0) + 0.0001;

const entered = computed(() => rows.filter((r) => Number(r.qty || 0) > 0));
const totalValue = computed(() => entered.value.reduce((s, r) => s + Number(r.qty || 0) * Number(r.price || 0), 0));
const anyOver = computed(() => rows.some(overOrdered));

const problem = computed(() => {
    if (props.locations.length > 0 && ! locationId.value) return 'اختر وجهة التخزين.';
    if (entered.value.length === 0) return 'اكتب الكمية اللي وصلت لصنف واحد على الأقل.';
    if (anyOver.value) return 'في كمية أكبر من المتبقي بالأمر.';
    return null;
});

const saving = ref(false);
const submit = () => {
    if (problem.value) { toast.warning(problem.value); return; }
    if (saving.value) return;
    saving.value = true;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = props.urls.submit;
    form.style.display = 'none';

    const add = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value ?? '';
        form.appendChild(input);
    };

    add('_token', document.querySelector('meta[name="csrf-token"]')?.content ?? '');
    if (locationId.value) add('storage_location_id', locationId.value);

    for (const r of entered.value) {
        add(`receipts[${r.id}]`, r.qty);
        if (Number(r.price) > 0) add(`unit_prices[${r.id}]`, r.price);
        if (r.batch.trim()) add(`batch_numbers[${r.id}]`, r.batch.trim());
        if (r.expiry) add(`expiry_dates[${r.id}]`, r.expiry);
    }

    document.body.appendChild(form);
    form.submit();
};

const fmt = (n) => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 4 });
const money = (n) => Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
</script>

<template>
    <Head :title="`استلام ${po.number}`" />

    <PageHeader :title="`استلام بضاعة — ${po.number}`" icon="bi-box-arrow-in-down"
                :subtitle="po.supplierName ? `من ${po.supplierName}` : ''"
                :crumbs="[{ label: 'أوامر الشراء', url: urls.back }]" />

    <div class="rc-note">
        <i class="bi bi-info-circle-fill"></i>
        <div>
            <strong>كيف بيمشي الاستلام</strong>
            <ul>
                <li><b>الكمية</b> = اللي وصل فعلاً. تقدر تستلم أقل من المطلوب والباقي بيضل مفتوح بالأمر.</li>
                <li><b>السعر</b> معبّى من أمر الشراء وقابل للتعديل — لو فاتورة المورّد مختلفة، اكتب السعر الفعلي: هو اللي بيحدّد متوسط التكلفة وتاريخ الأسعار.</li>
                <li><b>رقم الدفعة والصلاحية</b> اختياريان لكل صنف — بيغذّوا الـ FIFO وتنبيهات الانتهاء.</li>
            </ul>
        </div>
    </div>

    <DataPanel title="وجهة التخزين" icon="bi-house-door">
        <div class="rc-dest">
            <label class="rc-field">
                <span>المخزن أو المحطة *</span>
                <select v-model="locationId" class="form-select" :class="{ 'is-invalid': locations.length && ! locationId }">
                    <option value="">— اختر الوجهة —</option>
                    <option v-for="l in locations" :key="l.id" :value="l.id">{{ l.label }}</option>
                </select>
                <small>كل كمية مستلمة بتظهر بحركة المخزون على هذا الموقع.</small>
            </label>
            <button type="button" class="btn btn-light rc-fill" @click="receiveAll">
                <i class="bi bi-check2-all"></i> استلم كل المتبقي
            </button>
        </div>
    </DataPanel>

    <DataPanel title="الأصناف" :count="lines.length" icon="bi-list-check">
        <div class="table-responsive">
            <table class="table align-middle rc-table">
                <thead class="bg-light">
                    <tr>
                        <th>الصنف</th>
                        <th>مطلوب</th>
                        <th>مستلم سابقاً</th>
                        <th>متبقٍ</th>
                        <th>الكمية الواصلة</th>
                        <th>سعر/وحدة</th>
                        <th>رقم الدفعة</th>
                        <th>الصلاحية</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id"
                        :class="{ 'rc-row-done': lineById.get(r.id).isFull, 'rc-row-over': overOrdered(r) }">
                        <td class="rc-name">
                            {{ lineById.get(r.id).ingredientName }}
                            <small>{{ lineById.get(r.id).unitCode }}</small>
                        </td>
                        <td class="rc-num">{{ fmt(lineById.get(r.id).ordered) }}</td>
                        <td class="rc-num">{{ fmt(lineById.get(r.id).received) }}</td>
                        <td class="rc-num rc-out">{{ fmt(lineById.get(r.id).outstanding) }}</td>
                        <td>
                            <input v-model="r.qty" type="number" step="0.0001" min="0"
                                   class="form-control rc-input" :class="{ 'is-invalid': overOrdered(r) }"
                                   :disabled="lineById.get(r.id).isFull" placeholder="0">
                        </td>
                        <td>
                            <input v-model.number="r.price" type="number" step="0.0001" min="0"
                                   class="form-control rc-input" :disabled="lineById.get(r.id).isFull">
                        </td>
                        <td>
                            <input v-model="r.batch" class="form-control rc-batch" maxlength="100"
                                   :disabled="lineById.get(r.id).isFull" placeholder="اختياري">
                        </td>
                        <td>
                            <input v-model="r.expiry" type="date" class="form-control rc-date"
                                   :min="today" :disabled="lineById.get(r.id).isFull">
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template #footer>
            <div class="rc-foot">
                <div class="rc-total">
                    <span>قيمة الاستلام</span>
                    <strong>{{ money(totalValue) }} {{ po.currencyCode ?? '' }}</strong>
                    <small>{{ entered.length }} صنف</small>
                </div>
                <span v-if="problem" class="rc-problem"><i class="bi bi-exclamation-triangle-fill"></i> {{ problem }}</span>
                <a :href="urls.back" class="btn btn-light">إلغاء</a>
                <button type="button" class="btn btn-success btn-lg rc-save"
                        :disabled="Boolean(problem) || saving" @click="submit">
                    <i class="bi" :class="saving ? 'bi-arrow-repeat rc-spin' : 'bi-box-arrow-in-down'"></i>
                    {{ saving ? 'جارٍ التسجيل…' : 'سجّل الاستلام' }}
                </button>
            </div>
        </template>
    </DataPanel>
</template>

<style scoped>
.rc-note {
    display: flex;
    gap: .7rem;
    align-items: flex-start;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    color: #1e40af;
    border-radius: 14px;
    padding: .85rem 1rem;
    margin-bottom: .9rem;
    font-size: .82rem;
}
.rc-note > i { font-size: 1.15rem; }
.rc-note ul { margin: .3rem 0 0; padding-inline-start: 1.1rem; display: grid; gap: .2rem; }

.rc-dest { display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap; }
.rc-field { display: flex; flex-direction: column; gap: .35rem; margin: 0; flex: 1 1 280px; }
.rc-field > span { font-size: .84rem; font-weight: 800; color: #334155; }
.rc-field small { font-size: .72rem; color: #94a3b8; }
.rc-fill { min-height: 46px; font-weight: 700; }

.rc-table th { white-space: nowrap; font-size: .8rem; }
.rc-name { font-weight: 700; color: #0f172a; }
.rc-name small { display: block; font-weight: 600; color: #94a3b8; font-size: .72rem; }
.rc-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.rc-out { font-weight: 800; color: rgb(var(--primary-rgb)); }
.rc-input { width: 110px; text-align: center; font-weight: 700; }
.rc-batch { min-width: 120px; }
.rc-date { min-width: 150px; }
.rc-row-done { opacity: .5; }
.rc-row-over { background: rgba(254, 226, 226, .4); }

.rc-foot { display: flex; align-items: center; gap: .8rem; flex-wrap: wrap; }
.rc-total { display: flex; align-items: baseline; gap: .5rem; }
.rc-total span { font-size: .82rem; color: #64748b; font-weight: 700; }
.rc-total strong { font-size: 1.25rem; font-weight: 900; color: #059669; }
.rc-total small { color: #94a3b8; font-size: .74rem; }
.rc-problem { color: #b45309; font-size: .82rem; font-weight: 700; }
.rc-save { margin-inline-start: auto; font-weight: 800; }
.rc-spin { animation: rc-rotate 1s linear infinite; display: inline-block; }
@keyframes rc-rotate { to { transform: rotate(360deg); } }
</style>
