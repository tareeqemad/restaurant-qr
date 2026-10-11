<script setup>
/**
 * منيو الزبون — Wave 2's centerpiece. The 2,752-line Alpine page reborn:
 * same contracts (server-priced promos, live stock, add-time stock gate,
 * tax display modes and remembered customer details), redesigned surface
 * (the /cart page is now a sheet, categories are calm responsive grids,
 * search filters everything in place).
 *
 * Self-contained on the bare inertia root — customers never see the admin
 * shell. A 419 from any cart call flips the session-expired overlay.
 */
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import CartSheet from '../../Components/CustomerMenu/CartSheet.vue';
import DishCard from '../../Components/CustomerMenu/DishCard.vue';
import ItemSheet from '../../Components/CustomerMenu/ItemSheet.vue';
import OrderTrackingSheet from '../../Components/CustomerMenu/OrderTrackingSheet.vue';
import Toaster from '../../Components/Ui/Toaster.vue';
import { useCustomerCart } from '../../Composables/useCustomerCart';
import { useToast } from '../../Composables/useToast';

const props = defineProps({
    brand: { type: Object, required: true },
    sessionInfo: { type: Object, required: true },
    money: { type: Object, required: true },
    activeOrdersCount: { type: Number, default: 0 },
    sessionOrders: { type: Array, default: () => [] },
    featured: { type: Array, required: true },
    categories: { type: Array, required: true },
    cart: { type: Array, required: true },
    submitToken: { type: String, required: true },
    i18n: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const toast = useToast();
const page = usePage();
const liveSessionInfo = ref({ ...props.sessionInfo });
const helpPending = ref(Boolean(liveSessionInfo.value.helpPending));
const orderingEnabled = computed(() => liveSessionInfo.value.canOrder !== false);
const submittedOrders = ref([...props.sessionOrders]);
const nextSubmitToken = ref(props.submitToken);
const submittingOrder = ref(false);
const activeStatuses = new Set(['pending', 'approved', 'preparing', 'ready', 'delivered']);
const currentActiveOrdersCount = computed(() => submittedOrders.value
    .filter((order) => activeStatuses.has(order.status)).length);
const trackVersion = ref(null);
const orderPulseBusy = ref(false);
const liveOrderMessage = ref('');

const t = (key, repl = {}) => {
    let text = props.i18n[key] ?? null;
    if (text === null) return null;
    Object.entries(repl).forEach(([name, value]) => {
        text = text.replaceAll(':' + name, value);
    });
    return text;
};

// ── Session death → rescue overlay ───────────────────────────────────
const sessionDead = ref(false);
const markOrderingLocked = (message) => {
    liveSessionInfo.value = { ...liveSessionInfo.value, canOrder: false };
    toast.warning(message ?? 'الإضافات متاحة من الهاتف الذي بدأ جلسة الطلب أو من فريق الصالة.');
};

// ── Cart ─────────────────────────────────────────────────────────────
const cart = useCustomerCart({
    urls: props.urls,
    initial: props.cart,
    onSessionExpired: () => { sessionDead.value = true; },
    onOrderingLocked: markOrderingLocked,
    onRejected: (message) => toast.warning(message),
});

const firstValidationMessage = (data) => {
    const firstError = data?.errors ? Object.values(data.errors).flat()[0] : null;
    return firstError ?? data?.message ?? 'تعذّر إرسال الطلب — جرّب مرة ثانية.';
};

const submitOrder = async (payload) => {
    if (submittingOrder.value || cart.rows.length === 0 || ! orderingEnabled.value) return;
    submittingOrder.value = true;

    try {
        const response = await fetch(props.urls.cartSubmit, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({ ...payload, _idem: nextSubmitToken.value }),
        });
        const data = await response.json().catch(() => null);

        if (response.status === 419) {
            sessionDead.value = true;
            return;
        }
        if (response.status === 409 && data?.error === 'ordering_device_locked') {
            markOrderingLocked(data.message);
            return;
        }
        if (! response.ok || data?.ok === false) {
            toast.warning(firstValidationMessage(data));
            return;
        }

        if (Array.isArray(data?.orders)) submittedOrders.value = data.orders;
        if (data?.submitToken) nextSubmitToken.value = data.submitToken;
        if (data?.sessionInfo) {
            liveSessionInfo.value = { ...liveSessionInfo.value, ...data.sessionInfo };
        }

        cart.clear();
        cartOpen.value = false;
        // Stay on the table's QR URL, but immediately show the authoritative saved round.
        // This makes the send feel like an app transition and removes the
        // common doubt: «هل وصل الطلب أم اختفى؟».
        trackingOpen.value = true;
        const acceptedRound = Array.isArray(data?.orders) ? data.orders[0]?.roundNumber : null;
        toast.success(acceptedRound
            ? `وصلت الجولة ${acceptedRound} لفريق الصالة؛ راجع أصنافها وحالتها هنا.`
            : (data?.message ?? 'وصلت الجولة لفريق الصالة، وستبقى ظاهرة هنا.'));
    } catch {
        toast.error(t('offline_note') ?? 'انقطع الاتصال — الطلب بقي في سلتك، جرّب مرة ثانية.');
    } finally {
        submittingOrder.value = false;
    }
};

// ── Sheets ───────────────────────────────────────────────────────────
const sheetItem = ref(null);
const cartOpen = ref(false);
const trackingOpen = ref(false);

const announceOrderTransitions = (nextOrders) => {
    const previous = new Map(submittedOrders.value.map((order) => [Number(order.id), order.status]));
    let message = '';

    for (const order of nextOrders) {
        const oldStatus = previous.get(Number(order.id));
        if (! oldStatus || oldStatus === order.status) continue;
        if (order.status === 'ready') {
            message = order.roundNumber
                ? `الجولة ${order.roundNumber} جاهزة وستصل إلى طاولتك الآن.`
                : 'طلبك جاهز وسيصل إلى طاولتك الآن.';
            break;
        }
        if (['delivered', 'completed'].includes(order.status)) {
            message = order.roundNumber
                ? `تم تقديم الجولة ${order.roundNumber}. صحتين وعافية!`
                : 'تم تقديم طلبك. صحتين وعافية!';
        } else if (['approved', 'preparing'].includes(order.status) && oldStatus === 'pending' && ! message) {
            message = order.roundNumber
                ? `بدأ تجهيز الجولة ${order.roundNumber}.`
                : 'بدأ تجهيز طلبك.';
        }
    }

    if (! message) return;
    liveOrderMessage.value = message;
    toast.success(message);
    try { navigator.vibrate?.([90, 60, 90]); } catch { /* unsupported device */ }
};

const refreshOrdersInBackground = async () => {
    const response = await fetch(props.urls.trackData, {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (response.status === 419) {
        sessionDead.value = true;
        return;
    }

    const data = response.ok ? await response.json().catch(() => null) : null;
    if (! data) return;

    const nextOrders = Array.isArray(data.orders) ? data.orders : [];
    announceOrderTransitions(nextOrders);
    submittedOrders.value = nextOrders;
    trackVersion.value = data.version ?? trackVersion.value;
    if (data.sessionInfo) {
        liveSessionInfo.value = { ...liveSessionInfo.value, ...data.sessionInfo };
        helpPending.value = Boolean(data.sessionInfo.helpPending);
    }
};

const checkOrderPulse = async () => {
    if (trackingOpen.value || document.hidden || submittedOrders.value.length === 0 || orderPulseBusy.value) return;
    orderPulseBusy.value = true;
    try {
        const response = await fetch(props.urls.trackPulse, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        if (response.status === 419) {
            sessionDead.value = true;
            return;
        }
        const data = response.ok ? await response.json().catch(() => null) : null;
        if (data && (trackVersion.value === null || data.version !== trackVersion.value)) {
            await refreshOrdersInBackground();
        }
    } catch { /* Keep the menu calm; the next visible pulse retries. */ }
    finally { orderPulseBusy.value = false; }
};

const openItem = (item) => { sheetItem.value = item; };

const onPlus = async (item) => {
    if (! orderingEnabled.value) return;
    if (item.has_modifiers || item.removable_ingredients?.length) {
        openItem(item);
        return;
    }
    // Same merge rule as the old quickAdd: a plain row of this item (no
    // modifiers, no note) gets its quantity bumped instead of duplicating.
    const plain = cart.rows.find((r) => Number(r.menu_item_id) === Number(item.id)
        && ! (r.modifier_ids?.length) && ! (r.excluded_ingredient_ids?.length) && ! r.notes && ! r._pending);
    if (plain) {
        cart.setQuantity(plain, plain.quantity + 1);
        return;
    }
    const ok = await cart.add({ item, quantity: 1 });
    if (ok) toast.success(`${item.name} ${t('added_suffix') ?? '— انضاف للسلة'}`);
};

const onMinus = (item) => {
    if (! orderingEnabled.value) return;
    const rows = cart.rows.filter((r) => Number(r.menu_item_id) === Number(item.id));
    if (rows.length === 0) return;
    const last = rows[rows.length - 1];
    if (last.quantity > 1) cart.setQuantity(last, last.quantity - 1);
    else cart.remove(last);
};

const onSheetAdd = async (line) => {
    if (! orderingEnabled.value) return;
    const ok = await cart.add(line);
    if (ok) {
        sheetItem.value = null;
        toast.success(`${line.item.name} ${t('added_suffix') ?? '— انضاف للسلة'}`);
    }
};

// ── Search + category filter ─────────────────────────────────────────
const search = ref('');

const matches = (item) => {
    const q = search.value.trim().toLowerCase();
    if (! q) return true;
    return [item.name, item.description, ...(item.allergens ?? []), ...(item.ingredients ?? [])]
        .filter(Boolean)
        .some((v) => String(v).toLowerCase().includes(q));
};

const visibleCategories = computed(() => props.categories
    .map((c) => ({ ...c, items: c.items.filter(matches) }))
    .filter((c) => c.items.length > 0));

const visibleFeatured = computed(() => props.featured.filter(matches));

// ── Featured mobile slider — native swipe + accessible controls ─────
const featuredTrack = ref(null);
const featuredIndex = ref(0);

const goToFeatured = (index) => {
    const count = visibleFeatured.value.length;
    if (! count) return;
    featuredIndex.value = Math.min(count - 1, Math.max(0, index));
    featuredTrack.value?.children?.[featuredIndex.value]?.scrollIntoView({
        behavior: 'smooth',
        block: 'nearest',
        inline: 'center',
    });
};

const moveFeatured = (delta) => goToFeatured(featuredIndex.value + delta);
const syncFeaturedIndex = () => {
    const track = featuredTrack.value;
    if (! track?.children?.length) return;
    const center = track.getBoundingClientRect().left + track.clientWidth / 2;
    let closest = 0;
    let distance = Number.POSITIVE_INFINITY;
    Array.from(track.children).forEach((child, index) => {
        const rect = child.getBoundingClientRect();
        const nextDistance = Math.abs((rect.left + rect.width / 2) - center);
        if (nextDistance < distance) {
            distance = nextDistance;
            closest = index;
        }
    });
    featuredIndex.value = closest;
};

// ── Category chips + scrollspy ───────────────────────────────────────
const activeCat = ref(null);
let observer = null;
const menuScrollKey = 'qr-customer-menu-scroll';

const rememberMenuPosition = () => {
    try { sessionStorage.setItem(menuScrollKey, String(window.scrollY)); } catch { /* private browsing */ }
};

const scrollToCat = (id) => {
    document.getElementById(`cat-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

let orderPulseTimer = null;
onMounted(() => {
    observer = new IntersectionObserver((entries) => {
        for (const entry of entries) {
            if (entry.isIntersecting) {
                activeCat.value = entry.target.dataset.cat;
                break;
            }
        }
    }, { rootMargin: '-25% 0px -65% 0px' });

    document.querySelectorAll('[data-cat]').forEach((el) => observer.observe(el));
    orderPulseTimer = window.setInterval(checkOrderPulse, 5000);

    try {
        const savedScroll = Number(sessionStorage.getItem(menuScrollKey));
        if (Number.isFinite(savedScroll) && savedScroll > 0) {
            window.requestAnimationFrame(() => window.scrollTo(0, savedScroll));
        }
        sessionStorage.removeItem(menuScrollKey);
    } catch { /* private browsing */ }
});
onBeforeUnmount(() => {
    observer?.disconnect();
    window.clearInterval(orderPulseTimer);
});

// ── Call waiter ──────────────────────────────────────────────────────
const calling = ref(false);
const callWaiter = async () => {
    if (calling.value || helpPending.value) return;
    calling.value = true;
    try {
        const res = await fetch(props.urls.callWaiter, {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
        });
        const data = await res.json().catch(() => null);
        if (res.status === 419) sessionDead.value = true;
        else if (res.ok && data?.ok !== false) {
            helpPending.value = true;
            toast.success(data?.message ?? t('waiter_called') ?? 'ناديناله — الجرسون جاي 🙌');
        } else {
            toast.warning(data?.message ?? t('waiter_call_failed') ?? 'ما قدرنا نوصل النداء — جرب كمان مرة.');
        }
    } catch {
        toast.error(t('offline_note') ?? 'انقطع الاتصال — جرب كمان مرة.');
    } finally {
        setTimeout(() => { calling.value = false; }, 4000); // debounce repeat taps
    }
};

const fmt = (n) => (Number(n) || 0).toFixed(2);
</script>

<template>
    <Head :title="t('title') ?? 'المنيو'" />

    <div class="qm">
        <p class="qm-live-status" aria-live="polite" aria-atomic="true">{{ liveOrderMessage }}</p>
        <!-- ── Topbar ─────────────────────────────────────────────── -->
        <header class="qm-top">
            <div class="qm-brand">
                <img :src="brand.logo || '/brand/dar-al-sarw-mark.svg'" :alt="brand.name || 'مطعم دار السرو'" class="qm-logo">
                <div class="qm-brand-copy">
                    <strong>{{ brand.name || 'مطعم دار السرو' }}</strong>
                    <small>
                        <i class="bi bi-geo-alt"></i>
                        {{ t('table_word') ?? 'طاولة' }} {{ liveSessionInfo.tableNumber }}
                        <template v-if="liveSessionInfo.branchName"> · {{ liveSessionInfo.branchName }}</template>
                    </small>
                </div>
            </div>

            <div class="qm-top-actions">
                <button v-if="currentActiveOrdersCount > 0" type="button" class="qm-chip qm-chip--track"
                        @click="trackingOpen = true">
                    <i class="bi bi-receipt-cutoff"></i>
                    {{ t('track_order') ?? 'طلباتي' }}
                    <span class="qm-chip-badge">{{ currentActiveOrdersCount }}</span>
                </button>
                <Link v-if="submittedOrders.length > 0" :href="urls.bill" class="qm-chip"
                      view-transition @click="rememberMenuPosition">
                    <i class="bi bi-wallet2"></i>
                    {{ t('bill_word') ?? 'الفاتورة' }}
                </Link>
                <button type="button" class="qm-chip qm-chip--call" :class="{ 'is-sent': helpPending }"
                        :disabled="calling || helpPending" @click="callWaiter">
                    <i class="bi" :class="calling ? 'bi-arrow-repeat qm-spin' : (helpPending ? 'bi-check2-circle' : 'bi-hand-index-thumb')"></i>
                    {{ calling ? 'جارٍ إرسال النداء…' : (helpPending ? 'الجرسون جاي' : (t('call_waiter') ?? 'نادِ الجرسون')) }}
                </button>
            </div>
        </header>

        <p v-if="liveSessionInfo.dinerName" class="qm-greet">
            {{ t('welcome_back') ?? 'أهلاً برجعتك،' }} <strong>{{ liveSessionInfo.dinerName }}</strong> 👋
        </p>

        <div v-if="! orderingEnabled" class="qm-order-lock" role="status">
            <i class="bi bi-phone"></i>
            <div>
                <strong>الطلب مفتوح من هاتف آخر</strong>
                <span>تقدر تتصفح المنيو وتتابع طلبات الطاولة. الإضافات من الهاتف الذي بدأ الجلسة أو من فريق الصالة.</span>
            </div>
        </div>

        <!-- Sent rounds stay visible while the diner builds the next one. -->
        <section v-if="submittedOrders.length" class="qm-orders" aria-label="طلبات الجلسة">
            <header class="qm-orders-head">
                <div>
                    <strong><i class="bi bi-receipt-cutoff"></i> طلبات جلستك</strong>
                    <small>كل إرسال جولة مستقلة للتنفيذ — وجميع الجولات على فاتورة واحدة</small>
                </div>
                <button type="button" @click="trackingOpen = true">
                    التفاصيل <i class="bi bi-chevron-left"></i>
                </button>
            </header>
            <div class="qm-orders-track">
                <article v-for="order in submittedOrders" :key="order.id"
                         class="qm-order-round" :class="`is-${order.status}`">
                    <header>
                        <strong>جولة {{ order.roundNumber }}</strong>
                        <span>{{ order.statusLabel }}</span>
                    </header>
                    <div class="qm-order-items">
                        <span v-for="item in order.items.slice(0, 3)" :key="item.id">
                            {{ item.name }} <b>×{{ item.qty }}</b>
                        </span>
                        <small v-if="order.items.length > 3">+ {{ order.items.length - 3 }} أصناف</small>
                    </div>
                    <footer>
                        <small>{{ order.createdAgo }}</small>
                        <strong>{{ order.total }}</strong>
                    </footer>
                </article>
            </div>
        </section>

        <section class="qm-menu-heading"><div><span>من مطبخنا إلى طاولتك</span><h1>قائمة الطعام</h1></div><span class="qm-table-number">طاولة <strong>{{ liveSessionInfo.tableNumber }}</strong></span></section>

        <p v-if="page.props.restaurantBrand?.demoNotice" class="qm-demo-note">
            {{ page.props.restaurantBrand.demoNotice }}
        </p>

        <!-- ── Sticky search + category chips ─────────────────────── -->
        <div class="qm-command">
            <div class="qm-search">
                <i class="bi bi-search"></i>
                <input v-model="search" type="text" :placeholder="t('search_placeholder') ?? 'دوّر على أكلة…'"
                       autocomplete="off" inputmode="search">
                <button v-if="search" type="button" aria-label="مسح" @click="search = ''">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>

            <nav v-if="! search" class="qm-cats" aria-label="الأقسام">
                <button v-if="visibleFeatured.length" type="button"
                        class="qm-cat" :class="{ 'is-active': activeCat === 'featured' }"
                        @click="scrollToCat('featured')">
                    <i class="bi bi-star-fill"></i> {{ t('featured') ?? 'المميز' }}
                </button>
                <button v-for="c in visibleCategories" :key="c.id" type="button"
                        class="qm-cat" :class="{ 'is-active': activeCat === String(c.id) }"
                        :style="c.color ? { '--cat-color': c.color } : null"
                        @click="scrollToCat(c.id)">
                    <i class="bi" :class="c.icon"></i> {{ c.label }}
                </button>
            </nav>
        </div>

        <!-- ── Featured ───────────────────────────────────────────── -->
        <section v-if="visibleFeatured.length" id="cat-featured" data-cat="featured" class="qm-section">
            <div class="qm-section-head">
                <h2 class="qm-section-title"><i class="bi bi-star-fill qm-star"></i> {{ t('featured') ?? 'المميز' }}</h2>
                <div v-if="visibleFeatured.length > 1" class="qm-slider-controls">
                    <button type="button" aria-label="الصنف السابق" :disabled="featuredIndex === 0" @click="moveFeatured(-1)">
                        <i class="bi bi-chevron-right"></i>
                    </button>
                    <span>{{ featuredIndex + 1 }} / {{ visibleFeatured.length }}</span>
                    <button type="button" aria-label="الصنف التالي" :disabled="featuredIndex === visibleFeatured.length - 1" @click="moveFeatured(1)">
                        <i class="bi bi-chevron-left"></i>
                    </button>
                </div>
            </div>
            <div ref="featuredTrack" class="qm-strip" role="region" aria-label="الأصناف المميزة"
                 @scroll.passive="syncFeaturedIndex">
                <DishCard v-for="item in visibleFeatured" :key="`f-${item.id}`"
                          class="qm-strip-card"
                          :item="item" :qty="cart.qtyOf(item.id)" :symbol="money.symbol" :t="t"
                          :ordering-enabled="orderingEnabled"
                          @open="openItem" @plus="onPlus" @minus="onMinus" />
            </div>
            <div v-if="visibleFeatured.length > 1" class="qm-slider-dots" aria-hidden="true">
                <button v-for="(_, index) in visibleFeatured" :key="index" type="button"
                        :class="{ 'is-active': featuredIndex === index }" tabindex="-1"
                        @click="goToFeatured(index)"></button>
            </div>
        </section>

        <!-- ── Categories ─────────────────────────────────────────── -->
        <section v-for="c in visibleCategories" :key="c.id"
                 :id="`cat-${c.id}`" :data-cat="String(c.id)" class="qm-section">
            <div class="qm-category-head">
                <h2 class="qm-section-title">
                    <span v-if="c.color" class="qm-cat-bar" :style="{ background: c.color }"></span>
                    <i class="bi" :class="c.icon"></i> {{ c.label }}
                    <small>{{ c.items.length }}</small>
                </h2>
                <span v-if="c.items.length > 1" class="qm-swipe-hint" aria-hidden="true">
                    <i class="bi bi-arrow-left-right"></i>
                    اسحب للتصفح
                </span>
            </div>
            <div class="qm-grid qm-category-track" role="region" :aria-label="`أصناف ${c.label}`">
                <DishCard v-for="item in c.items" :key="item.id"
                          class="qm-category-card"
                          :item="item" :qty="cart.qtyOf(item.id)" :symbol="money.symbol" :t="t"
                          :ordering-enabled="orderingEnabled"
                          @open="openItem" @plus="onPlus" @minus="onMinus" />
            </div>
        </section>

        <div v-if="visibleCategories.length === 0 && visibleFeatured.length === 0" class="qm-empty">
            <i class="bi" :class="search ? 'bi-search' : 'bi-journal-x'"></i>
            <p>{{ search ? (t('no_search_results') ?? 'ما في أكلة مطابقة') : (t('no_items_available') ?? 'المنيو فاضي حالياً') }}</p>
        </div>

        <div class="qm-bottom-spacer"></div>

        <!-- ── Cart FAB ───────────────────────────────────────────── -->
        <Transition name="qm-fab">
            <button v-if="orderingEnabled && cart.count.value > 0" type="button" class="qm-fab" @click="cartOpen = true">
                <span class="qm-fab-count">{{ cart.count.value }}</span>
                <span class="qm-fab-label">{{ t('view_cart') ?? 'شوف السلة' }}</span>
                <span class="qm-fab-total">{{ fmt(cart.total.value) }} {{ money.symbol }}</span>
            </button>
        </Transition>

        <!-- ── Session expired rescue ─────────────────────────────── -->
        <div v-if="sessionDead" class="qm-dead">
            <div class="qm-dead-card">
                <i class="bi bi-qr-code-scan"></i>
                <h3>{{ t('session_expired_title') ?? 'انتهت جلسة الطاولة' }}</h3>
                <p>{{ t('session_expired_body') ?? 'امسح رمز QR على الطاولة من جديد وبنرجعك على سلتك.' }}</p>
            </div>
        </div>
    </div>

    <ItemSheet :item="sheetItem" :symbol="money.symbol" :t="t" :busy="cart.busy.value"
               :ordering-enabled="orderingEnabled"
               @close="sheetItem = null" @add="onSheetAdd" />

    <CartSheet :open="cartOpen" :cart="cart" :money="money" :session-info="liveSessionInfo"
               :submitting="submittingOrder" :has-previous-orders="submittedOrders.length > 0" :t="t"
               :ordering-enabled="orderingEnabled"
               @close="cartOpen = false" @submit="submitOrder" />

    <OrderTrackingSheet
        :open="trackingOpen"
        :urls="urls"
        :ordering-enabled="orderingEnabled"
        @close="trackingOpen = false"
        @orders="submittedOrders = $event"
        @session-expired="sessionDead = true"
        @ordering-locked="markOrderingLocked"
    />

    <Toaster />
</template>

<style scoped>
.qm-order-lock {
    display: flex;
    align-items: flex-start;
    gap: .65rem;
    margin: .7rem 0;
    padding: .72rem .8rem;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--canvas);
    color: #315B6B;
}
.qm-order-lock > i { margin-top: .08rem; }
.qm-order-lock div { display: flex; flex-direction: column; gap: .12rem; }
.qm-order-lock strong { font-size: .875rem; font-weight: 800; }
.qm-order-lock span { color: var(--ink); font-size: .875rem; line-height: 1.55; }
.qm {
    min-height: 100dvh;
    background: var(--canvas);
    color: var(--ink);
    padding: 1.25rem 1rem 0;
    max-width: 1180px;
    margin-inline: auto;
}
.qm-live-status {
    position: fixed;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

/* ── Topbar ── */
.qm-top { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .7rem; padding-bottom: 1rem; border-bottom: 1px solid var(--line); }
.qm-brand { display: flex; align-items: center; gap: .65rem; min-width: 0; }
.qm-logo { width: 54px; height: 54px; border-radius: 12px; object-fit: contain; background: var(--brand-soft); border: 0; padding: .25rem; }
.qm-brand-copy { display: flex; flex-direction: column; min-width: 0; }
.qm-brand-copy strong { font-size: 1.2rem; font-weight: 800; }
.qm-brand-copy small { color: var(--muted); font-size: .875rem; }
.qm-top-actions { display: flex; align-items: center; gap: .4rem; flex-wrap: wrap; }
.qm-chip {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    min-height: 44px;
    padding: 0 .8rem;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--surface);
    color: var(--ink);
    font-size: .875rem;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    font-family: inherit;
}
.qm-chip--track { border-color: color-mix(in srgb, var(--brand) 40%, transparent); color: var(--brand); }
.qm-chip-badge {
    background: var(--brand);
    color: #FFFDFA;
    border-radius: 8px;
    font-size: .875rem;
    padding: .08rem .42rem;
}
.qm-chip--call { background: var(--canvas); border-color: var(--accent); color: #92400e; }
.qm-chip--call:disabled { opacity: .55; }
.qm-chip--call.is-sent { background: var(--canvas); border-color: var(--brand); color: var(--brand); opacity: 1; }
.qm-spin { animation: qm-spin .8s linear infinite; }
@keyframes qm-spin { to { transform: rotate(360deg); } }

.qm-greet { margin: .6rem 0 0; font-size: .88rem; color: var(--ink); }

/* ── Session rounds ── */
.qm-orders {
    margin-top: .75rem;
    padding: .75rem;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--canvas);
}
.qm-orders-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .8rem;
    margin-bottom: .65rem;
}
.qm-orders-head > div { display: flex; flex-direction: column; min-width: 0; }
.qm-orders-head strong { display: flex; align-items: center; gap: .4rem; font-size: .9rem; font-weight: 800; }
.qm-orders-head strong i { color: var(--brand); }
.qm-orders-head small { margin-top: .15rem; color: #315B6B; font-size: .875rem; line-height: 1.45; }
.qm-orders-head > button {
    display: inline-flex;
    align-items: center;
    gap: .25rem;
    padding: .4rem .55rem;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: var(--brand);
    font-family: inherit;
    font-size: .875rem;
    font-weight: 800;
    white-space: nowrap;
    cursor: pointer;
}
.qm-orders-track {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
    gap: .55rem;
}
.qm-order-round {
    min-width: 0;
    padding: .65rem .7rem;
    border: 1px solid var(--line);
    border-inline-start: 4px solid #f59e0b;
    border-radius: 12px;
    background: var(--surface);
    scroll-snap-align: start;
}
.qm-order-round.is-approved, .qm-order-round.is-preparing { border-inline-start-color: #315B6B; }
.qm-order-round.is-ready { border-inline-start-color: var(--line); }
.qm-order-round.is-delivered, .qm-order-round.is-completed { border-inline-start-color: var(--line); }
.qm-order-round.is-cancelled { border-inline-start-color: #dc2626; opacity: .72; }
.qm-order-round > header, .qm-order-round > footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: .5rem;
}
.qm-order-round > header strong { font-size: .875rem; font-weight: 800; color: var(--ink); }
.qm-order-round > header span {
    padding: .12rem .45rem;
    border-radius: 12px;
    background: var(--canvas);
    color: #9a3412;
    font-size: .875rem;
    font-weight: 800;
}
.qm-order-round.is-approved > header span, .qm-order-round.is-preparing > header span { background: var(--canvas); color: #315B6B; }
.qm-order-round.is-ready > header span, .qm-order-round.is-delivered > header span,
.qm-order-round.is-completed > header span { background: var(--canvas); color: var(--ink); }
.qm-order-round.is-cancelled > header span { background: var(--canvas); color: #b91c1c; }
.qm-order-items {
    display: flex;
    flex-wrap: wrap;
    gap: .25rem .5rem;
    margin: .55rem 0;
    color: var(--ink);
    font-size: .875rem;
}
.qm-order-items span:not(:last-child)::after { content: '•'; margin-inline-start: .5rem; color: #FFFDFA; }
.qm-order-items b { color: var(--ink); }
.qm-order-items small { color: #315B6B; font-weight: 700; }
.qm-order-round > footer { padding-top: .45rem; border-top: 1px dashed var(--line); }
.qm-order-round > footer small { color: #315B6B; font-size: .875rem; }
.qm-order-round > footer strong { color: var(--brand); font-size: .875rem; font-weight: 800;  }

/* ── Command bar ── */
.qm-demo-note { margin: 1rem 0 0; color: var(--muted); font-size: .875rem; line-height: 1.7; }
.qm-command {
    position: sticky;
    top: 0;
    z-index: 100;
    background: var(--canvas);
    padding: .7rem 0 .5rem;
    display: flex;
    flex-direction: column;
    gap: .55rem;
    padding-block: .9rem;
}
.qm-search { position: relative; }
.qm-search > i {
    position: absolute;
    inset-inline-start: .9rem;
    top: 50%;
    transform: translateY(-50%);
    color: #315B6B;
}
.qm-search input {
    width: 100%;
    box-sizing: border-box;
    min-height: 46px;
    border: 1.5px solid var(--line);
    border-radius: 8px;
    padding-inline: 2.4rem;
    font: inherit;
    font-size: .9rem;
    background: var(--surface);
}
.qm-search input:focus { outline: none; border-color: var(--brand); }
.qm-search button {
    position: absolute;
    inset-inline-end: .4rem;
    top: 50%;
    transform: translateY(-50%);
    width: 36px;
    height: 36px;
    border: 0;
    border-radius: 8px;
    background: var(--canvas);
    color: #315B6B;
    cursor: pointer;
}
.qm-cats { display: flex; gap: .4rem; overflow-x: auto; scrollbar-width: none; padding-bottom: 2px; }
.qm-cats::-webkit-scrollbar { display: none; }
.qm-cat {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    min-height: 44px;
    padding: 0 .85rem;
    border: 1px solid var(--line);
    border-radius: 8px;
    background: var(--surface);
    color: var(--ink);
    font: inherit;
    font-size: .875rem;
    font-weight: 700;
    cursor: pointer;
    white-space: nowrap;
}
.qm-cat.is-active {
    background: var(--brand);
    border-color: var(--brand);
    color: #FFFDFA;
}

/* ── Sections ── */
.qm-section { margin-top: 1.1rem; scroll-margin-top: 120px; }
.qm-section-title {
    display: flex;
    align-items: center;
    gap: .5rem;
    margin: 0 0 .65rem;
    font-size: 1.2rem;
    font-weight: 800;
}
.qm-section-head { display: flex; align-items: center; justify-content: space-between; gap: .6rem; margin-bottom: .65rem; }
.qm-section-head .qm-section-title { margin-bottom: 0; }
.qm-category-head { display: flex; align-items: center; justify-content: space-between; gap: .7rem; margin-bottom: .65rem; }
.qm-category-head .qm-section-title { margin-bottom: 0; min-width: 0; }
.qm-swipe-hint {
    display: none;
    align-items: center;
    gap: .3rem;
    color: #315B6B;
    font-size: .875rem;
    font-weight: 750;
    white-space: nowrap;
}
.qm-slider-controls { display: flex; align-items: center; gap: .35rem; }
.qm-slider-controls button {
    width: 40px; height: 40px;
    display: inline-grid; place-items: center;
    border: 1px solid var(--line); border-radius: 8px;
    background: var(--surface); color: var(--brand); cursor: pointer;
}
.qm-slider-controls button:disabled { opacity: .35; cursor: default; }
.qm-slider-controls span { min-width: 42px; text-align: center; color: #315B6B; font-size: .875rem; font-weight: 800;  }
.qm-section-title small { color: #315B6B; font-weight: 700; }
.qm-cat-bar { width: 4px; height: 18px; border-radius: 2px; }
.qm-star { color: #b45309; }

.qm-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(210px, calc((100vw - 3rem) / 2)), 1fr));
    gap: 1rem;
}
.qm-strip {
    display: flex;
    gap: .65rem;
    overflow-x: auto;
    padding: .1rem .05rem .45rem;
    scroll-snap-type: x mandatory;
    scroll-padding-inline: .05rem;
    overscroll-behavior-inline: contain;
    scrollbar-width: none;
}
.qm-strip::-webkit-scrollbar { display: none; }
.qm-strip-card { flex: 0 0 min(240px, 68vw); scroll-snap-align: center; scroll-snap-stop: always; }
.qm-slider-dots { display: flex; justify-content: center; gap: 5px; margin-top: .35rem; }
.qm-slider-dots button { width: 6px; height: 6px; padding: 0; border: 0; border-radius: 8px; background: var(--surface-muted); }
.qm-slider-dots button.is-active { width: 20px; background: var(--brand); }

.qm-empty { text-align: center; color: #315B6B; padding: 3rem 1rem; }
.qm-empty i { font-size: 2.4rem; display: block; margin-bottom: .6rem; }
.qm-empty p { margin: 0; font-weight: 700; }

.qm-bottom-spacer { height: 96px; }

/* ── Cart FAB ── */
.qm-fab {
    position: fixed;
    bottom: calc(14px + env(safe-area-inset-bottom));
    inset-inline: 14px;
    max-width: 560px;
    margin-inline: auto;
    min-height: 54px;
    display: flex;
    align-items: center;
    gap: .7rem;
    padding: 0 1.1rem;
    border: 0;
    border-radius: 8px;
    background: var(--brand);
    color: #FFFDFA;
    font: inherit;
    font-size: .95rem;
    font-weight: 800;
    cursor: pointer;
    box-shadow: none;
    z-index: 1000;
}
.qm-fab-count {
    background: rgba(255, 255, 255, .22);
    border-radius: 12px;
    min-width: 26px;
    height: 26px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: .875rem;
}
.qm-fab-label { flex: 1; text-align: start; }
.qm-fab-total { font-weight: 800; }
.qm-fab-enter-active, .qm-fab-leave-active { transition: transform .2s, opacity .2s; }
.qm-fab-enter-from, .qm-fab-leave-to { transform: translateY(20px); opacity: 0; }

/* ── Session dead ── */
.qm-dead {
    position: fixed;
    inset: 0;
    z-index: 30000;
    background: rgba(15, 23, 42, .6);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1rem;
}
.qm-dead-card {
    background: var(--surface);
    border-radius: 12px;
    padding: 2rem 1.6rem;
    text-align: center;
    max-width: 360px;
}
.qm-dead-card i { font-size: 2.6rem; color: var(--brand); }
.qm-dead-card h3 { margin: .8rem 0 .35rem; font-size: 1.1rem; font-weight: 800; }
.qm-dead-card p { margin: 0; color: #315B6B; font-size: .88rem; line-height: 1.6; }

@media (prefers-reduced-motion: reduce) {
    .qm-fab-enter-active, .qm-fab-leave-active { transition: none; }
    .qm-strip { scroll-behavior: auto; }
}

@media (max-width: 600px) {
    .qm { padding-inline: .7rem; }
    .qm-orders { margin-inline: -.15rem; padding: .65rem; }
    .qm-orders-head small { max-width: 240px; }
    .qm-orders-track {
        display: flex;
        overflow-x: auto;
        scroll-snap-type: x mandatory;
        overscroll-behavior-inline: contain;
        scrollbar-width: none;
    }
    .qm-orders-track::-webkit-scrollbar { display: none; }
    .qm-order-round { flex: 0 0 min(280px, 82vw); }
    .qm-strip { margin-inline: -.7rem; padding-inline: .7rem; scroll-padding-inline: .7rem; }
    .qm-strip-card { flex-basis: min(286px, 82vw); }
    .qm-swipe-hint { display: inline-flex; }
    .qm-grid {
        display: flex;
        gap: .65rem;
        overflow-x: auto;
        margin-inline: -.7rem;
        padding: .1rem .7rem .55rem;
        scroll-snap-type: x mandatory;
        scroll-padding-inline: .7rem;
        overscroll-behavior-inline: contain;
        scrollbar-width: none;
    }
    .qm-grid::-webkit-scrollbar { display: none; }
    .qm-category-card {
        flex: 0 0 min(320px, 84vw);
        scroll-snap-align: center;
        scroll-snap-stop: always;
    }
}

/* Task-focused workspace layout. */
.qm{max-width:1240px;padding:24px 28px 0}.qm-top{padding-bottom:20px}.qm-top-actions .qm-chip{min-height:42px;background:transparent;border:1px solid var(--line);border-radius:6px}.qm-menu-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;padding:32px 0 20px}.qm-menu-heading h1{font-size:clamp(28px,4vw,40px);margin:4px 0 0;font-weight:700}.qm-menu-heading>div>span{font-size:14px;color:var(--brand)}.qm-table-number{display:grid;gap:2px;text-align:center;color:var(--muted);font-size:14px;border-inline-start:1px solid var(--line);padding-inline-start:24px}.qm-table-number strong{font-size:30px;color:var(--ink);line-height:1.2}.qm-command{display:grid;grid-template-columns:minmax(230px,330px) minmax(0,1fr);gap:18px;align-items:center;background:var(--canvas);padding:12px 0;border-bottom:1px solid var(--line)}.qm-cats{gap:4px;margin:0;padding:0}.qm-cat{border:0;border-radius:0;background:transparent;min-height:48px;padding:10px 14px;color:var(--muted)}.qm-cat.is-active{background:transparent;color:var(--brand);box-shadow:inset 0 -3px var(--brand)}.qm-section{margin-top:30px;scroll-margin-top:95px}.qm-category-head,.qm-section-head{margin-bottom:16px;border-bottom:1px solid var(--line);padding-bottom:12px}.qm-section-title{font-size:22px;gap:10px}.qm-cat-bar{width:4px;border-radius:0}.qm-swipe-hint,.qm-slider-dots{display:none}.qm-grid.qm-category-track{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;overflow:visible;scroll-snap-type:none;padding:0}.qm-category-card{width:auto;min-width:0;flex:initial}.qm-strip{gap:14px;padding-bottom:8px}.qm-strip-card{flex:0 0 340px;max-width:90vw}.qm-fab{max-width:620px;border-radius:8px;min-height:60px}.qm-submitted{border-inline-start:3px solid var(--brand);padding:12px 16px;background:var(--surface)}@media(max-width:740px){.qm{padding:16px 16px 0}.qm-top-actions{width:100%;justify-content:flex-start}.qm-menu-heading{padding:24px 0 16px}.qm-command{grid-template-columns:1fr;gap:8px;padding:10px 0}.qm-grid.qm-category-track{grid-template-columns:1fr;gap:12px}.qm-section{scroll-margin-top:150px}.qm-section-title{font-size:20px}.qm-strip-card{flex-basis:300px}}
</style>
