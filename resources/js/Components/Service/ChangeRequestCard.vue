<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    request: { type: Object, required: true },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['act']);
const choosingDisposition = ref(false);

const requestedResult = computed(() => {
    if (props.request.typeLabel === 'إلغاء صنف') return 'حذف الصنف من الطلب والفاتورة';
    if (props.request.typeLabel === 'إلغاء الطلب بالكامل') return 'إلغاء هذه الجولة كاملة';
    if (props.request.requestedQuantity) return `الكمية ${props.request.requestedQuantity}`;
    return 'تطبيق الملاحظة وإعادة تجهيز الصنف';
});

const rejectLabel = computed(() => props.request.typeLabel === 'إلغاء الطلب بالكامل'
    ? 'أبقِ الجولة كما هي'
    : 'استمر بالصنف كما هو');

watch(() => props.request.id, () => { choosingDisposition.value = false; });

const resolve = (decision, disposition = 'return') => emit('act', {
    verb: 'resolve-change',
    request_id: props.request.id,
    decision,
    disposition,
    expected_started: props.request.started,
});

const accept = () => {
    if (! props.request.started) {
        resolve('approve');
        return;
    }
    choosingDisposition.value = true;
};
</script>

<template>
    <article class="cr-card" :class="{ 'is-started': request.started, 'is-ready': request.ready }">
        <header class="cr-head">
            <span class="cr-priority"><i class="bi bi-lightning-charge-fill"></i> قرار الآن</span>
            <div class="cr-place">
                <strong>{{ request.title }}</strong>
                <small><template v-if="request.roundNumber">جولة {{ request.roundNumber }} · {{ request.roundLabel }} · </template>{{ request.stationName }} · {{ request.statusLabel }}</small>
            </div>
            <span class="cr-time">{{ request.askedAgo }}</span>
        </header>

        <div class="cr-main">
            <div class="cr-request">
                <span class="cr-icon"><i class="bi bi-pencil-square"></i></span>
                <div>
                    <small>طلب الزبون · #{{ request.orderNumber }}</small>
                    <h4>{{ request.typeLabel }}</h4>
                    <p v-if="request.itemName">{{ request.itemName }} <b>×{{ request.itemQty }}</b></p>
                </div>
            </div>

            <i class="bi bi-arrow-left cr-arrow"></i>

            <div class="cr-result">
                <small>المطلوب بعد القرار</small>
                <strong>{{ requestedResult }}</strong>
                <p v-if="request.note"><i class="bi bi-chat-quote-fill"></i> {{ request.note }}</p>
            </div>
        </div>

        <div class="cr-guidance" :class="request.ready ? 'is-ready' : (request.started ? 'is-started' : 'is-safe')">
            <i class="bi" :class="request.ready ? 'bi-bag-check-fill' : (request.started ? 'bi-fire' : 'bi-check2-circle')"></i>
            <div v-if="request.ready">
                <strong>الصنف صار جاهزاً</strong>
                <span>إن بقي صالحاً للبيع أو كان مغلقاً أعده، وإلا ألغِه كهدر. لا تسلّمه قبل القرار.</span>
            </div>
            <div v-else-if="request.started">
                <strong>بدأ المطبخ أو البار بالتحضير</strong>
                <span>تأكد من المحطة: هل المواد قابلة للرجوع فعلياً أم استُهلكت وتُسجّل هدرًا؟</span>
            </div>
            <div v-else>
                <strong>لم يبدأ التحضير بعد</strong>
                <span>يمكن تنفيذ التغيير مباشرة، وسيصل التحديث للمحطة والحساب تلقائياً.</span>
            </div>
        </div>

        <footer v-if="request.canResolve" class="cr-actions" :class="{ 'is-disposition': choosingDisposition }">
            <template v-if="! choosingDisposition">
                <button type="button" class="cr-btn cr-btn--keep" :disabled="busy" @click="resolve('reject')">
                    <i class="bi bi-x-circle"></i><span><strong>رفض التغيير</strong><small>{{ rejectLabel }}</small></span>
                </button>
                <button type="button" class="cr-btn cr-btn--execute" :disabled="busy" @click="accept">
                    <i class="bi bi-check2-circle"></i><span><strong>قبول وتنفيذ التغيير</strong><small>{{ request.started ? 'بعدها حدّد حالة المواد' : 'سيُحدّث الطلب والمحطة فوراً' }}</small></span>
                </button>
            </template>

            <template v-else>
                <div class="cr-disposition-title">
                    <button type="button" :disabled="busy" aria-label="رجوع" @click="choosingDisposition = false"><i class="bi bi-arrow-right"></i></button>
                    <span><strong>ماذا حدث للمواد؟</strong><small>هذا السؤال للمخزون فقط؛ التغيير مقبول في الحالتين.</small></span>
                </div>
                <button type="button" class="cr-btn cr-btn--return" :disabled="busy" @click="resolve('approve', 'return')">
                    <i class="bi bi-box-arrow-in-down"></i><span><strong>المواد قابلة للرجوع</strong><small>ترجع الكمية للمخزون</small></span>
                </button>
                <button type="button" class="cr-btn cr-btn--waste" :disabled="busy" @click="resolve('approve', 'waste')">
                    <i class="bi bi-trash3"></i><span><strong>استهلكت أو تلفت</strong><small>تُسجّل هدرًا ولا ترجع</small></span>
                </button>
            </template>
        </footer>
    </article>
</template>

