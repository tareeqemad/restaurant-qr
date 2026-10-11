<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    order: { type: Object, required: true },
    canManage: { type: Boolean, default: true },
});
const emit = defineEmits(['cancel', 'change']);

const STEPS = [
    { label: 'تم الاستلام', icon: 'bi-receipt' },
    { label: 'قيد التحضير', icon: 'bi-fire' },
    { label: 'جاهز', icon: 'bi-bag-check' },
];

const ORDER_STATUS = {
    pending: { label: 'وصل لفريق الصالة', tone: 'waiting', icon: 'bi-check2-circle' },
    approved: { label: 'بدأ التجهيز', tone: 'progress', icon: 'bi-fire' },
    preparing: { label: 'قيد التحضير', tone: 'progress', icon: 'bi-fire' },
    ready: { label: 'جاهز للتقديم', tone: 'ready', icon: 'bi-bag-check-fill' },
    delivered: { label: 'وصل للطاولة', tone: 'ready', icon: 'bi-check2-all' },
    completed: { label: 'مكتمل', tone: 'done', icon: 'bi-check-circle-fill' },
    cancelled: { label: 'ملغي', tone: 'cancelled', icon: 'bi-x-circle-fill' },
};

const ITEM_BADGES = {
    pending: 'وصل للصالة',
    approved: 'قيد التجهيز',
    preparing: 'يُحضّر الآن',
    ready: 'جاهز',
    served: 'تم التقديم',
    cancelled: 'ملغي',
};

const now = ref(Date.now());
let timer = null;
onMounted(() => { timer = window.setInterval(() => { now.value = Date.now(); }, 1000); });
onBeforeUnmount(() => window.clearInterval(timer));

const etaDeadline = ref(null);
const cancelDeadline = ref(null);
watch(() => props.order, (order) => {
    etaDeadline.value = order.etaSeconds !== null ? Date.now() + order.etaSeconds * 1000 : null;
    cancelDeadline.value = order.canCancel ? Date.now() + order.cancelRemaining * 1000 : null;
}, { immediate: true });

const etaMinutes = computed(() => {
    if (etaDeadline.value === null) return null;
    const seconds = Math.floor((etaDeadline.value - now.value) / 1000);
    return seconds > 0 ? Math.max(1, Math.ceil(seconds / 60)) : 0;
});

const cancelSeconds = computed(() => cancelDeadline.value === null
    ? 0
    : Math.max(0, Math.ceil((cancelDeadline.value - now.value) / 1000)));
const canStillCancel = computed(() => cancelSeconds.value > 0);
const status = computed(() => ORDER_STATUS[props.order.status] ?? ORDER_STATUS.pending);
const changeTone = computed(() => ({ approved: 'approved', rejected: 'rejected' }[props.order.changeRequest?.status] ?? 'pending'));
const changeStatusText = computed(() => ({
    approved: 'تم اعتماد التعديل وتحديث طلبك',
    rejected: 'تعذّر تنفيذ التعديل',
}[props.order.changeRequest?.status] ?? 'وصل لفريق الصالة وبانتظار المراجعة'));
</script>

