<script setup>
/**
 * مساحة الجرد — Wave 4. One row per ingredient: type the physically
 * counted quantity, see the variance and its money value instantly.
 *
 * Two rules this screen must never soften:
 *  - «فاضي» (null) and «صفر» are different answers. Empty = not counted
 *    yet and finalize leaves that ingredient alone; 0 = counted and there
 *    is none left, and finalize writes it down to zero.
 *  - 0.0001 is the epsilon everywhere (server rounds to 4 decimals), so a
 *    row only reads as a variance when it really is one.
 *
 * Rows autosave on blur through the SAME validated endpoint the manual
 * "save all" uses — the retired component had its own unvalidated path,
 * which let a negative count through.
 */
import { computed, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { useToast } from '../../../Composables/useToast';
import { formPost } from '../../../Support/formPost';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    count: { type: Object, required: true },
    rows: { type: Array, required: true },
    summary: { type: Object, required: true },
    can: { type: Object, required: true },
    currency: { type: String, default: '₪' },
    urls: { type: Object, required: true },
});

const toast = useToast();
const { ask } = useConfirm();

const EPS = 0.0001;

// Local editable mirror. `counted` holds '' for "not counted" so an
// emptied number input round-trips as null, never as 0.
const draft = reactive(props.rows.map((r) => ({
    id: r.id,
    counted: r.countedQty === null ? '' : String(r.countedQty),
    notes: r.notes,
})));
const byId = computed(() => new Map(props.rows.map((r) => [r.id, r])));
const rowState = reactive({});   // id → 'dirty' | 'saving' | 'saved' | 'error'
const rowError = reactive({});

const filter = ref('all');       // all | pending | variance | matches
const search = ref('');

// ── Derived per row ──────────────────────────────────────────────────
const countedOf = (d) => (d.counted === '' || d.counted === null ? null : Number(d.counted));

const varianceOf = (d) => {
    const c = countedOf(d);
    if (c === null) return null;
    return c - Number(byId.value.get(d.id)?.systemQty ?? 0);
};

const varianceCostOf = (d) => {
    const v = varianceOf(d);
    if (v === null) return null;
    return v * Number(byId.value.get(d.id)?.costPerUnit ?? 0);
};

const toneOf = (d) => {
    const v = varianceOf(d);
    if (v === null) return 'pending';
    if (Math.abs(v) < EPS) return 'match';
    return v < 0 ? 'short' : 'over';
};

const visible = computed(() => {
    const q = search.value.trim().toLowerCase();
    return draft.filter((d) => {
        const row = byId.value.get(d.id);
        if (! row) return false;
        if (q && ! row.name.toLowerCase().includes(q)) return false;
        const tone = toneOf(d);
        if (filter.value === 'pending') return tone === 'pending';
        if (filter.value === 'variance') return tone === 'short' || tone === 'over';
        if (filter.value === 'matches') return tone === 'match';
        return true;
    });
});

// Live totals from the draft — the operator sees progress as they type,
// without waiting for the server's stored variance to catch up.
const live = computed(() => {
    let counted = 0, matches = 0, shortages = 0, overages = 0, net = 0;
    for (const d of draft) {
        const tone = toneOf(d);
        if (tone === 'pending') continue;
        counted++;
        if (tone === 'match') matches++;
        else if (tone === 'short') shortages++;
        else overages++;
        net += varianceCostOf(d) ?? 0;
    }
    return { counted, matches, shortages, overages, net };
});

// ── Saving ───────────────────────────────────────────────────────────
const markDirty = (d) => { rowState[d.id] = 'dirty'; delete rowError[d.id]; };

const post = async (counts, notes) => {
    const res = await fetch(props.urls.save, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify({ counts, notes }),
    });
    const data = await res.json().catch(() => null);
    return { ok: res.ok && data?.ok, message: data?.message ?? (data?.errors ? Object.values(data.errors)[0]?.[0] : null) };
};

const saveRow = async (d) => {
    if (! props.count.editable || ! props.can.update) return;
    if (rowState[d.id] !== 'dirty') return;   // untouched blur = no request

    rowState[d.id] = 'saving';
    const counted = countedOf(d);
    try {
        const { ok, message } = await post({ [d.id]: counted }, { [d.id]: d.notes ?? '' });
        if (ok) {
            rowState[d.id] = 'saved';
            setTimeout(() => { if (rowState[d.id] === 'saved') delete rowState[d.id]; }, 3000);
        } else {
            rowState[d.id] = 'error';
            rowError[d.id] = message ?? 'فشل الحفظ';
        }
    } catch {
        rowState[d.id] = 'error';
        rowError[d.id] = 'تعذّر الاتصال';
    }
};

