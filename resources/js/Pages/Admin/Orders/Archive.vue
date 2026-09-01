<script setup>
/**
 * The archive answers one operational question: "find the old order".
 * Marketplace fields deliberately stay out of this screen because the
 * restaurant accepts table and phone orders only; reviving those fields in
 * the UI would imply a workflow the business does not operate.
 */
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    orders: { type: Object, required: true },
    stats: { type: Object, required: true },
    timing: { type: Object, required: true },
    filters: { type: Object, required: true },
    options: { type: Object, required: true },
    hasQuery: { type: Boolean, default: false },
    showBranchColumn: { type: Boolean, default: false },
    urls: { type: Object, required: true },
});

// snake_case is intentional: these names are the bookmarkable query string.
const form = reactive({
    search: props.filters.search ?? '',
    from: props.filters.from ?? '',
    to: props.filters.to ?? '',
    status: [...(props.filters.status ?? [])],
    table_id: props.filters.table_id != null ? String(props.filters.table_id) : '',
    min_total: props.filters.min_total ?? '',
    max_total: props.filters.max_total ?? '',
    sort: props.filters.sort ?? 'created_at',
    dir: props.filters.dir ?? 'desc',
    delayed_only: Boolean(props.filters.delayed_only),
});

const advancedOpen = ref(Boolean(
    props.filters.table_id
    || props.filters.min_total
    || props.filters.max_total
    || props.filters.delayed_only
    || props.filters.sort !== 'created_at'
    || props.filters.dir !== 'desc',
));

const payload = () => ({
    from: form.from || undefined,
    to: form.to || undefined,
    search: String(form.search).trim() || undefined,
    status: form.status.length ? form.status : undefined,
    table_id: form.table_id || undefined,
    min_total: form.min_total !== '' ? form.min_total : undefined,
    max_total: form.max_total !== '' ? form.max_total : undefined,
    sort: form.sort !== 'created_at' ? form.sort : undefined,
    dir: form.dir !== 'desc' ? form.dir : undefined,
    delayed_only: form.delayed_only ? 1 : undefined,
});

const runSearch = () => router.get(props.urls.archive, payload(), {
    preserveState: true,
    preserveScroll: true,
    replace: true,
});

const clearAll = () => router.get(props.urls.archive);

const toggleStatus = (value) => {
    const index = form.status.indexOf(value);
    if (index === -1) form.status.push(value);
    else form.status.splice(index, 1);
    runSearch();
};

const isoDate = (date) => {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
};

const selectRange = (days) => {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - Math.max(0, days - 1));
    form.from = isoDate(start);
    form.to = isoDate(end);
    runSearch();
};

const activeFilterCount = computed(() => [
    form.search,
    form.status.length,
    form.table_id,
    form.min_total,
    form.max_total,
    form.delayed_only,
    form.sort !== 'created_at' || form.dir !== 'desc',
].filter(Boolean).length);

const statusPalette = {
    primary: '#2563eb',
    secondary: '#64748b',
    success: '#16804f',
    danger: '#c43d3d',
    warning: '#a56600',
    info: '#087c94',
    light: '#718078',
    dark: '#283a31',
};

const statusStyle = (color) => ({
    '--status-color': statusPalette[color] ?? '#65766d',
});

const timingCopy = (timing) => {
    if (timing.mode === 'measured') {
        return `${timing.actualLabel} فعلي / ${timing.estLabel} متوقع`;
    }
    if (timing.mode === 'cooking') return `استغرق ${timing.cookingMinutes}د حتى الآن`;
    if (timing.mode === 'bogus') return 'توقيت يحتاج مراجعة';
    return 'لم يُقَس زمن التحضير';
};

const timingClass = (timing) => {
    if (timing.mode === 'measured') return timing.deltaClass;
    if (timing.mode === 'cooking') return 'archive-order__timing--working';
    if (timing.mode === 'bogus') return 'archive-order__timing--bad';
    return 'archive-order__timing--muted';
};
</script>