<template>
    <article class="order-card" :class="`order-card--${status.tone}`">
        <header class="order-header">
            <div class="order-heading">
                <span class="order-status" :class="`order-status--${status.tone}`">
                    <i class="bi" :class="status.icon"></i>{{ status.label }}
                </span>
                <span v-if="order.roundNumber" class="round-badge">الجولة {{ order.roundNumber }}</span>
                <h3>{{ order.title }}</h3>
                <p><span>{{ order.number }}</span><i></i><span>{{ order.createdAgo }}</span><i></i><span>{{ order.itemCount }} صنف</span></p>
            </div>
            <div class="order-total-top">
                <small>الإجمالي</small>
                <strong>{{ order.total }}</strong>
            </div>
        </header>

        <div v-if="order.status === 'cancelled'" class="final-state final-state--cancelled">
            <span><i class="bi bi-x-lg"></i></span>
            <div><strong>تم إلغاء الطلب</strong><small v-if="order.cancelledReason">{{ order.cancelledReason }}</small></div>
        </div>

        <div v-else-if="order.status === 'completed'" class="final-state final-state--done">
            <span><i class="bi bi-check2"></i></span>
            <div><strong>اكتمل الطلب</strong><small>نتمنى لك وجبة شهية وصحتين!</small></div>
        </div>

        <template v-else>
            <div class="progress-track" aria-label="حالة الطلب">
                <div v-for="(step, index) in STEPS" :key="step.label" class="progress-step" :class="{ 'is-done': index < order.stepIndex, 'is-current': index === order.stepIndex }">
                    <span class="progress-icon"><i class="bi" :class="index < order.stepIndex ? 'bi-check2' : step.icon"></i></span>
                    <small>{{ step.label }}</small>
                </div>
            </div>

            <div v-if="order.status === 'ready'" class="eta-box eta-box--ready">
                <span class="eta-icon"><i class="bi bi-bag-check-fill"></i></span>
                <div><small>الطلب جاهز</small><strong>سيصل إلى طاولتك الآن</strong></div>
                <i class="bi bi-stars eta-decoration"></i>
            </div>
            <div v-else-if="etaMinutes !== null" class="eta-box">
                <span class="eta-icon"><i class="bi bi-stopwatch"></i></span>
                <div>
                    <small>الوقت المتوقع</small>
                    <strong v-if="etaMinutes > 0"><b>{{ etaMinutes }}</b> دقيقة تقريباً</strong>
                    <strong v-else>اقترب موعد التجهيز</strong>
                </div>
                <span class="eta-live"><i></i> مباشر</span>
            </div>
        </template>

        <section class="items-section">
            <div class="items-title"><span>تفاصيل الطلب</span><small>{{ order.itemCount }} صنف</small></div>
            <div class="items-list">
                <div v-for="item in order.items" :key="item.id" class="item-row" :class="`is-${item.status}`">
                    <span class="item-quantity">{{ item.qty }}</span>
                    <div class="item-copy">
                        <strong>{{ item.name }}</strong>
                        <small v-if="item.modifiers.length">{{ item.modifiers.join('، ') }}</small>
                        <small v-if="item.exclusions?.length" class="item-exclusions">
                            <i class="bi bi-slash-circle-fill"></i> بدون {{ item.exclusions.join('، بدون ') }}
                        </small>
                        <small v-if="item.notes" class="item-note"><i class="bi bi-chat-left-text"></i>{{ item.notes }}</small>
                        <small v-if="item.status === 'cancelled' && item.cancelledReason" class="item-cancel-note">{{ item.cancelledReason }}</small>
                    </div>
                    <div class="item-side">
                        <strong>{{ item.subtotal }}</strong>
                        <span :class="`item-badge item-badge--${item.status}`">{{ ITEM_BADGES[item.status] ?? item.status }}</span>
                    </div>
                </div>
            </div>
        </section>

        <div v-if="order.changeRequest" class="change-state" :class="`change-state--${changeTone}`">
            <span class="change-icon"><i class="bi" :class="changeTone === 'approved' ? 'bi-check2' : (changeTone === 'rejected' ? 'bi-x-lg' : 'bi-hourglass-split')"></i></span>
            <div>
                <strong>{{ order.changeRequest.typeLabel }}</strong>
                <p>{{ changeStatusText }}</p>
                <small v-if="order.changeRequest.requestNote">طلبك: {{ order.changeRequest.requestNote }}</small>
                <small v-if="order.changeRequest.resolutionNote">رد المطعم: {{ order.changeRequest.resolutionNote }}</small>
            </div>
        </div>

        <footer v-if="canManage && (canStillCancel || order.canRequestChange)" class="order-actions">
            <button v-if="order.canRequestChange && ! order.hasPendingChange" type="button" class="order-button order-button--change" @click="emit('change', order)">
                <i class="bi bi-pencil-square"></i><span><strong>اطلب تغييراً</strong><small>فريق الصالة يراجع إمكانية التنفيذ</small></span>
            </button>
            <button v-else-if="order.hasPendingChange" type="button" class="order-button order-button--pending" disabled>
                <i class="bi bi-hourglass-split"></i><span><strong>التعديل قيد المراجعة</strong><small>ستظهر النتيجة هنا</small></span>
            </button>
            <button v-if="canStillCancel" type="button" class="order-button order-button--cancel" @click="emit('cancel', order)">
                <i class="bi bi-x-circle"></i><span><strong>إلغاء فوري</strong><small>متاح لـ {{ cancelSeconds }} ثانية</small></span>
            </button>
        </footer>
    </article>
