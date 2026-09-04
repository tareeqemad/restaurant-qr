<script setup>
/**
 * لوحة الحجوزات — Wave 5. Built around the host's actual questions:
 * who lands in the next 90 minutes, who is already late, and which of
 * today's bookings still has no table.
 *
 * Every transition posts to its own validated endpoint, so the state
 * machine and its policy gates stay server-side — the buttons here only
 * appear when the server said the user may take that step.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { formPost } from '../../../Support/formPost';
import { localDateInput } from '../../../Utils/dateInput';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    reservations: { type: Object, required: true },
    stats: { type: Object, required: true },
    filters: { type: Object, required: true },
    statuses: { type: Array, required: true },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();

const form = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    date: props.filters.date ?? '',
    table: props.filters.table ?? '',
    party: props.filters.party ?? '',
});

const visit = (patch = {}) => {
    Object.assign(form, patch);
    router.get(props.urls.index, {
        search: form.search || undefined,
        status: form.status || undefined,
        date: form.date || undefined,
        table: form.table || undefined,
        party: form.party || undefined,
    }, { preserveState: true, preserveScroll: true });
};

const clear = () => visit({ search: '', status: '', date: '', table: '', party: '' });
const hasFilters = computed(() => Object.values(form).some(Boolean));

const today = () => localDateInput();
const tomorrow = () => {
    const d = new Date();
    d.setDate(d.getDate() + 1);
    return localDateInput(d);
};

// Quick lenses — the three the host opens this screen for.
const showToday = () => visit({ date: today(), status: '', table: '', party: '' });
const showTomorrow = () => visit({ date: tomorrow(), status: '', table: '', party: '' });
const showPending = () => visit({ status: 'pending', date: '', table: '', party: '' });
const showNoTable = () => visit({ date: today(), table: 'unassigned', status: '', party: '' });

// ── Transitions ──────────────────────────────────────────────────────
const cancelling = ref(null);
const cancelReason = ref('');

const confirm = (r) => formPost(r.urls.confirm);
const seat = (r) => formPost(r.urls.seat);
const complete = (r) => formPost(r.urls.complete);

const noShow = async (r) => {
    const yes = await ask({
        title: `تسجيل عدم حضور ${r.reference}؟`,
        message: 'بيتسجّل على الزبون وبيحرّر الحجز.',
        confirmLabel: 'سجّل عدم الحضور',
        danger: true,
    });
    if (yes) formPost(r.urls.noShow);
};

const openCancel = (r) => {
    cancelling.value = r;
    cancelReason.value = '';
};
const submitCancel = () => {
    formPost(cancelling.value.urls.cancel, { cancelled_reason: cancelReason.value.trim() });
};

// ── Quiet auto-refresh — bookings arrive while the host watches ──────
let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (document.hidden || cancelling.value) return;
        const el = document.activeElement;
        if (el && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return;
        router.reload({ only: ['reservations', 'stats'], preserveScroll: true });
    }, 30000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head title="الحجوزات" />

    <PageHeader title="الحجوزات" icon="bi-calendar-event-fill"
                subtitle="مين جاي، ومين تأخّر، ومين لسا بلا طاولة" />

    <StatRail :stats="[
        { label: 'حجوزات اليوم', value: stats.today, icon: 'bi-calendar-day', color: 'primary' },
        { label: 'بانتظار التأكيد', value: stats.pending, icon: 'bi-hourglass-split', color: 'accent' },
        { label: 'واصلين خلال ساعة ونص', value: stats.arrivingSoon, icon: 'bi-clock-history', color: 'success' },
        { label: 'متأخرين', value: stats.late, icon: 'bi-exclamation-triangle-fill', color: 'danger' },
        { label: 'جالسين الآن', value: stats.seatedNow, icon: 'bi-people-fill', color: 'primary' },
    ]" />

    <div class="rz-lenses">
        <button type="button" class="rz-lens" @click="showToday"><i class="bi bi-calendar-day"></i> اليوم</button>
        <button type="button" class="rz-lens" @click="showTomorrow"><i class="bi bi-calendar-plus"></i> بكرا</button>
        <button type="button" class="rz-lens" @click="showPending">
            <i class="bi bi-hourglass-split"></i> بانتظار التأكيد ({{ stats.pending }})
        </button>
        <button v-if="stats.noTableToday > 0" type="button" class="rz-lens rz-lens--warn" @click="showNoTable">
            <i class="bi bi-grid-3x3-gap"></i> بلا طاولة اليوم ({{ stats.noTableToday }})
        </button>
    </div>

    <DataPanel title="الحجوزات" :count="reservations.total" icon="bi-calendar-event">
        <template v-if="hasFilters" #actions>
            <button type="button" class="btn btn-light" @click="clear">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </button>
        </template>

        <template #filters>
            <form class="row g-2" @submit.prevent="visit()">
                <div class="col-md-3">
                    <input v-model="form.search" class="form-control" placeholder="🔍 مرجع، اسم، جوال، ملاحظة…">
                </div>
                <div class="col-md-2">
                    <input v-model="form.date" type="date" class="form-control" @change="visit()">
                </div>
                <div class="col-md-3">
                    <select v-model="form.status" class="form-select" @change="visit()">
                        <option value="">كل الحالات</option>
                        <option v-for="s in statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select v-model="form.table" class="form-select" @change="visit()">
                        <option value="">الطاولة: الكل</option>
                        <option value="unassigned">بلا طاولة</option>
                        <option value="assigned">معيّنة</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select v-model="form.party" class="form-select" @change="visit()">
                        <option value="">الحجم: الكل</option>
                        <option value="large">٦ فأكثر</option>
                        <option value="small">٢ فأقل</option>
                    </select>
                </div>
            </form>
        </template>

        <div class="rz-list">
            <article v-for="r in reservations.data" :key="r.id"
                     class="rz-card" :class="{ 'is-late': r.isLate, 'is-soon': r.arrivingSoon }">
                <div class="rz-when">
                    <strong>{{ r.reservedFor?.slice(11) ?? '—' }}</strong>
                    <small>{{ r.reservedFor?.slice(0, 10) }}</small>
                    <span v-if="r.isLate" class="rz-flag rz-flag--late">متأخر</span>
                    <span v-else-if="r.arrivingSoon" class="rz-flag rz-flag--soon">قريب</span>
                </div>

                <div class="rz-main">
                    <div class="rz-title">
                        <strong>{{ r.customerName ?? 'ضيف' }}</strong>
                        <span class="rz-party"><i class="bi bi-people-fill"></i> {{ r.partySize }}</span>
                        <span class="badge" :class="`bg-${r.statusColor}`">{{ r.statusLabel }}</span>
                    </div>
                    <div class="rz-meta">
                        <span><i class="bi bi-hash"></i>{{ r.reference }}</span>
                        <span v-if="r.customerPhone"><i class="bi bi-telephone"></i> {{ r.customerPhone }}</span>
                        <span v-if="r.tableNumber"><i class="bi bi-grid-3x3-gap-fill"></i> طاولة {{ r.tableNumber }}</span>
                        <span v-else class="rz-notable"><i class="bi bi-exclamation-circle"></i> بلا طاولة</span>
                        <span v-if="r.branchName"><i class="bi bi-building"></i> {{ r.branchName }}</span>
                    </div>
                    <p v-if="r.customerNotes" class="rz-note"><i class="bi bi-chat-left-text"></i> {{ r.customerNotes }}</p>
                    <p v-if="r.internalNotes" class="rz-note rz-note--internal"><i class="bi bi-lock"></i> {{ r.internalNotes }}</p>
                    <p v-if="r.cancelledReason" class="rz-note rz-note--cancel"><i class="bi bi-x-circle"></i> {{ r.cancelledReason }}</p>
                </div>

                <div class="rz-actions">
                    <button v-if="r.can.confirm" type="button" class="btn btn-sm btn-success" @click="confirm(r)">
                        <i class="bi bi-check2"></i> تأكيد
                    </button>
                    <button v-if="r.can.seat" type="button" class="btn btn-sm btn-primary" @click="seat(r)">
                        <i class="bi bi-door-open"></i> جلوس
                    </button>
                    <button v-if="r.can.complete" type="button" class="btn btn-sm btn-outline-primary" @click="complete(r)">
                        <i class="bi bi-check2-all"></i> إنهاء
                    </button>
                    <button v-if="r.can.noShow" type="button" class="btn btn-sm btn-outline-warning" @click="noShow(r)">
                        <i class="bi bi-person-x"></i> ما حضر
                    </button>
                    <button v-if="r.can.cancel" type="button" class="btn btn-sm btn-outline-danger" @click="openCancel(r)">
                        <i class="bi bi-x-lg"></i> إلغاء
                    </button>
                    <a v-if="r.can.update" :href="r.urls.edit" class="btn btn-sm btn-light">
                        <i class="bi bi-pencil"></i>
                    </a>
                </div>
            </article>

            <EmptyState v-if="reservations.data.length === 0" icon="bi-calendar-x"
                        title="ما في حجوزات هون"
                        :message="hasFilters ? 'جرّب تمسح الفلاتر.' : 'أول حجز بيوصل بيظهر هون.'" />
        </div>

        <template #footer>
            <Pagination :links="reservations.links" />
        </template>
    </DataPanel>

    <!-- Cancel sheet -->
    <Teleport to="body">
        <Transition name="rzx">
            <div v-if="cancelling" class="rzx-backdrop" @click.self="cancelling = null">
                <div class="rzx-card" role="dialog" aria-modal="true">
                    <h3>إلغاء الحجز {{ cancelling.reference }}؟</h3>
                    <p>بينحرّر الوقت والطاولة، والسبب بينحفظ مع الحجز.</p>
                    <label>
                        <span>سبب الإلغاء</span>
                        <input v-model="cancelReason" maxlength="255" placeholder="مثلاً: الزبون اتصل وأجّل…"
                               @keydown.enter.prevent="submitCancel">
                    </label>
                    <div class="rzx-actions">
                        <button type="button" class="rzx-btn" @click="cancelling = null">تراجع</button>
                        <button type="button" class="rzx-btn rzx-btn--danger" @click="submitCancel">أكّد الإلغاء</button>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.rz-lenses { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: .9rem; }
.rz-lens {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    min-height: 42px;
    padding: 0 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: #fff;
    color: #334155;
    font: inherit;
    font-size: .84rem;
    font-weight: 700;
    cursor: pointer;
}
.rz-lens--warn { border-color: #fde68a; background: #fffbeb; color: #92400e; }

.rz-list { display: flex; flex-direction: column; gap: .6rem; }
.rz-card {
    display: flex;
    gap: .9rem;
    align-items: flex-start;
    border: 1px solid #eef0f3;
    border-inline-start: 4px solid #cbd5e1;
    border-radius: 14px;
    padding: .8rem .9rem;
    background: #fff;
    flex-wrap: wrap;
}
.rz-card.is-soon { border-inline-start-color: #059669; background: #f8fffb; }
.rz-card.is-late { border-inline-start-color: #dc2626; background: #fffafa; }

.rz-when {
    display: flex;
    flex-direction: column;
    align-items: center;
    min-width: 72px;
    flex-shrink: 0;
}
.rz-when strong { font-size: 1.1rem; font-weight: 900; color: #0f172a; font-variant-numeric: tabular-nums; }
.rz-when small { font-size: .7rem; color: #94a3b8; }
.rz-flag {
    margin-top: .25rem;
    border-radius: 999px;
    padding: .05rem .45rem;
    font-size: .66rem;
    font-weight: 800;
}
.rz-flag--late { background: #fee2e2; color: #b91c1c; }
.rz-flag--soon { background: #d1fae5; color: #047857; }

.rz-main { flex: 1 1 260px; min-width: 0; display: flex; flex-direction: column; gap: .25rem; }
.rz-title { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
.rz-title strong { font-size: .95rem; color: #0f172a; }
.rz-party { font-size: .78rem; color: #475569; font-weight: 700; }
.rz-meta { display: flex; gap: .8rem; flex-wrap: wrap; font-size: .76rem; color: #94a3b8; }
.rz-notable { color: #b45309; font-weight: 700; }
.rz-note { margin: .15rem 0 0; font-size: .78rem; color: #475569; }
.rz-note--internal { color: #7c3aed; }
.rz-note--cancel { color: #b91c1c; }

.rz-actions { display: flex; gap: .35rem; flex-wrap: wrap; align-items: flex-start; }

.rzx-backdrop {
    position: fixed;
    inset: 0;
    z-index: 18000;
    background: rgba(15, 23, 42, .5);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.rzx-card {
    width: min(440px, 100%);
    background: #fff;
    border-radius: 18px;
    padding: 1.2rem;
    display: flex;
    flex-direction: column;
    gap: .8rem;
}
.rzx-card h3 { margin: 0; font-size: 1.02rem; font-weight: 900; }
.rzx-card p { margin: 0; font-size: .84rem; color: #64748b; }
.rzx-card label { display: flex; flex-direction: column; gap: .3rem; margin: 0; }
.rzx-card label span { font-size: .8rem; font-weight: 800; color: #334155; }
.rzx-card input {
    border: 1.5px solid #e2e8f0;
    border-radius: 11px;
    min-height: 46px;
    padding: 0 .8rem;
    font: inherit;
    font-size: .88rem;
}
.rzx-actions { display: flex; gap: .6rem; }
.rzx-btn {
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
.rzx-btn--danger { background: #dc2626; color: #fff; }

.rzx-enter-active, .rzx-leave-active { transition: opacity .15s; }
.rzx-enter-from, .rzx-leave-to { opacity: 0; }
</style>