<template>
    <Head title="أرشيف الطلبات" />

    <PageHeader
        title="أرشيف الطلبات"
        icon="bi-archive"
        subtitle="ابحث عن أي طلب سابق وافتح تفاصيله من شاشة واحدة."
    />

    <StatRail :stats="[
        { label: 'الطلبات', value: stats.countLabel, icon: 'bi-receipt-cutoff', color: 'primary' },
        { label: 'إجمالي القيمة', value: stats.grossLabel, icon: 'bi-cash-stack', color: 'success' },
        { label: 'متوسط الطلب', value: stats.avgLabel, icon: 'bi-graph-up', color: 'accent' },
        { label: 'الملغاة', value: stats.cancelledLabel, icon: 'bi-x-octagon', color: 'muted' },
    ]" />

    <section class="archive-search" aria-label="البحث في أرشيف الطلبات">
        <form class="archive-search__primary" @submit.prevent="runSearch">
            <label class="archive-field archive-field--search">
                <span class="archive-field__label">ابحث عن الطلب</span>
                <span class="archive-field__control">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input
                        v-model="form.search"
                        type="search"
                        autocomplete="off"
                        placeholder="رقم الطلب، اسم الزبون، رقم الجوال أو ملاحظة…"
                    >
                </span>
            </label>

            <label class="archive-field">
                <span class="archive-field__label">من تاريخ</span>
                <input v-model="form.from" type="date">
            </label>

            <label class="archive-field">
                <span class="archive-field__label">إلى تاريخ</span>
                <input v-model="form.to" type="date">
            </label>

            <button type="submit" class="archive-search__submit">
                <i class="bi bi-search" aria-hidden="true"></i>
                <span>بحث</span>
            </button>
        </form>

        <div class="archive-search__quick">
            <div class="archive-ranges" aria-label="فترات سريعة">
                <span>عرض سريع:</span>
                <button type="button" @click="selectRange(1)">اليوم</button>
                <button type="button" @click="selectRange(7)">آخر 7 أيام</button>
                <button type="button" @click="selectRange(30)">آخر 30 يوماً</button>
            </div>

            <button
                type="button"
                class="archive-advanced-toggle"
                :aria-expanded="advancedOpen"
                @click="advancedOpen = !advancedOpen"
            >
                <i class="bi bi-sliders" aria-hidden="true"></i>
                تفاصيل البحث
                <span v-if="activeFilterCount" class="archive-filter-count">{{ activeFilterCount }}</span>
                <i class="bi" :class="advancedOpen ? 'bi-chevron-up' : 'bi-chevron-down'" aria-hidden="true"></i>
            </button>

            <button v-if="hasQuery" type="button" class="archive-clear" @click="clearAll">
                <i class="bi bi-x-circle" aria-hidden="true"></i>
                مسح الفلاتر
            </button>
        </div>

        <div class="archive-statuses" aria-label="حالة الطلب">
            <button
                v-for="status in options.statuses"
                :key="status.value"
                type="button"
                class="archive-status"
                :class="{ 'is-active': form.status.includes(status.value) }"
                :style="statusStyle(status.color)"
                :aria-pressed="form.status.includes(status.value)"
                @click="toggleStatus(status.value)"
            >
                <span class="archive-status__dot"></span>
                {{ status.label }}
            </button>
        </div>

        <form v-show="advancedOpen" class="archive-advanced" @submit.prevent="runSearch">
            <label class="archive-field">
                <span class="archive-field__label">الطاولة</span>
                <select v-model="form.table_id">
                    <option value="">كل الطاولات</option>
                    <option v-for="table in options.tables" :key="table.id" :value="String(table.id)">
                        طاولة {{ table.number }}
                    </option>
                </select>
            </label>

            <label class="archive-field">
                <span class="archive-field__label">الحد الأدنى</span>
                <input v-model="form.min_total" type="number" min="0" step="0.01" placeholder="0.00">
            </label>

            <label class="archive-field">
                <span class="archive-field__label">الحد الأعلى</span>
                <input v-model="form.max_total" type="number" min="0" step="0.01" placeholder="0.00">
            </label>

            <label class="archive-field">
                <span class="archive-field__label">ترتيب النتائج</span>
                <select v-model="form.sort">
                    <option value="created_at">حسب التاريخ</option>
                    <option value="total">حسب القيمة</option>
                    <option value="number">حسب رقم الطلب</option>
                </select>
            </label>

            <label class="archive-field">
                <span class="archive-field__label">اتجاه الترتيب</span>
                <select v-model="form.dir">
                    <option value="desc">الأحدث أو الأعلى أولاً</option>
                    <option value="asc">الأقدم أو الأقل أولاً</option>
                </select>
            </label>

            <label class="archive-check">
                <input v-model="form.delayed_only" type="checkbox">
                <span>
                    <strong>المتأخرة فقط</strong>
                    <small>تجاوزت وقت التحضير المتوقع</small>
                </span>
            </label>

            <button type="submit" class="archive-advanced__apply">
                <i class="bi bi-funnel" aria-hidden="true"></i>
                تطبيق التفاصيل
            </button>
        </form>
    </section>

    <section v-if="timing.show" class="archive-performance" aria-label="أداء التحضير">
        <div class="archive-performance__intro">
            <span class="archive-performance__icon"><i class="bi bi-stopwatch"></i></span>
            <div>
                <strong>أداء التحضير للفترة المحددة</strong>
                <span>{{ timing.measuredLabel }} طلبات مقاسة فعلياً</span>
            </div>
        </div>
        <div class="archive-performance__metric">
            <span>في الوقت</span>
            <strong :class="`text-${timing.onTimeColor}`">{{ timing.onTimePctLabel }}</strong>
        </div>
        <div class="archive-performance__metric">
            <span>متوسط التحضير</span>
            <strong>{{ timing.avgActualLabel }}</strong>
        </div>
        <div class="archive-performance__metric">
            <span>الفرق عن المتوقع</span>
            <strong :class="`text-${timing.avgDelayColor}`">{{ timing.avgDelayLabel }}</strong>
        </div>
    </section>

    <section class="archive-results" aria-labelledby="archive-results-title">
        <header class="archive-results__header">
            <div>
                <span class="archive-results__eyebrow">النتائج</span>
                <h2 id="archive-results-title">{{ orders.total }} طلب</h2>
            </div>
            <span class="archive-results__hint">اضغط «عرض التفاصيل» للمراجعة الكاملة</span>
        </header>

        <div v-if="orders.data.length" class="archive-orders">
            <article v-for="order in orders.data" :key="order.id" class="archive-order">
                <div class="archive-order__identity">
                    <span class="archive-order__icon" aria-hidden="true">
                        <i class="bi" :class="order.channelIcon"></i>
                    </span>
                    <div class="archive-order__heading">
                        <div class="archive-order__title-line">
                            <strong>{{ order.number }}</strong>
                            <span class="archive-order__status" :style="statusStyle(order.statusColor)">
                                <span></span>{{ order.statusLabel }}
                            </span>
                        </div>
                        <div class="archive-order__meta">
                            <span><i class="bi bi-calendar3"></i>{{ order.dateLabel }}</span>
                            <span><i class="bi bi-clock"></i>{{ order.timeLabel }}</span>
                            <span><i class="bi" :class="order.channelIcon"></i>{{ order.channelLabel }}</span>
                            <span v-if="order.tableLabel"><i class="bi bi-grid-3x3-gap"></i>طاولة {{ order.tableLabel }}</span>
                            <span v-if="showBranchColumn && order.branchName"><i class="bi bi-shop"></i>{{ order.branchName }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="order.customerName || order.customerPhone" class="archive-order__customer">
                    <span class="archive-order__customer-icon"><i class="bi bi-person"></i></span>
                    <div>
                        <strong>{{ order.customerName || 'زبون' }}</strong>
                        <a v-if="order.customerPhone" :href="`tel:${order.customerPhone}`">{{ order.customerPhone }}</a>
                    </div>
                </div>

                <div class="archive-order__facts">
                    <span>
                        <small>الأصناف</small>
                        <strong>{{ order.itemsCount }}</strong>
                    </span>
                    <span>
                        <small>الإجمالي</small>
                        <strong>{{ order.totalLabel }}</strong>
                    </span>
                    <span class="archive-order__timing" :class="timingClass(order.timing)">
                        <small>التحضير</small>
                        <strong>{{ timingCopy(order.timing) }}</strong>
                        <em v-if="order.timing.deltaLabel">{{ order.timing.deltaLabel }}</em>
                    </span>
                </div>

                <Link class="archive-order__open" :href="order.urls.show">
                    عرض التفاصيل
                    <i class="bi bi-arrow-left" aria-hidden="true"></i>
                </Link>
            </article>
        </div>

        <EmptyState
            v-else
            icon="bi-search"
            title="لا توجد طلبات مطابقة"
            message="غيّر كلمات البحث أو الفترة أو امسح الفلاتر لعرض نتائج أخرى."
        >
            <template v-if="hasQuery" #cta>
                <button type="button" class="archive-empty-clear" @click="clearAll">مسح الفلاتر</button>
            </template>
        </EmptyState>

        <Pagination :links="orders.links" />
    </section>
</template>

<style scoped>
.archive-search,
.archive-results,
.archive-performance {
    border: 1px solid #dfe8e3;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 10px 28px rgba(24, 71, 49, .055);
}

.archive-search {
    margin-block: 18px;
    padding: 18px;
}

.archive-search__primary {
    display: grid;
    grid-template-columns: minmax(280px, 1fr) minmax(155px, .34fr) minmax(155px, .34fr) auto;
    gap: 12px;
    align-items: end;
}

.archive-field {
    display: grid;
    gap: 7px;
    min-width: 0;
}

.archive-field__label {
    color: #54685e;
    font-size: .77rem;
    font-weight: 800;
}

.archive-field input,
.archive-field select,
.archive-field__control {
    min-height: 46px;
    width: 100%;
    border: 1px solid #d8e3dd;
    border-radius: 12px;
    background: #fff;
    color: #173c2b;
    font: inherit;
    outline: 0;
}

.archive-field input,
.archive-field select {
    padding-inline: 13px;
}

.archive-field input:focus,
.archive-field select:focus,
.archive-field__control:focus-within {
    border-color: #2d8b62;
    box-shadow: 0 0 0 3px rgba(45, 139, 98, .12);
}

.archive-field__control {
    display: flex;
    align-items: center;
    gap: 10px;
    padding-inline: 14px;
}

.archive-field__control > i {
    color: #6f887b;
    font-size: 1.05rem;
}

.archive-field__control input {
    min-height: 44px;
    padding: 0;
    border: 0;
    box-shadow: none;
}

.archive-search__submit,
.archive-advanced__apply,
.archive-empty-clear {
    min-height: 46px;
    border: 0;
    border-radius: 12px;
    background: #147347;
    color: #fff;
    font-weight: 900;
}

.archive-search__submit {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-width: 110px;
    padding-inline: 22px;
}

.archive-search__quick {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 9px;
    margin-block-start: 14px;
    padding-block-start: 14px;
    border-block-start: 1px solid #edf2ef;
}

.archive-ranges {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 7px;
    margin-inline-end: auto;
    color: #71847a;
    font-size: .78rem;
    font-weight: 800;
}

.archive-ranges button,
.archive-advanced-toggle,
.archive-clear {
    min-height: 38px;
    border: 1px solid #dbe6e0;
    border-radius: 999px;
    background: #f9fbfa;
    color: #355647;
    font: inherit;
    font-weight: 800;
}

.archive-ranges button {
    padding-inline: 13px;
}

.archive-advanced-toggle,
.archive-clear {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 44px;
    padding-inline: 15px;
}

.archive-clear {
    color: #b13b3b;
    background: #fff9f9;
    border-color: #f1d7d7;
}

.archive-filter-count {
    display: grid;
    place-items: center;
    min-width: 22px;
    height: 22px;
    border-radius: 999px;
    background: #147347;
    color: #fff;
    font-size: .7rem;
}

.archive-statuses {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    margin-block-start: 14px;
    padding-block-end: 3px;
    scrollbar-width: thin;
}

.archive-status {
    --status-color: #65766d;
    flex: 0 0 auto;
    min-height: 42px;
    padding-inline: 14px;
    border: 1px solid color-mix(in srgb, var(--status-color), transparent 67%);
    border-radius: 999px;
    background: color-mix(in srgb, var(--status-color), white 94%);
    color: #385246;
    font: inherit;
    font-weight: 800;
}

.archive-status.is-active {
    background: color-mix(in srgb, var(--status-color), white 84%);
    border-color: var(--status-color);
    color: var(--status-color);
    box-shadow: inset 0 0 0 1px var(--status-color);
}

.archive-status__dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    margin-inline-end: 6px;
    border-radius: 50%;
    background: var(--status-color);
}

