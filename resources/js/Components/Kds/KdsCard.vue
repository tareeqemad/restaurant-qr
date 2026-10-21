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

const emit = defineEmits(['act', 'focus-ready']);

const now = ref(Date.now());
let timer = null;
let mounted = false;

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
    syncUndoClock();
}, { immediate: true });

function syncUndoClock() {
    now.value = Date.now();
    const needsClock = [...undoDeadlines.value.values()].some((deadline) => deadline > now.value);
    if (mounted && needsClock && timer === null) timer = window.setInterval(syncUndoClock, 1000);
    if (!needsClock && timer !== null) {
        window.clearInterval(timer);
        timer = null;
    }
}

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
    mounted = true;
    syncUndoClock();
    document.addEventListener('click', closeMenu);
    document.addEventListener('keydown', handleEscape);
});

onBeforeUnmount(() => {
    mounted = false;
    window.clearInterval(timer);
    document.removeEventListener('click', closeMenu);
    document.removeEventListener('keydown', handleEscape);
});

const act = (verb, payload = {}) => {
    menuOpenFor.value = null;
    emit('act', { verb, ...payload });
};
const bulkItems = (status) => props.card.items.filter((item) => item.status === status && !item.changePending).map((item) => item.id);

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

        <button v-if="column !== 'ready' && card.stageCounts?.ready" type="button" class="kb-partial-ready"
                @click="emit('focus-ready')">
            <i class="bi bi-bell-fill"></i>
            <strong>{{ card.readyQuantity }} جاهز للاستلام</strong>
            <span>{{ card.handoff.recipientLabel }}</span>
            <i class="bi bi-chevron-left"></i>
        </button>

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

                <div class="kb-item-actions" :class="{ 'has-open-menu': menuOpenFor === it.id }">
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

                    <div v-if="['approved', 'preparing'].includes(it.status) && it.canCancel && ! it.changePending" class="kb-more" :class="{ 'is-open': menuOpenFor === it.id }" @click.stop>
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
                    :disabled="busy || !bulkItems('approved').length" @click="act('start-all', { order_id: card.orderId, item_ids: bulkItems('approved') })">
                <i class="bi" :class="busy ? 'bi-arrow-repeat kb-spin' : 'bi-play-fill'"></i> {{ busy ? 'جارٍ البدء…' : 'ابدأ المتاح' }}
            </button>
            <button v-else-if="column === 'cooking'" type="button" class="kb-card-btn kb-card-btn-success"
                    :disabled="busy || !bulkItems('preparing').length" @click="act('ready-all', { order_id: card.orderId, item_ids: bulkItems('preparing') })">
                <i class="bi" :class="busy ? 'bi-arrow-repeat kb-spin' : 'bi-bell-fill'"></i> {{ busy ? 'جارٍ الإكمال…' : 'الكل جاهز' }}
            </button>
            <div v-else-if="column === 'ready'" class="kb-handoff">
                <span class="kb-handoff-icon"><i class="bi bi-person-check-fill"></i></span>
                <div>
                    <strong>سلّم إلى {{ card.handoff.recipientLabel }}</strong>
                </div>
            </div>
        </footer>
    </article>
</template>