const savingAll = ref(false);
const saveAll = async () => {
    if (! props.count.editable || ! props.can.update || savingAll.value) return;
    savingAll.value = true;

    const counts = {};
    const notes = {};
    for (const d of draft) {
        counts[d.id] = countedOf(d);
        notes[d.id] = d.notes ?? '';
    }

    try {
        const { ok, message } = await post(counts, notes);
        if (ok) {
            toast.success('تم حفظ كل التعديلات.');
            Object.keys(rowState).forEach((k) => delete rowState[k]);
            router.reload({ only: ['rows', 'summary'], preserveScroll: true });
        } else {
            toast.error(message ?? 'تعذّر الحفظ.');
        }
    } catch {
        toast.error('انقطع الاتصال — ما انحفظ.');
    } finally {
        savingAll.value = false;
    }
};

// ── Finalize / cancel ────────────────────────────────────────────────
const finalize = async () => {
    const yes = await ask({
        title: `اعتماد الجرد ${props.count.number}؟`,
        message: `رح تنطبّق التسويات على ${live.value.counted} صنف معدود. الأصناف الفاضية ما رح تتأثر، والاعتماد ما بينعكس.`,
        confirmLabel: 'اعتمد وطبّق التسويات',
        danger: true,
    });
    if (yes) formPost(props.urls.finalize);
};

const cancelReason = ref('');
const cancelling = ref(false);
const submitCancel = () => {
    if (! cancelReason.value.trim()) return;
    formPost(props.urls.cancel, { reason: cancelReason.value.trim() });
};

const fmtQty = (n) => (n === null ? '—' : Number(n).toLocaleString('en-US', { maximumFractionDigits: 4 }));
const fmtMoney = (n) => `${Number(n || 0).toLocaleString('en-US', { maximumFractionDigits: 2 })} ${props.currency}`;
</script>