.archive-advanced {
    display: grid;
    grid-template-columns: repeat(5, minmax(130px, 1fr));
    gap: 12px;
    align-items: end;
    margin-block-start: 16px;
    padding: 15px;
    border: 1px solid #dfe9e4;
    border-radius: 14px;
    background: #f8fbf9;
}

.archive-check {
    display: flex;
    align-items: center;
    gap: 10px;
    min-height: 46px;
    padding: 7px 12px;
    border: 1px solid #d8e3dd;
    border-radius: 12px;
    background: #fff;
    cursor: pointer;
}

.archive-check input {
    width: 18px;
    height: 18px;
    accent-color: #147347;
}

.archive-check span {
    display: grid;
    gap: 1px;
}

.archive-check strong {
    color: #294d3c;
    font-size: .82rem;
}

.archive-check small {
    color: #7b8d84;
    font-size: .68rem;
}

.archive-advanced__apply {
    padding-inline: 18px;
}

.archive-performance {
    display: grid;
    grid-template-columns: minmax(260px, 1.5fr) repeat(3, minmax(120px, .65fr));
    align-items: stretch;
    margin-block-end: 18px;
    overflow: hidden;
}

.archive-performance__intro,
.archive-performance__metric {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 78px;
    padding: 14px 18px;
}

