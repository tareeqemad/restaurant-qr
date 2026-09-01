<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ChangeRequestDialog from '../CustomerTrack/ChangeRequestDialog.vue';
import TrackCard from '../CustomerTrack/TrackCard.vue';
import { useToast } from '../../Composables/useToast';

const props = defineProps({
    open: { type: Boolean, default: false },
    urls: { type: Object, required: true },
    orderingEnabled: { type: Boolean, default: true },
});

const emit = defineEmits(['close', 'orders', 'session-expired', 'ordering-locked']);
const toast = useToast();
const orders = ref([]);
const version = ref(null);
const loading = ref(false);
const refreshing = ref(false);
const errorMessage = ref('');
const actionBusy = ref(false);
const cancelOrder = ref(null);
const cancelReason = ref('');
const changeOrder = ref(null);

const active = computed(() => orders.value.filter((order) => ! ['cancelled', 'completed'].includes(order.status)));
const finished = computed(() => orders.value.filter((order) => ['cancelled', 'completed'].includes(order.status)));
const readyCount = computed(() => active.value.filter((order) => ['ready', 'delivered'].includes(order.status)).length);
const preparingCount = computed(() => active.value.filter((order) => ['approved', 'preparing'].includes(order.status)).length);
const activeItemCount = computed(() => active.value.reduce((total, order) => total + Number(order.itemCount || 0), 0));

const headline = computed(() => {
    if (! active.value.length) return orders.value.length ? 'كل طلباتك محفوظة' : 'لا يوجد طلب حتى الآن';
    if (readyCount.value) return readyCount.value > 1 ? 'طلباتك جاهزة' : 'طلبك جاهز';
    if (preparingCount.value) return 'طلبك قيد التحضير';
    return 'استلمنا طلبك';
});

const headlineNote = computed(() => {
    if (! active.value.length) return orders.value.length ? 'يمكنك إغلاق المتابعة وإضافة جولة جديدة.' : 'اختر من المنيو وأرسل طلبك؛ سيظهر هنا مباشرة.';
    if (readyCount.value) return 'سيصل إلى طاولتك بعد قليل.';
    if (preparingCount.value) return 'المطبخ يعمل عليه الآن، والحالة تتحدث تلقائياً.';
    return 'بانتظار اعتماد الجرسون وبدء التحضير.';
});

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const handleExpired = () => {
    emit('session-expired');
    emit('close');
};

const loadOrders = async (silent = false) => {
    if (loading.value || refreshing.value) return;
    if (silent) refreshing.value = true;
    else loading.value = true;
    errorMessage.value = '';

    try {
        const response = await fetch(props.urls.trackData, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
        });
        if (response.status === 419) {
            handleExpired();
            return;
        }
        const data = await response.json().catch(() => null);
        if (! response.ok || ! data) throw new Error(data?.message ?? 'تعذّر تحديث الطلبات.');

        orders.value = Array.isArray(data.orders) ? data.orders : [];
        version.value = data.version ?? null;
        emit('orders', orders.value);
    } catch (error) {
        errorMessage.value = error?.message ?? 'تعذّر تحديث الطلبات. تأكد من الاتصال وحاول ثانية.';
    } finally {
        loading.value = false;
        refreshing.value = false;
    }
};

const checkPulse = async () => {
    if (! props.open || document.hidden) return;
    try {
        const response = await fetch(props.urls.trackPulse, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (response.status === 419) {
            handleExpired();
            return;
        }
        const data = response.ok ? await response.json() : null;
        if (data && (version.value === null || data.version !== version.value)) await loadOrders(true);
    } catch { /* The next five-second pulse retries without disturbing the diner. */ }
};

const postJson = async (url, payload) => {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
        body: JSON.stringify(payload),
    });
    if (response.status === 419) {
        handleExpired();
        return null;
    }
    const data = await response.json().catch(() => null);
    if (response.status === 409 && data?.error === 'ordering_device_locked') {
        emit('ordering-locked', data.message);
        throw new Error(data.message);
    }
    if (! response.ok || data?.ok === false) throw new Error(data?.message ?? 'تعذّر تنفيذ الطلب.');
    return data;
};

