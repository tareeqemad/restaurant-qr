<script setup>
/**
 * اقتراحات إعادة الطلب — Wave 4. Pick what to reorder, adjust the
 * quantity, optionally route a line to a CHEAPER supplier, and the server
 * mints one draft PO per supplier.
 *
 * The suggested quantity already subtracts what is on the way, so the
 * buyer isn't nudged into ordering stock that is already in a truck.
 */
import { computed, reactive, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { useToast } from '../../../Composables/useToast';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    rows: { type: Array, required: true },
    totals: { type: Object, required: true },
    urgencyCounts: { type: Object, required: true },
    currency: { type: String, default: '₪' },
    canCreatePo: { type: Boolean, default: false },
    urls: { type: Object, required: true },
});

const toast = useToast();

const URGENCY = {
    critical: { label: 'حرج', tone: 'critical' },
    high: { label: 'عالٍ', tone: 'high' },
    medium: { label: 'متوسط', tone: 'medium' },
    low: { label: 'منخفض', tone: 'low' },
};

// One editable pick per row; nothing is selected until the buyer says so.
const picks = reactive(props.rows.map((r) => ({
    id: r.id,
    on: false,
    qty: r.suggestedQty,
    supplierId: r.supplierId ?? '',
})));

const rowById = computed(() => new Map(props.rows.map((r) => [r.id, r])));
const filter = ref('all');

const visible = computed(() => picks.filter((p) => {
    if (filter.value === 'all') return true;
    if (filter.value === 'selected') return p.on;
    return rowById.value.get(p.id)?.urgency === filter.value;
}));

const priceFor = (p) => {
    const row = rowById.value.get(p.id);
    if (! row) return 0;
    const chosen = row.suppliers.find((s) => s.id === Number(p.supplierId));
    return chosen ? chosen.price : row.costPerUnit;
};

const lineCost = (p) => Number(p.qty || 0) * priceFor(p);

const selected = computed(() => picks.filter((p) => p.on && Number(p.qty) > 0));
const selectedCost = computed(() => selected.value.reduce((s, p) => s + lineCost(p), 0));

// One draft PO per distinct supplier — show the buyer how many they'll get.
const supplierCount = computed(() => new Set(
    selected.value.map((p) => p.supplierId || rowById.value.get(p.id)?.supplierId).filter(Boolean),
).size);

const noSupplier = computed(() => selected.value.filter(
    (p) => ! (p.supplierId || rowById.value.get(p.id)?.supplierId),
).length);

const selectAll = () => {
    const target = visible.value;
    const allOn = target.every((p) => p.on);
    target.forEach((p) => { p.on = ! allOn; });
};

const saving = ref(false);
const submit = () => {
    if (selected.value.length === 0) { toast.warning('اختر بنداً واحداً على الأقل.'); return; }
    if (saving.value) return;
    saving.value = true;

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = props.urls.bulkCreate;
    form.style.display = 'none';

    const add = (name, value) => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    };

    add('_token', document.querySelector('meta[name="csrf-token"]')?.content ?? '');
    selected.value.forEach((p, i) => {
        // "id:qty:supplierId" — the supplier segment routes the line to a
        // cheaper vendor than the ingredient's default.
        const sid = p.supplierId || rowById.value.get(p.id)?.supplierId;
        add(`selections[${i}]`, `${p.id}:${Number(p.qty)}${sid ? ':' + sid : ''}`);
    });

    document.body.appendChild(form);
    form.submit();
};

const fmt = (n) => Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 2 });
const money = (n) => `${Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${props.currency}`;
</script>