.archive-performance__metric {
    display: grid;
    gap: 3px;
    border-inline-start: 1px solid #e7eeea;
}

.archive-performance__intro div,
.archive-performance__metric {
    color: #6d8076;
    font-size: .75rem;
}

.archive-performance__intro strong,
.archive-performance__intro span,
.archive-performance__metric strong {
    display: block;
}

.archive-performance__intro strong {
    color: #173c2b;
    font-size: .92rem;
}

.archive-performance__intro span {
    margin-block-start: 3px;
}

.archive-performance__metric strong {
    color: #173c2b;
    font-size: 1.04rem;
}

.archive-performance__icon {
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #eaf6ef;
    color: #147347;
    font-size: 1.15rem;
}

.archive-results {
    padding: 18px;
}

.archive-results__header {
    display: flex;
    align-items: end;
    justify-content: space-between;
    gap: 12px;
    margin-block-end: 14px;
}

.archive-results__eyebrow {
    color: #70857a;
    font-size: .72rem;
    font-weight: 900;
}

.archive-results__header h2 {
    margin: 2px 0 0;
    color: #163a29;
    font-size: 1.18rem;
    font-weight: 950;
}

.archive-results__hint {
    color: #819188;
    font-size: .76rem;
}

.archive-orders {
    display: grid;
    gap: 10px;
    margin-block-end: 18px;
}