const openCancel = (order) => {
    cancelOrder.value = order;
    cancelReason.value = '';
};

const submitCancel = async () => {
    if (! cancelOrder.value || actionBusy.value) return;
    actionBusy.value = true;
    try {
        const data = await postJson(cancelOrder.value.urls.cancel, { reason: cancelReason.value.trim() });
        if (! data) return;
        cancelOrder.value = null;
        if (Array.isArray(data.orders)) {
            orders.value = data.orders;
            version.value = data.version ?? version.value;
            emit('orders', orders.value);
        } else {
            await loadOrders(true);
        }
        toast.success(data.message ?? 'تم إلغاء الطلب وإبلاغ المطعم.');
    } catch (error) {
        toast.warning(error?.message ?? 'تعذّر إلغاء الطلب.');
    } finally {
        actionBusy.value = false;
    }
};

const openChange = (order) => {
    changeOrder.value = order;
};

const submitChange = async (payload) => {
    if (! changeOrder.value || actionBusy.value) return;
    actionBusy.value = true;
    try {
        const data = await postJson(changeOrder.value.urls.changeRequest, payload);
        if (! data) return;
        changeOrder.value = null;
        await loadOrders(true);
        toast.success(data.message ?? 'وصل طلب التعديل للجرسون.');
    } catch (error) {
        toast.warning(error?.message ?? 'تعذّر إرسال طلب التعديل.');
    } finally {
        actionBusy.value = false;
    }
};

const addFromMenu = () => {
    changeOrder.value = null;
    emit('close');
};

const closeTopLayer = () => {
    if (changeOrder.value) changeOrder.value = null;
    else if (cancelOrder.value) cancelOrder.value = null;
    else emit('close');
};

const handleEscape = (event) => {
    if (event.key === 'Escape' && props.open) closeTopLayer();
};

let pulseTimer = null;
let previousBodyOverflow = '';

watch(() => props.open, (open) => {
    if (typeof document === 'undefined') return;
    if (open) {
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        loadOrders();
    } else {
        document.body.style.overflow = previousBodyOverflow;
        cancelOrder.value = null;
        changeOrder.value = null;
    }
});

onMounted(() => {
    window.addEventListener('keydown', handleEscape);
    pulseTimer = window.setInterval(checkPulse, 5000);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleEscape);
    window.clearInterval(pulseTimer);
    if (typeof document !== 'undefined') document.body.style.overflow = previousBodyOverflow;
});
</script>

