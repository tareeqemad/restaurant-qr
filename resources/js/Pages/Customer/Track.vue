<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import ChangeRequestDialog from '../../Components/CustomerTrack/ChangeRequestDialog.vue';
import TrackCard from '../../Components/CustomerTrack/TrackCard.vue';
import Toaster from '../../Components/Ui/Toaster.vue';
import { useLiveRefresh } from '../../Composables/useLiveRefresh';
import { useToast } from '../../Composables/useToast';

const props = defineProps({
    sessionInfo: { type: Object, required: true },
    orders: { type: Array, required: true },
    live: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const toast = useToast();
const page = usePage();

watch(() => page.props.flash, (flash) => {
    if (! flash) return;
    if (flash.success) toast.success(flash.success);
    if (flash.error) toast.error(flash.error);
    if (flash.warning) toast.warning(flash.warning);
    if (flash.info) toast.info(flash.info);
}, { immediate: true });

const active = computed(() => props.orders.filter((order) => ! ['cancelled', 'completed'].includes(order.status)));
const finished = computed(() => props.orders.filter((order) => ['cancelled', 'completed'].includes(order.status)));
const activeItemCount = computed(() => active.value.reduce((total, order) => total + Number(order.itemCount || 0), 0));
const readyCount = computed(() => active.value.filter((order) => ['ready', 'delivered'].includes(order.status)).length);
const preparingCount = computed(() => active.value.filter((order) => ['approved', 'preparing'].includes(order.status)).length);

const headline = computed(() => {
    if (! active.value.length) return props.orders.length ? 'طلباتك السابقة هنا' : 'جاهز تطلب؟';
    if (readyCount.value) return readyCount.value > 1 ? 'طلباتك جاهزة' : 'طلبك جاهز';
    if (preparingCount.value) return 'طلبك قيد التحضير';
    return 'استلمنا طلبك';
});

const headlineNote = computed(() => {
    if (! active.value.length) return props.orders.length ? 'يمكنك الرجوع للمنيو وبدء طلب جديد.' : 'اختر من المنيو، وسنُظهر لك كل خطوة هنا.';
    if (readyCount.value) return 'سيصل إلى طاولتك بعد قليل.';
    if (preparingCount.value) return 'الفريق يعمل عليه الآن، وسنحدّث الشاشة تلقائياً.';
    return 'بانتظار اعتماد الطلب وبدء التحضير.';
});

const refresh = () => router.reload({ only: ['orders', 'sessionInfo', 'live'], preserveScroll: true });
let lastVersion = props.live.version;
let idlePolls = 0;
watch(() => props.live.version, (version) => { lastVersion = version; });

const checkPulse = async (signal) => {
    try {
        const response = await fetch(props.urls.pulse, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal,
        });
        const data = response.ok ? await response.json() : null;
        if (! data || data.version !== lastVersion || ++idlePolls >= 6) {
            idlePolls = 0;
            refresh();
        }
    } catch { /* The next visible poll retries. */ }
};

useLiveRefresh({
    pollMs: 5000,
    onPing: (reason, signal) => (reason === 'poll' ? checkPulse(signal) : refresh()),
});

const calling = ref(false);
const callWaiter = async () => {
    if (calling.value || props.sessionInfo.helpPending) return;
    calling.value = true;
    try {
        const response = await fetch(props.urls.help, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        if (response.ok) {
            toast.success('وصل النداء — الجرسون جاي 🙌');
            refresh();
        } else {
            toast.warning('لم نتمكن من إرسال النداء، جرّب مرة ثانية.');
        }
    } catch {
        toast.error('انقطع الاتصال، جرّب مرة ثانية.');
    } finally {
        window.setTimeout(() => { calling.value = false; }, 4000);
    }
};

const cancelOrder = ref(null);
const cancelReason = ref('');
const actionBusy = ref(false);
const submitCancel = () => {
    if (! cancelOrder.value || actionBusy.value) return;
    actionBusy.value = true;
    router.post(cancelOrder.value.urls.cancel, { reason: cancelReason.value.trim() }, {
        preserveScroll: true,
        preserveState: true,
        showProgress: false,
        onSuccess: () => { cancelOrder.value = null; },
        onFinish: () => { actionBusy.value = false; },
    });
};

const changeOrder = ref(null);
const sheetOpen = computed(() => Boolean(cancelOrder.value || changeOrder.value));
let previousBodyOverflow = '';

const closeSheet = () => {
    if (changeOrder.value) changeOrder.value = null;
    else if (cancelOrder.value) cancelOrder.value = null;
};

const handleEscape = (event) => {
    if (event.key === 'Escape' && sheetOpen.value) closeSheet();
};

watch(sheetOpen, (open) => {
    if (typeof document === 'undefined') return;
    if (open) {
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = previousBodyOverflow;
    }
});

onMounted(() => window.addEventListener('keydown', handleEscape));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleEscape);
    if (typeof document !== 'undefined') document.body.style.overflow = previousBodyOverflow;
});

