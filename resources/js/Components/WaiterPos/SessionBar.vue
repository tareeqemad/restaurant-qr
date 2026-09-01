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

        <span v-if="orders.length" class="order-pill session-summary"
              :class="`is-${latestOrder?.status ?? 'pending'}`"
              title="كل الجولات ضمن جلسة وفاتورة واحدة">
            <i class="bi bi-layers-fill"></i>
            <b>جلسة مفتوحة · {{ orders.length }} {{ orders.length === 1 ? 'جولة' : 'جولات' }}</b>
            <small>آخرها: {{ latestOrder?.statusLabel }}</small>
        </span>

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
    border: 1px solid rgba(15, 71, 49, .18);
    border-radius: 999px;
    background: #fff;
}
.covers-control > span {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .3rem;
    min-width: 54px;
    font-size: 13px;
    font-weight: 800;
    color: #1f2937;
}
.covers-control > span i { color: var(--wp-primary); }
.covers-control button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: 0;
    color: #374151;
    background: #f8fafc;
    cursor: pointer;
}
.covers-control button:hover:not(:disabled) { background: #eef2f7; }
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
    border-radius: 999px;
    font-size: 12.5px;
    font-weight: 700;
    white-space: nowrap;
}
.order-pill {
    border: 1px solid transparent;
    color: #475569;
    background: #f1f5f9;
    text-decoration: none;
}
.order-pill b { font-variant-numeric: tabular-nums; }
.session-summary { gap: .4rem; }
.session-summary small { padding-inline-start: .4rem; border-inline-start: 1px solid currentColor; opacity: .75; }
.order-pill.is-pending { color: #b45309; background: #fef3c7; }
.order-pill.is-approved,
.order-pill.is-preparing { color: #1d4ed8; background: #dbeafe; }
.order-pill.is-ready { color: #047857; background: #d1fae5; }
.order-pill.is-delivered { color: #475569; background: #f1f5f9; }
.customer-pill { color: #475569; background: #f1f5f9; }
.customer-pill.is-action { border: 1px solid #e2e8f0; font-family: inherit; cursor: pointer; }
.customer-pill.is-action:hover { border-color: var(--wp-primary); color: var(--wp-primary); }
.debt-pill { color: #b91c1c; background: #fef2f2; }
@media (max-width: 560px) {
    .session-summary small { display: none; }
}
</style>
