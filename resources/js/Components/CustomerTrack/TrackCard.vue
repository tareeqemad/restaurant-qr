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
    --card-primary: rgb(var(--primary-rgb, 15, 71, 49));
    border: 1px solid #e2e9e4;
    border-radius: 24px;
    background: #fff;
    padding: clamp(1rem, 2.5vw, 1.3rem);
    display: grid;
    gap: 1rem;
    box-shadow: 0 18px 55px -45px rgba(16, 52, 32, .65);
}
.order-card--ready { border-color: #b8dfc8; box-shadow: 0 18px 55px -42px rgba(15, 121, 73, .65); }
.order-card--cancelled, .order-card--done { background: #fbfcfb; }
.order-header { display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; }
.order-heading { min-width: 0; }
.order-status { width: fit-content; min-height: 27px; border-radius: 999px; padding: 0 .62rem; display: inline-flex; align-items: center; gap: .32rem; background: #f2f5f3; color: #596860; font-size: .67rem; font-weight: 850; }
.order-status--progress { background: #fff5e8; color: #a14e0b; }
.order-status--ready { background: #eaf8ef; color: #087449; }
.order-status--cancelled { background: #fff0f0; color: #b52727; }
.order-status--done { background: #edf4f0; color: #436052; }
.round-badge { display: inline-flex; min-height: 27px; margin-inline-start: .35rem; padding: 0 .6rem; align-items: center; border-radius: 999px; background: #eef6f1; color: var(--card-primary); font-size: .65rem; font-weight: 900; }
.order-heading h3 { margin: .55rem 0 .18rem; font-size: 1.05rem; font-weight: 950; color: #17251d; }
.order-heading p { margin: 0; display: flex; align-items: center; flex-wrap: wrap; gap: .38rem; color: #86928b; font-size: .68rem; }
.order-heading p i { width: 3px; height: 3px; border-radius: 50%; background: #bbc4bf; }
.order-total-top { flex: 0 0 auto; text-align: end; padding-top: .1rem; }
.order-total-top small, .order-total-top strong { display: block; }
.order-total-top small { color: #8a968f; font-size: .65rem; }
.order-total-top strong { margin-top: .1rem; color: var(--card-primary); font-size: 1.08rem; font-weight: 950; }
.progress-track { position: relative; display: grid; grid-template-columns: repeat(3, 1fr); }
.progress-track::before { content: ''; position: absolute; top: 19px; inset-inline: 16.5%; height: 2px; background: #e5eae7; }
.progress-step { position: relative; z-index: 1; display: grid; justify-items: center; gap: .35rem; color: #96a19b; }
.progress-icon { width: 40px; height: 40px; border: 2px solid #e2e8e4; border-radius: 14px; display: grid; place-items: center; background: #fff; font-size: .9rem; }
.progress-step small { font-size: .66rem; font-weight: 800; }
.progress-step.is-done, .progress-step.is-current { color: var(--card-primary); }
.progress-step.is-done .progress-icon, .progress-step.is-current .progress-icon { border-color: var(--card-primary); background: var(--card-primary); color: #fff; }
.progress-step.is-current .progress-icon { box-shadow: 0 0 0 5px rgba(var(--primary-rgb, 15, 71, 49), .09); }
.eta-box { min-height: 72px; position: relative; overflow: hidden; border: 1px solid #f1cf9c; border-radius: 18px; padding: .7rem .8rem; display: flex; align-items: center; gap: .7rem; background: linear-gradient(135deg, #fffaf1, #fff4df); color: #704412; }
.eta-box--ready { border-color: #a9dbc0; background: linear-gradient(135deg, #f4fff8, #e7f8ee); color: #12613f; }
.eta-icon { width: 43px; height: 43px; flex: 0 0 43px; border-radius: 14px; display: grid; place-items: center; background: rgba(255, 255, 255, .85); font-size: 1.15rem; }
.eta-box > div { flex: 1; }
.eta-box small, .eta-box strong { display: block; }
.eta-box small { font-size: .65rem; opacity: .72; }
.eta-box strong { margin-top: .08rem; font-size: .82rem; font-weight: 900; }
.eta-box strong b { font-size: 1.3rem; }
.eta-live { display: inline-flex; align-items: center; gap: .3rem; font-size: .62rem; font-weight: 800; opacity: .72; }
.eta-live i { width: 6px; height: 6px; border-radius: 50%; background: #ed8c27; }
.eta-decoration { position: absolute; inset-inline-end: 1rem; font-size: 1.3rem; opacity: .25; }
.items-section { border: 1px solid #edf1ee; border-radius: 18px; overflow: hidden; }
.items-title { min-height: 43px; padding: 0 .8rem; display: flex; align-items: center; justify-content: space-between; background: #f8faf8; border-bottom: 1px solid #edf1ee; }
.items-title span { font-size: .72rem; font-weight: 900; color: #4d5e54; }
.items-title small { color: #8b9891; font-size: .64rem; }
.items-list { padding: 0 .8rem; }
.item-row { display: flex; align-items: flex-start; gap: .65rem; padding: .72rem 0; border-bottom: 1px dashed #e6ebe8; }
.item-row:last-child { border-bottom: 0; }
.item-row.is-cancelled { opacity: .6; }
.item-quantity { width: 31px; height: 31px; flex: 0 0 31px; border-radius: 10px; display: grid; place-items: center; background: rgba(var(--primary-rgb, 15, 71, 49), .07); color: var(--card-primary); font-size: .73rem; font-weight: 900; }
.item-copy { min-width: 0; flex: 1; display: grid; gap: .12rem; }
.item-copy strong { font-size: .78rem; font-weight: 900; color: #26372d; }
.item-copy small { color: #7b8981; font-size: .66rem; line-height: 1.45; }
.item-copy .item-note { display: inline-flex; align-items: center; gap: .28rem; color: #9b661c; }
.item-copy .item-exclusions { width: fit-content; display: inline-flex; align-items: center; gap: .28rem; padding: .13rem .4rem; border-radius: 999px; background: #fee2e2; color: #991b1b; font-weight: 850; }
.item-copy .item-cancel-note { color: #aa3434; }
.item-side { flex: 0 0 auto; display: grid; justify-items: end; gap: .28rem; }
.item-side > strong { font-size: .75rem; font-weight: 900; color: #26372d; }
.item-badge { border-radius: 999px; padding: .15rem .48rem; background: #f1f4f2; color: #69766f; font-size: .58rem; font-weight: 850; }
.item-badge--preparing { background: #fff2df; color: #a94d06; }
.item-badge--ready { background: #e9f8ef; color: #087249; }
.item-badge--served { background: #eaf3ee; color: var(--card-primary); }
.item-badge--cancelled { background: #fff0f0; color: #b52d2d; }
.change-state { padding: .72rem .8rem; border: 1px solid #ecd38f; border-radius: 16px; display: flex; align-items: flex-start; gap: .65rem; background: #fffaea; color: #694d12; }
.change-state--approved { border-color: #b4dfc5; background: #f0fbf4; color: #17603e; }
.change-state--rejected { border-color: #ecc1c1; background: #fff3f3; color: #9e2929; }
.change-icon { width: 34px; height: 34px; flex: 0 0 34px; border-radius: 11px; display: grid; place-items: center; background: rgba(255, 255, 255, .7); }
.change-state div { display: grid; gap: .1rem; }
.change-state strong { font-size: .74rem; font-weight: 900; }
.change-state p, .change-state small { margin: 0; font-size: .65rem; line-height: 1.5; }
.change-state small { opacity: .8; }
.order-actions { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .55rem; }
.order-button { min-height: 56px; border-radius: 15px; padding: .5rem .7rem; display: flex; align-items: center; justify-content: flex-start; gap: .55rem; text-align: start; font: inherit; cursor: pointer; }
.order-button > i { width: 32px; height: 32px; flex: 0 0 32px; border-radius: 10px; display: grid; place-items: center; background: rgba(255, 255, 255, .65); }
.order-button span, .order-button strong, .order-button small { display: block; }
.order-button strong { font-size: .72rem; font-weight: 900; }
.order-button small { margin-top: .06rem; font-size: .59rem; opacity: .72; }
.order-button--change { border: 1px solid rgba(var(--primary-rgb, 15, 71, 49), .24); background: rgba(var(--primary-rgb, 15, 71, 49), .06); color: var(--card-primary); }
.order-button--cancel { border: 1px solid #ecc4c4; background: #fff3f3; color: #aa2d2d; }
.order-button--pending { grid-column: 1 / -1; border: 1px solid #ead59e; background: #fff9e9; color: #7b5a18; cursor: default; }
.final-state { min-height: 68px; border-radius: 17px; padding: .7rem .8rem; display: flex; align-items: center; gap: .65rem; }
.final-state > span { width: 40px; height: 40px; flex: 0 0 40px; border-radius: 13px; display: grid; place-items: center; background: rgba(255, 255, 255, .7); }
.final-state strong, .final-state small { display: block; }
.final-state strong { font-size: .78rem; font-weight: 900; }
.final-state small { margin-top: .08rem; font-size: .65rem; opacity: .75; }
.final-state--cancelled { background: #fff0f0; color: #a32929; }
.final-state--done { background: #edf8f1; color: #17603e; }

@media (max-width: 520px) {
    .order-card { border-radius: 20px; padding: .9rem; }
    .order-header { gap: .5rem; }
    .order-heading h3 { font-size: .94rem; }
    .order-total-top strong { font-size: .94rem; }
    .progress-step small { font-size: .6rem; }
    .order-actions { grid-template-columns: 1fr; }
    .order-button--pending { grid-column: auto; }
}
</style>
