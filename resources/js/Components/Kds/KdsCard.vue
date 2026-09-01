<script setup>
/**
 * One kitchen ticket — the _kitchen-card partial reborn. Dominant table
 * ribbon (or source-colored channel verb for external orders), allergy
 * note band above the items, per-item actions with the undo windows
 * anchored to the DEVICE clock (60s undo-start / 120s undo-ready), and
 * the chef-side cancel popover with its two dispositions.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = defineProps({
    card: { type: Object, required: true },
    column: { type: String, required: true },   // waiting | cooking | ready
    followUp: { type: Boolean, default: false },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits(['act']);

const now = ref(Date.now());
let timer = null;

// Undo deadlines re-anchor on every server refresh (props are truth).
const undoDeadlines = ref(new Map());
watch(() => props.card, (card) => {
    const map = new Map();
    for (const it of card.items) {
        if (it.undoStartRemaining !== null && it.undoStartRemaining > 0) {
            map.set(`s-${it.id}`, Date.now() + it.undoStartRemaining * 1000);
        }
        if (it.undoReadyRemaining !== null && it.undoReadyRemaining > 0) {
            map.set(`r-${it.id}`, Date.now() + it.undoReadyRemaining * 1000);
        }
    }
    undoDeadlines.value = map;
}, { immediate: true });

const canUndoStart = (it) => (undoDeadlines.value.get(`s-${it.id}`) ?? 0) > now.value;
const canUndoReady = (it) => (undoDeadlines.value.get(`r-${it.id}`) ?? 0) > now.value;

const stage = computed(() => ({
    waiting: { icon: 'bi-inbox-fill', label: 'طلب جديد' },
    cooking: { icon: 'bi-fire', label: 'قيد التحضير' },
    ready: { icon: 'bi-bell-fill', label: 'جاهز للتسليم' },
}[props.column]));

const menuOpenFor = ref(null);
const toggleMenu = (id) => { menuOpenFor.value = menuOpenFor.value === id ? null : id; };
const closeMenu = () => { menuOpenFor.value = null; };
const handleEscape = (event) => { if (event.key === 'Escape') closeMenu(); };

onMounted(() => {
    timer = window.setInterval(() => { now.value = Date.now(); }, 1000);
    document.addEventListener('click', closeMenu);
    document.addEventListener('keydown', handleEscape);
});

onBeforeUnmount(() => {
    window.clearInterval(timer);
    document.removeEventListener('click', closeMenu);
    document.removeEventListener('keydown', handleEscape);
});

const act = (verb, payload = {}) => {
    menuOpenFor.value = null;
    emit('act', { verb, ...payload });
};

const ribbon = computed(() => {
    if (! props.card.external) {
        return { label: 'طاولة', big: props.card.tableNum || '—' };
    }

    return { label: props.card.external.sourceLabel, big: props.card.external.typeLabel };
});
</script>

<template>
    <article class="kb-card" :class="[
                 `kb-urg-${card.urgency}`,
                 `kb-card--${column}`,
                 card.external ? 'kb-card--external' : 'kb-card--dinein',
                 { 'kb-card--flash': card.flash, 'kb-card--change': card.changeRequest, 'kb-card--busy': busy },
             ]"
             :aria-busy="busy"
             :style="card.external ? { '--source-color': card.external.sourceColor } : null">
        <div class="kb-table-ribbon">
            <div class="kb-table-ribbon-inner">
                <span v-if="card.external" class="kb-source-icon"><i class="bi" :class="card.external.sourceIcon"></i></span>
                <span class="kb-table-label">{{ ribbon.label }}</span>
                <span class="kb-table-big">{{ ribbon.big }}</span>
                <span v-if="card.roundNumber" class="kb-round-chip" :class="{ 'is-addition': card.isAddition }">
                    جولة {{ card.roundNumber }} · {{ card.roundLabel }}
                </span>
                <span class="kb-state-chip" :class="`is-${column}`">
                    <i class="bi" :class="stage.icon"></i>
                    {{ stage.label }}
                </span>
                <span v-if="followUp" class="kb-followup-chip" title="لهذه الطاولة أكثر من جولة نشطة على هذه الشاشة">
                    <i class="bi bi-layers-fill"></i> {{ card.pieceCount }} قطعة
                </span>
            </div>
            <div class="kb-table-ribbon-side">
                <span class="kb-age-chip" :title="`منذ ${card.ageMin} دقيقة`">
                    <i class="bi bi-clock-fill"></i>
                    <strong>{{ card.ageMin < 1 ? '<1' : card.ageMin }}</strong>
                    <span class="kb-age-unit">د</span>
                </span>
                <span v-if="card.etaMin !== null && column === 'cooking'"
                      class="kb-eta-chip" :class="{ 'is-overdue': card.etaMin < 0 }"
                      :title="`موعد الجاهزية المتوقع ${card.etaLabel}`">
                    <i class="bi bi-flag-fill"></i>
                    <strong>{{ card.etaMin < 0 ? `متأخر ${Math.max(1, Math.abs(card.etaMin))} د` : `باقي ${card.etaMin < 1 ? '<1' : card.etaMin} د` }}</strong>
                </span>
                <div class="kb-order-mini">#{{ card.number }}</div>
            </div>
        </div>

        <div v-if="card.external" class="kb-ext-info">
            <span v-if="card.external.customerName" class="kb-ext-chip kb-ext-chip--name" title="اسم الزبون">
                <i class="bi bi-person-fill"></i>
                <strong>{{ card.external.customerName }}</strong>
            </span>
            <span v-if="card.external.customerPhone" class="kb-ext-chip" title="رقم الهاتف">
                <i class="bi bi-telephone-fill"></i>
                <span>{{ card.external.customerPhone }}</span>
            </span>
            <span v-if="card.external.scheduledAt" class="kb-ext-chip kb-ext-chip--sched" title="وقت محدد لاستلام/تسليم الطلب">
                <i class="bi bi-alarm-fill"></i>
                <strong>للساعة {{ card.external.scheduledAt }}</strong>
            </span>
            <div v-if="card.external.address" class="kb-ext-address" :title="card.external.address">
                <i class="bi bi-geo-alt-fill"></i>
                <span>{{ card.external.address }}</span>
            </div>
        </div>

        <!-- Allergy warnings live here — loud amber band ABOVE the items. -->
        <div v-if="card.notes" class="kb-card-note">
            <i class="bi bi-exclamation-triangle-fill"></i>
            {{ card.notes }}
        </div>

        <div v-if="card.changeRequest" class="kb-change-alert" role="alert">
            <i class="bi bi-pause-circle-fill"></i>
            <div>
                <strong>{{ card.changeRequest.wholeOrder ? 'أوقف هذه الجولة' : `أوقف ${card.changeRequest.itemName || 'الصنف المحدد'} فقط` }} — الزبون طلب {{ card.changeRequest.typeLabel }}</strong>
                <small v-if="card.changeRequest.note">{{ card.changeRequest.note }}</small>
                <small v-else>الجرسون يراجع التغيير الآن؛ باقي الأصناف تستمر كالمعتاد.</small>
            </div>
        </div>

        <div class="kb-items">
            <div v-for="it in card.items" :key="it.id"
                 class="kb-item" :class="[{
                     'is-approved': it.status === 'approved',
                     'is-preparing': it.status === 'preparing',
                     'is-ready': it.status === 'ready',
                 }, it.delay ? `kb-delay-${it.delay}` : '']">

                <span class="kb-item-qty">×{{ it.qty }}</span>
                <div class="kb-item-body">
                    <div class="kb-item-name">
                        {{ it.name }}
                        <span v-if="it.prepMin > 0 && it.status !== 'preparing'" class="kb-prep-tag" title="وقت التحضير المتوقع">
                            <i class="bi bi-clock"></i> {{ it.prepMin }}د
                        </span>
                        <span v-if="it.elapsedMin !== null" class="kb-elapsed-tag" title="منقضي منذ بدء التحضير">
                            <i class="bi bi-hourglass-split"></i>
                            منقضي {{ it.elapsedMin < 1 ? '<1' : it.elapsedMin }}د
                        </span>
                        <span v-if="it.orphan" class="kb-orphan-tag" title="صنف بلا محطة محددة — يظهر هنا حتى لا يضيع الطلب">
                            <i class="bi bi-question-circle"></i> بدون محطة
                        </span>
                        <span v-if="it.changePending" class="kb-change-tag">
                            <i class="bi bi-pause-fill"></i> تعديل بانتظار الجرسون
                        </span>
                    </div>
                    <div v-if="it.mods.length" class="kb-item-mods">
                        <span v-for="(m, i) in it.mods" :key="i"
                              :class="m.kind === 'remove' ? 'kb-mod--remove' : (m.kind === 'extra' ? 'kb-mod--extra' : '')">
                            {{ m.name }}
                        </span>
                    </div>
                    <div v-if="it.exclusions?.length" class="kb-item-exclusions" role="note" aria-label="مكوّنات ممنوعة">
                        <strong><i class="bi bi-slash-circle-fill"></i> انتبه:</strong>
                        <span v-for="ingredient in it.exclusions" :key="ingredient">بدون {{ ingredient }}</span>
                    </div>
                    <div v-if="it.notes" class="kb-item-note">
                        <i class="bi bi-chat-left-text-fill"></i>
                        {{ it.notes }}
                    </div>
                </div>

                <div class="kb-item-actions">
                    <button v-if="it.status === 'approved'" type="button" class="kb-item-action is-start"
                            :disabled="busy || it.changePending" @click="act('start', { item_id: it.id })">
                        <i class="bi" :class="busy ? 'bi-arrow-repeat kb-spin' : 'bi-play-fill'"></i><span>{{ busy ? 'جارٍ…' : 'ابدأ' }}</span>
                    </button>
                    <template v-else-if="it.status === 'preparing'">
                        <button type="button" class="kb-item-action is-ready"
                                :disabled="busy || it.changePending" @click="act('ready', { item_id: it.id })">
                            <i class="bi" :class="busy ? 'bi-arrow-repeat kb-spin' : 'bi-check2'"></i><span>{{ busy ? 'جارٍ…' : 'جاهز' }}</span>
                        </button>
                    </template>
                    <template v-else-if="it.status === 'ready'">
                        <button v-if="canUndoReady(it)" type="button" class="kb-item-action is-undo-ready"
                                title="إعادة الصنف للتحضير" :disabled="busy || it.changePending"
                                @click="act('undo-ready', { item_id: it.id })">
                            <i class="bi bi-arrow-counterclockwise"></i><span>تراجع</span>
                        </button>
                        <span v-else class="kb-item-done" title="جاهز للاستلام"><i class="bi bi-check2-circle"></i></span>
                    </template>

                    <div v-if="['approved', 'preparing'].includes(it.status) && ! it.changePending" class="kb-more" @click.stop>
                        <button type="button" class="kb-more-btn" aria-label="خيارات الصنف"
                                :disabled="busy" :aria-expanded="menuOpenFor === it.id" @click="toggleMenu(it.id)">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <div v-show="menuOpenFor === it.id" class="kb-item-menu">
                            <button v-if="it.status === 'preparing' && canUndoStart(it)" type="button" class="is-undo"
                                    :disabled="busy" @click="act('undo-start', { item_id: it.id })">
                                <i class="bi bi-arrow-counterclockwise"></i><span><strong>تراجع عن البدء</strong><small>إعادته لقائمة الانتظار</small></span>
                            </button>
                            <div class="kb-menu-title">إلغاء الصنف</div>
                            <button type="button" :disabled="busy" @click="act('cancel-item', { item_id: it.id, disposition: 'return' })">
                                <i class="bi bi-box-arrow-in-down"></i><span><strong>لم يبدأ فعليًا</strong><small>إرجاع المكوّنات للمخزون</small></span>
                            </button>
                            <button type="button" class="is-waste" :disabled="busy" @click="act('cancel-item', { item_id: it.id, disposition: 'waste' })">
                                <i class="bi bi-trash3"></i><span><strong>بدأ فعليًا</strong><small>تسجيل المكوّنات كهدر</small></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <footer class="kb-card-foot">
            <button v-if="column === 'waiting'" type="button" class="kb-card-btn kb-card-btn-primary"
                    :disabled="busy || card.items.every((it) => it.changePending)" @click="act('start-all', { order_id: card.orderId })">
                <i class="bi" :class="busy ? 'bi-arrow-repeat kb-spin' : 'bi-play-fill'"></i> {{ busy ? 'جارٍ البدء…' : (card.changeRequest ? 'ابدأ الأصناف غير المعدّلة' : 'ابدأ الكل') }}
            </button>
            <button v-else-if="column === 'cooking'" type="button" class="kb-card-btn kb-card-btn-success"
                    :disabled="busy || card.items.every((it) => it.changePending)" @click="act('ready-all', { order_id: card.orderId })">
                <i class="bi" :class="busy ? 'bi-arrow-repeat kb-spin' : 'bi-bell-fill'"></i> {{ busy ? 'جارٍ الإكمال…' : (card.changeRequest ? 'أكمل الأصناف غير المعدّلة' : 'جهّز الطلب للجرسون') }}
            </button>
            <div v-else-if="column === 'ready'" class="kb-handoff">
                <span class="kb-handoff-icon"><i class="bi bi-person-check-fill"></i></span>
                <div>
                    <strong>سلّم إلى {{ card.handoff.recipientLabel }}</strong>
                    <small><i class="bi bi-bell-fill"></i> يصله التنبيه تلقائياً — وهو يؤكد التقديم من شاشة الخدمة</small>
                </div>
            </div>
        </footer>
    </article>
</template>

<style scoped>
.kb-card {
    position: relative;
    min-width: 0;
    border-radius: 11px;
    border-width: 1px;
    box-shadow: 0 2px 8px rgba(18, 50, 32, .08);
    opacity: 1;
}
.kb-card--waiting { border-inline-start: 4px solid #f59e0b; }
.kb-card--busy { border-color: #86b8a0; box-shadow: 0 0 0 3px rgba(31, 107, 80, .12), 0 2px 8px rgba(18, 50, 32, .08); }
.kb-spin { animation: kb-spin .72s linear infinite; }
@keyframes kb-spin { to { transform: rotate(360deg); } }
.kb-card--cooking { border-inline-start: 4px solid #3b82f6; opacity: 1; }
.kb-card--ready { border-inline-start: 4px solid #059669; opacity: 1; }
.kb-card--cooking .kb-table-ribbon { filter: none; }
.kb-card--ready .kb-table-ribbon {
    background: linear-gradient(135deg, #176b4a, #0f5132);
    filter: none;
}
.kb-card--change { border-color: #ef4444; box-shadow: 0 0 0 2px rgba(239, 68, 68, .12), 0 8px 22px rgba(127, 29, 29, .14); }

.kb-table-ribbon { min-height: 54px; padding: .38rem .5rem; gap: .35rem; border-radius: 9px 9px 0 0; }
.kb-table-ribbon-inner { min-width: 0; flex-wrap: wrap; align-items: center; gap: .22rem .35rem; }
.kb-table-label { font-size: .62rem; }
.kb-table-big { font-size: 1.72rem; line-height: .9; }
.kb-round-chip { display: inline-flex; align-items: center; min-height: 24px; padding: 0 .42rem; border-radius: 999px; background: rgba(255,255,255,.16); color: #fff; font-size: .62rem; font-weight: 950; white-space: nowrap; }
.kb-round-chip.is-addition { background: #fff1d8; color: #8e4c0d; }
.kb-table-ribbon-side { flex: 0 0 auto; }
.kb-age-chip { padding: 1px 6px; font-size: .68rem; }
.kb-age-chip strong { font-size: .8rem; }
.kb-order-mini { max-width: 88px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .6rem; }
.kb-state-chip {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 2px 6px; border-radius: 999px;
    background: rgba(255,255,255,.16); color: #fff;
    font-size: .62rem; font-weight: 900; white-space: nowrap;
}
.kb-state-chip.is-waiting { color: #ffedd5; }
.kb-state-chip.is-cooking { color: #dbeafe; }
.kb-state-chip.is-ready { color: #d1fae5; }
.kb-followup-chip { margin: 0; padding: 2px 6px; font-size: .6rem; }

.kb-ext-info { display: flex; flex-wrap: wrap; gap: .25rem; padding: .35rem .5rem; border-bottom: 1px solid #e5e7eb; background: #f8fafc; }
.kb-ext-chip { display: inline-flex; align-items: center; gap: 3px; font-size: .67rem; color: #475569; }
.kb-ext-address { width: 100%; display: flex; gap: 3px; color: #475569; font-size: .67rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.kb-card-note { padding: .38rem .5rem; font-size: .78rem; line-height: 1.3; }
.kb-change-alert {
    display: flex; align-items: flex-start; gap: .45rem;
    padding: .48rem .55rem; border-block: 1px solid #fecaca;
    background: #fff1f2; color: #991b1b;
}
.kb-change-alert > i { margin-top: 1px; font-size: 1rem; }
.kb-change-alert div { min-width: 0; display: grid; gap: .08rem; }
.kb-change-alert strong { font-size: .76rem; font-weight: 950; }
.kb-change-alert small { font-size: .66rem; line-height: 1.45; }
.kb-items { padding: .35rem; gap: .3rem; }
.kb-item {
    position: relative;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: .32rem;
    min-height: 48px;
    padding: .3rem;
    border: 1px solid #edf1ee;
    border-radius: 8px;
    background: #f9fbfa;
}
.kb-item.is-preparing { background: #eff6ff; border-color: #bfdbfe; }
.kb-card--ready .kb-item.is-ready {
    border-color: #bbf7d0;
    background: #f0fdf4;
    opacity: 1;
}
.kb-card--ready .kb-item.is-ready .kb-item-name,
.kb-card--ready .kb-item.is-ready .kb-item-mods {
    text-decoration: none;
    opacity: 1;
}
.kb-item-qty { width: auto; min-width: 27px; font-size: .9rem; color: #b45309; }
.kb-item-name { font-size: .82rem; line-height: 1.25; font-weight: 900; }
.kb-prep-tag, .kb-elapsed-tag, .kb-orphan-tag { display: inline-flex; align-items: center; gap: 2px; margin-inline-start: .2rem; font-size: .61rem; font-weight: 750; color: #64748b; }
.kb-elapsed-tag { color: #1d4ed8; }
.kb-orphan-tag { color: #b91c1c; }
.kb-change-tag { display: inline-flex; align-items: center; gap: 2px; margin-inline-start: .2rem; padding: 2px 5px; border-radius: 999px; background: #fee2e2; color: #b91c1c; font-size: .61rem; font-weight: 900; }
.kb-item:has(.kb-change-tag) { border-color: #fca5a5; background: #fff7f7; }
.kb-item-mods { gap: 3px; }
.kb-item-mods span { padding: 1px 6px; font-size: .68rem; line-height: 1.35; }
.kb-item-exclusions { display: flex; flex-wrap: wrap; align-items: center; gap: 3px; margin-top: 2px; padding: 4px 5px; border: 1px solid #fca5a5; border-radius: 7px; background: #fee2e2; color: #991b1b; }
.kb-item-exclusions strong { display: inline-flex; align-items: center; gap: 3px; font-size: .7rem; font-weight: 950; }
.kb-item-exclusions span { padding: 1px 5px; border-radius: 999px; background: #fff; color: #b91c1c; font-size: .7rem; font-weight: 950; }
.kb-item-note { padding: 1px 5px; font-size: .67rem; }

.kb-item-actions { display: flex; align-items: center; gap: .22rem; }
.kb-item-action {
    min-width: 54px; min-height: 40px;
    display: inline-flex; align-items: center; justify-content: center; gap: 3px;
    border: 0; border-radius: 8px;
    font: inherit; font-size: .72rem; font-weight: 900; cursor: pointer;
}
.kb-item-action.is-start { background: #fff7ed; color: #c2410c; border: 1px solid #fed7aa; }
.kb-item-action.is-ready { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.kb-item-action.is-undo-ready { min-width: 48px; background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.kb-item-done {
    width: 38px;
    height: 38px;
    display: inline-grid;
    place-items: center;
    border-radius: 9px;
    background: #dcfce7;
    color: #047857;
    font-size: 1.1rem;
}
.kb-item-action:disabled, .kb-more-btn:disabled { opacity: .55; cursor: default; }
.kb-more { position: relative; }
.kb-more-btn {
    width: 34px; height: 40px;
    display: inline-grid; place-items: center;
    border: 1px solid #dbe4de; border-radius: 8px;
    background: #fff; color: #52645a; cursor: pointer;
}
.kb-item-menu {
    position: absolute;
    inset-inline-end: 0;
    top: calc(100% + 5px);
    z-index: 80;
    width: min(250px, 84vw);
    padding: .35rem;
    border: 1px solid #d9e2dc;
    border-radius: 11px;
    background: #fff;
    box-shadow: 0 16px 38px rgba(15, 23, 42, .2);
}
.kb-menu-title { padding: .25rem .4rem; color: #7c8a81; font-size: .66rem; font-weight: 900; }
.kb-item-menu button {
    width: 100%; min-height: 48px;
    display: flex; align-items: center; gap: .5rem;
    padding: .35rem .45rem;
    border: 0; border-radius: 8px;
    background: transparent; color: #32443a; text-align: start; font: inherit; cursor: pointer;
}
.kb-item-menu button:hover { background: #f3f6f4; }
.kb-item-menu button > i { width: 28px; height: 28px; display: inline-grid; place-items: center; border-radius: 7px; background: #eef2ef; }
.kb-item-menu button span { display: flex; flex-direction: column; }
.kb-item-menu button strong { font-size: .74rem; }
.kb-item-menu button small { color: #718078; font-size: .65rem; }
.kb-item-menu .is-undo { color: #92400e; }
.kb-item-menu .is-waste { color: #b91c1c; }

.kb-card-foot { padding: .35rem; border-radius: 0 0 9px 9px; }
.kb-card-btn { min-height: 42px; padding: .35rem; font-size: .78rem; font-weight: 950; }
.kb-card-btn-primary { background: #c2410c; }
.kb-card-btn-primary:hover { background: #9a3412; }
.kb-card--ready .kb-card-foot {
    border-top: 1px solid #bbf7d0;
    background: #ecfdf5;
}
.kb-handoff {
    min-height: 52px;
    display: flex;
    align-items: center;
    gap: .55rem;
    padding: .22rem .28rem;
}
.kb-handoff-icon {
    width: 38px;
    height: 38px;
    flex: 0 0 38px;
    display: inline-grid;
    place-items: center;
    border-radius: 10px;
    background: #047857;
    color: #fff;
    font-size: 1.05rem;
}
.kb-handoff > div { min-width: 0; display: grid; gap: 2px; }
.kb-handoff strong { color: #065f46; font-size: .82rem; font-weight: 950; }
.kb-handoff small { color: #477162; font-size: .65rem; line-height: 1.45; }
.kb-handoff small i { color: #047857; }

@media (max-width: 680px) {
    .kb-item { grid-template-columns: auto minmax(0, 1fr); }
    .kb-item-actions { grid-column: 1 / -1; }
    .kb-item-action { flex: 1; }
    .kb-item-done { width: 100%; }
}
</style>
