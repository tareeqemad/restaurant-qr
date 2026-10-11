<script setup>
import { computed } from 'vue';
import { formatMoney } from '../../Composables/useMoney';

const props = defineProps({
    covers: { type: Number, required: true },
    orders: { type: Array, required: true },
    customer: { type: String, default: null },
    debt: { type: Number, default: 0 },
    currency: { type: Object, required: true },
    // Ported features (Phase-3 wiring): the bar is also the doorway to the
    // customer sheet and — when the restaurant configured bank details and
    // the session has a fired order — the transfer-claim sheet.
    transferVisible: { type: Boolean, default: false },
});

const emit = defineEmits({
    'change-covers': (delta) => Number.isInteger(delta) && Math.abs(delta) === 1,
    'open-session': () => true,
    'open-customer': () => true,
    'open-transfer': () => true,
});

const latestOrder = computed(() => props.orders[0] ?? null);
</script>

<template>
    <div class="session-bar" role="group" aria-label="وضع الجلسة">
        <span class="covers-control" title="عدد الأشخاص">
            <button
                type="button"
                :disabled="covers <= 1"
                aria-label="تقليل عدد الأشخاص"
                @click="emit('change-covers', -1)"
            >
                <i class="bi bi-dash"></i>
            </button>
            <span><i class="bi bi-people-fill"></i> {{ covers }}</span>
            <button
                type="button"
                :disabled="covers >= 50"
                aria-label="زيادة عدد الأشخاص"
                @click="emit('change-covers', 1)"
            >
                <i class="bi bi-plus"></i>
            </button>
        </span>

        <button v-if="orders.length" type="button" class="order-pill session-summary"
                :class="`is-${latestOrder?.status ?? 'pending'}`"
                title="عرض أصناف ومبالغ كل الجولات"
                @click="emit('open-session')">
            <i class="bi bi-layers-fill"></i>
            <b>جلسة مفتوحة · {{ orders.length }} {{ orders.length === 1 ? 'جولة' : 'جولات' }}</b>
            <small>آخرها: {{ latestOrder?.statusLabel }}</small>
            <i class="bi bi-chevron-left session-summary__arrow"></i>
        </button>

        <button type="button" class="customer-pill is-action" @click="emit('open-customer')"
                :title="customer ? 'إدارة زبون الطاولة' : 'ربط زبون بالطاولة'">
            <i class="bi" :class="customer ? 'bi-person-check' : 'bi-person-plus'"></i>
            {{ customer ?? 'ربط زبون' }}
        </button>

        <span v-if="customer && debt > 0.009" class="debt-pill" title="دين سابق على الزبون">
            <i class="bi bi-exclamation-triangle-fill"></i>
            دين {{ formatMoney(debt, currency) }}
        </span>

        <button v-if="transferVisible" type="button" class="customer-pill is-action"
                title="تسجيل حوالة بنكية من الزبون" @click="emit('open-transfer')">
            <i class="bi bi-bank"></i>
            حوالة بنكية
        </button>
    </div>
</template>

<style scoped>
.session-bar {
    display: flex;
    align-items: center;
    gap: .4rem;
    width: min(100%, 1180px);
    margin-inline: auto;
    padding: .15rem 0 .45rem;
    overflow-x: auto;
    scrollbar-width: thin;
    -webkit-overflow-scrolling: touch;
}
.covers-control {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    overflow: hidden;
    border: 1px solid color-mix(in srgb, var(--brand) 18%, transparent);
    border-radius: 12px;
    background: var(--surface);
}
.covers-control > span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .3rem;
    min-width: 54px;
    font-size: 14px;
    font-weight: 800;
    color: var(--ink);
}
.covers-control > span i { color: var(--wp-primary); }
.covers-control button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: 0;
    color: var(--ink);
    background: var(--surface);
    cursor: pointer;
}
.covers-control button:hover:not(:disabled) { background: var(--canvas); }
.covers-control button:disabled { opacity: .4; cursor: default; }
.order-pill,
.customer-pill,
.debt-pill {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    gap: .3rem;
    min-height: 44px;
    box-sizing: border-box;
    padding: .3rem .7rem;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    white-space: nowrap;
}
.order-pill {
    border: 1px solid transparent;
    color: var(--ink);
    background: var(--canvas);
    font-family: inherit;
    text-decoration: none;
}
.order-pill b { font-variant-numeric: tabular-nums; }
.session-summary { gap: .4rem; cursor: pointer; }
.session-summary:hover { border-color: currentColor; }
.session-summary small { padding-inline-start: .4rem; border-inline-start: 1px solid currentColor; opacity: .75; }
.session-summary__arrow { margin-inline-start: .1rem; font-size: .875rem; }
.order-pill.is-pending { color: #b45309; background: #F8F0E2; }
.order-pill.is-approved,
.order-pill.is-preparing { color: #315B6B; background: var(--canvas); }
.order-pill.is-ready { color: var(--ink); background: #EAF0E9; }
.order-pill.is-delivered { color: var(--ink); background: var(--canvas); }
.customer-pill { color: var(--ink); background: var(--canvas); }
.customer-pill.is-action { border: 1px solid var(--line); font-family: inherit; cursor: pointer; }
.customer-pill.is-action:hover { border-color: var(--wp-primary); color: var(--wp-primary); }
.debt-pill { color: #b91c1c; background: var(--canvas); }
@media (max-width: 560px) {
    .session-summary small { display: none; }
}

/* Task-specific component layout. */
.session-bar{display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:12px 0;border-block:1px solid var(--line);border-radius:0;background:transparent}.covers-control{border-radius:6px;background:var(--surface);border:1px solid var(--line)}.covers-control button{width:42px;height:42px}.order-pill,.customer-pill,.debt-pill{min-height:44px;border-radius:6px;font-size:14px;padding:8px 12px;background:var(--surface);border:1px solid var(--line)}.session-summary{margin-inline-start:auto}.session-summary__arrow{margin-inline-start:8px}.customer-pill{color:var(--ink)}@media(max-width:600px){.session-summary{margin-inline-start:0;flex:1}.order-pill{white-space:normal;text-align:start}}
</style>