<style scoped>
.cr-card { overflow: hidden; border: 1px solid #efd79b; border-radius: 12px; background: var(--surface); box-shadow: none; }
.cr-card.is-started { border-color: #f1b0a7; }
.cr-card.is-ready { border-color: #e59083; box-shadow: none; }
.cr-head { min-height: 48px; display: flex; align-items: center; gap: .7rem; padding: .5rem .8rem; border-bottom: 1px solid var(--line); background: #fbfcfb; }
.cr-priority { min-height: 27px; display: inline-flex; align-items: center; gap: .25rem; padding: 0 .55rem; border-radius: 12px; background: #fff1d8; color: #a55109; font-size: .875rem; font-weight: 800; white-space: nowrap; }
.is-started .cr-priority { background: #fee2e2; color: #b42318; }
.cr-place { min-width: 0; display: grid; gap: .05rem; }
.cr-place strong { color: var(--brand); font-size: .875rem; font-weight: 800; }
.cr-place small { color: var(--muted); font-size: .875rem; }
.cr-time { margin-inline-start: auto; color: var(--muted); font-size: .875rem; white-space: nowrap; }
.cr-main { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: .7rem; padding: .8rem; }
.cr-request { min-width: 0; display: flex; align-items: center; gap: .6rem; }
.cr-icon { width: 40px; height: 40px; flex: 0 0 40px; display: grid; place-items: center; border-radius: 12px; background: #fff3db; color: #a55109; }
.cr-request small, .cr-result small { color: var(--brand); font-size: .875rem; }
.cr-request h4 { margin: .06rem 0; color: var(--brand); font-size: .88rem; font-weight: 800; }
.cr-request p, .cr-result p { margin: 0; color: var(--brand); font-size: .875rem; }
.cr-arrow { color: var(--muted); }
.cr-result { min-width: 0; display: grid; gap: .12rem; }
.cr-result > strong { color: var(--brand); font-size: .875rem; font-weight: 800; }
.cr-result p { display: flex; align-items: flex-start; gap: .3rem; color: #84521a; line-height: 1.45; }
.cr-guidance { display: flex; align-items: flex-start; gap: .55rem; margin: 0 .8rem .7rem; padding: .6rem .7rem; border-radius: 12px; }
.cr-guidance > i { margin-top: 1px; }
.cr-guidance div { display: grid; gap: .08rem; }
.cr-guidance strong { font-size: .875rem; font-weight: 800; }
.cr-guidance span { font-size: .875rem; line-height: 1.5; }
.cr-guidance.is-safe { background: #edf9f2; color: var(--brand); }
.cr-guidance.is-started { background: #fff5e8; color: #8d4a0a; }
.cr-guidance.is-ready { background: #fff0f0; color: #992828; }
.cr-actions { display: grid; grid-template-columns: minmax(0, .85fr) minmax(0, 1.15fr); gap: .45rem; padding: .7rem .8rem .8rem; border-top: 1px solid var(--line); background: #fbfcfb; }
.cr-actions.is-disposition { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.cr-disposition-title { grid-column: 1 / -1; display: flex; align-items: center; gap: .5rem; padding: .25rem .1rem .35rem; }
.cr-disposition-title > button { width: 38px; height: 38px; flex: 0 0 38px; border: 1px solid var(--line); border-radius: 8px; background: var(--surface); color: var(--brand); }
.cr-disposition-title span, .cr-disposition-title strong, .cr-disposition-title small { display: block; }
.cr-disposition-title strong { color: var(--brand); font-size: .875rem; font-weight: 800; }
.cr-disposition-title small { margin-top: .04rem; color: var(--muted); font-size: .875rem; }
.cr-btn { min-height: 53px; display: flex; align-items: center; justify-content: flex-start; gap: .5rem; padding: .45rem .55rem; border: 1px solid transparent; border-radius: 8px; font: inherit; text-align: start; cursor: pointer; }
.cr-btn > i { width: 30px; height: 30px; flex: 0 0 30px; display: grid; place-items: center; border-radius: 8px; background: rgba(255, 255, 255, .7); }
.cr-btn span, .cr-btn strong, .cr-btn small { display: block; }
.cr-btn strong { font-size: .875rem; font-weight: 800; }
.cr-btn small { margin-top: .04rem; font-size: .875rem; opacity: .72; }
.cr-btn:disabled { opacity: .5; cursor: default; }
.cr-btn--keep { border-color: var(--line); background: #f3f6f4; color: var(--brand); }
.cr-btn--execute, .cr-btn--return { background: var(--brand); color: #FFFDFA; }
.cr-btn--waste { border-color: #f5c2c2; background: #fff0f0; color: #ad2929; }

@media (max-width: 680px) {
    .cr-main { grid-template-columns: 1fr; gap: .45rem; }
    .cr-arrow { transform: rotate(-90deg); justify-self: center; }
    .cr-actions { grid-template-columns: 1fr; }
    .cr-actions.is-disposition { grid-template-columns: 1fr; }
    .cr-place small { white-space: normal; }
}

/* Operational layout and responsive hierarchy. */
.cr-card{border-radius:8px;border:1px solid var(--line);border-inline-start:4px solid var(--accent);box-shadow:none}.cr-head{padding:14px 16px;background:var(--canvas);border-bottom:1px solid var(--line)}.cr-main{display:grid;grid-template-columns:1fr 24px 1fr;gap:14px;padding:16px}.cr-request,.cr-result{padding:12px;border-radius:6px;background:var(--canvas)}.cr-actions{padding:16px;border-top:1px solid var(--line);gap:12px}.cr-btn{border-radius:8px;min-height:56px;font-size:15px}.cr-guidance{margin:0 16px 16px;padding:12px;border-radius:6px;line-height:1.7}.cr-guidance strong,.cr-guidance small{font-size:14px}@media(max-width:550px){.cr-main{grid-template-columns:1fr}.cr-arrow{transform:rotate(-90deg);justify-self:center}.cr-actions{flex-wrap:wrap}.cr-btn{flex-basis:100%}}
</style>
