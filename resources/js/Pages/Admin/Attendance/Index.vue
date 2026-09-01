<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import AttendanceEditor from '../../../Components/Attendance/AttendanceEditor.vue';
import AttendanceRecord from '../../../Components/Attendance/AttendanceRecord.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import { useLiveRefresh } from '../../../Composables/useLiveRefresh';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    attendances: { type: Object, required: true },
    stats: { type: Object, required: true },
    filters: { type: Object, required: true },
    staff: { type: Array, default: () => [] },
    defaults: { type: Object, required: true },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const filter = reactive({
    search: props.filters.search ?? '',
    date: props.filters.date ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    userId: props.filters.userId ?? '',
    status: props.filters.status ?? '',
});
const periodOpen = ref(Boolean(filter.from || filter.to));
const editorOpen = ref(false);
const editorMode = ref('add');
const selectedRecord = ref(null);
const submitting = ref(false);
const errors = ref({});
let searchTimer = null;

const query = computed(() => ({
    search: filter.search.trim() || undefined,
    date: filter.date || undefined,
    from: !filter.date && filter.from ? filter.from : undefined,
    to: !filter.date && filter.to ? filter.to : undefined,
    user_id: filter.userId || undefined,
    status: filter.status || undefined,
}));

const activeLens = computed(() => {
    if (filter.status === 'open' && !filter.date && !filter.from && !filter.to) return 'open';
    if (filter.status === 'review' && !filter.date && !filter.from && !filter.to) return 'review';
    if (filter.date === props.defaults.today && !filter.status && !filter.from && !filter.to) return 'today';
    if (!filter.date && !filter.from && !filter.to && !filter.status) return 'all';
    return 'custom';
});

const hasFilters = computed(() => Object.values(query.value).some(Boolean));
const exportUrl = computed(() => {
    const params = new URLSearchParams();
    Object.entries(query.value).forEach(([key, value]) => { if (value) params.set(key, value); });
    return params.size ? `${props.urls.export}?${params}` : props.urls.export;
});

function load(patch = {}, options = {}) {
    Object.assign(filter, patch);
    router.get(props.urls.index, query.value, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['attendances', 'stats', 'filters'],
        ...options,
    });
}

function setLens(lens) {
    periodOpen.value = false;
    const base = { date: '', from: '', to: '', status: '' };
    if (lens === 'today') base.date = props.defaults.today;
    if (lens === 'open') base.status = 'open';
    if (lens === 'review') base.status = 'review';
    load(base);
}

function onSearch() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => load(), 350);
}

function setDay(value) {
    periodOpen.value = false;
    load({ date: value, from: '', to: '' });
}

function setRangeField(key, value) {
    load({ [key]: value, date: '' });
}

function clearFilters() {
    periodOpen.value = false;
    load({ search: '', date: '', from: '', to: '', userId: '', status: '' });
}

function openAdd() {
    selectedRecord.value = null;
    editorMode.value = 'add';
    errors.value = {};
    editorOpen.value = true;
}

function openEdit(record) {
    selectedRecord.value = record;
    editorMode.value = 'edit';
    errors.value = {};
    editorOpen.value = true;
}

function closeEditor(force = false) {
    if (submitting.value && !force) return;
    editorOpen.value = false;
    selectedRecord.value = null;
    errors.value = {};
}

function saveRecord(payload) {
    if (submitting.value) return;
    submitting.value = true;
    errors.value = {};
    const options = {
        preserveScroll: true,
        preserveState: true,
        only: ['attendances', 'stats', 'flash'],
        onError: (bag) => { errors.value = bag; },
        onSuccess: () => closeEditor(true),
        onFinish: () => { submitting.value = false; },
    };

    if (editorMode.value === 'add') router.post(props.urls.store, payload, options);
    else router.put(selectedRecord.value.urls.update, payload, options);
}

