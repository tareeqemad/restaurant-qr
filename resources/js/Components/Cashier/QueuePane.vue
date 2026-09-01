<script setup>
import { computed } from 'vue';
import { formatMoney } from '../../Composables/useMoney';

const props = defineProps({
    sessions: { type: Array, required: true },
    remoteOrders: { type: Array, required: true },
    selection: { type: Object, default: null },
    mode: { type: String, required: true },
    filter: { type: String, required: true },
    search: { type: String, required: true },
    counts: { type: Object, required: true },
    currency: { type: Object, required: true },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits({
    select: (kind, id) => ['session', 'order'].includes(kind) && Number(id) > 0,
    'update:mode': (value) => ['all', 'tables', 'remote'].includes(value),
    'update:filter': (value) => ['checkout', 'all'].includes(value),
    'update:search': (value) => typeof value === 'string',
    refresh: () => true,
});

const rows = computed(() => {
    const sessions = props.sessions.map((row) => ({ ...row, rowKind: 'session' }));
    const orders = props.remoteOrders.map((row) => ({ ...row, rowKind: 'order' }));
    return [...sessions, ...orders];
});

function isSelected(row) {
    return props.selection?.kind === row.rowKind && Number(props.selection?.id) === Number(row.id);
}

function checkoutLabel(row) {
    if (row.invoice) return Number(row.invoice.balance) > 0.001 ? 'متبقي للتحصيل' : 'مكتملة';
    if (row.bill_requested_at) return 'الزبون طلب الفاتورة';
    return 'جاهزة لإصدار الفاتورة';
}
</script>

<template>
    <aside class="queue-pane" aria-label="قائمة التحصيل">
        <div class="queue-heading">
            <div>
                <span>قائمة العمل</span>
                <strong>{{ rows.length }} ظاهر</strong>
            </div>
            <button type="button" :disabled="loading" aria-label="تحديث القائمة" @click="emit('refresh')">
                <i class="bi bi-arrow-clockwise" :class="{ spinning: loading }"></i>
            </button>
        </div>

        <label class="queue-search">
            <i class="bi bi-search"></i>
            <input
                :value="search"
                type="search"
                placeholder="طاولة، اسم، هاتف أو رقم طلب"
                autocomplete="off"
                enterkeyhint="search"
                @input="emit('update:search', $event.target.value)"
            >
            <button
                v-if="search"
                type="button"
                aria-label="مسح البحث"
                @click="emit('update:search', '')"
            ><i class="bi bi-x-lg"></i></button>
        </label>

        <div class="queue-modes" aria-label="نوع القائمة">
            <button type="button" :class="{ active: mode === 'all' }" @click="emit('update:mode', 'all')">الكل</button>
            <button type="button" :class="{ active: mode === 'tables' }" @click="emit('update:mode', 'tables')">
                طاولات <b>{{ counts.checkout_sessions ?? 0 }}</b>
            </button>
            <button type="button" :class="{ active: mode === 'remote' }" @click="emit('update:mode', 'remote')">
                هاتفية <b>{{ counts.remote_unpaid ?? 0 }}</b>
            </button>
        </div>

        <button
            type="button"
            class="filter-toggle"
            @click="emit('update:filter', filter === 'checkout' ? 'all' : 'checkout')"
        >
            <i :class="filter === 'checkout' ? 'bi bi-funnel-fill' : 'bi bi-funnel'"></i>
            {{ filter === 'checkout' ? 'تحتاج تحصيل فقط' : 'كل الطاولات النشطة' }}
        </button>

        <div class="queue-list">
            <button
                v-for="row in rows"
                :key="`${row.rowKind}:${row.id}`"
                type="button"
                class="queue-row"
                :class="[{ selected: isSelected(row) }, `is-${row.urgency}`]"
                @click="emit('select', row.rowKind, row.id)"
            >
                <span class="row-title">
                    <strong>{{ row.label }}</strong>
                    <small v-if="row.rowKind === 'session'">
                        {{ row.customer || 'بدون اسم' }} · {{ row.orders_count }} {{ row.orders_count === 1 ? 'جولة' : 'جولات' }}
                    </small>
                    <small v-else>{{ row.customer || row.phone || 'طلب هاتفي' }} · {{ row.status_label }}</small>
                </span>
                <span class="row-money">
                    <b>{{ formatMoney(row.invoice?.balance ?? row.total, currency) }}</b>
                    <small v-if="row.wait_minutes !== null && row.wait_minutes !== undefined">{{ row.wait_minutes }} د</small>
                    <small v-else :class="{ 'needs-issue': !row.invoice }">{{ checkoutLabel(row) }}</small>
                </span>
                <span v-if="row.pending_changes" class="change-count" title="طلبات تعديل معلّقة">
                    <i class="bi bi-arrow-repeat"></i> {{ row.pending_changes }}
                </span>
            </button>

            <div v-if="!rows.length" class="queue-empty">
                <i class="bi bi-check2-circle"></i>
                <strong>لا يوجد شيء في هذه القائمة</strong>
                <span>غيّر الفلتر أو ابحث عن طاولة محددة.</span>
            </div>
        </div>
    </aside>
</template>

<style scoped>
.queue-pane {
    display: flex;
    min-height: 0;
    flex-direction: column;
    padding: .7rem;
    border: 1px solid #e1e8e4;
    border-radius: 15px;
    background: #fff;
    box-shadow: 0 12px 34px -30px rgba(15, 49, 31, .8);
}
.queue-heading { display: flex; align-items: center; justify-content: space-between; margin-bottom: .55rem; }
.queue-heading > div { display: flex; align-items: baseline; gap: .45rem; }
.queue-heading span { color: #24362a; font-size: .92rem; font-weight: 850; }
.queue-heading strong { color: #7b887f; font-size: .68rem; }
.queue-heading button {
    display: grid;
    place-items: center;
    width: 44px;
    height: 44px;
    border: 1px solid #e3e9e5;
    border-radius: 11px;
    color: var(--cx-primary);
    background: #f8faf9;
}
.queue-search { position: relative; display: block; }
.queue-search i { position: absolute; top: 50%; inset-inline-start: .75rem; color: #89958d; transform: translateY(-50%); }
.queue-search > button { position: absolute; top: 50%; inset-inline-end: .35rem; display: grid; width: 34px; height: 34px; place-items: center; border: 0; border-radius: 8px; color: #69766e; background: #edf2ef; transform: translateY(-50%); }
.queue-search > button i { position: static; transform: none; }
.queue-search input {
    width: 100%;
    height: 44px;
    box-sizing: border-box;
    padding: 0 2.25rem;
    border: 1px solid #dde5e0;
    border-radius: 11px;
    outline: none;
    background: #fbfcfb;
    font: inherit;
    font-size: .78rem;
}
.queue-search input:focus { border-color: rgba(var(--primary-rgb, 22 101 52), .5); box-shadow: 0 0 0 3px rgba(var(--primary-rgb, 22 101 52), .08); }
.queue-modes { display: grid; grid-template-columns: repeat(3, 1fr); gap: .3rem; margin-top: .5rem; padding: .25rem; border-radius: 11px; background: #f1f5f2; }
.queue-modes button {
    min-height: 38px;
    border: 0;
    border-radius: 8px;
    color: #66736b;
    background: transparent;
    font: inherit;
    font-size: .72rem;
    font-weight: 750;
}
.queue-modes button.active { color: var(--cx-primary); background: #fff; box-shadow: 0 2px 8px rgba(25, 61, 38, .08); }
.queue-modes b { display: inline-grid; min-width: 18px; height: 18px; place-items: center; border-radius: 999px; background: #e8f4eb; font-size: .63rem; }
.filter-toggle {
    display: flex;
    align-items: center;
    gap: .35rem;
    min-height: 38px;
    margin-top: .35rem;
    padding-inline: .45rem;
    border: 0;
    color: #5c6961;
    background: transparent;
    font: inherit;
    font-size: .7rem;
    font-weight: 700;
}
.queue-list { display: flex; min-height: 0; margin-top: .25rem; flex: 1; flex-direction: column; gap: .4rem; overflow-y: auto; }
.queue-row {
    position: relative;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: .45rem;
    align-items: center;
    min-height: 68px;
    padding: .58rem .65rem;
    border: 1px solid #e5ebe7;
    border-inline-start: 3px solid #aeb9b2;
    border-radius: 11px;
    color: #243129;
    background: #fff;
    text-align: start;
    cursor: pointer;
}
.queue-row:hover { background: #f9fbfa; }
.queue-row.is-warning { border-inline-start-color: #d97706; }
.queue-row.is-critical { border-inline-start-color: #dc3545; }
.queue-row.selected { border-color: rgba(var(--primary-rgb, 22 101 52), .56); background: #f2faf4; box-shadow: 0 0 0 2px rgba(var(--primary-rgb, 22 101 52), .08); }
.row-title, .row-money { display: flex; min-width: 0; flex-direction: column; }
.row-title strong, .row-title small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.row-title strong { font-size: .78rem; }
.row-title small, .row-money small { margin-top: .16rem; color: #7a867e; font-size: .64rem; }
.row-money { align-items: flex-end; }
.row-money b { color: #1f3b29; font-size: .75rem; white-space: nowrap; }
.row-money small.needs-issue { color: #a45a06; font-weight: 800; }
.change-count { position: absolute; top: -.32rem; inset-inline-end: -.25rem; padding: .1rem .32rem; border-radius: 999px; color: #fff; background: #c92b3a; font-size: .61rem; font-weight: 800; }
.queue-empty { display: grid; min-height: 180px; place-items: center; align-content: center; gap: .3rem; color: #829087; text-align: center; }
.queue-empty i { color: #8bb69a; font-size: 1.35rem; }
.queue-empty strong { color: #526158; font-size: .78rem; }
.queue-empty span { font-size: .68rem; }
.spinning { animation: spin .75s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (prefers-reduced-motion: reduce) { .spinning { animation: none; } }
</style>