</template>

<style scoped>
.order-card {
    --card-primary: var(--brand);
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--surface);
    padding: clamp(1rem, 2.5vw, 1.3rem);
    display: grid;
    gap: 1rem;
    box-shadow: none;
}
.order-card--ready { border-color: var(--line); box-shadow: none; }
.order-card--cancelled, .order-card--done { background: var(--surface); }
.order-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.order-heading { min-width: 0; }
.order-status { width: fit-content; min-height: 27px; border-radius: 12px; padding: 0 .62rem; display: inline-flex; align-items: center; gap: .32rem; background: var(--canvas); color: var(--muted); font-size: .875rem; font-weight: 800; }
.order-status--progress { background: var(--canvas); color: #a14e0b; }
.order-status--ready { background: var(--canvas); color: var(--brand); }
.order-status--cancelled { background: var(--canvas); color: #b52727; }
.order-status--done { background: var(--canvas); color: var(--brand); }
.round-badge { display: inline-flex; min-height: 27px; margin-inline-start: .35rem; padding: 0 .6rem; align-items: center; border-radius: 12px; background: var(--canvas); color: var(--card-primary); font-size: .875rem; font-weight: 800; }
.order-heading h3 { margin: .55rem 0 .18rem; font-size: 1.05rem; font-weight: 800; color: var(--ink); }
.order-heading p { margin: 0; display: flex; align-items: center; flex-wrap: wrap; gap: .38rem; color: var(--muted); font-size: .875rem; }
.order-heading p i { width: 3px; height: 3px; border-radius: 50%; background: var(--muted); }
.order-total-top { flex: 0 0 auto; text-align: end; padding-top: .1rem; }
.order-total-top small, .order-total-top strong { display: block; }
.order-total-top small { color: var(--muted); font-size: .875rem; }
.order-total-top strong { margin-top: .1rem; color: var(--card-primary); font-size: 1.08rem; font-weight: 800; }
.progress-track { position: relative; display: grid; grid-template-columns: repeat(3, 1fr); }
.progress-track::before { content: ''; position: absolute; top: 19px; inset-inline: 16.5%; height: 2px; background: var(--surface-muted); }
.progress-step { position: relative; z-index: 1; display: grid; justify-items: center; gap: .35rem; color: var(--muted); }
.progress-icon { width: 40px; height: 40px; border: 2px solid var(--line); border-radius: 12px; display: grid; place-items: center; background: var(--surface); font-size: .9rem; }
.progress-step small { font-size: .875rem; font-weight: 800; }
.progress-step.is-done, .progress-step.is-current { color: var(--card-primary); }
.progress-step.is-done .progress-icon, .progress-step.is-current .progress-icon { border-color: var(--card-primary); background: var(--card-primary); color: #FFFDFA; }
.progress-step.is-current .progress-icon { box-shadow: 0 0 0 5px color-mix(in srgb, var(--brand) 9%, transparent); }
.eta-box { min-height: 72px; position: relative; overflow: hidden; border: 1px solid var(--accent); border-radius: 12px; padding: .7rem .8rem; display: flex; align-items: center; gap: .7rem; background: var(--canvas); color: #704412; }
.eta-box--ready { border-color: var(--line); background: var(--canvas); color: var(--brand); }
.eta-icon { width: 43px; height: 43px; flex: 0 0 43px; border-radius: 12px; display: grid; place-items: center; background: rgba(255, 255, 255, .85); font-size: 1.15rem; }
.eta-box > div { flex: 1; }
.eta-box small, .eta-box strong { display: block; }
.eta-box small { font-size: .875rem; opacity: .72; }
.eta-box strong { margin-top: .08rem; font-size: .875rem; font-weight: 800; }
.eta-box strong b { font-size: 1.3rem; }
.eta-live { display: inline-flex; align-items: center; gap: .3rem; font-size: .875rem; font-weight: 800; opacity: .72; }
.eta-live i { width: 6px; height: 6px; border-radius: 50%; background: #ed8c27; }
.eta-decoration { position: absolute; inset-inline-end: 1rem; font-size: 1.3rem; opacity: .25; }
.items-section { border: 1px solid var(--line); border-radius: 12px; overflow: hidden; }
.items-title { min-height: 43px; padding: 0 .8rem; display: flex; align-items: center; justify-content: space-between; background: var(--canvas); border-bottom: 1px solid var(--line); }
.items-title span { font-size: .875rem; font-weight: 800; color: var(--brand); }
.items-title small { color: var(--muted); font-size: .875rem; }
.items-list { padding: 0 .8rem; }
.item-row { display: flex; align-items: flex-start; gap: .65rem; padding: .72rem 0; border-bottom: 1px dashed var(--line); }
.item-row:last-child { border-bottom: 0; }
.item-row.is-cancelled { opacity: .6; }
.item-quantity { width: 31px; height: 31px; flex: 0 0 31px; border-radius: 10px; display: grid; place-items: center; background: color-mix(in srgb, var(--brand) 7.000000000000001%, transparent); color: var(--card-primary); font-size: .875rem; font-weight: 800; }
.item-copy { min-width: 0; flex: 1; display: grid; gap: .12rem; }
.item-copy strong { font-size: .875rem; font-weight: 800; color: var(--brand); }
.item-copy small { color: var(--muted); font-size: .875rem; line-height: 1.45; }
.item-copy .item-note { display: inline-flex; align-items: center; gap: .28rem; color: #9b661c; }
.item-copy .item-exclusions { width: fit-content; display: inline-flex; align-items: center; gap: .28rem; padding: .13rem .4rem; border-radius: 12px; background: #fee2e2; color: #991b1b; font-weight: 800; }
.item-copy .item-cancel-note { color: #aa3434; }
.item-side { flex: 0 0 auto; display: grid; justify-items: end; gap: .28rem; }
.item-side > strong { font-size: .875rem; font-weight: 800; color: var(--brand); }
.item-badge { border-radius: 12px; padding: .15rem .48rem; background: var(--canvas); color: var(--muted); font-size: .875rem; font-weight: 800; }
.item-badge--preparing { background: var(--canvas); color: #a94d06; }
.item-badge--ready { background: var(--canvas); color: var(--brand); }
.item-badge--served { background: var(--canvas); color: var(--card-primary); }
.item-badge--cancelled { background: var(--canvas); color: #b52d2d; }
.change-state { padding: .72rem .8rem; border: 1px solid var(--accent); border-radius: 12px; display: flex; align-items: flex-start; gap: .65rem; background: var(--canvas); color: #694d12; }
.change-state--approved { border-color: var(--line); background: var(--canvas); color: var(--brand); }
.change-state--rejected { border-color: #ecc1c1; background: var(--canvas); color: #9e2929; }
.change-icon { width: 34px; height: 34px; flex: 0 0 34px; border-radius: 11px; display: grid; place-items: center; background: rgba(255, 255, 255, .7); }
.change-state div { display: grid; gap: .1rem; }
.change-state strong { font-size: .875rem; font-weight: 800; }
.change-state p, .change-state small { margin: 0; font-size: .875rem; line-height: 1.5; }
.change-state small { opacity: .8; }
.order-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .55rem; }
.order-button { min-height: 56px; border-radius: 8px; padding: .5rem .7rem; display: flex; align-items: center; justify-content: flex-start; gap: .55rem; text-align: start; font: inherit; cursor: pointer; }
.order-button > i { width: 32px; height: 32px; flex: 0 0 32px; border-radius: 8px; display: grid; place-items: center; background: rgba(255, 255, 255, .65); }
.order-button span, .order-button strong, .order-button small { display: block; }
.order-button strong { font-size: .875rem; font-weight: 800; }
.order-button small { margin-top: .06rem; font-size: .875rem; opacity: .72; }
.order-button--change { border: 1px solid color-mix(in srgb, var(--brand) 24%, transparent); background: color-mix(in srgb, var(--brand) 6%, transparent); color: var(--card-primary); }
.order-button--cancel { border: 1px solid var(--line); background: var(--canvas); color: #aa2d2d; }
.order-button--pending { grid-column: 1 / -1; border: 1px solid var(--accent); background: var(--canvas); color: #7b5a18; cursor: default; }
.final-state { min-height: 68px; border-radius: 12px; padding: .7rem .8rem; display: flex; align-items: center; gap: .65rem; }
.final-state > span { width: 40px; height: 40px; flex: 0 0 40px; border-radius: 12px; display: grid; place-items: center; background: rgba(255, 255, 255, .7); }
.final-state strong, .final-state small { display: block; }
.final-state strong { font-size: .875rem; font-weight: 800; }
.final-state small { margin-top: .08rem; font-size: .875rem; opacity: .75; }
.final-state--cancelled { background: var(--canvas); color: #a32929; }
.final-state--done { background: var(--canvas); color: var(--brand); }

@media (max-width: 520px) {
    .order-card { border-radius: 12px; padding: .9rem; }
    .order-header { gap: .5rem; }
    .order-heading h3 { font-size: .94rem; }
    .order-total-top strong { font-size: .94rem; }
    .progress-step small { font-size: .875rem; }
    .order-actions { grid-template-columns: 1fr; }
    .order-button--pending { grid-column: auto; }
}

/* Task-specific component layout. */
.order-card{border:1px solid var(--line);border-radius:8px;background:var(--surface);box-shadow:none;overflow:hidden}.order-header{padding:18px 20px;border-bottom:1px solid var(--line);background:var(--surface);gap:12px}.order-heading h3{font-size:20px}.order-status,.round-badge{border-radius:4px;font-size:14px}.order-total-top{font-size:19px;color:var(--ink)}.progress-track{padding:18px 20px;margin:0;border-bottom:1px solid var(--line);background:var(--canvas)}.progress-icon{width:32px;height:32px;border-radius:6px;font-size:15px}.progress-step span{font-size:14px}.eta-box{margin:16px 20px;padding:14px;border-radius:6px;background:var(--canvas);border:1px solid var(--line)}.eta-decoration{display:none}.items-section{padding:0 20px}.items-title{padding:14px 0;font-size:15px}.item-row{display:grid;grid-template-columns:30px minmax(0,1fr) auto;gap:10px;padding:14px 0;border-bottom:1px solid var(--line)}.item-quantity{font-size:17px;background:transparent;color:var(--muted)}.item-copy strong{font-size:16px}.item-badge{font-size:14px;border-radius:4px}.item-exclusions,.item-note,.item-cancel-note{font-size:14px;line-height:1.6}.order-actions{padding:16px 20px;border-top:1px solid var(--line);background:var(--surface);gap:10px}.order-button{min-height:44px;border-radius:6px;font-size:14px}.change-state{margin:14px 20px;border-radius:6px;padding:14px;line-height:1.65}@media(max-width:500px){.item-row{grid-template-columns:26px minmax(0,1fr)}.item-side{grid-column:2;display:flex;justify-content:space-between;gap:10px}.progress-track{padding:16px 10px}.progress-step span{font-size:14px}}
</style>