<template>
    <Teleport to="body">
        <Transition name="tracking-sheet">
            <div v-if="open" class="ots-backdrop" @click.self="emit('close')">
                <section class="ots-sheet" role="dialog" aria-modal="true" aria-labelledby="tracking-title">
                    <header class="ots-header">
                        <div>
                            <span class="ots-live"><i></i> تحديث تلقائي</span>
                            <h2 id="tracking-title">تتبع طلباتك</h2>
                        </div>
                        <button type="button" class="ots-close" aria-label="إغلاق التتبع" @click="emit('close')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </header>

                    <div class="ots-scroll">
                        <section class="ots-hero" :class="{ 'is-ready': readyCount }">
                            <div>
                                <h3>{{ headline }}</h3>
                                <p>{{ headlineNote }}</p>
                                <div v-if="active.length" class="ots-facts">
                                    <span><b>{{ active.length }}</b> طلب نشط</span>
                                    <span><b>{{ activeItemCount }}</b> صنف</span>
                                    <span v-if="readyCount"><b>{{ readyCount }}</b> جاهز</span>
                                </div>
                            </div>
                            <span class="ots-hero-icon">
                                <i class="bi" :class="readyCount ? 'bi-bag-check-fill' : (preparingCount ? 'bi-fire' : 'bi-receipt-cutoff')"></i>
                            </span>
                        </section>

                        <div v-if="loading" class="ots-state">
                            <i class="bi bi-arrow-repeat ots-spin"></i>
                            <strong>نحدّث حالة طلبك…</strong>
                        </div>

                        <div v-else-if="errorMessage" class="ots-state ots-state--error">
                            <i class="bi bi-wifi-off"></i>
                            <strong>{{ errorMessage }}</strong>
                            <button type="button" @click="loadOrders()">حاول ثانية</button>
                        </div>

                        <div v-else-if="orders.length === 0" class="ots-state">
                            <i class="bi bi-basket2"></i>
                            <strong>لم ترسل طلباً بعد</strong>
                            <small>أغلق هذه اللوحة واختر ما تحب من المنيو.</small>
                        </div>

                        <template v-else>
                            <div v-if="active.length" class="ots-section-head">
                                <div><small>قيد التنفيذ الآن</small><strong>متابعة الطلب</strong></div>
                                <button type="button" :disabled="refreshing" aria-label="تحديث" @click="loadOrders(true)">
                                    <i class="bi bi-arrow-clockwise" :class="{ 'ots-spin': refreshing }"></i>
                                </button>
                            </div>

                            <div class="ots-orders">
                                <TrackCard
                                    v-for="order in active"
                                    :key="order.id"
                                    :order="order"
                                    :can-manage="orderingEnabled"
                                    @cancel="openCancel"
                                    @change="openChange"
                                />
                            </div>

                            <details v-if="finished.length" class="ots-finished">
                                <summary>
                                    <span><i class="bi bi-clock-history"></i> الطلبات المكتملة والملغاة</span>
                                    <b>{{ finished.length }}</b>
                                </summary>
                                <div class="ots-orders">
                                    <TrackCard v-for="order in finished" :key="order.id" :order="order" />
                                </div>
                            </details>
                        </template>
                    </div>

                    <footer class="ots-footer">
                        <button type="button" @click="emit('close')">
                            <i class="bi" :class="orderingEnabled ? 'bi-plus-lg' : 'bi-x-lg'"></i>
                            {{ orderingEnabled ? 'أضف جولة جديدة · نفس الفاتورة' : 'إغلاق المتابعة' }}
                        </button>
                        <span><i></i> ابقَ هنا، الحالة تتحدث وحدها</span>
                    </footer>
                </section>

                <div v-if="cancelOrder" class="ots-action-layer" @click.self="cancelOrder = null">
                    <section class="ots-action" role="alertdialog" aria-modal="true" aria-labelledby="cancel-order-title">
                        <span class="ots-action-icon is-danger"><i class="bi bi-x-circle"></i></span>
                        <h3 id="cancel-order-title">إلغاء الطلب {{ cancelOrder.number }}؟</h3>
                        <p>الإلغاء الفوري متاح فقط قبل بدء التحضير، وسيصل التنبيه للمطعم مباشرة.</p>
                        <label>
                            <span>السبب <small>اختياري</small></span>
                            <textarea v-model="cancelReason" rows="3" maxlength="500" placeholder="مثال: غيّرت رأيي"></textarea>
                        </label>
                        <div class="ots-action-buttons">
                            <button type="button" :disabled="actionBusy" @click="cancelOrder = null">رجوع</button>
                            <button type="button" class="is-danger" :disabled="actionBusy" @click="submitCancel">
                                {{ actionBusy ? 'جارٍ الإلغاء…' : 'نعم، ألغِ الطلب' }}
                            </button>
                        </div>
                    </section>
                </div>

                <div v-if="changeOrder" class="ots-action-layer" @click.self="changeOrder = null">
                    <ChangeRequestDialog
                        :order="changeOrder"
                        :busy="actionBusy"
                        @close="changeOrder = null"
                        @add="addFromMenu"
                        @submit="submitChange"
                    />
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.ots-backdrop {
    position: fixed;
    inset: 0;
    z-index: 12000;
    display: grid;
    place-items: center;
    padding: 1rem;
    background: rgba(15, 23, 42, .56);
    backdrop-filter: blur(5px);
}
.ots-sheet {
    width: min(780px, 100%);
    max-height: min(920px, calc(100dvh - 2rem));
    display: grid;
    grid-template-rows: auto minmax(0, 1fr) auto;
    overflow: hidden;
    border: 1px solid #dce6df;
    border-radius: 26px;
    background: #f6f9f7;
    color: #17251d;
    box-shadow: 0 32px 90px -30px rgba(9, 40, 25, .6);
}
.ots-header, .ots-footer {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .8rem;
    padding: .9rem 1rem;
    background: rgba(255, 255, 255, .96);
}
.ots-header { border-bottom: 1px solid #e3ebe6; }
.ots-header h2 { margin: .12rem 0 0; font-size: 1.08rem; font-weight: 950; }
.ots-live { display: inline-flex; align-items: center; gap: .3rem; color: #66776d; font-size: .65rem; font-weight: 800; }
.ots-live i, .ots-footer span i { width: 7px; height: 7px; border-radius: 50%; background: #19a367; box-shadow: 0 0 0 4px rgba(25, 163, 103, .1); }
.ots-close { width: 42px; height: 42px; border: 1px solid #dfe7e2; border-radius: 13px; background: #fff; color: #53645a; cursor: pointer; }
.ots-scroll { min-height: 0; overflow-y: auto; overscroll-behavior: contain; padding: 1rem; }
.ots-hero {
    min-height: 128px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    margin-bottom: 1rem;
    padding: 1.1rem;
    overflow: hidden;
    border-radius: 22px;
    background: linear-gradient(135deg, #123f31, #1f6b50);
    color: #fff;
}
.ots-hero.is-ready { background: linear-gradient(135deg, #0f6642, #1d8b59); }
.ots-hero h3 { margin: 0; font-size: 1.18rem; font-weight: 950; }
.ots-hero p { margin: .3rem 0 0; color: rgba(255, 255, 255, .78); font-size: .75rem; line-height: 1.6; }
.ots-hero-icon { width: 58px; height: 58px; flex: 0 0 58px; display: grid; place-items: center; border-radius: 19px; background: rgba(255, 255, 255, .14); font-size: 1.45rem; }
.ots-facts { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .7rem; }
.ots-facts span { padding: .3rem .55rem; border-radius: 999px; background: rgba(255, 255, 255, .12); font-size: .64rem; font-weight: 800; }
.ots-facts b { font-size: .77rem; }
.ots-section-head { display: flex; align-items: center; justify-content: space-between; gap: .6rem; margin: 0 0 .65rem; }
.ots-section-head > div { display: grid; gap: .05rem; }
.ots-section-head small { color: #718077; font-size: .64rem; }
.ots-section-head strong { font-size: .9rem; font-weight: 950; }
.ots-section-head button { width: 38px; height: 38px; border: 1px solid #dde6e0; border-radius: 12px; background: #fff; color: #1f6b50; cursor: pointer; }
.ots-orders { display: grid; gap: .75rem; }
.ots-state { min-height: 280px; display: grid; place-items: center; align-content: center; gap: .6rem; text-align: center; color: #718077; }
.ots-state > i { font-size: 2rem; color: #1f6b50; }
.ots-state strong { color: #34473c; font-size: .85rem; }
.ots-state small { font-size: .72rem; }
.ots-state button { min-height: 40px; padding: 0 .9rem; border: 0; border-radius: 12px; background: #1f6b50; color: #fff; font: inherit; font-size: .75rem; font-weight: 850; cursor: pointer; }
.ots-state--error > i { color: #b64635; }
.ots-finished { margin-top: .9rem; border: 1px solid #e0e7e3; border-radius: 17px; background: rgba(255, 255, 255, .75); overflow: hidden; }
.ots-finished summary { min-height: 48px; display: flex; align-items: center; justify-content: space-between; gap: .6rem; padding: 0 .85rem; color: #53645a; font-size: .72rem; font-weight: 850; cursor: pointer; list-style: none; }
.ots-finished summary::-webkit-details-marker { display: none; }
.ots-finished summary b { min-width: 25px; height: 25px; display: grid; place-items: center; border-radius: 999px; background: #edf3ef; }
.ots-finished .ots-orders { padding: 0 .7rem .7rem; }
.ots-footer { border-top: 1px solid #e3ebe6; }
.ots-footer > button { min-height: 44px; padding: 0 .9rem; border: 0; border-radius: 13px; background: #1f6b50; color: #fff; font: inherit; font-size: .76rem; font-weight: 900; cursor: pointer; }
.ots-footer > span { display: inline-flex; align-items: center; gap: .42rem; color: #718077; font-size: .65rem; font-weight: 750; }
.ots-action-layer { position: absolute; inset: 0; z-index: 5; display: grid; place-items: center; padding: 1rem; background: rgba(15, 23, 42, .64); }
.ots-action { width: min(480px, 100%); max-height: calc(100dvh - 2rem); overflow-y: auto; padding: 1.1rem; border-radius: 22px; background: #fff; box-shadow: 0 24px 70px -25px rgba(0, 0, 0, .6); }
.ots-action-icon { width: 48px; height: 48px; display: grid; place-items: center; border-radius: 15px; background: #edf6f1; color: #1f6b50; font-size: 1.2rem; }
.ots-action-icon.is-danger { background: #fff0f0; color: #b72d2d; }
.ots-action h3 { margin: .75rem 0 .2rem; font-size: 1rem; font-weight: 950; }
.ots-action > p { margin: 0 0 .85rem; color: #718077; font-size: .72rem; line-height: 1.65; }
.ots-action label { display: grid; gap: .35rem; margin-top: .65rem; color: #405248; font-size: .7rem; font-weight: 850; }
.ots-action label small { color: #8b9890; font-weight: 600; }
.ots-action textarea, .ots-action select, .ots-action input { width: 100%; box-sizing: border-box; border: 1px solid #dce5df; border-radius: 12px; padding: .65rem .75rem; background: #fff; color: #26372d; font: inherit; font-size: .78rem; }
.ots-action textarea { resize: vertical; line-height: 1.6; }
.ots-action textarea:focus, .ots-action select:focus, .ots-action input:focus { outline: 2px solid rgba(31, 107, 80, .16); border-color: #1f6b50; }
.ots-action-buttons { display: grid; grid-template-columns: 1fr 1fr; gap: .55rem; margin-top: 1rem; }
.ots-action-buttons button { min-height: 44px; border: 1px solid #dce5df; border-radius: 12px; background: #fff; color: #405248; font: inherit; font-size: .74rem; font-weight: 900; cursor: pointer; }
.ots-action-buttons button.is-primary { border-color: #1f6b50; background: #1f6b50; color: #fff; }
.ots-action-buttons button.is-danger { border-color: #c94040; background: #c94040; color: #fff; }
.ots-action-buttons button:disabled { opacity: .5; cursor: default; }
.ots-spin { animation: ots-spin .8s linear infinite; }
.tracking-sheet-enter-active, .tracking-sheet-leave-active { transition: opacity .18s ease; }
.tracking-sheet-enter-active .ots-sheet, .tracking-sheet-leave-active .ots-sheet { transition: transform .22s ease; }
.tracking-sheet-enter-from, .tracking-sheet-leave-to { opacity: 0; }
.tracking-sheet-enter-from .ots-sheet, .tracking-sheet-leave-to .ots-sheet { transform: translateY(24px) scale(.98); }
@keyframes ots-spin { to { transform: rotate(360deg); } }

@media (max-width: 640px) {
    .ots-backdrop { padding: 0; place-items: stretch; }
    .ots-sheet { width: 100%; max-height: 100dvh; height: 100dvh; border: 0; border-radius: 0; }
    .ots-header { padding-top: calc(.75rem + env(safe-area-inset-top)); }
    .ots-scroll { padding: .75rem; }
    .ots-hero { min-height: 112px; margin-bottom: .75rem; border-radius: 19px; padding: .9rem; }
    .ots-hero-icon { width: 48px; height: 48px; flex-basis: 48px; border-radius: 15px; }
    .ots-footer { padding-bottom: calc(.75rem + env(safe-area-inset-bottom)); }
    .ots-footer > span { display: none; }
    .ots-footer > button { width: 100%; }
    .ots-action-layer { position: fixed; }
    .ots-action { align-self: end; width: 100%; max-height: 88dvh; border-radius: 22px 22px 0 0; padding-bottom: calc(1.1rem + env(safe-area-inset-bottom)); }
}

@media (prefers-reduced-motion: reduce) {
    .tracking-sheet-enter-active, .tracking-sheet-leave-active,
    .tracking-sheet-enter-active .ots-sheet, .tracking-sheet-leave-active .ots-sheet { transition: none; }
}
</style>
