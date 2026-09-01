<script setup>
/**
 * Staff customer directory. The page favours fast lookup over CRM-style
 * density: one search row, four useful lenses, and cards that collapse to a
 * single readable column on the cashier's smaller screen.
 */
import { computed, reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    customers: { type: Object, required: true },
    stats: { type: Object, required: true },
    filteredStats: { type: Object, required: true },
    filters: { type: Object, required: true },
    scope: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const form = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    newToday: Boolean(props.filters.newToday),
    active30d: Boolean(props.filters.active30d),
});

const hasFilters = computed(() => Boolean(
    form.search || form.status || form.newToday || form.active30d,
));

function visit(patch = {}) {
    Object.assign(form, patch);
    router.get(props.urls.index, {
        search: form.search || undefined,
        status: form.status || undefined,
        new_today: form.newToday ? 1 : undefined,
        active_30d: form.active30d ? 1 : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function clear() {
    visit({ search: '', status: '', newToday: false, active30d: false });
}
</script>

<template>
    <Head title="الزبائن" />

    <PageHeader title="الزبائن" icon="bi-people-fill" :subtitle="scope.label">
        <template #actions>
            <a :href="urls.debts" class="btn btn-outline-danger">
                <i class="bi bi-wallet2"></i> الديون والتحصيل
            </a>
        </template>
    </PageHeader>

    <div class="cu-stats">
        <StatRail :stats="[
            { label: 'إجمالي الزبائن', value: stats.total, icon: 'bi-people-fill', color: 'primary' },
            { label: 'جدد اليوم', value: stats.new_today, icon: 'bi-person-plus-fill', color: 'success' },
            { label: 'جدد هذا الشهر', value: stats.new_month, icon: 'bi-calendar2-week', color: 'accent' },
            { label: 'نشطون آخر 30 يوم', value: stats.active_30d, icon: 'bi-activity', color: 'muted' },
        ]" />
    </div>

    <div class="cu-lenses" aria-label="فلاتر سريعة">
        <button type="button" class="cu-lens" :class="{ active: !hasFilters }" @click="clear">
            <i class="bi bi-people"></i> الكل
            <b>{{ stats.total }}</b>
        </button>
        <button type="button" class="cu-lens" :class="{ active: form.newToday }"
                @click="visit({ newToday: !form.newToday, active30d: false })">
            <i class="bi bi-person-plus"></i> جدد اليوم
            <b>{{ stats.new_today }}</b>
        </button>
        <button type="button" class="cu-lens" :class="{ active: form.active30d }"
                @click="visit({ active30d: !form.active30d, newToday: false })">
            <i class="bi bi-lightning-charge"></i> نشطون مؤخراً
            <b>{{ stats.active_30d }}</b>
        </button>
        <button type="button" class="cu-lens cu-lens--danger" :class="{ active: form.status === 'blocked' }"
                @click="visit({ status: form.status === 'blocked' ? '' : 'blocked' })">
            <i class="bi bi-slash-circle"></i> محظورون
            <b>{{ filteredStats.blocked }}</b>
        </button>
    </div>

    <DataPanel title="دليل الزبائن" :count="filteredStats.count" icon="bi-person-lines-fill">
        <template #actions>
            <button v-if="hasFilters" type="button" class="btn btn-light" @click="clear">
                <i class="bi bi-x-circle"></i> مسح
            </button>
        </template>

        <template #filters>
            <form class="cu-search" @submit.prevent="visit()">
                <label class="cu-search__box">
                    <i class="bi bi-search"></i>
                    <input v-model="form.search" autocomplete="off"
                           placeholder="ابحث بالاسم أو الجوال أو الإيميل…">
                </label>
                <select v-model="form.status" aria-label="حالة الزبون" @change="visit()">
                    <option value="">كل الحالات</option>
                    <option value="active">نشط</option>
                    <option value="blocked">محظور</option>
                </select>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> ابحث
                </button>
            </form>
            <p class="cu-scope">
                <i class="bi bi-geo-alt"></i>
                {{ scope.branchName ? `العرض مرتبط بالفرع النشط: ${scope.branchName}` : 'عرض موحّد لكل الفروع' }}
            </p>
        </template>

        <div v-if="customers.data.length" class="cu-grid">
            <Link v-for="customer in customers.data" :key="customer.id" :href="customer.url"
                  class="cu-card" :class="{ 'is-blocked': customer.isBlocked }">
                <span class="cu-avatar" :style="{ '--hue': (customer.id * 47) % 360 }">
                    {{ customer.initial }}
                </span>

                <div class="cu-person">
                    <div class="cu-person__title">
                        <strong>{{ customer.name }}</strong>
                        <span class="cu-status" :class="customer.isBlocked ? 'is-danger' : 'is-active'">
                            {{ customer.isBlocked ? 'محظور' : 'نشط' }}
                        </span>
                    </div>
                    <div class="cu-contact">
                        <bdi><i class="bi bi-telephone"></i> {{ customer.phone }}</bdi>
                        <bdi v-if="customer.email"><i class="bi bi-envelope"></i> {{ customer.email }}</bdi>
                    </div>
                    <p v-if="customer.isBlocked && customer.blockedReason" class="cu-block-reason">
                        {{ customer.blockedReason }}
                    </p>
                </div>

                <div class="cu-branch">
                    <span v-if="customer.branch" class="cu-branch__tag"
                          :style="{ '--hue': customer.branch.hue }">
                        <i class="bi bi-building"></i> {{ customer.branch.name }}
                    </span>
                    <span v-else class="cu-muted">بلا فرع مفضّل</span>
                    <small>منذ {{ customer.createdAgo }}</small>
                </div>

                <div class="cu-activity" aria-label="نشاط الزبون">
                    <span title="الطلبات"><i class="bi bi-receipt"></i><b>{{ customer.activity.orders }}</b><small>طلب</small></span>
                    <span title="الفواتير"><i class="bi bi-cash-stack"></i><b>{{ customer.activity.invoices }}</b><small>فاتورة</small></span>
                    <span title="الحجوزات"><i class="bi bi-calendar2-check"></i><b>{{ customer.activity.reservations }}</b><small>حجز</small></span>
                </div>

                <i class="bi bi-chevron-left cu-open" aria-hidden="true"></i>
            </Link>
        </div>

        <EmptyState v-else icon="bi-person-x" title="لا يوجد زبائن بهذه المواصفات"
                    :message="hasFilters ? 'امسح الفلاتر أو جرّب رقم جوال آخر.' : 'يُضاف الزبون تلقائياً من أول طلب أو من الكاشير.'" />

        <template #footer>
            <Pagination :links="customers.links" />
        </template>
    </DataPanel>
</template>

<style scoped>
.cu-lenses { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: .65rem; margin-bottom: .9rem; }
.cu-lens {
    min-height: 58px; padding: .65rem .85rem; border: 1px solid #e4ebe7; border-radius: 13px;
    background: #fff; color: #405249; display: flex; align-items: center; gap: .55rem;
    font-weight: 750; transition: .16s ease; text-align: start;
}
.cu-lens i { color: #167443; font-size: 1.05rem; }
.cu-lens b { margin-inline-start: auto; min-width: 28px; padding: .18rem .45rem; border-radius: 99px; background: #edf7f1; text-align: center; }
.cu-lens:hover, .cu-lens.active { border-color: #178047; background: #f2faf5; color: #0d6737; transform: translateY(-1px); }
.cu-lens--danger i { color: #c33b3b; }
.cu-lens--danger.active { border-color: #e3a1a1; background: #fff5f5; color: #a52f2f; }
.cu-search { display: grid; grid-template-columns: minmax(260px, 1fr) 190px auto; gap: .65rem; align-items: center; }
.cu-search__box { min-height: 46px; display: flex; align-items: center; gap: .65rem; padding: 0 .9rem; border: 1px solid #dfe7e2; border-radius: 11px; background: #fff; }
.cu-search__box:focus-within { border-color: #1a7f49; box-shadow: 0 0 0 3px rgba(26,127,73,.09); }
.cu-search__box i { color: #789087; }
.cu-search__box input { width: 100%; border: 0; outline: 0; background: transparent; min-height: 42px; }
.cu-search select { min-height: 46px; border: 1px solid #dfe7e2; border-radius: 11px; padding: 0 .8rem; background: #fff; }
.cu-search .btn { min-height: 46px; padding-inline: 1.25rem; }
.cu-scope { margin: .65rem 0 0; color: #74857d; font-size: .78rem; }
.cu-grid { display: grid; gap: .65rem; }
.cu-card {
    position: relative; display: grid; grid-template-columns: 52px minmax(210px, 1.3fr) minmax(155px, .8fr) minmax(230px, .9fr) 18px;
    gap: .9rem; align-items: center; min-height: 86px; padding: .8rem .9rem; border: 1px solid #e5ece8;
    border-radius: 14px; background: #fff; color: inherit; text-decoration: none; transition: .15s ease;
}
.cu-card:hover { border-color: #a9cfb8; box-shadow: 0 8px 24px rgba(21,80,47,.08); transform: translateY(-1px); }
.cu-card.is-blocked { border-inline-start: 4px solid #d9534f; background: linear-gradient(90deg, #fff 75%, #fff8f8); }
.cu-avatar { width: 52px; height: 52px; border-radius: 16px; display: grid; place-items: center; color: #fff; font-size: 1.25rem; font-weight: 900; background: linear-gradient(145deg, hsl(var(--hue) 50% 48%), hsl(var(--hue) 55% 35%)); }
.cu-person { min-width: 0; }
.cu-person__title { display: flex; gap: .55rem; align-items: center; }
.cu-person__title strong { font-size: .98rem; color: #17251e; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cu-status { font-size: .68rem; padding: .15rem .45rem; border-radius: 99px; font-weight: 800; }
.cu-status.is-active { color: #0d7540; background: #e8f7ee; }
.cu-status.is-danger { color: #b52e2e; background: #feecec; }
.cu-contact { display: flex; flex-wrap: wrap; gap: .35rem .85rem; margin-top: .32rem; color: #6e7e76; font-size: .76rem; }
.cu-contact i { margin-inline-end: .2rem; }
.cu-block-reason { margin: .35rem 0 0; color: #ae3434; font-size: .72rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cu-branch { display: flex; flex-direction: column; gap: .35rem; align-items: flex-start; }
.cu-branch small, .cu-muted { color: #89968f; font-size: .7rem; }
.cu-branch__tag { max-width: 100%; padding: .25rem .55rem; border-radius: 8px; background: hsl(var(--hue) 55% 95%); color: hsl(var(--hue) 45% 30%); font-size: .72rem; font-weight: 750; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.cu-activity { display: grid; grid-template-columns: repeat(3, 1fr); gap: .35rem; }
.cu-activity span { min-height: 48px; padding: .4rem; border-radius: 10px; background: #f6f9f7; display: grid; grid-template-columns: auto auto; align-items: center; justify-content: center; gap: .15rem .35rem; }
.cu-activity i { color: #608073; }
.cu-activity b { color: #1b3328; }
.cu-activity small { grid-column: 1 / -1; color: #86948d; font-size: .62rem; text-align: center; }
.cu-open { color: #91a49a; }
@media (max-width: 1100px) {
    .cu-card { grid-template-columns: 52px minmax(200px, 1fr) minmax(210px, .8fr) 18px; }
    .cu-branch { display: none; }
}
@media (max-width: 767.98px) {
    .cu-stats :deep(.stat-rail) { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .55rem; }
    .cu-stats :deep(.stat-rail-item) { min-height: 68px; padding: .55rem .65rem; gap: .5rem; }
    .cu-stats :deep(.stat-rail-icon) { width: 34px; height: 34px; font-size: .9rem; }
    .cu-stats :deep(.stat-rail-label) { font-size: .66rem; line-height: 1.25; }
    .cu-stats :deep(.stat-rail-value) { font-size: 1.05rem; }
    .cu-lenses { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cu-lens { min-height: 50px; font-size: .78rem; }
    .cu-search { grid-template-columns: 1fr; }
    .cu-card { grid-template-columns: 46px minmax(0, 1fr) 14px; padding: .75rem; }
    .cu-avatar { width: 46px; height: 46px; border-radius: 14px; }
    .cu-activity { grid-column: 1 / -1; }
    .cu-contact { flex-direction: column; }
}
</style>