const openChange = (order) => {
    changeOrder.value = order;
};
const submitChange = (payload) => {
    if (! changeOrder.value || actionBusy.value) return;
    actionBusy.value = true;
    router.post(changeOrder.value.urls.changeRequest, payload, {
        preserveScroll: true,
        preserveState: true,
        showProgress: false,
        onSuccess: () => { changeOrder.value = null; },
        onFinish: () => { actionBusy.value = false; },
    });
};
const goToMenu = () => router.visit(props.urls.menu, {
    viewTransition: true,
    showProgress: false,
});
</script>

<template>
    <Head title="تتبع طلباتك" />

    <div class="track-page">
        <div class="track-glow track-glow--one"></div>
        <div class="track-glow track-glow--two"></div>

        <header class="track-header">
            <Link :href="urls.menu" class="brand" aria-label="العودة إلى المنيو" view-transition>
                <span class="brand-mark"><i class="bi bi-cup-hot-fill"></i></span>
                <span>
                    <strong>طلباتك</strong>
                    <small>تحديث مباشر من المطعم</small>
                </span>
            </Link>

            <div class="header-actions">
                <span class="table-pill"><i class="bi bi-geo-alt-fill"></i> طاولة {{ sessionInfo.tableNumber }}</span>
                <Link :href="urls.menu" class="header-link" view-transition><i class="bi bi-grid-fill"></i><span>المنيو</span></Link>
            </div>
        </header>

        <main class="track-shell">
            <section class="track-main">
                <div class="status-hero">
                    <div class="status-copy">
                        <span class="live-pill"><i></i> تحديث تلقائي</span>
                        <h1>{{ headline }}</h1>
                        <p>{{ headlineNote }}</p>

                        <div v-if="active.length" class="status-facts">
                            <span><strong>{{ active.length }}</strong> {{ active.length === 1 ? 'طلب نشط' : 'طلبات نشطة' }}</span>
                            <span><strong>{{ activeItemCount }}</strong> صنف</span>
                            <span v-if="readyCount" class="is-ready"><strong>{{ readyCount }}</strong> جاهز</span>
                        </div>
                    </div>
                    <div class="status-orbit" :class="{ 'is-ready': readyCount }" aria-hidden="true">
                        <span><i class="bi" :class="readyCount ? 'bi-bag-check-fill' : (preparingCount ? 'bi-fire' : 'bi-receipt-cutoff')"></i></span>
                    </div>
                </div>

                <div v-if="orders.length === 0" class="track-empty">
                    <span class="empty-icon"><i class="bi bi-basket2"></i></span>
                    <h2>لا يوجد طلب حتى الآن</h2>
                    <p>افتح المنيو واختر ما تحب؛ سيظهر طلبك هنا فور إرساله.</p>
                    <Link :href="urls.menu" class="primary-action" view-transition><i class="bi bi-grid-fill"></i> تصفّح المنيو</Link>
                </div>

                <template v-else>
                    <div v-if="active.length" class="section-heading">
                        <div>
                            <span>قيد التنفيذ الآن</span>
                            <h2>متابعة الطلب</h2>
                        </div>
                        <button type="button" class="refresh-button" title="تحديث" @click="refresh"><i class="bi bi-arrow-clockwise"></i></button>
                    </div>

                    <div class="order-list">
                        <TrackCard
                            v-for="order in active"
                            :key="order.id"
                            :order="order"
                            @cancel="cancelOrder = $event; cancelReason = ''"
                            @change="openChange"
                        />
                    </div>

                    <details v-if="finished.length" class="finished-orders">
                        <summary>
                            <span><i class="bi bi-clock-history"></i> الطلبات المكتملة والملغاة</span>
                            <span class="finished-count">{{ finished.length }}</span>
                            <i class="bi bi-chevron-down finished-chevron"></i>
                        </summary>
                        <div class="order-list order-list--finished">
                            <TrackCard v-for="order in finished" :key="order.id" :order="order" />
                        </div>
                    </details>

                </template>

            </section>

            <aside class="quick-panel">
                <div class="quick-card">
                    <span class="quick-label">كل ما تحتاجه من هنا</span>
                    <h2>خدمة الطاولة</h2>

                    <button
                        type="button"
                        class="waiter-action"
                        :class="{ 'is-sent': sessionInfo.helpPending }"
                        :disabled="sessionInfo.helpPending || calling"
                        @click="callWaiter"
                    >
                        <span class="action-icon"><i class="bi" :class="sessionInfo.helpPending ? 'bi-check2' : 'bi-bell-fill'"></i></span>
                        <span>
                            <strong>{{ sessionInfo.helpPending ? 'الجرسون بالطريق' : (calling ? 'جارٍ إرسال النداء…' : 'نادِ الجرسون') }}</strong>
                            <small>{{ sessionInfo.helpPending ? 'تم إرسال النداء لطاولتك' : 'مساعدة، سؤال أو طلب إضافي' }}</small>
                        </span>
                    </button>

                    <Link :href="urls.bill" class="quick-action quick-action--bill" view-transition>
                        <span class="action-icon"><i class="bi bi-receipt-cutoff"></i></span>
                        <span><strong>الفاتورة والدفع</strong><small>راجع الحساب واختر وسيلة الدفع</small></span>
                        <i class="bi bi-arrow-left"></i>
                    </Link>

                    <Link :href="urls.menu" class="quick-action" view-transition>
                        <span class="action-icon"><i class="bi bi-plus-lg"></i></span>
                        <span><strong>أضف على الطلب</strong><small>ارجع للمنيو من دون فقد المتابعة</small></span>
                        <i class="bi bi-arrow-left"></i>
                    </Link>
                </div>

                <p class="connection-note"><i></i> الشاشة تتحدث تلقائياً، لا تحتاج تعمل تحديث.</p>
            </aside>
        </main>

        <nav class="mobile-dock" aria-label="إجراءات الطلب">
            <Link :href="urls.menu" view-transition><i class="bi bi-grid-fill"></i><span>المنيو</span></Link>
            <button type="button" :class="{ 'is-sent': sessionInfo.helpPending }" :disabled="sessionInfo.helpPending || calling" @click="callWaiter">
                <i class="bi" :class="sessionInfo.helpPending ? 'bi-check2-circle' : 'bi-bell-fill'"></i>
                <span>{{ sessionInfo.helpPending ? 'تم النداء' : 'الجرسون' }}</span>
            </button>
            <Link :href="urls.bill" class="is-primary" view-transition><i class="bi bi-receipt-cutoff"></i><span>الفاتورة</span></Link>
        </nav>
    </div>

    <Teleport to="body">
        <Transition name="sheet">
            <div v-if="cancelOrder" class="sheet-backdrop" @click.self="cancelOrder = null">
                <section class="sheet-card" role="dialog" aria-modal="true" aria-labelledby="cancel-title">
                    <button type="button" class="sheet-close" aria-label="إغلاق" @click="cancelOrder = null"><i class="bi bi-x-lg"></i></button>
                    <span class="sheet-symbol sheet-symbol--danger"><i class="bi bi-x-circle"></i></span>
                    <h2 id="cancel-title">إلغاء الطلب {{ cancelOrder.number }}؟</h2>
                    <p>يمكن الإلغاء الفوري فقط قبل بدء التحضير. بعد ذلك أرسل طلب تعديل للجرسون.</p>
                    <label>
                        <span>سبب الإلغاء <small>اختياري</small></span>
                        <textarea v-model="cancelReason" rows="3" maxlength="500" placeholder="مثال: غيّرت رأيي"></textarea>
                    </label>
                    <div class="sheet-actions">
                        <button type="button" class="sheet-button" @click="cancelOrder = null">الاحتفاظ بالطلب</button>
                        <button type="button" class="sheet-button sheet-button--danger" :disabled="actionBusy"
                                :aria-busy="actionBusy" @click="submitCancel">
                            {{ actionBusy ? 'جارٍ تنفيذ الإلغاء…' : 'نعم، ألغِ الطلب' }}
                        </button>
                    </div>
                </section>
            </div>
        </Transition>
    </Teleport>

    <Teleport to="body">
        <Transition name="sheet">
            <div v-if="changeOrder" class="sheet-backdrop" @click.self="changeOrder = null">
                <ChangeRequestDialog
                    :order="changeOrder"
                    :busy="actionBusy"
                    @close="changeOrder = null"
                    @add="goToMenu"
                    @submit="submitChange"
                />
            </div>
        </Transition>
    </Teleport>

    <Toaster />