.archive-order {
    display: grid;
    grid-template-columns: minmax(300px, 1.35fr) minmax(180px, .6fr) minmax(320px, 1fr) auto;
    gap: 15px;
    align-items: center;
    min-height: 104px;
    padding: 13px;
    border: 1px solid #dfe8e3;
    border-radius: 15px;
    background: #fff;
    transition: border-color .16s ease, box-shadow .16s ease, transform .16s ease;
}

.archive-order:hover {
    border-color: #b9d3c5;
    box-shadow: 0 8px 22px rgba(24, 76, 50, .07);
    transform: translateY(-1px);
}

.archive-order__identity,
.archive-order__customer {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 0;
}

.archive-order__icon,
.archive-order__customer-icon {
    display: grid;
    place-items: center;
    flex: 0 0 auto;
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: #eaf5ef;
    color: #147347;
    font-size: 1.08rem;
}

.archive-order__heading {
    min-width: 0;
}

.archive-order__title-line,
.archive-order__meta,
.archive-order__facts {
    display: flex;
    align-items: center;
}

.archive-order__title-line {
    flex-wrap: wrap;
    gap: 8px;
}

.archive-order__title-line > strong {
    color: #123724;
    font-size: .95rem;
    font-weight: 950;
}

.archive-order__status {
    --status-color: #65766d;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    min-height: 26px;
    padding-inline: 9px;
    border-radius: 999px;
    background: color-mix(in srgb, var(--status-color), white 89%);
    color: var(--status-color);
    font-size: .68rem;
    font-weight: 900;
}

.archive-order__status > span {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--status-color);
}

.archive-order__meta {
    flex-wrap: wrap;
    gap: 5px 11px;
    margin-block-start: 8px;
    color: #71847a;
    font-size: .71rem;
    font-weight: 700;
}

.archive-order__meta span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.archive-order__customer div {
    display: grid;
    gap: 3px;
}

.archive-order__customer strong {
    color: #244738;
    font-size: .82rem;
}

.archive-order__customer a {
    color: #6e8277;
    font-size: .75rem;
    text-decoration: none;
}

.archive-order__facts {
    justify-content: space-between;
    gap: 15px;
    padding-inline: 12px;
    border-inline: 1px solid #e7eeea;
}

.archive-order__facts > span {
    display: grid;
    gap: 3px;
    min-width: 62px;
}

.archive-order__facts small {
    color: #819188;
    font-size: .67rem;
    font-weight: 800;
}

.archive-order__facts strong {
    color: #1e4432;
    font-size: .8rem;
    font-style: normal;
}

.archive-order__timing {
    position: relative;
    min-width: 145px !important;
}