<template>
    <Head :title="`جرد ${count.number}`" />

    <PageHeader :title="`جرد ${count.number}`" icon="bi-clipboard-check"
                :subtitle="`${count.locationName}${count.countDate ? ' · ' + count.countDate : ''}`"
                :crumbs="[{ label: 'الجرد', url: urls.index }]">
        <template #actions>
            <a :href="urls.export" class="btn btn-light"><i class="bi bi-file-earmark-excel"></i> تصدير</a>
        </template>
    </PageHeader>

    <div class="sc-status" :class="`sc-status--${count.status}`">
        <i class="bi" :class="count.status === 'draft' ? 'bi-pencil-square' : (count.status === 'finalized' ? 'bi-check-circle-fill' : 'bi-x-circle-fill')"></i>
        <div>
            <strong>{{ count.statusLabel }}</strong>
            <span v-if="count.status === 'finalized'">
                اعتمده {{ count.finalizerName ?? '—' }} · {{ count.finalizedAt }}
            </span>
            <span v-else-if="count.status === 'draft'">
                أنشأه {{ count.creatorName ?? '—' }} · {{ count.createdAgo }} — الكميات بتنحفظ تلقائياً وما بتتطبّق حتى تعتمد
            </span>
            <span v-else>{{ count.notes }}</span>
        </div>
    </div>

    <StatRail :stats="[
        { label: 'معدود', value: `${live.counted} / ${summary.total}`, icon: 'bi-check2-square', color: 'primary' },
        { label: 'مطابق', value: live.matches, icon: 'bi-check-circle', color: 'success' },
        { label: 'نقص', value: live.shortages, icon: 'bi-arrow-down-circle', color: 'danger' },
        { label: 'زيادة', value: live.overages, icon: 'bi-arrow-up-circle', color: 'accent' },
        { label: 'صافي الفرق', value: fmtMoney(live.net), icon: 'bi-cash-stack', color: live.net < 0 ? 'danger' : 'success' },
    ]" />

    <DataPanel title="بنود الجرد" :count="summary.total" icon="bi-list-check">
        <template #filters>
            <div class="sc-tools">
                <input v-model="search" class="form-control sc-search" placeholder="🔍 ابحث عن مكوّن…">
                <div class="sc-chips">
                    <button v-for="f in [
                                { key: 'all', label: 'الكل' },
                                { key: 'pending', label: `لم يُعدّ (${summary.total - live.counted})` },
                                { key: 'variance', label: `فروقات (${live.shortages + live.overages})` },
                                { key: 'matches', label: `مطابق (${live.matches})` },
                            ]" :key="f.key"
                            type="button" class="sc-chip" :class="{ 'is-active': filter === f.key }"
                            @click="filter = f.key">
                        {{ f.label }}
                    </button>
                </div>
            </div>
        </template>

        <div class="table-responsive">
            <table class="table align-middle sc-table">
                <thead class="bg-light">
                    <tr>
                        <th>المكوّن</th>
                        <th>بالنظام</th>
                        <th>المعدود</th>
                        <th>الفرق</th>
                        <th>القيمة</th>
                        <th>ملاحظات</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="d in visible" :key="d.id" :class="`sc-row--${toneOf(d)}`">
                        <td class="sc-name">
                            {{ byId.get(d.id).name }}
                            <small>{{ byId.get(d.id).unit }}</small>
                        </td>
                        <td class="sc-num">{{ fmtQty(byId.get(d.id).systemQty) }}</td>
                        <td>
                            <input v-model="d.counted" type="number" step="0.0001" min="0"
                                   class="form-control sc-input" :disabled="! count.editable || ! can.update"
                                   placeholder="—"
                                   @input="markDirty(d)" @blur="saveRow(d)">
                        </td>
                        <td class="sc-num" :class="`sc-var--${toneOf(d)}`">
                            <template v-if="varianceOf(d) !== null">
                                {{ varianceOf(d) > 0 ? '+' : '' }}{{ fmtQty(varianceOf(d)) }}
                            </template>
                            <template v-else>—</template>
                        </td>
                        <td class="sc-num" :class="`sc-var--${toneOf(d)}`">
                            <template v-if="varianceCostOf(d) !== null && Math.abs(varianceOf(d)) >= 0.0001">
                                {{ fmtMoney(varianceCostOf(d)) }}
                            </template>
                            <template v-else>—</template>
                        </td>
                        <td>
                            <input v-model="d.notes" class="form-control sc-note" maxlength="500"
                                   :disabled="! count.editable || ! can.update"
                                   placeholder="—" @input="markDirty(d)" @blur="saveRow(d)">
                        </td>
                        <td class="sc-state">
                            <i v-if="rowState[d.id] === 'saving'" class="bi bi-arrow-repeat sc-spin" title="جارٍ الحفظ"></i>
                            <i v-else-if="rowState[d.id] === 'saved'" class="bi bi-check-lg sc-ok" title="انحفظ"></i>
                            <i v-else-if="rowState[d.id] === 'dirty'" class="bi bi-dot sc-dirty" title="غير محفوظ"></i>
                            <span v-else-if="rowState[d.id] === 'error'" class="sc-err" :title="rowError[d.id]">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                            </span>
                        </td>
                    </tr>
                    <tr v-if="visible.length === 0">
                        <td colspan="7" class="text-center text-muted py-4">ما في بنود بهذا الفلتر.</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template #footer>
            <div v-if="count.editable" class="sc-foot">
                <button type="button" class="btn btn-light" :disabled="savingAll || ! can.update" @click="saveAll">
                    <i class="bi" :class="savingAll ? 'bi-arrow-repeat sc-spin' : 'bi-save'"></i>
                    {{ savingAll ? 'جارٍ الحفظ…' : 'احفظ الكل' }}
                </button>
                <button v-if="can.cancel" type="button" class="btn btn-outline-danger" @click="cancelling = true">
                    <i class="bi bi-x-circle"></i> إلغاء الجرد
                </button>
                <button v-if="can.finalize" type="button" class="btn btn-success btn-lg sc-finalize"
                        :disabled="live.counted === 0" @click="finalize">
                    <i class="bi bi-check2-circle"></i> اعتمد وطبّق التسويات ({{ live.counted }})
                </button>
            </div>
            <div v-else class="sc-locked">
                <i class="bi bi-lock-fill"></i> هذا الجرد مقفل — للعرض فقط.
            </div>
        </template>
    </DataPanel>

    <!-- Cancel sheet -->
    <Teleport to="body">
        <Transition name="scx">
            <div v-if="cancelling" class="scx-backdrop" @click.self="cancelling = false">
                <div class="scx-card" role="dialog" aria-modal="true">
                    <h3>إلغاء الجرد {{ count.number }}؟</h3>
                    <p>البنود المدخلة بتضل محفوظة للمراجعة، بس ما رح تتطبّق أي تسوية.</p>
                    <label>
                        <span>سبب الإلغاء *</span>
                        <textarea v-model="cancelReason" rows="3" maxlength="500" placeholder="مثلاً: تكرار جرد، أو ظروف الفرع…"></textarea>
                    </label>
                    <div class="scx-actions">
                        <button type="button" class="scx-btn" @click="cancelling = false">تراجع</button>
                        <button type="button" class="scx-btn scx-btn--danger" :disabled="! cancelReason.trim()" @click="submitCancel">
                            تأكيد الإلغاء
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.sc-status {
    display: flex;
    gap: .7rem;
    align-items: flex-start;
    border-radius: 14px;
    padding: .85rem 1rem;
    margin-bottom: .9rem;
    font-size: .86rem;
}
.sc-status > i { font-size: 1.2rem; }
.sc-status strong { display: block; }
.sc-status--draft { background: #eff6ff; border: 1px solid #bfdbfe; color: #1e40af; }
.sc-status--finalized { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }
.sc-status--cancelled { background: #f1f5f9; border: 1px solid #e2e8f0; color: #475569; }

.sc-tools { display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; }
.sc-search { max-width: 260px; }
.sc-chips { display: flex; gap: .35rem; flex-wrap: wrap; }
.sc-chip {
    min-height: 38px;
    padding: 0 .8rem;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: #fff;
    color: #334155;
    font: inherit;
    font-size: .8rem;
    font-weight: 700;
    cursor: pointer;
}
.sc-chip.is-active { background: rgb(var(--primary-rgb)); border-color: rgb(var(--primary-rgb)); color: #fff; }

.sc-table th { white-space: nowrap; }
.sc-name { font-weight: 700; color: #0f172a; }
.sc-name small { display: block; font-weight: 600; color: #94a3b8; font-size: .72rem; }
.sc-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.sc-input { width: 120px; text-align: center; font-weight: 800; }
.sc-note { min-width: 140px; }

.sc-var--short { color: #b91c1c; font-weight: 800; }
.sc-var--over { color: #b45309; font-weight: 800; }
.sc-var--match { color: #059669; font-weight: 700; }
.sc-var--pending { color: #cbd5e1; }
.sc-row--short { background: rgba(254, 226, 226, .35); }
.sc-row--over { background: rgba(254, 243, 199, .3); }

.sc-state { width: 34px; text-align: center; }
.sc-ok { color: #059669; }
.sc-dirty { color: #f59e0b; font-size: 1.4rem; }
.sc-err { color: #b91c1c; cursor: help; }
.sc-spin { animation: sc-rotate 1s linear infinite; display: inline-block; }
@keyframes sc-rotate { to { transform: rotate(360deg); } }

.sc-foot { display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; }
.sc-finalize { margin-inline-start: auto; font-weight: 800; }
.sc-locked { color: #64748b; font-weight: 700; display: flex; align-items: center; gap: .4rem; }

.scx-backdrop {
    position: fixed;
    inset: 0;
    z-index: 18000;
    background: rgba(15, 23, 42, .5);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.scx-card {
    width: min(460px, 100%);
    background: #fff;
    border-radius: 18px;
    padding: 1.2rem;
    display: flex;
    flex-direction: column;
    gap: .8rem;
}
.scx-card h3 { margin: 0; font-size: 1.02rem; font-weight: 900; }
.scx-card p { margin: 0; font-size: .84rem; color: #64748b; }
.scx-card label { display: flex; flex-direction: column; gap: .3rem; margin: 0; }
.scx-card label span { font-size: .8rem; font-weight: 800; color: #334155; }
.scx-card textarea {
    border: 1.5px solid #e2e8f0;
    border-radius: 11px;
    padding: .6rem .8rem;
    font: inherit;
    font-size: .88rem;
    resize: none;
}
.scx-actions { display: flex; gap: .6rem; }
.scx-btn {
    flex: 1;
    min-height: 46px;
    border: 0;
    border-radius: 12px;
    background: #f1f5f9;
    color: #334155;
    font: inherit;
    font-weight: 800;
    cursor: pointer;
}
.scx-btn--danger { background: #dc2626; color: #fff; }
.scx-btn:disabled { opacity: .5; }

.scx-enter-active, .scx-leave-active { transition: opacity .15s; }
.scx-enter-from, .scx-leave-to { opacity: 0; }
</style>
