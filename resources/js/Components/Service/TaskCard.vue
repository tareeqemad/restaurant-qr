<script setup>
/**
 * One service task — ONE card shape for all four kinds (pending /
 * production / ready / billing). The kind only changes the accent stripe,
 * the icon, and the primary button; everything else reads identically, so
 * a waiter mid-rush learns one layout instead of four.
 *
 * Details expand in place: lines, notes, and the stock block that explains
 * exactly which items to cancel before approval can go through.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    task: { type: Object, required: true },
    stock: { type: Object, default: null },   // { issues, shortItemIds } when short
    expanded: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle', 'act']);

const KIND_META = {
    help: { icon: 'bi-hand-index-thumb-fill', tone: 'help' },
    pending: { icon: 'bi-inbox-fill', tone: 'pending' },
    production: { icon: 'bi-fire', tone: 'production' },
    ready: { icon: 'bi-bell-fill', tone: 'ready' },
    billing: { icon: 'bi-receipt-cutoff', tone: 'billing' },
};

const meta = computed(() => KIND_META[props.task.kind] ?? KIND_META.pending);

// Ready red / urgent anything = hot. Amber ready or late production = warm.
const heat = computed(() => {
    if (props.task.readyUrgency === 'red') return 'hot';
    if (props.task.urgent && ['pending', 'billing', 'ready'].includes(props.task.kind)) return 'hot';
    if (props.task.readyUrgency === 'amber' || (props.task.urgent && props.task.kind === 'production')) return 'warm';
    return '';
});

const blocked = computed(() => props.task.kind === 'pending' && Boolean(props.stock));
const isShort = (itemId) => props.stock?.shortItemIds?.includes(itemId) ?? false;
const previewItems = computed(() => props.task.items?.slice(0, 3) ?? []);
const hiddenPreviewCount = computed(() => Math.max(0, (props.task.items?.length ?? 0) - previewItems.value.length));
const act = (verb, payload = {}) => emit('act', { verb, ...payload });
</script>

<template>
    <article class="sv-card" :class="[`sv-card--${task.kind}`, heat ? `is-${heat}` : '', { 'is-open': expanded, 'is-busy': busy }]" :aria-busy="busy">
        <button type="button" class="sv-head" @click="emit('toggle', task.key)">
            <span class="sv-badge"><i class="bi" :class="meta.icon"></i></span>

            <span class="sv-head-copy">
                <span class="sv-title">
                    {{ task.title }}
                    <small v-if="task.zoneLabel" class="sv-zone">{{ task.zoneLabel }}</small>
                </span>
                <span class="sv-sub">{{ task.subtitle }}</span>
            </span>

            <span class="sv-head-side">
                <span class="sv-label">{{ task.label }}</span>
                <span class="sv-age" :class="{ 'is-late': task.urgent }">
                    <i class="bi bi-clock"></i> {{ task.ageLabel }}
                </span>
            </span>

            <i class="bi sv-chev" :class="expanded ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
        </button>

        <!-- The same execution identity is shown to waiter, kitchen and diner. -->
        <div v-if="task.roundNumber" class="sv-round">
            <span class="sv-round-name"><i class="bi bi-layers-fill"></i> جولة {{ task.roundNumber }}</span>
            <span class="sv-round-kind" :class="{ 'is-addition': task.isAddition }">{{ task.roundLabel }}</span>
            <span class="sv-round-count"><b>{{ task.pieceCount }}</b> قطعة · {{ task.lineCount }} صنف</span>
            <span v-for="station in task.stations" :key="station.name" class="sv-destination">
                {{ station.name }} <b>{{ station.pieces }}</b>
            </span>
        </div>

        <!-- Pending cards are compact under pressure but never hide what the
             waiter is being asked to approve. -->
        <div v-if="task.kind === 'pending' && ! expanded" class="sv-preview" aria-label="ملخص أصناف الجولة">
            <div v-for="it in previewItems" :key="it.id" class="sv-preview-line">
                <b>×{{ it.qty }}</b>
                <span>{{ it.name }}</span>
                <small>{{ it.stationName || 'خدمة مباشرة' }}</small>
            </div>
            <div v-if="hiddenPreviewCount" class="sv-preview-more">+ {{ hiddenPreviewCount }} أصناف أخرى</div>
            <div v-if="task.notes" class="sv-preview-note"><i class="bi bi-chat-left-text-fill"></i> {{ task.notes }}</div>
        </div>

        <div v-if="blocked" class="sv-block">
            <i class="bi bi-exclamation-octagon-fill"></i>
            <div>
                <strong>نقص بالمخزون — الاعتماد موقوف</strong>
                <span>{{ stock.issues.slice(0, 3).map(i => `${i.ingredient} (متاح ${i.available})`).join('، ') }}</span>
                <small>ألغِ الأصناف الناقصة من التفاصيل ثم اعتمد الباقي.</small>
            </div>
        </div>

        <div v-if="task.kind === 'pending' && task.hasPendingChange" class="sv-block sv-block--change">
            <i class="bi bi-pause-circle-fill"></i>
            <div>
                <strong>الزبون طلب تغييراً على هذه الجولة</strong>
                <span>احسم بطاقة التعديل أولاً؛ بعدها ستتحدث الأصناف ويمكن اعتماد النسخة الصحيحة فقط.</span>
            </div>
        </div>

        <div v-if="expanded" class="sv-body">
            <div v-if="task.notes" class="sv-note">
                <i class="bi bi-exclamation-triangle-fill"></i> {{ task.notes }}
            </div>

            <div v-if="task.items.length" class="sv-lines">
                <div v-for="it in task.items" :key="it.id" class="sv-line" :class="[`is-${it.status}`, { 'is-short': isShort(it.id) }]">
                    <span class="sv-line-qty">×{{ it.qty }}</span>
                    <div class="sv-line-body">
                        <span class="sv-line-name">
                            {{ it.name }}
                            <small v-if="isShort(it.id)" class="sv-short-tag">ناقص</small>
                        </span>
                        <small v-if="it.stationName" class="sv-station">
                            <i class="bi bi-geo-alt-fill"></i> الاستلام من {{ it.stationName }}
                        </small>
                        <small v-if="it.mods.length" class="sv-line-mods">{{ it.mods.join('، ') }}</small>
                        <small v-if="it.exclusions?.length" class="sv-line-exclusions">
                            <i class="bi bi-slash-circle-fill"></i> بدون {{ it.exclusions.join('، بدون ') }}
                        </small>
                        <small v-if="it.notes" class="sv-line-note">📝 {{ it.notes }}</small>
                    </div>
                    <div class="sv-line-side">
                        <span class="sv-line-status" :class="`sv-st--${it.status}`">
                            {{ { pending: 'بانتظار', approved: 'معتمد', preparing: 'قيد التحضير', ready: 'جاهز', served: 'مقدّم' }[it.status] ?? it.status }}
                        </span>
                        <button v-if="it.status === 'ready' && task.canServe" type="button" class="sv-mini"
                                :disabled="busy" @click.stop="act('serve-item', { item_id: it.id })">
                            <i class="bi bi-check2"></i> قدّم
                        </button>
                        <button v-else-if="isShort(it.id) && task.canCancel" type="button" class="sv-mini sv-mini--danger"
                                :disabled="busy" @click.stop="act('cancel-item', { item_id: it.id, name: it.name })">
                            <i class="bi bi-x-lg"></i> ألغِ
                        </button>
                    </div>
                </div>
            </div>

            <div class="sv-meta">
                <span v-if="task.number"><i class="bi bi-hash"></i>{{ task.number }}</span>
                <span v-if="task.customerName"><i class="bi bi-person"></i> {{ task.customerName }}</span>
                <span v-if="task.waiterName"><i class="bi bi-person-badge"></i> {{ task.waiterName }}</span>
                <span v-if="task.orderCount"><i class="bi bi-receipt"></i> {{ task.orderCount }} طلب</span>
                <span class="sv-total">{{ task.total }}</span>
            </div>
        </div>

        <footer class="sv-foot">
            <button v-if="task.kind === 'help' && task.canAck" type="button"
                    class="sv-btn sv-btn--help" :disabled="busy"
                    @click="act('ack-help', { session_id: task.sessionId })">
                <i class="bi" :class="busy ? 'bi-arrow-repeat sv-spin' : 'bi-person-walking'"></i> {{ busy ? 'جارٍ التأكيد…' : 'أنا ذاهب للطاولة' }}
            </button>

            <Link v-else-if="task.kind === 'pending' && task.canApprove"
                  :href="task.reviewUrl" class="sv-btn sv-btn--review">
                <i class="bi bi-person-check-fill"></i>
                <span class="sv-review-copy">
                    <b>{{ task.external ? 'راجع الطلب ثم اعتمده' : 'اذهب للطاولة وراجع الجولة' }}</b>
                    <small>{{ task.hasPendingChange ? 'يوجد تعديل جديد من الزبون' : `${task.pieceCount} قطعة قبل إرسالها للمحطات` }}</small>
                </span>
                <i class="bi bi-arrow-left"></i>
            </Link>

            <button v-else-if="task.kind === 'ready' && task.canServe" type="button"
                    class="sv-btn sv-btn--serve" :disabled="busy"
                    @click="act('serve-ready', { order_id: task.orderId })">
                <i class="bi" :class="busy ? 'bi-arrow-repeat sv-spin' : 'bi-bag-check-fill'"></i> {{ busy ? 'جارٍ التقديم…' : `تقديم الجاهز (${task.readyCount})` }}
            </button>

            <a v-else-if="task.kind === 'billing'" :href="task.cashierUrl" class="sv-btn sv-btn--bill">
                <i class="bi bi-cash-stack"></i> افتح الكاشير
            </a>

            <span v-else class="sv-watch"><i class="bi bi-eye"></i> بالمطبخ — للمتابعة فقط</span>
        </footer>
    </article>
</template>

<style scoped>
.sv-card {
    background: #fff;
    border: 1px solid #e8ecf0;
    border-radius: 16px;
    border-inline-start: 5px solid #cbd5e1;
    overflow: hidden;
    transition: box-shadow .15s, border-color .15s;
}
.sv-card.is-open { box-shadow: 0 10px 28px -14px rgba(15, 23, 42, .22); }
.sv-card.is-busy { border-color: #9fc5b3; box-shadow: 0 0 0 3px rgba(var(--primary-rgb), .08); }
.sv-card--pending { border-inline-start-color: #2563eb; }
.sv-card--help { border-inline-start-color: #dc2626; background: #fffafa; }
.sv-card--production { border-inline-start-color: #94a3b8; }
.sv-card--ready { border-inline-start-color: #059669; }
.sv-card--billing { border-inline-start-color: #7c3aed; }
.sv-card.is-warm { border-inline-start-color: #d97706; background: #fffdf7; }
.sv-card.is-hot { border-inline-start-color: #dc2626; background: #fffafa; }

.sv-head {
    display: flex;
    align-items: center;
    gap: .7rem;
    width: 100%;
    padding: .85rem .9rem;
    background: transparent;
    border: 0;
    font: inherit;
    text-align: start;
    cursor: pointer;
    min-height: 64px;
}
.sv-badge {
    width: 42px;
    height: 42px;
    flex-shrink: 0;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
    background: #f1f5f9;
    color: #475569;
}
.sv-card--pending .sv-badge { background: #eff6ff; color: #1d4ed8; }
.sv-card--help .sv-badge { background: #fef2f2; color: #b91c1c; }
.sv-card--ready .sv-badge { background: #ecfdf5; color: #047857; }
.sv-card--billing .sv-badge { background: #f5f3ff; color: #6d28d9; }
.sv-card.is-hot .sv-badge { background: #fef2f2; color: #b91c1c; }
.sv-card.is-warm .sv-badge { background: #fffbeb; color: #b45309; }

.sv-head-copy { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .15rem; }
.sv-title { font-size: 1rem; font-weight: 900; color: #0f172a; display: flex; align-items: baseline; gap: .4rem; }
.sv-zone { font-size: .7rem; font-weight: 600; color: #94a3b8; }
.sv-sub {
    font-size: .8rem;
    color: #64748b;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.sv-head-side { display: flex; flex-direction: column; align-items: flex-end; gap: .2rem; flex-shrink: 0; }
.sv-label { font-size: .74rem; font-weight: 800; color: #334155; }
.sv-card.is-hot .sv-label { color: #b91c1c; }
.sv-age { font-size: .72rem; color: #94a3b8; font-weight: 700; }
.sv-age.is-late { color: #b45309; }
.sv-chev { color: #cbd5e1; font-size: .8rem; flex-shrink: 0; }

.sv-round {
    min-height: 38px;
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: .35rem;
    margin: 0 .9rem .6rem;
    padding: .35rem .5rem;
    border-radius: 10px;
    background: #f5f8f6;
    color: #53645b;
    font-size: .68rem;
}
.sv-round-name { display: inline-flex; align-items: center; gap: .25rem; color: rgb(var(--primary-rgb)); font-weight: 950; }
.sv-round-kind { padding: .12rem .4rem; border-radius: 999px; background: #e9f1ec; font-weight: 850; }
.sv-round-kind.is-addition { background: #fff1d8; color: #9a500c; }
.sv-round-count { margin-inline-end: auto; }
.sv-destination { padding: .1rem .38rem; border: 1px solid #dce6e0; border-radius: 999px; background: #fff; white-space: nowrap; }
.sv-destination b { color: #17251d; }

.sv-preview { display: grid; gap: .3rem; margin: 0 .9rem .65rem; padding: .5rem .6rem; border: 1px solid #e4ebe6; border-radius: 11px; background: #fbfcfb; }
.sv-preview-line { min-height: 28px; display: grid; grid-template-columns: 2.2rem minmax(0, 1fr) auto; align-items: center; gap: .4rem; }
.sv-preview-line b { color: rgb(var(--primary-rgb)); font-size: .73rem; }
.sv-preview-line span { overflow: hidden; color: #24362c; font-size: .78rem; font-weight: 850; text-overflow: ellipsis; white-space: nowrap; }
.sv-preview-line small { color: #718078; font-size: .62rem; }
.sv-preview-more { color: #6b7971; font-size: .65rem; font-weight: 850; }
.sv-preview-note { padding-top: .35rem; border-top: 1px dashed #e2e8e4; color: #935615; font-size: .68rem; line-height: 1.5; }

.sv-block {
    display: flex;
    gap: .6rem;
    align-items: flex-start;
    margin: 0 .9rem .7rem;
    padding: .65rem .8rem;
    background: #fef2f2;
    border: 1px solid #fecaca;
    border-radius: 12px;
    color: #991b1b;
    font-size: .78rem;
}
.sv-block > i { font-size: 1rem; margin-top: 1px; }
.sv-block strong, .sv-block span, .sv-block small { display: block; }
.sv-block small { opacity: .8; margin-top: .2rem; }
.sv-block--change { background: #fff8e7; border-color: #efd38d; color: #7c5313; }

.sv-body { padding: 0 .9rem .3rem; display: flex; flex-direction: column; gap: .6rem; }
.sv-note {
    background: #fffbeb;
    border: 1px solid #fde68a;
    color: #92400e;
    border-radius: 10px;
    padding: .5rem .7rem;
    font-size: .8rem;
    font-weight: 700;
}

.sv-lines { display: flex; flex-direction: column; }
.sv-line {
    display: flex;
    align-items: flex-start;
    gap: .6rem;
    padding: .5rem 0;
    border-bottom: 1px dashed #eef2f6;
}
.sv-line:last-child { border-bottom: 0; }
.sv-line.is-short { background: #fff5f5; border-radius: 8px; padding-inline: .5rem; }
.sv-line-qty { font-size: .78rem; font-weight: 900; color: #475569; min-width: 2em; }
.sv-line-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .1rem; }
.sv-line-name { font-size: .86rem; font-weight: 700; color: #0f172a; }
.sv-short-tag {
    background: #fee2e2;
    color: #b91c1c;
    border-radius: 999px;
    padding: 0 .4rem;
    font-size: .66rem;
    font-weight: 800;
    margin-inline-start: .3rem;
}
.sv-line-mods, .sv-line-note, .sv-station { font-size: .72rem; color: #64748b; }
.sv-line-exclusions { width: fit-content; padding: .12rem .4rem; border-radius: 999px; background: #fee2e2; color: #991b1b; font-size: .72rem; font-weight: 900; }
.sv-station { color: #0f766e; font-weight: 800; }
.sv-line-side { display: flex; align-items: center; gap: .4rem; flex-shrink: 0; }
.sv-line-status { font-size: .68rem; font-weight: 800; border-radius: 999px; padding: .12rem .5rem; background: #f1f5f9; color: #475569; }
.sv-st--ready { background: #ecfdf5; color: #047857; }
.sv-st--preparing { background: #fff7ed; color: #c2410c; }
.sv-st--served { background: #eef2ff; color: #4338ca; }
.sv-mini {
    min-height: 34px;
    padding: 0 .6rem;
    border: 0;
    border-radius: 9px;
    background: rgb(var(--primary-rgb));
    color: #fff;
    font: inherit;
    font-size: .74rem;
    font-weight: 800;
    cursor: pointer;
}
.sv-mini--danger { background: #fef2f2; color: #b91c1c; }
.sv-mini:disabled { opacity: .5; }

.sv-meta {
    display: flex;
    flex-wrap: wrap;
    gap: .7rem;
    padding-top: .4rem;
    border-top: 1px solid #f1f5f9;
    font-size: .74rem;
    color: #94a3b8;
    align-items: center;
}
.sv-total { margin-inline-start: auto; font-weight: 900; color: #0f172a; font-size: .85rem; }

.sv-foot { padding: .6rem .9rem .8rem; }
.sv-btn {
    width: 100%;
    min-height: 48px;
    border: 0;
    border-radius: 13px;
    font: inherit;
    font-size: .92rem;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
    text-decoration: none;
}
.sv-btn--go { background: rgb(var(--primary-rgb)); color: #fff; }
.sv-btn--review { justify-content: flex-start; border: 1px solid rgba(var(--primary-rgb), .24); background: rgba(var(--primary-rgb), .07); color: rgb(var(--primary-rgb)); }
.sv-review-copy { display: flex; flex: 1; min-width: 0; flex-direction: column; align-items: flex-start; gap: .08rem; }
.sv-review-copy b { font-size: .86rem; }
.sv-review-copy small { color: #64748b; font-size: .66rem; font-weight: 750; }
.sv-btn--help { background: #dc2626; color: #fff; }
.sv-btn--go.is-blocked { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }
.sv-btn--serve { background: #059669; color: #fff; }
.sv-btn--bill { background: #f5f3ff; color: #6d28d9; border: 1.5px solid #ddd6fe; }
.sv-btn:disabled { opacity: .6; }
.sv-spin { animation: sv-spin .75s linear infinite; }
@keyframes sv-spin { to { transform: rotate(360deg); } }
.sv-watch {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: .4rem;
    min-height: 40px;
    color: #94a3b8;
    font-size: .8rem;
    font-weight: 700;
}

@media (max-width: 520px) {
    .sv-head { padding: .72rem; gap: .5rem; }
    .sv-head-side { display: none; }
    .sv-round, .sv-preview, .sv-block { margin-inline: .7rem; }
    .sv-round-count { width: 100%; margin-inline-end: 0; }
    .sv-preview-line { grid-template-columns: 2rem minmax(0, 1fr); }
    .sv-preview-line small { grid-column: 2; }
}
</style>