</template>

<style scoped>
.track-page {
    --track-primary: rgb(var(--primary-rgb, 15, 71, 49));
    --track-ink: #14231b;
    --track-muted: #67766e;
    --track-line: #e5ebe7;
    min-height: 100dvh;
    position: relative;
    overflow-x: hidden;
    color: var(--track-ink);
    background: #f5f8f6;
    padding: 1rem 1rem 7rem;
}
.track-glow { position: fixed; border-radius: 999px; filter: blur(90px); pointer-events: none; opacity: .25; }
.track-glow--one { width: 26rem; height: 26rem; background: rgba(var(--primary-rgb, 15, 71, 49), .25); top: -15rem; inset-inline-end: -9rem; }
.track-glow--two { width: 20rem; height: 20rem; background: rgba(212, 165, 80, .18); bottom: -12rem; inset-inline-start: -8rem; }
.track-header, .track-shell { width: min(1160px, 100%); margin-inline: auto; position: relative; z-index: 1; }
.track-header { min-height: 64px; display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.brand { display: inline-flex; align-items: center; gap: .7rem; text-decoration: none; color: inherit; }
.brand-mark { width: 44px; height: 44px; border-radius: 15px; display: grid; place-items: center; background: var(--track-primary); color: #fff; box-shadow: 0 10px 24px rgba(var(--primary-rgb, 15, 71, 49), .2); }
.brand strong, .brand small { display: block; }
.brand strong { font-size: 1rem; font-weight: 900; }
.brand small { color: var(--track-muted); font-size: .7rem; margin-top: .08rem; }
.header-actions { display: flex; align-items: center; gap: .55rem; }
.table-pill, .header-link { min-height: 42px; border: 1px solid var(--track-line); background: rgba(255, 255, 255, .86); border-radius: 14px; display: inline-flex; align-items: center; gap: .4rem; padding: 0 .8rem; color: #405149; font-size: .78rem; font-weight: 800; text-decoration: none; }
.header-link i { color: var(--track-primary); }
.track-shell { display: grid; grid-template-columns: minmax(0, 1fr) 320px; align-items: start; gap: 1.25rem; }
.track-main { min-width: 0; display: grid; gap: 1rem; }
.status-hero { min-height: 190px; border-radius: 28px; padding: clamp(1.25rem, 3vw, 2rem); overflow: hidden; position: relative; display: flex; justify-content: space-between; align-items: center; gap: 1rem; color: #fff; background: linear-gradient(135deg, #123b29, var(--track-primary)); box-shadow: 0 24px 60px -36px rgba(8, 48, 31, .75); }
.status-hero::after { content: ''; position: absolute; width: 280px; height: 280px; border: 1px solid rgba(255, 255, 255, .12); border-radius: 50%; inset-inline-end: -90px; top: -140px; box-shadow: 0 0 0 36px rgba(255, 255, 255, .035), 0 0 0 74px rgba(255, 255, 255, .025); }
.status-copy { position: relative; z-index: 1; }
.live-pill { width: fit-content; display: flex; align-items: center; gap: .4rem; padding: .3rem .62rem; border-radius: 999px; background: rgba(255, 255, 255, .12); font-size: .7rem; font-weight: 800; }
.live-pill i, .connection-note i { width: 7px; height: 7px; border-radius: 50%; background: #7df2b1; box-shadow: 0 0 0 4px rgba(125, 242, 177, .12); }
.status-copy h1 { margin: .75rem 0 .28rem; font-size: clamp(1.65rem, 4vw, 2.45rem); font-weight: 950; letter-spacing: -.035em; }
.status-copy p { margin: 0; max-width: 32rem; color: rgba(255, 255, 255, .77); font-size: .88rem; line-height: 1.75; }
.status-facts { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: 1rem; }
.status-facts span { border: 1px solid rgba(255, 255, 255, .15); background: rgba(255, 255, 255, .09); border-radius: 10px; padding: .38rem .62rem; font-size: .72rem; }
.status-facts strong { font-size: .88rem; margin-inline-end: .18rem; }
.status-facts .is-ready { background: rgba(125, 242, 177, .14); color: #b9ffd5; }
.status-orbit { width: 104px; height: 104px; flex: 0 0 104px; position: relative; z-index: 1; border-radius: 50%; display: grid; place-items: center; border: 1px solid rgba(255, 255, 255, .18); background: rgba(255, 255, 255, .08); }
.status-orbit::before { content: ''; position: absolute; inset: 10px; border-radius: inherit; border: 1px dashed rgba(255, 255, 255, .25); animation: orbit 14s linear infinite; }
.status-orbit span { width: 58px; height: 58px; border-radius: 20px; display: grid; place-items: center; background: #fff; color: var(--track-primary); font-size: 1.55rem; box-shadow: 0 14px 30px rgba(0, 0, 0, .18); }
.status-orbit.is-ready span { color: #087b50; }
@keyframes orbit { to { transform: rotate(360deg); } }
.section-heading { display: flex; justify-content: space-between; align-items: end; padding: .4rem .25rem 0; }
.section-heading span, .quick-label { display: block; color: #8a9890; font-size: .68rem; font-weight: 800; }
.section-heading h2, .quick-card h2 { margin: .12rem 0 0; font-size: 1.08rem; font-weight: 950; }
.refresh-button { width: 42px; height: 42px; border: 1px solid var(--track-line); border-radius: 13px; background: #fff; color: var(--track-primary); cursor: pointer; }
.order-list { display: grid; gap: .85rem; }
.track-empty { min-height: 360px; border: 1px solid var(--track-line); border-radius: 26px; background: #fff; display: grid; justify-items: center; align-content: center; text-align: center; padding: 2rem; }
.empty-icon { width: 72px; height: 72px; border-radius: 22px; display: grid; place-items: center; background: rgba(var(--primary-rgb, 15, 71, 49), .08); color: var(--track-primary); font-size: 1.8rem; }
.track-empty h2 { margin: 1rem 0 .25rem; font-size: 1.2rem; font-weight: 950; }
.track-empty p { margin: 0 0 1rem; max-width: 24rem; color: var(--track-muted); font-size: .82rem; line-height: 1.7; }
.primary-action { min-height: 46px; border-radius: 13px; padding: 0 1rem; display: inline-flex; align-items: center; justify-content: center; gap: .45rem; background: var(--track-primary); color: #fff; font-size: .82rem; font-weight: 850; text-decoration: none; }
.quick-panel { position: sticky; top: 1rem; display: grid; gap: .75rem; }
.quick-card { border: 1px solid var(--track-line); border-radius: 24px; background: rgba(255, 255, 255, .92); padding: 1.1rem; box-shadow: 0 18px 50px -38px rgba(15, 45, 30, .55); }
.quick-card h2 { margin-bottom: 1rem; }
.waiter-action, .quick-action { width: 100%; min-height: 72px; border-radius: 17px; padding: .65rem; display: flex; align-items: center; gap: .7rem; text-align: start; text-decoration: none; font: inherit; }
.waiter-action { border: 0; background: var(--track-primary); color: #fff; cursor: pointer; }
.waiter-action.is-sent { background: #eaf8ef; color: #17603f; border: 1px solid #b8dfc9; }
.waiter-action:disabled { cursor: default; }
.quick-action { margin-top: .55rem; border: 1px solid var(--track-line); color: var(--track-ink); background: #fff; }
.quick-action--bill { border-color: rgba(var(--primary-rgb, 15, 71, 49), .2); background: rgba(var(--primary-rgb, 15, 71, 49), .045); }
.action-icon { width: 40px; height: 40px; flex: 0 0 40px; border-radius: 13px; display: grid; place-items: center; background: rgba(255, 255, 255, .15); }
.quick-action .action-icon { background: #f1f5f2; color: var(--track-primary); }
.waiter-action span:nth-child(2), .quick-action span:nth-child(2) { flex: 1; min-width: 0; }
.waiter-action strong, .waiter-action small, .quick-action strong, .quick-action small { display: block; }
.waiter-action strong, .quick-action strong { font-size: .82rem; font-weight: 900; }
.waiter-action small, .quick-action small { margin-top: .12rem; font-size: .65rem; opacity: .72; }
.connection-note { display: flex; align-items: center; justify-content: center; gap: .45rem; margin: 0; color: #78877f; font-size: .67rem; }
.finished-orders { border: 1px solid var(--track-line); border-radius: 20px; background: rgba(255, 255, 255, .72); overflow: hidden; }
.finished-orders summary { min-height: 58px; padding: 0 1rem; display: flex; align-items: center; gap: .55rem; list-style: none; cursor: pointer; color: #58675f; font-size: .78rem; font-weight: 850; }
.finished-orders summary::-webkit-details-marker { display: none; }
.finished-orders summary > span:first-child { flex: 1; }
.finished-count { min-width: 26px; height: 26px; border-radius: 9px; display: grid; place-items: center; background: #eef2ef; font-size: .7rem; }
.finished-chevron { transition: transform .2s; }
.finished-orders[open] .finished-chevron { transform: rotate(180deg); }
.order-list--finished { padding: 0 .7rem .7rem; opacity: .88; }
.mobile-dock { display: none; }

.sheet-backdrop { position: fixed; z-index: 20000; inset: 0; padding: 1rem; display: flex; align-items: center; justify-content: center; background: rgba(13, 28, 20, .55); backdrop-filter: blur(5px); }
.sheet-card { width: min(520px, 100%); max-height: calc(100dvh - 2rem); overflow-y: auto; position: relative; border-radius: 24px; background: #fff; padding: 1.3rem; color: #17251d; box-shadow: 0 28px 80px rgba(6, 30, 18, .28); }
.sheet-close { position: absolute; top: 1rem; inset-inline-end: 1rem; width: 38px; height: 38px; border: 0; border-radius: 12px; background: #f2f5f3; color: #607068; cursor: pointer; }
.sheet-symbol { width: 50px; height: 50px; border-radius: 16px; display: grid; place-items: center; background: rgba(var(--primary-rgb, 15, 71, 49), .08); color: var(--track-primary); font-size: 1.25rem; }
.sheet-symbol--danger { background: #fff0f0; color: #bc2d2d; }
.sheet-card h2 { margin: .85rem 0 .25rem; padding-inline-end: 2rem; font-size: 1.15rem; font-weight: 950; }
.sheet-card > p { margin: 0 0 1rem; color: #6d7b73; font-size: .78rem; line-height: 1.7; }
.sheet-card label { display: grid; gap: .35rem; margin-top: .75rem; }
.sheet-card label > span { font-size: .75rem; font-weight: 850; }
.sheet-card label small { color: #8d9992; font-weight: 600; }
.sheet-card select, .sheet-card input, .sheet-card textarea { width: 100%; min-height: 48px; border: 1.5px solid #dfe6e1; border-radius: 13px; padding: .65rem .75rem; background: #fbfcfb; color: #17251d; font: inherit; font-size: .82rem; outline: 0; }
.sheet-card textarea { min-height: 84px; resize: vertical; }
.sheet-card select:focus, .sheet-card input:focus, .sheet-card textarea:focus { border-color: rgba(var(--primary-rgb, 15, 71, 49), .65); box-shadow: 0 0 0 4px rgba(var(--primary-rgb, 15, 71, 49), .08); }
.sheet-actions { display: flex; gap: .55rem; margin-top: 1.15rem; }
.sheet-button { min-height: 48px; flex: 1; border: 0; border-radius: 13px; padding: 0 .8rem; background: #eff3f0; color: #45544c; font: inherit; font-size: .8rem; font-weight: 850; cursor: pointer; }
.sheet-button--primary { background: var(--track-primary); color: #fff; }
.sheet-button--danger { background: #c93939; color: #fff; }
.sheet-button:disabled { opacity: .5; cursor: not-allowed; }
.sheet-enter-active, .sheet-leave-active { transition: opacity .18s ease; }
.sheet-enter-active .sheet-card, .sheet-leave-active .sheet-card { transition: transform .18s ease, opacity .18s ease; }
.sheet-enter-from, .sheet-leave-to { opacity: 0; }
.sheet-enter-from .sheet-card, .sheet-leave-to .sheet-card { transform: translateY(14px) scale(.985); opacity: 0; }

@media (max-width: 860px) {
    .track-shell { grid-template-columns: 1fr; }
    .quick-panel { display: none; }
    .mobile-dock { position: fixed; z-index: 1000; inset-inline: 0; bottom: 0; display: grid; grid-template-columns: repeat(3, 1fr); gap: .35rem; padding: .55rem max(.7rem, env(safe-area-inset-right)) calc(.55rem + env(safe-area-inset-bottom)) max(.7rem, env(safe-area-inset-left)); border-top: 1px solid #dfe7e2; background: rgba(255, 255, 255, .94); backdrop-filter: blur(16px); box-shadow: 0 -10px 35px rgba(25, 52, 37, .08); }
    .mobile-dock a, .mobile-dock button { min-height: 52px; border: 0; border-radius: 14px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .15rem; background: transparent; color: #53635a; text-decoration: none; font: inherit; font-size: .68rem; font-weight: 850; }
    .mobile-dock i { font-size: 1rem; }
    .mobile-dock .is-primary { background: var(--track-primary); color: #fff; }
    .mobile-dock button.is-sent { color: #147248; background: #edf9f2; }
}
@media (max-width: 560px) {
    .track-page { padding: .55rem .7rem 6.2rem; }
    .track-header { min-height: 58px; margin-bottom: .55rem; }
    .brand-mark { width: 40px; height: 40px; border-radius: 13px; }
    .brand small { display: none; }
    .table-pill { min-height: 38px; padding: 0 .65rem; }
    .header-link { width: 38px; min-height: 38px; justify-content: center; padding: 0; }
    .header-link span { display: none; }
    .status-hero { min-height: 168px; border-radius: 23px; padding: 1.15rem; }
    .status-copy h1 { font-size: 1.65rem; }
    .status-copy p { font-size: .78rem; line-height: 1.65; }
    .status-orbit { width: 76px; height: 76px; flex-basis: 76px; }
    .status-orbit span { width: 48px; height: 48px; border-radius: 16px; font-size: 1.2rem; }
    .status-facts span { font-size: .66rem; }
    .sheet-backdrop { align-items: flex-end; padding: 0; }
    .sheet-card { width: 100%; max-height: 92dvh; border-radius: 24px 24px 0 0; padding: 1.15rem 1rem calc(1rem + env(safe-area-inset-bottom)); }
    .sheet-actions { flex-direction: column-reverse; }
}
@media (prefers-reduced-motion: reduce) {
    .status-orbit::before { animation: none; }
    *, *::before, *::after { scroll-behavior: auto !important; }
}
</style>