.archive-order__timing em {
    position: absolute;
    inset-block-start: -2px;
    inset-inline-end: 0;
    padding: 2px 6px;
    border-radius: 999px;
    font-size: .61rem;
    font-style: normal;
    font-weight: 900;
}

.archive-order__timing.arx-var--good em { background: #e5f7ed; color: #16804f; }
.archive-order__timing.arx-var--warn em,
.archive-order__timing--working em { background: #fff4dc; color: #a46700; }
.archive-order__timing.arx-var--bad em,
.archive-order__timing--bad em { background: #ffe8e8; color: #b83d3d; }
.archive-order__timing--muted strong { color: #8a9991; }

.archive-order__open {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 44px;
    padding-inline: 15px;
    border: 1px solid #b9d3c5;
    border-radius: 11px;
    color: #126b43;
    font-size: .78rem;
    font-weight: 900;
    text-decoration: none;
    white-space: nowrap;
}

.archive-order__open:hover,
.archive-order__open:focus-visible {
    background: #147347;
    border-color: #147347;
    color: #fff;
}

.archive-empty-clear {
    padding-inline: 20px;
}

@media (max-width: 1199.98px) {
    .archive-search__primary {
        grid-template-columns: minmax(280px, 1fr) repeat(2, minmax(150px, .45fr));
    }

    .archive-search__submit {
        grid-column: 1 / -1;
        justify-self: start;
    }

    .archive-advanced {
        grid-template-columns: repeat(3, minmax(150px, 1fr));
    }

    .archive-order {
        grid-template-columns: minmax(300px, 1.2fr) minmax(300px, .9fr) auto;
    }

    .archive-order__customer {
        display: none;
    }
}

@media (max-width: 767.98px) {
    .archive-search,
    .archive-results {
        margin-inline: -4px;
        padding: 13px;
        border-radius: 14px;
    }

    .archive-search__primary {
        grid-template-columns: 1fr 1fr;
    }

    .archive-field--search,
    .archive-search__submit {
        grid-column: 1 / -1;
    }

    .archive-search__submit {
        width: 100%;
    }

    .archive-search__quick {
        align-items: stretch;
    }

    .archive-ranges {
        width: 100%;
        margin-inline-end: 0;
        overflow-x: auto;
        flex-wrap: nowrap;
        padding-block-end: 3px;
    }

    .archive-ranges span,
    .archive-ranges button {
        flex: 0 0 auto;
    }

    .archive-advanced-toggle,
    .archive-clear {
        flex: 1 1 140px;
    }

    .archive-advanced {
        grid-template-columns: 1fr 1fr;
        padding: 12px;
    }

    .archive-advanced__apply {
        grid-column: 1 / -1;
        min-height: 46px;
    }

    .archive-performance {
        grid-template-columns: repeat(3, 1fr);
        border-radius: 14px;
    }

    .archive-performance__intro {
        grid-column: 1 / -1;
        min-height: 68px;
    }

    .archive-performance__metric {
        min-height: 66px;
        padding: 10px;
        border-block-start: 1px solid #e7eeea;
        text-align: center;
    }

    .archive-results__hint {
        display: none;
    }

    .archive-order {
        grid-template-columns: 1fr auto;
        gap: 12px;
        padding: 12px;
    }

    .archive-order__identity {
        grid-column: 1 / -1;
    }

    .archive-order__facts {
        grid-column: 1 / -1;
        justify-content: flex-start;
        gap: 20px;
        padding: 11px 0 0;
        border-inline: 0;
        border-block-start: 1px solid #edf2ef;
    }

    .archive-order__timing {
        margin-inline-start: auto;
    }

    .archive-order__open {
        grid-column: 1 / -1;
        width: 100%;
    }
}

@media (max-width: 420px) {
    .archive-search__primary,
    .archive-advanced {
        grid-template-columns: 1fr;
    }

    .archive-field--search,
    .archive-search__submit,
    .archive-advanced__apply {
        grid-column: auto;
    }

    .archive-performance__metric span {
        font-size: .67rem;
    }

    .archive-performance__metric strong {
        font-size: .88rem;
    }

    .archive-order__icon {
        width: 40px;
        height: 40px;
    }

    .archive-order__facts {
        display: grid;
        grid-template-columns: auto auto;
        gap: 10px;
    }

    .archive-order__timing {
        grid-column: 1 / -1;
        min-width: 0 !important;
        margin: 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .archive-order {
        transition: none;
    }
}
</style>