function excludeRecord(reason) {
    if (!selectedRecord.value || submitting.value) return;
    submitting.value = true;
    errors.value = {};
    router.delete(selectedRecord.value.urls.destroy, {
        data: { reason },
        preserveScroll: true,
        preserveState: true,
        only: ['attendances', 'stats', 'flash'],
        onError: (bag) => { errors.value = bag; },
        onSuccess: () => closeEditor(true),
        onFinish: () => { submitting.value = false; },
    });
}

function refresh() {
    if (editorOpen.value || ['INPUT', 'SELECT', 'TEXTAREA'].includes(document.activeElement?.tagName)) return;
    router.reload({ only: ['attendances', 'stats'], preserveScroll: true });
}

useLiveRefresh({ pollMs: 30000, throttleMs: 5000, onPing: refresh });
onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<template>
    <Head title="الحضور والانصراف" />

    <PageHeader title="الحضور والانصراف" icon="bi-clock-history"
                subtitle="دوام واضح للموظف، ومراجعة موثّقة للمدير دون ساعات تقديرية">
        <template #actions>
            <button type="button" class="attendance-action is-secondary" @click="refresh"><i class="bi bi-arrow-clockwise"></i><span>تحديث</span></button>
            <a :href="exportUrl" class="attendance-action is-secondary"><i class="bi bi-file-earmark-spreadsheet"></i><span>تصدير النتائج</span></a>
            <button v-if="can.create" type="button" class="attendance-action is-primary" @click="openAdd"><i class="bi bi-plus-lg"></i><span>إضافة سجل</span></button>
        </template>
    </PageHeader>

    <section class="attendance-summary" aria-label="ملخص الحضور">
        <button type="button" class="summary-card is-live" :class="{ active: activeLens === 'open' }" @click="setLens('open')">
            <span class="summary-icon"><i class="bi bi-person-check-fill"></i></span><span><small>على رأس العمل الآن</small><strong>{{ stats.openNow }}</strong></span><i class="bi bi-chevron-left summary-arrow"></i>
        </button>
        <button type="button" class="summary-card is-review" :class="{ active: activeLens === 'review' }" @click="setLens('review')">
            <span class="summary-icon"><i class="bi bi-shield-exclamation"></i></span><span><small>تحتاج مراجعة</small><strong>{{ stats.needsReview }}</strong></span><i class="bi bi-chevron-left summary-arrow"></i>
        </button>
        <button type="button" class="summary-card" :class="{ active: activeLens === 'today' }" @click="setLens('today')">
            <span class="summary-icon"><i class="bi bi-people"></i></span><span><small>حضروا اليوم</small><strong>{{ stats.presentToday }}</strong></span><i class="bi bi-chevron-left summary-arrow"></i>
        </button>
        <button type="button" class="summary-card" :class="{ active: activeLens === 'today' }" @click="setLens('today')">
            <span class="summary-icon"><i class="bi bi-stopwatch"></i></span><span><small>صافي ساعات اليوم</small><strong class="is-duration">{{ stats.todayTotal }}</strong></span><i class="bi bi-chevron-left summary-arrow"></i>
        </button>
    </section>

    <DataPanel title="سجل الدوام" :count="attendances.total" icon="bi-clock-history">
        <template #filters>
            <div class="filter-shell">
                <div class="quick-lenses" aria-label="عرض سريع">
                    <button type="button" :class="{ active: activeLens === 'today' }" @click="setLens('today')">اليوم</button>
                    <button type="button" :class="{ active: activeLens === 'open' }" @click="setLens('open')"><i class="live-dot"></i>الحاضرون</button>
                    <button type="button" :class="{ active: activeLens === 'review' }" @click="setLens('review')">تحتاج مراجعة <b>{{ stats.needsReview }}</b></button>
                    <button type="button" :class="{ active: activeLens === 'all' }" @click="setLens('all')">كل السجل</button>
                </div>

                <div class="filter-grid">
                    <label class="search-field"><i class="bi bi-search"></i><input v-model="filter.search" type="search" placeholder="ابحث باسم الموظف أو اسم المستخدم…" @input="onSearch"></label>
                    <select v-model="filter.userId" aria-label="الموظف" @change="load()"><option value="">كل الموظفين</option><option v-for="member in staff" :key="member.id" :value="String(member.id)">{{ member.name }}</option></select>
                    <select v-model="filter.status" aria-label="حالة السجل" @change="load()"><option value="">كل الحالات</option><option value="open">على رأس العمل</option><option value="closed">مكتمل</option><option value="review">يحتاج مراجعة</option></select>
                    <label class="day-field"><span>يوم</span><input :value="filter.date" type="date" @change="setDay($event.target.value)"></label>
                    <button type="button" class="range-toggle" :class="{ active: periodOpen }" @click="periodOpen = !periodOpen"><i class="bi bi-calendar-range"></i> فترة</button>
                    <button v-if="hasFilters" type="button" class="clear-button" @click="clearFilters"><i class="bi bi-x-lg"></i><span>مسح</span></button>
                </div>

                <Transition name="range-slide">
                    <div v-if="periodOpen" class="range-panel">
                        <span class="range-hint"><i class="bi bi-info-circle"></i>للكشوف الدورية اختر بداية ونهاية الفترة.</span>
                        <label><span>من</span><input :value="filter.from" type="date" @change="setRangeField('from', $event.target.value)"></label>
                        <label><span>إلى</span><input :value="filter.to" type="date" @change="setRangeField('to', $event.target.value)"></label>
                    </div>
                </Transition>
            </div>
        </template>

        <div v-if="attendances.data.length" class="attendance-list">
            <AttendanceRecord v-for="record in attendances.data" :key="record.id" :record="record" @edit="openEdit" />
        </div>
        <EmptyState v-else icon="bi-clock-history" title="لا توجد سجلات مطابقة" :message="hasFilters ? 'غيّر الفلاتر أو اعرض كل السجل.' : 'سيظهر حضور الموظفين هنا فور تسجيله.'" />
        <template #footer><Pagination :links="attendances.links" /></template>
    </DataPanel>

    <AttendanceEditor :open="editorOpen" :mode="editorMode" :record="selectedRecord" :staff="staff" :defaults="defaults"
                      :submitting="submitting" :errors="errors" @close="closeEditor" @save="saveRecord" @exclude="excludeRecord" />
