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
const restaurantBrand = computed(() => page.props.restaurantBrand ?? { name: 'مطعم دار السرو', logo: '/brand/dar-al-sarw-mark.svg' });

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
        <header class="track-header">
            <Link :href="urls.menu" class="brand" aria-label="العودة إلى المنيو" view-transition>
                <span class="brand-mark"><img :src="restaurantBrand.logo || '/brand/dar-al-sarw-mark.svg'" alt=""></span>
                <span>
                    <strong>طلباتك</strong>
                    <small>{{ restaurantBrand.name }}</small>
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
    --track-primary: var(--brand);
    --track-ink: var(--ink);
    --track-muted: var(--muted);
    --track-line: var(--line);
    min-height: 100dvh;
    position: relative;
    overflow-x: hidden;
    color: var(--track-ink);
    background: var(--canvas);
    padding: 1rem 1rem 7rem;
}
.track-glow { position: fixed; border-radius: 12px; filter: blur(90px); pointer-events: none; opacity: .25; display: none; }
.track-glow--one { width: 26rem; height: 26rem; background: color-mix(in srgb, var(--brand) 25%, transparent); top: -15rem; inset-inline-end: -9rem; }
.track-glow--two { width: 20rem; height: 20rem; background: rgba(212, 165, 80, .18); bottom: -12rem; inset-inline-start: -8rem; }
.track-header, .track-shell { width: min(1160px, 100%); margin-inline: auto; position: relative; z-index: 1; }
.track-header { min-height: 64px; display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.brand { display: inline-flex; align-items: center; gap: .7rem; text-decoration: none; color: inherit; }
.brand-mark { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; background: var(--brand-soft); color: #FFFDFA; box-shadow: none; }
.brand strong, .brand small { display: block; }
.brand strong { font-size: 1rem; font-weight: 800; }
.brand small { color: var(--track-muted); font-size: .875rem; margin-top: .08rem; }
.header-actions { display: flex; align-items: center; gap: .55rem; }
.table-pill, .header-link { min-height: 42px; border: 1px solid var(--track-line); background: rgba(255, 255, 255, .86); border-radius: 8px; display: inline-flex; align-items: center; gap: .4rem; padding: 0 .8rem; color: var(--ink); font-size: .875rem; font-weight: 800; text-decoration: none; }
.header-link i { color: var(--track-primary); }
.track-shell { display: grid; grid-template-columns: minmax(0, 1fr) 320px; align-items: start; gap: 1.25rem; }
.track-main { min-width: 0; display: grid; gap: 1rem; }
.status-hero { min-height: 190px; border-radius: 12px; padding: clamp(1.25rem, 3vw, 2rem); overflow: hidden; position: relative; display: flex; justify-content: space-between; align-items: center; gap: 1rem; color: #FFFDFA; background: var(--brand-dark); box-shadow: none; border-inline-start: 4px solid var(--accent); }
.status-hero::after { content: ''; position: absolute; width: 280px; height: 280px; border: 1px solid rgba(255, 255, 255, .12); border-radius: 50%; inset-inline-end: -90px; top: -140px; box-shadow: 0 0 0 36px rgba(255, 255, 255, .035), 0 0 0 74px rgba(255, 255, 255, .025); display: none; }
.status-copy { position: relative; z-index: 1; }
.live-pill { width: fit-content; display: flex; align-items: center; gap: .4rem; padding: 0; border-radius: 0; background: transparent; font-size: .875rem; font-weight: 800; color: #E9D9BF; }
.live-pill i, .connection-note i { width: 7px; height: 7px; border-radius: 50%; background: var(--accent); box-shadow: none; }
.status-copy h1 { margin: .75rem 0 .28rem; font-size: clamp(1.65rem, 4vw, 2.45rem); font-weight: 800; letter-spacing: -.035em; }
.status-copy p { margin: 0; max-width: 32rem; color: rgba(255, 255, 255, .77); font-size: .88rem; line-height: 1.75; }
.status-facts { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: 1rem; }
.status-facts span { border: 1px solid rgba(255, 255, 255, .15); background: rgba(255, 255, 255, .09); border-radius: 10px; padding: .38rem .62rem; font-size: .875rem; }
.status-facts strong { font-size: .88rem; margin-inline-end: .18rem; }
.status-facts .is-ready { background: rgb(255 253 250 / 12%); color: #FFFDFA; }
.status-orbit { width: 72px; height: 72px; flex: 0 0 72px; position: relative; z-index: 1; border-radius: 12px; display: grid; place-items: center; border: 0; background: rgb(255 253 250 / 8%); }
.status-orbit::before { content: ''; position: absolute; inset: 10px; border-radius: inherit; border: 1px dashed rgba(255, 255, 255, .25); animation: orbit 14s linear infinite; display: none; }
.status-orbit span { width: 58px; height: 58px; border-radius: 12px; display: grid; place-items: center; background: transparent; color: #E9D9BF; font-size: 1.55rem; box-shadow: none; }
.status-orbit.is-ready span { color: #E9D9BF; }
@keyframes orbit { to { transform: rotate(360deg); } }
.section-heading { display: flex; justify-content: space-between; align-items: end; padding: .4rem .25rem 0; }
.section-heading span, .quick-label { display: block; color: var(--muted); font-size: .875rem; font-weight: 800; }
.section-heading h2, .quick-card h2 { margin: .12rem 0 0; font-size: 1.08rem; font-weight: 800; }
.refresh-button { width: 42px; height: 42px; border: 1px solid var(--track-line); border-radius: 8px; background: var(--surface); color: var(--track-primary); cursor: pointer; }
.order-list { display: grid; gap: .85rem; }
.track-empty { min-height: 360px; border: 1px solid var(--track-line); border-radius: 12px; background: var(--surface); display: grid; justify-items: center; align-content: center; text-align: center; padding: 2rem; }
.empty-icon { width: 72px; height: 72px; border-radius: 12px; display: grid; place-items: center; background: color-mix(in srgb, var(--brand) 8%, transparent); color: var(--track-primary); font-size: 1.8rem; }
.track-empty h2 { margin: 1rem 0 .25rem; font-size: 1.2rem; font-weight: 800; }
.track-empty p { margin: 0 0 1rem; max-width: 24rem; color: var(--track-muted); font-size: .875rem; line-height: 1.7; }
.primary-action { min-height: 46px; border-radius: 8px; padding: 0 1rem; display: inline-flex; align-items: center; justify-content: center; gap: .45rem; background: var(--track-primary); color: #FFFDFA; font-size: .875rem; font-weight: 800; text-decoration: none; }
.quick-panel { position: sticky; top: 1rem; display: grid; gap: .75rem; }
.quick-card { border: 1px solid var(--track-line); border-radius: 12px; background: var(--surface); padding: 1.1rem; box-shadow: none; }
.quick-card h2 { margin-bottom: 1rem; }
.waiter-action, .quick-action { width: 100%; min-height: 72px; border-radius: 8px; padding: .65rem; display: flex; align-items: center; gap: .7rem; text-align: start; text-decoration: none; font: inherit; }
.waiter-action { border: 0; background: var(--track-primary); color: #FFFDFA; cursor: pointer; }
.waiter-action.is-sent { background: var(--canvas); color: var(--brand); border: 1px solid var(--brand); }
.waiter-action:disabled { cursor: default; }
.quick-action { margin-top: .55rem; border: 1px solid var(--track-line); color: var(--track-ink); background: var(--surface); }
.quick-action--bill { border-color: color-mix(in srgb, var(--brand) 20%, transparent); background: color-mix(in srgb, var(--brand) 4.5%, transparent); }
.action-icon { width: 40px; height: 40px; flex: 0 0 40px; border-radius: 8px; display: grid; place-items: center; background: rgba(255, 255, 255, .15); }
.quick-action .action-icon { background: var(--canvas); color: var(--track-primary); }
.waiter-action span:nth-child(2), .quick-action span:nth-child(2) { flex: 1; min-width: 0; }
.waiter-action strong, .waiter-action small, .quick-action strong, .quick-action small { display: block; }
.waiter-action strong, .quick-action strong { font-size: .875rem; font-weight: 800; }
.waiter-action small, .quick-action small { margin-top: .12rem; font-size: .875rem; opacity: .72; }
.connection-note { display: flex; align-items: center; justify-content: center; gap: .45rem; margin: 0; color: var(--muted); font-size: .875rem; }
.finished-orders { border: 1px solid var(--track-line); border-radius: 12px; background: rgba(255, 255, 255, .72); overflow: hidden; }
.finished-orders summary { min-height: 58px; padding: 0 1rem; display: flex; align-items: center; gap: .55rem; list-style: none; cursor: pointer; color: var(--muted); font-size: .875rem; font-weight: 800; }
.finished-orders summary::-webkit-details-marker { display: none; }
.finished-orders summary > span:first-child { flex: 1; }
.finished-count { min-width: 26px; height: 26px; border-radius: 9px; display: grid; place-items: center; background: var(--canvas); font-size: .875rem; }
.finished-chevron { transition: transform .2s; }
.finished-orders[open] .finished-chevron { transform: rotate(180deg); }
.order-list--finished { padding: 0 .7rem .7rem; opacity: .88; }
.mobile-dock { display: none; background: var(--surface); }

.sheet-backdrop { position: fixed; z-index: 20000; inset: 0; padding: 1rem; display: flex; align-items: center; justify-content: center; background: rgba(13, 28, 20, .55); backdrop-filter: blur(5px); }
.sheet-card { width: min(520px, 100%); max-height: calc(100dvh - 2rem); overflow-y: auto; position: relative; border-radius: 12px; background: var(--surface); padding: 1.3rem; color: var(--ink); box-shadow: none; }
.sheet-close { position: absolute; top: 1rem; inset-inline-end: 1rem; width: 38px; height: 38px; border: 0; border-radius: 12px; background: var(--canvas); color: var(--muted); cursor: pointer; }
.sheet-symbol { width: 50px; height: 50px; border-radius: 12px; display: grid; place-items: center; background: color-mix(in srgb, var(--brand) 8%, transparent); color: var(--track-primary); font-size: 1.25rem; }
.sheet-symbol--danger { background: var(--canvas); color: #bc2d2d; }
.sheet-card h2 { margin: .85rem 0 .25rem; padding-inline-end: 2rem; font-size: 1.15rem; font-weight: 800; }
.sheet-card > p { margin: 0 0 1rem; color: var(--muted); font-size: .875rem; line-height: 1.7; }
.sheet-card label { display: grid; gap: .35rem; margin-top: .75rem; }
.sheet-card label > span { font-size: .875rem; font-weight: 800; }
.sheet-card label small { color: var(--muted); font-weight: 600; }
.sheet-card select, .sheet-card input, .sheet-card textarea { width: 100%; min-height: 48px; border: 1.5px solid var(--line); border-radius: 8px; padding: .65rem .75rem; background: var(--surface); color: var(--ink); font: inherit; font-size: .875rem; outline: 0; }
.sheet-card textarea { min-height: 84px; resize: vertical; }
.sheet-card select:focus, .sheet-card input:focus, .sheet-card textarea:focus { border-color: color-mix(in srgb, var(--brand) 65%, transparent); box-shadow: 0 0 0 4px color-mix(in srgb, var(--brand) 8%, transparent); }
.sheet-actions { display: flex; gap: .55rem; margin-top: 1.15rem; }
.sheet-button { min-height: 48px; flex: 1; border: 0; border-radius: 8px; padding: 0 .8rem; background: var(--canvas); color: var(--ink); font: inherit; font-size: .875rem; font-weight: 800; cursor: pointer; }
.sheet-button--primary { background: var(--track-primary); color: #FFFDFA; }
.sheet-button--danger { background: #c93939; color: #FFFDFA; }
.sheet-button:disabled { opacity: .5; cursor: not-allowed; }
.sheet-enter-active, .sheet-leave-active { transition: opacity .18s ease; }
.sheet-enter-active .sheet-card, .sheet-leave-active .sheet-card { transition: transform .18s ease, opacity .18s ease; }
.sheet-enter-from, .sheet-leave-to { opacity: 0; }
.sheet-enter-from .sheet-card, .sheet-leave-to .sheet-card { transform: translateY(14px) scale(.985); opacity: 0; }

@media (max-width: 860px) {
    .track-shell { grid-template-columns: 1fr; }
    .quick-panel { display: none; }
    .mobile-dock { position: fixed; z-index: 1000; inset-inline: 0; bottom: 0; display: grid; grid-template-columns: repeat(3, 1fr); gap: .35rem; padding: .55rem max(.7rem, env(safe-area-inset-right)) calc(.55rem + env(safe-area-inset-bottom)) max(.7rem, env(safe-area-inset-left)); border-top: 1px solid var(--line); background: rgba(255, 255, 255, .94); backdrop-filter: blur(16px); box-shadow: none; }
    .mobile-dock a, .mobile-dock button { min-height: 52px; border: 0; border-radius: 8px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: .15rem; background: transparent; color: var(--brand); text-decoration: none; font: inherit; font-size: .875rem; font-weight: 800; }
    .mobile-dock i { font-size: 1rem; }
    .mobile-dock .is-primary { background: var(--track-primary); color: #FFFDFA; }
    .mobile-dock button.is-sent { color: var(--brand); background: var(--canvas); }
}
@media (max-width: 560px) {
    .track-page { padding: .55rem .7rem 6.2rem; }
    .track-header { min-height: 58px; margin-bottom: .55rem; }
    .brand-mark { width: 40px; height: 40px; border-radius: 12px; }
    .brand small { display: none; }
    .table-pill { min-height: 38px; padding: 0 .65rem; }
    .header-link { width: 38px; min-height: 38px; justify-content: center; padding: 0; }
    .header-link span { display: none; }
    .status-hero { min-height: 168px; border-radius: 12px; padding: 1.15rem; }
    .status-copy h1 { font-size: 1.65rem; }
    .status-copy p { font-size: .875rem; line-height: 1.65; }
    .status-orbit { width: 76px; height: 76px; flex-basis: 76px; }
    .status-orbit span { width: 48px; height: 48px; border-radius: 12px; font-size: 1.2rem; }
    .status-facts span { font-size: .875rem; }
    .sheet-backdrop { align-items: flex-end; padding: 0; }
    .sheet-card { width: 100%; max-height: 92dvh; border-radius: 12px 12px 0 0; padding: 1.15rem 1rem calc(1rem + env(safe-area-inset-bottom)); }
    .sheet-actions { flex-direction: column-reverse; }
}
@media (prefers-reduced-motion: reduce) {
    .status-orbit::before { animation: none; }
    *, *::before, *::after { scroll-behavior: auto !important; }
}
.brand-mark img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: .3rem;
    box-sizing: border-box;
}

/* Operational layout and responsive hierarchy. */
.track-page{max-width:1180px;margin:auto;padding:24px 24px 90px}.track-header{padding:0 0 20px;border-bottom:1px solid var(--line);margin-bottom:24px}.track-shell{grid-template-columns:minmax(0,1fr) 260px;gap:24px}.status-hero{display:flex;align-items:center;gap:18px;padding:22px;border:1px solid var(--line);border-inline-start:4px solid var(--brand);border-radius:8px;background:var(--surface);color:var(--ink)}.status-copy h1{font-size:28px;color:var(--ink);margin:10px 0}.status-copy p{font-size:15px;color:var(--muted)}.live-pill{color:var(--muted);background:transparent;padding:0}.status-orbit{order:-1;flex:0 0 48px;width:48px;height:48px}.status-orbit>span{width:48px;height:48px;border-radius:8px;background:var(--brand-soft);color:var(--brand);font-size:23px}.status-facts{border-top:1px solid var(--line);padding-top:14px;gap:20px}.status-facts>span{border:0;background:transparent;padding:0;color:var(--muted)}.status-facts strong{color:var(--ink)}.quick-panel{position:sticky;top:20px;align-self:start}.quick-card{padding:18px;border-radius:8px;background:var(--surface);border:1px solid var(--line)}.quick-card h2{font-size:18px;margin:0 0 14px}.quick-action,.waiter-action{padding:14px 0;border:0;border-bottom:1px solid var(--line);border-radius:0;background:transparent;color:var(--ink)}.action-icon{width:32px;height:32px;background:var(--brand-soft);color:var(--brand);border-radius:6px}.quick-action small,.waiter-action small{font-size:14px}.order-list{gap:16px}.section-heading{margin:24px 0 14px}.section-heading h2{font-size:21px}@media(max-width:800px){.track-shell{grid-template-columns:1fr}.quick-panel{display:none}.track-page{padding:16px 14px 100px}.status-hero{padding:16px}.status-copy h1{font-size:23px}.status-orbit{display:none}.mobile-dock{display:flex;border-top:1px solid var(--line);border-radius:0}}
</style>