<template>
    <Head title="اقتراحات إعادة الطلب" />

    <PageHeader title="اقتراحات إعادة الطلب" icon="bi-cart-plus-fill"
                subtitle="شو قرب يخلص — والكمية المقترحة بتحسب استهلاك ٣٠ يوم وبتطرح اللي بالطريق">
        <template #actions>
            <a :href="urls.purchaseOrders" class="btn btn-light"><i class="bi bi-file-earmark-text"></i> أوامر الشراء</a>
        </template>
    </PageHeader>

    <StatRail :stats="[
        { label: 'أصناف مقترحة', value: totals.items, icon: 'bi-box-seam', color: 'primary' },
        { label: 'حرج', value: urgencyCounts.critical, icon: 'bi-exclamation-octagon-fill', color: 'danger' },
        { label: 'عالٍ', value: urgencyCounts.high, icon: 'bi-exclamation-triangle-fill', color: 'accent' },
        { label: 'التكلفة التقديرية', value: money(totals.cost), icon: 'bi-cash-stack', color: 'success' },
    ]" />

    <EmptyState v-if="rows.length === 0" icon="bi-check-circle"
                title="ما في شي محتاج إعادة طلب"
                message="كل المكوّنات فوق حد إعادة الطلب — نطمّنك، المخزون بخير." />

    <DataPanel v-else title="هدف المخزون والكميات المقترحة" :count="rows.length" icon="bi-cart-check">
        <template #actions>
            <button type="button" class="btn btn-light" @click="selectAll">
                <i class="bi bi-check2-square"></i> اختر/ألغِ المعروض
            </button>
        </template>

        <template #filters>
            <div class="rs-chips">
                <button v-for="f in [
                            { key: 'all', label: `الكل (${rows.length})` },
                            { key: 'critical', label: `حرج (${urgencyCounts.critical})` },
                            { key: 'high', label: `عالٍ (${urgencyCounts.high})` },
                            { key: 'selected', label: `المختار (${selected.length})` },
                        ]" :key="f.key"
                        type="button" class="rs-chip" :class="{ 'is-active': filter === f.key }"
                        @click="filter = f.key">
                    {{ f.label }}
                </button>
            </div>
        </template>

        <div class="table-responsive">
            <table class="table align-middle rs-table">
                <thead class="bg-light">
                    <tr>
                        <th style="width: 40px"></th>
                        <th>المكوّن</th>
                        <th>الحالي</th>
                        <th>الحد</th>
                        <th>استهلاك ٣٠ي</th>
                        <th>الكمية المطلوبة</th>
                        <th>المورّد</th>
                        <th>التكلفة</th>
                        <th>الإلحاح</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="p in visible" :key="p.id" :class="{ 'rs-row-on': p.on }">
                        <td>
                            <input v-model="p.on" type="checkbox" class="form-check-input rs-check">
                        </td>
                        <td class="rs-name">
                            {{ rowById.get(p.id).name }}
                            <small>{{ rowById.get(p.id).unit }}</small>
                        </td>
                        <td class="rs-num">{{ fmt(rowById.get(p.id).currentStock) }}</td>
                        <td class="rs-num rs-dim">{{ fmt(rowById.get(p.id).threshold) }}</td>
                        <td class="rs-num rs-dim">{{ fmt(rowById.get(p.id).used30d) }}</td>
                        <td>
                            <input v-model.number="p.qty" type="number" step="0.01" min="0"
                                   class="form-control rs-qty" @focus="p.on = true">
                        </td>
                        <td>
                            <select v-model="p.supplierId" class="form-select rs-supplier">
                                <option v-if="rowById.get(p.id).suppliers.length === 0" value="">بدون مورّد</option>
                                <option v-for="s in rowById.get(p.id).suppliers" :key="s.id" :value="s.id">
                                    {{ s.name }} — {{ money(s.price) }}
                                </option>
                            </select>
                        </td>
                        <td class="rs-num rs-cost">{{ money(lineCost(p)) }}</td>
                        <td>
                            <span class="rs-urg" :class="`rs-urg--${rowById.get(p.id).urgency}`">
                                {{ URGENCY[rowById.get(p.id).urgency]?.label ?? rowById.get(p.id).urgency }}
                                <small v-if="rowById.get(p.id).daysToStockout !== null">
                                    {{ rowById.get(p.id).daysToStockout }} يوم
                                </small>
                            </span>
                        </td>
                    </tr>
                    <tr v-if="visible.length === 0">
                        <td colspan="9" class="text-center text-muted py-4">ما في بنود بهذا الفلتر.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template #footer>
            <div class="rs-foot">
                <div class="rs-summary">
                    <strong>{{ selected.length }}</strong> بند مختار
                    <template v-if="supplierCount > 0"> · <strong>{{ supplierCount }}</strong> أمر شراء</template>
                    <span class="rs-total">{{ money(selectedCost) }}</span>
                </div>
                <span v-if="noSupplier > 0" class="rs-warn">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    {{ noSupplier }} بند بلا مورّد — رح ينحذف من التوليد
                </span>
                <button v-if="canCreatePo" type="button" class="btn btn-primary btn-lg rs-create"
                        :disabled="selected.length === 0 || saving" @click="submit">
                    <i class="bi" :class="saving ? 'bi-arrow-repeat rs-spin' : 'bi-file-earmark-plus'"></i>
                    {{ saving ? 'جارٍ التوليد…' : 'ولّد أوامر شراء (مسودات)' }}
                </button>
            </div>
        </template>
    </DataPanel>
</template>

<style scoped>
.rs-chips { display: flex; gap: .4rem; flex-wrap: wrap; }
.rs-chip {
    min-height: 38px;
    padding: 0 .85rem;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: #fff;
    color: #334155;
    font: inherit;
    font-size: .8rem;
    font-weight: 700;
    cursor: pointer;
}
.rs-chip.is-active { background: rgb(var(--primary-rgb)); border-color: rgb(var(--primary-rgb)); color: #fff; }

.rs-table th { white-space: nowrap; font-size: .8rem; }
.rs-check { width: 20px; height: 20px; }
.rs-name { font-weight: 700; color: #0f172a; }
.rs-name small { display: block; font-weight: 600; color: #94a3b8; font-size: .72rem; }
.rs-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.rs-dim { color: #94a3b8; }
.rs-cost { font-weight: 800; }
.rs-qty { width: 110px; text-align: center; font-weight: 700; }
.rs-supplier { min-width: 190px; font-size: .82rem; }
.rs-row-on { background: rgba(var(--primary-rgb), .05); }

.rs-urg {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    border-radius: 8px;
    padding: .15rem .55rem;
    font-size: .74rem;
    font-weight: 800;
}
.rs-urg small { font-weight: 600; opacity: .8; font-size: .66rem; }
.rs-urg--critical { background: #fef2f2; color: #b91c1c; }
.rs-urg--high { background: #fff7ed; color: #c2410c; }
.rs-urg--medium { background: #fffbeb; color: #b45309; }
.rs-urg--low { background: #f1f5f9; color: #475569; }

.rs-foot { display: flex; align-items: center; gap: .9rem; flex-wrap: wrap; }
.rs-summary { font-size: .86rem; color: #475569; }
.rs-summary strong { color: #0f172a; font-size: 1rem; }
.rs-total { margin-inline-start: .6rem; font-weight: 900; color: rgb(var(--primary-rgb)); font-size: 1.05rem; }
.rs-warn { color: #b45309; font-size: .8rem; font-weight: 700; }
.rs-create { margin-inline-start: auto; font-weight: 800; }
.rs-spin { animation: rs-rotate 1s linear infinite; display: inline-block; }
@keyframes rs-rotate { to { transform: rotate(360deg); } }
</style>