</template>

<style scoped>
.attendance-action{display:inline-flex;min-height:44px;align-items:center;justify-content:center;gap:.42rem;padding:0 .85rem;border:1px solid #dbe5de;border-radius:11px;font-size:.65rem;font-weight:850;text-decoration:none}.attendance-action.is-secondary{background:#fff;color:#52665b}.attendance-action.is-primary{border-color:rgb(var(--primary-rgb,22 115 67));background:rgb(var(--primary-rgb,22 115 67));color:#fff}
.attendance-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:.7rem;margin-bottom:.8rem}.summary-card{display:grid;grid-template-columns:46px 1fr auto;min-height:88px;align-items:center;gap:.65rem;padding:.72rem;border:1px solid #dfe8e2;border-radius:15px;background:#fff;color:#263d31;text-align:start;box-shadow:0 6px 20px rgba(24,55,37,.035)}.summary-card:hover,.summary-card.active{border-color:#99c5aa;background:#f5fbf7}.summary-icon{display:grid;width:46px;height:46px;place-items:center;border-radius:13px;background:#edf5f0;color:rgb(var(--primary-rgb,22 115 67));font-size:1.05rem}.summary-card>span:nth-child(2){display:grid;gap:.14rem}.summary-card small{color:#7c8b83;font-size:.6rem;font-weight:700}.summary-card strong{color:#173526;font-size:1.18rem;font-weight:950}.summary-card strong.is-duration{font-size:.9rem}.summary-arrow{color:#a6b1ab;font-size:.7rem}.summary-card.is-review .summary-icon{background:#fff3db;color:#a86600}.summary-card.is-review.active{border-color:#e3c17c;background:#fffaf0}
.filter-shell{display:grid;gap:.65rem}.quick-lenses{display:flex;gap:.42rem;overflow-x:auto;scrollbar-width:none}.quick-lenses button{min-height:42px;padding:0 .75rem;border:1px solid #dfe7e2;border-radius:10px;background:#fff;color:#5c6d63;font-size:.63rem;font-weight:820;white-space:nowrap}.quick-lenses button.active{border-color:#9bc6aa;background:#edf7f1;color:rgb(var(--primary-rgb,22 115 67))}.quick-lenses b{margin-inline-start:.3rem;padding:.08rem .35rem;border-radius:999px;background:#fff0d5;color:#9b6003}.live-dot{display:inline-block;width:7px;height:7px;margin-inline-end:.32rem;border-radius:50%;background:#16a05a;box-shadow:0 0 0 4px rgba(22,160,90,.12)}
.filter-grid{display:grid;grid-template-columns:minmax(220px,1fr) 190px 160px 190px auto auto;gap:.5rem;align-items:center}.filter-grid input,.filter-grid select,.range-panel input{width:100%;min-height:44px;padding:.5rem .68rem;border:1px solid #dce6df;border-radius:10px;background:#fff;color:#33483d;font-size:.65rem;outline:0}.filter-grid input:focus,.filter-grid select:focus,.range-panel input:focus{border-color:#8ab99b;box-shadow:0 0 0 3px rgba(22,115,67,.08)}.search-field{position:relative}.search-field i{position:absolute;inset-inline-start:.72rem;inset-block-start:50%;z-index:1;color:#87958d;transform:translateY(-50%)}.search-field input{padding-inline-start:2.15rem}.day-field{display:grid;grid-template-columns:auto 1fr;align-items:center;gap:.4rem;color:#74847b;font-size:.6rem;font-weight:800}.range-toggle,.clear-button{display:inline-flex;min-height:44px;align-items:center;justify-content:center;gap:.3rem;padding:0 .65rem;border:1px solid #dce6df;border-radius:10px;background:#fff;color:#596b61;font-size:.62rem;font-weight:820;white-space:nowrap}.range-toggle.active{border-color:#a3c9b1;background:#edf7f1;color:#126f3e}.clear-button{border-color:#eac9c6;color:#a34038}
.range-panel{display:grid;grid-template-columns:1fr 190px 190px;align-items:end;gap:.55rem;padding:.68rem;border:1px solid #e1e9e4;border-radius:11px;background:#f8faf9}.range-panel label{display:grid;grid-template-columns:auto 1fr;align-items:center;gap:.4rem;color:#66786e;font-size:.6rem;font-weight:800}.range-hint{align-self:center;color:#76867d;font-size:.62rem}.range-hint i{margin-inline-end:.3rem}.attendance-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem}.range-slide-enter-active,.range-slide-leave-active{transition:.16s ease}.range-slide-enter-from,.range-slide-leave-to{opacity:0;transform:translateY(-5px)}
@media(max-width:1180px){.attendance-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-grid{grid-template-columns:1fr 1fr 1fr}.search-field{grid-column:span 2}.attendance-list{grid-template-columns:1fr}}@media(max-width:640px){.attendance-action span{display:none}.attendance-action{width:44px;padding:0}.attendance-summary{gap:.5rem}.summary-card{grid-template-columns:40px 1fr;min-height:76px;padding:.58rem}.summary-icon{width:40px;height:40px}.summary-arrow{display:none}.summary-card small{font-size:.54rem}.summary-card strong{font-size:1rem}.filter-grid{grid-template-columns:1fr 1fr}.search-field,.day-field{grid-column:1/-1}.range-panel{grid-template-columns:1fr 1fr}.range-hint{grid-column:1/-1}}@media(max-width:380px){.attendance-summary{grid-template-columns:1fr}}@media(prefers-reduced-motion:reduce){.range-slide-enter-active,.range-slide-leave-active{transition:none}}
</style>