<style scoped>
.kb-card {
    position: relative;
    min-width: 0;
    border-radius: 12px;
    border-width: 1px;
    box-shadow: none;
    opacity: 1;
    border: 1px solid var(--line, var(--line));
    background: var(--surface, #FFFDFA);
}
.kb-card--waiting { border-inline-start: 4px solid #f59e0b; }
.kb-card--busy { border-color: var(--brand, #A3422B); box-shadow: 0 0 0 2px var(--brand-soft, #F3E3D8); }
.kb-spin { animation: kb-spin .72s linear infinite; }
@keyframes kb-spin { to { transform: rotate(360deg); } }
.kb-card--cooking { border-inline-start: 4px solid #3b82f6; opacity: 1; }
.kb-card--ready { border-inline-start: 4px solid #059669; opacity: 1; }
.kb-card--cooking .kb-table-ribbon { filter: none; }
.kb-card--ready .kb-table-ribbon {
    background: var(--surface, #FFFDFA);
    color: var(--ink, #25343A);
    filter: none;
}
.kb-card--change { border-color: #ef4444; box-shadow: none; }

.kb-table-ribbon { min-height: 76px; padding: .65rem .75rem; gap: .55rem; border-radius: 11px 11px 0 0; background: var(--ink, #25343A); }
.kb-table-ribbon-inner { min-width: 0; flex-wrap: wrap; align-items: center; gap: .22rem .35rem; }
.kb-table-label { font-size: .875rem; opacity: 1; }
.kb-table-big { font-size: 2rem; line-height: 1.1; }
.kb-round-chip { display: inline-flex; align-items: center; min-height: 24px; padding: 0 .42rem; border-radius: 6px; background: rgba(255,255,255,.16); color: #FFFDFA; font-size: .875rem; font-weight: 800; white-space: nowrap; }
.kb-round-chip.is-addition { background: #fff1d8; color: #8e4c0d; }
.kb-table-ribbon-side { flex: 0 0 auto; }
.kb-age-chip { padding: 1px 6px; font-size: .875rem; border-radius: 6px; min-height: 28px; }
.kb-age-chip strong { font-size: .875rem; }
.kb-order-mini { max-width: 110px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .875rem; }
.kb-state-chip {
    display: inline-flex; align-items: center; gap: 3px;
    padding: 2px 6px; border-radius: 6px;
    background: rgba(255,255,255,.16); color: #FFFDFA;
    font-size: .875rem; font-weight: 800; white-space: nowrap; min-height: 26px;
}
.kb-state-chip.is-waiting { padding: 2px 6px; background: #f8f0e2; color: #7f5525; }
.kb-state-chip.is-cooking { padding: 2px 6px; background: #edf1f2; color: #315b6b; }
.kb-state-chip.is-ready { padding: 2px 6px; background: #eaf0e9; color: #365841; }
.kb-followup-chip { margin: 0; padding: 2px 6px; font-size: .875rem; }

.kb-ext-info { display: flex; flex-wrap: wrap; gap: .25rem; padding: .35rem .5rem; border-bottom: 1px solid var(--line, var(--line)); background: var(--canvas); }
.kb-ext-chip { display: inline-flex; align-items: center; gap: 3px; font-size: .875rem; color: var(--muted, var(--muted)); }
.kb-ext-address { width: 100%; display: flex; gap: 3px; color: var(--muted, var(--muted)); font-size: .875rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

.kb-card-note { padding: .38rem .5rem; font-size: .875rem; line-height: 1.3; }
.kb-change-alert {
    display: flex; align-items: flex-start; gap: .45rem;
    padding: .48rem .55rem; border-block: 1px solid #fecaca;
    background: #fff1f2; color: #991b1b;
}
.kb-change-alert > i { margin-top: 1px; font-size: 1rem; }
.kb-change-alert div { min-width: 0; display: grid; gap: .08rem; }
.kb-change-alert strong { font-size: .875rem; font-weight: 800; }
.kb-change-alert small { font-size: .875rem; line-height: 1.45; }
.kb-partial-ready { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; width: 100%; min-height: 44px; padding: .5rem; border: 0; border-block: 1px solid var(--brand); background: var(--brand-soft); color: var(--brand); text-align: start; font: inherit; font-size: .875rem; cursor: pointer; }
.kb-partial-ready span { margin-inline-start: auto; }
.kb-items { padding: .65rem; gap: .5rem; }
.kb-item {
    position: relative;
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    align-items: center;
    gap: .45rem;
    min-height: 48px;
    padding: .55rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 8px;
    background: var(--canvas, #F7F3ED);
}
.kb-item.is-preparing { background: #eff6ff; border-color: #bfdbfe; }
.kb-item.is-ready {
    border-color: #bbf7d0;
    background: #f0fdf4;
    opacity: 1;
}
.kb-item.is-ready .kb-item-name,
.kb-item.is-ready .kb-item-mods {
    text-decoration: none;
    opacity: 1;
}
.kb-item-qty { width: auto; min-width: 32px; font-size: 1.1rem; color: var(--ink, var(--ink)); min-height: 32px; line-height: 32px; border-radius: 6px; background: var(--surface-muted, #EFE9E0); }
.kb-item-name { font-size: 1.05rem; line-height: 1.3; font-weight: 800; color: var(--ink, var(--ink)); }
.kb-prep-tag, .kb-elapsed-tag, .kb-orphan-tag { display: inline-flex; align-items: center; gap: 2px; margin-inline-start: .2rem; font-size: .875rem; font-weight: 750; color: var(--muted, var(--muted)); }
.kb-elapsed-tag { color: #1d4ed8; }
.kb-orphan-tag { color: #b91c1c; }
.kb-change-tag { display: inline-flex; align-items: center; gap: 2px; margin-inline-start: .2rem; padding: 2px 5px; border-radius: 12px; background: #fee2e2; color: #b91c1c; font-size: .875rem; font-weight: 800; }
.kb-item:has(.kb-change-tag) { border-color: #fca5a5; background: #fff7f7; }
.kb-item-mods { gap: 3px; }
.kb-item-mods span { padding: 2px 6px; font-size: .875rem; line-height: 1.35; border-radius: 6px; }
.kb-item-exclusions { display: flex; flex-wrap: wrap; align-items: center; gap: 3px; margin-top: 2px; padding: 4px 5px; border: 1px solid #fca5a5; border-radius: 7px; background: #fee2e2; color: #991b1b; }
.kb-item-exclusions strong { display: inline-flex; align-items: center; gap: 3px; font-size: .875rem; font-weight: 800; }
.kb-item-exclusions span { padding: 2px 5px; border-radius: 12px; background: var(--surface); color: #b91c1c; font-size: .875rem; font-weight: 800; }
.kb-item-note { padding: 2px 5px; font-size: .875rem; }

.kb-item-actions { display: flex; align-items: center; gap: .22rem; }
.kb-item-action {
    min-width: 60px; min-height: 44px;
    display: inline-flex; align-items: center; justify-content: center; gap: 3px;
    border: 0; border-radius: 8px;
    font: inherit; font-size: .875rem; font-weight: 800; cursor: pointer;
}
.kb-item-action.is-start { background: var(--brand-soft, #F3E3D8); color: var(--brand, #A3422B); border: 1px solid var(--line, var(--line)); }
.kb-item-action.is-ready { background: #dcfce7; color: #166534; border: 1px solid #86efac; }
.kb-item-action.is-undo-ready { min-width: 48px; background: #fffbeb; color: #92400e; border: 1px solid #fde68a; }
.kb-item-done {
    width: 38px;
    height: 38px;
    display: inline-grid;
    place-items: center;
    border-radius: 9px;
    background: var(--brand-soft, #F3E3D8);
    color: var(--ink, var(--ink));
    font-size: 1.1rem;
}
.kb-item-action:disabled, .kb-more-btn:disabled { opacity: .55; cursor: default; }
.kb-more { position: relative; }
.kb-more-btn {
    width: 44px; height: 44px;
    display: inline-grid; place-items: center;
    border: 1px solid var(--line, var(--line)); border-radius: 8px;
    background: var(--surface, #FFFDFA); color: var(--muted, var(--muted)); cursor: pointer;
}
.kb-item-menu {
    position: absolute;
    inset-inline-end: 0;
    top: calc(100% + 5px);
    z-index: 80;
    width: min(250px, 84vw);
    padding: .35rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 11px;
    background: var(--surface, #FFFDFA);
    box-shadow: none;
}
.kb-menu-title { padding: .25rem .4rem; color: var(--muted, var(--muted)); font-size: .875rem; font-weight: 800; }
.kb-item-menu button {
    width: 100%; min-height: 48px;
    display: flex; align-items: center; gap: .5rem;
    padding: .35rem .45rem;
    border: 0; border-radius: 8px;
    background: transparent; color: var(--ink, var(--ink)); text-align: start; font: inherit; cursor: pointer;
}
.kb-item-menu button:hover { background: var(--surface, #FFFDFA); }
.kb-item-menu button > i { width: 28px; height: 28px; display: inline-grid; place-items: center; border-radius: 7px; background: var(--surface, #FFFDFA); }
.kb-item-menu button span { display: flex; flex-direction: column; }
.kb-item-menu button strong { font-size: .875rem; }
.kb-item-menu button small { color: var(--muted, var(--muted)); font-size: .875rem; }
.kb-item-menu .is-undo { color: #92400e; }
.kb-item-menu .is-waste { color: #b91c1c; }

.kb-card-foot { padding: .65rem; border-radius: 0 0 9px 9px; border-top: 1px solid var(--line, var(--line)); background: var(--surface, #FFFDFA); }
.kb-card-btn { min-height: 50px; padding: .4rem; font-size: 1rem; font-weight: 800; border-radius: 8px; }
.kb-card-btn-primary { background: var(--brand, #A3422B); }
.kb-card-btn-primary:hover { background: var(--brand-hover, #823321); }
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
    background: var(--brand, #A3422B);
    color: #FFFDFA;
    font-size: 1.05rem;
}
.kb-handoff > div { min-width: 0; display: grid; gap: 2px; }
.kb-handoff strong { color: var(--ink, var(--ink)); font-size: .875rem; font-weight: 800; }
.kb-handoff small { color: var(--muted, var(--muted)); font-size: .875rem; line-height: 1.45; }
.kb-handoff small i { color: var(--ink, var(--ink)); }

@media (max-width: 680px) {
    .kb-item { grid-template-columns: auto minmax(0, 1fr); }
    .kb-item-actions { grid-column: 1 / -1; }
    .kb-item-action { flex: 1; }
    .kb-item-done { width: 100%; }
}

/* Task-focused workspace layout. */
.kb-card{border-radius:8px;border:1px solid var(--line);border-top:3px solid var(--brand);overflow:visible;background:var(--surface)}.kb-card--cooking{border-top-color:var(--accent)}.kb-card--ready{border-top-color:var(--line)}.kb-table-ribbon{background:var(--surface);color:var(--ink);padding:12px;border-bottom:1px solid var(--line);align-items:flex-start;gap:8px;border-radius:8px 8px 0 0}.kb-table-ribbon-inner{gap:6px;flex-wrap:wrap}.kb-table-big{font-size:28px;line-height:1.2}.kb-table-label{font-size:14px}.kb-age-chip{background:var(--canvas);color:var(--ink);border-radius:6px;padding:6px}.kb-state-chip{background:transparent;color:var(--muted);padding:0}.kb-round-chip{flex-basis:100%;background:transparent;color:var(--muted);padding:0}.kb-items{padding:0 12px}.kb-item{grid-template-columns:34px minmax(0,1fr);gap:8px;padding:14px 0;border-bottom:1px solid var(--line)}.kb-item-qty{font-size:20px;background:var(--canvas);border-radius:6px;min-width:32px}.kb-item-name{font-size:16px;line-height:1.5}.kb-item-actions{grid-column:2;justify-content:flex-end;flex-wrap:wrap}.kb-item-action{min-height:44px;min-width:84px}.kb-card-foot{padding:12px;background:var(--surface);border-top:1px solid var(--line)}.kb-card-btn{min-height:48px;border-radius:8px;font-size:15px}.kb-card-note,.kb-change-alert,.kb-item-exclusions{font-size:14px;line-height:1.6}.kb-urg-red{border-color:#b83434}.kb-urg-red .kb-age-chip{background:#fff0ed;color:#9f2d20}
.kb-urg-amber .kb-age-chip { background: #f8f0e2; color: #7f5525; }
.kb-urg-orange .kb-age-chip { background: #f9e9dd; color: #8e451c; }
.kb-age-chip strong, .kb-age-unit { color: inherit; }
</style>
