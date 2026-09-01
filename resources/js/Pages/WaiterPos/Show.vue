<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { formatMoney } from '../../Composables/useMoney';
// Phase-3 wiring (Claude): the page's only network surface — see waiterPosApi.js.
import { linkCustomer, previewStock, recordTransfer, reviewOrder as reviewPendingOrder, submitOrder, REPLAYED_TOKEN_MESSAGE } from '../../waiterPosApi';
import { useWaiterCartStore } from '../../Stores/waiterCart';
import CartSheet from '../../Components/WaiterPos/CartSheet.vue';
import CategoryChips from '../../Components/WaiterPos/CategoryChips.vue';
import CustomerSheet from '../../Components/WaiterPos/CustomerSheet.vue';
import FabBar from '../../Components/WaiterPos/FabBar.vue';
import LineSheet from '../../Components/WaiterPos/LineSheet.vue';
import MenuGrid from '../../Components/WaiterPos/MenuGrid.vue';
import QuickStrips from '../../Components/WaiterPos/QuickStrips.vue';
import SearchBar from '../../Components/WaiterPos/SearchBar.vue';
import SessionBar from '../../Components/WaiterPos/SessionBar.vue';
import StaffSheet from '../../Components/WaiterPos/StaffSheet.vue';
import TransferSheet from '../../Components/WaiterPos/TransferSheet.vue';
import NotificationsBell from '../../Components/AdminShell/NotificationsBell.vue';

const props = defineProps({
    table: { type: Object, required: true },
    session: { type: Object, required: true },
    carryOver: { type: Object, required: true },
    sessionOrders: { type: Array, required: true },
    categories: { type: Array, required: true },
    menu: { type: Array, required: true },
    quickPicks: { type: Array, required: true },
    lastRound: { type: Object, default: null },
    reviewOrder: { type: Object, default: null },
    submitToken: { type: String, required: true },
    currency: { type: Object, required: true },
    eligibleStaff: { type: Array, default: () => [] },
    transfer: { type: Object, default: () => ({ enabled: false, details: '', eligible: false }) },
    notificationUrls: { type: Object, required: true },
    flash: { type: Object, default: () => ({}) },
});

const cart = useWaiterCartStore();
const isReviewMode = computed(() => Boolean(props.reviewOrder));
const search = ref('');
const selectedCategoryId = ref('');
const cartOpen = ref(Boolean(props.reviewOrder));
const submitNotes = ref(props.reviewOrder?.notes ?? '');
const submitting = ref(false);
const activeLineItem = ref(null);
const activeLineIndex = ref(null);
const covers = ref(clampCovers(props.session.covers));
const confirmedCovers = ref(covers.value);
const pendingCoverDelta = ref(0);
const coverRequestRunning = ref(false);
const isOnline = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
const notice = ref(null);
let noticeTimer = null;

// ── Ported features' state (staff meal / customer / transfer) ────────────
const staffSheetOpen = ref(false);
const staffEmployeeId = ref(null);
const customerSheetOpen = ref(false);
const customerBusy = ref(false);
// Live copy of the session's customer — starts from props, updated by the
// sheet's link/detach responses without a page reload.
const linkedCustomer = ref(props.session.customer
    ? { name: props.session.customer, debt: props.session.debt }
    : null);
const transferSheetOpen = ref(false);
const transferBusy = ref(false);

const selectedStaff = computed(() => (
    props.eligibleStaff.find((person) => person.id === staffEmployeeId.value) ?? null
));

const sessionOutstanding = computed(() => props.sessionOrders.reduce(
    (sum, order) => sum + Number(order.total || 0),
    0,
));

const filteredMenu = computed(() => {
    const needle = search.value.trim().toLocaleLowerCase('ar');

    return props.menu.filter((item) => {
        const inCategory = selectedCategoryId.value === ''
            || String(item.category_id) === selectedCategoryId.value;
        const matchesSearch = needle === ''
            || String(item.name ?? '').toLocaleLowerCase('ar').includes(needle);

        return inCategory && matchesSearch;
    });
});

const activeLine = computed(() => (
    activeLineIndex.value === null ? null : cart.lines[activeLineIndex.value] ?? null
));

const carryOverAmount = computed(() => formatMoney(props.carryOver.outstanding, props.currency));

watch(
    () => props.flash,
    (flash) => {
        if (flash?.error) notify('error', flash.error);
        else if (flash?.warning) notify('warning', flash.warning);
        else if (flash?.success) notify('success', flash.success);
    },
    { immediate: true },
);

onMounted(() => {
    cart.startSession(props.session.id, props.menu, props.reviewOrder ? {
        storageKey: `waiter_cart_vue.review.${props.reviewOrder.id}.${props.reviewOrder.updatedAt}`,
        initialLines: props.reviewOrder.lines,
    } : {});
    window.addEventListener('online', updateConnectionState);
    window.addEventListener('offline', updateConnectionState);
    window.addEventListener('keydown', closeTopSheetOnEscape);
});

onBeforeUnmount(() => {
    window.removeEventListener('online', updateConnectionState);
    window.removeEventListener('offline', updateConnectionState);
    window.removeEventListener('keydown', closeTopSheetOnEscape);
    if (noticeTimer) window.clearTimeout(noticeTimer);
});

function clampCovers(value) {
    return Math.min(50, Math.max(1, Number(value) || 1));
}

function updateConnectionState() {
    isOnline.value = navigator.onLine;
}

function closeTopSheetOnEscape(event) {
    if (event.key !== 'Escape') return;

    if (activeLineItem.value) closeLineSheet();
    else if (cartOpen.value) cartOpen.value = false;
}

function notify(type, message) {
    if (!message) return;
    notice.value = { type, message };
    if (noticeTimer) window.clearTimeout(noticeTimer);
    noticeTimer = window.setTimeout(() => {
        notice.value = null;
    }, 4500);
}

function addItem(menuItemId) {
    const item = props.menu.find((candidate) => Number(candidate.id) === Number(menuItemId));
    if (!item || !item.in_stock) return;

    if (item.has_mods || item.removable_ingredients?.length) {
        activeLineItem.value = item;
        activeLineIndex.value = null;
        return;
    }

    cart.addPlain(item);
}

function editCartLine(index) {
    const line = cart.editLine(index);
    if (!line) return;

    const item = props.menu.find((candidate) => Number(candidate.id) === Number(line.menu_item_id));
    if (!item) {
        notify('warning', `الصنف «${line.name}» لم يعد موجودًا في المنيو.`);
        return;
    }

    activeLineItem.value = item;
    activeLineIndex.value = index;
}

function saveConfiguredLine(payload) {
    cart.saveLine({
        ...payload,
        item: activeLineItem.value,
        index: activeLineIndex.value,
    });
    closeLineSheet();
}

function closeLineSheet() {
    activeLineItem.value = null;
    activeLineIndex.value = null;
}

function repeatLines(lines) {
    if (!lines.length) return;

    const skipped = cart.repeatRound(lines);
    if (skipped.length) {
        notify('warning', `انضافت الجولة ما عدا: ${skipped.join('، ')}.`);
    } else {
        notify('success', lines.length > 1 ? 'انضافت الجولة الماضية كاملة للسلة.' : 'انضاف الصنف للسلة.');
    }
}

/**
 * Phase-3 wiring (Claude). The outcome map is deliberate:
 *  - transport failure (offline/server down) → KEEP the cart: it's already
 *    in localStorage, the waiter retries when the connection returns;
 *  - replayed-token refusal → the order EXISTS and only the response was
 *    lost: clear + leave, never invite a resend;
 *  - business refusal (stock, groups) → keep the cart, show the reason —
 *    the server released the token, an honest retry works;
 *  - success with `warning` → the order exists but a post-commit step
 *    failed: longer notice so the waiter reads "لا تعد الإرسال".
 */
async function prepareSubmission(payload) {
    if (submitting.value || !cart.lines.length) return;

    submitting.value = true;
    submitNotes.value = payload.notes;

    try {
        let result;
        try {
            result = isReviewMode.value
                ? await reviewPendingOrder(props.table.id, props.reviewOrder.id, {
                    expected_version: props.reviewOrder.updatedAt,
                    change_request_ids: props.reviewOrder.changes.map((change) => change.id),
                    notes: payload.notes,
                    cart: cart.lines,
                })
                : await submitOrder(props.table.id, {
                    token: props.submitToken,
                    notes: payload.notes,
                    staff_employee_id: staffEmployeeId.value,
                    cart: cart.lines,
                });
        } catch {
            notify('warning', isReviewMode.value
                ? 'ما في اتصال — تعديلات المراجعة محفوظة على الجهاز. أعد الاعتماد عند رجوع الاتصال.'
                : 'ما في اتصال — السلة محفوظة على الجهاز، ابعتها لما يرجع الاتصال.');
            return;
        }

        if (!result.ok) {
            if (!isReviewMode.value && result.data.message === REPLAYED_TOKEN_MESSAGE) {
                finishAndReturnToFloor('الطلب كان واصل من قبل — ما انبعت مرتين.', 1400);
                return;
            }
            notify('error', result.data.message || (isReviewMode.value
                ? 'تعذّر اعتماد المراجعة — افتح الجولة من جديد.'
                : 'تعذّر إرسال الطلب — جرّب من جديد.'));
            return;
        }

        if (result.data.warning) {
            finishAndReturnToFloor(result.data.warning, 4000, 'warning');
            return;
        }

        finishAndReturnToFloor(isReviewMode.value
            ? (result.data.message || `تم اعتماد ${result.data.order_number ?? 'الجولة'} وإرسالها للمطبخ والبار.`)
            : `تم إرسال الجولة ${result.data.order_number ?? ''}${result.data.fired ? ' للمطبخ والبار' : ' للمراجعة'}.`, 900);
    } finally {
        submitting.value = false;
    }
}

/** Order delivered: clear everything, show the notice, land back on the floor. */
function finishAndReturnToFloor(message, delayMs, type = 'success') {
    cart.clear();
    submitNotes.value = '';
    staffEmployeeId.value = null;
    cartOpen.value = false;
    notify(type, message);
    window.setTimeout(() => router.visit('/admin/tables', { replace: true }), delayMs);
}

// ── Ported features' handlers ────────────────────────────────────────────

function pickStaff(id) {
    staffEmployeeId.value = id;
    staffSheetOpen.value = false;
}

async function handleCustomerLink(payload) {
    customerBusy.value = true;
    try {
        const result = await linkCustomer(props.table.id, payload);
        if (!result.ok) {
            notify('error', result.data.message || 'تعذّر ربط الزبون.');
            return;
        }
        linkedCustomer.value = result.data.customer
            ? { name: result.data.customer.name, debt: result.data.customer.debt }
            : null;
        customerSheetOpen.value = false;
        notify('success', result.data.customer
            ? `تم ربط الزبون «${result.data.customer.name}».`
            : 'تم فك ربط الزبون.');
    } catch {
        notify('warning', 'ما في اتصال — جرّب ربط الزبون لما يرجع النت.');
    } finally {
        customerBusy.value = false;
    }
}

async function handleTransferRecord(payload) {
    transferBusy.value = true;
    try {
        const result = await recordTransfer(props.table.id, payload);
        if (!result.ok) {
            notify('error', result.data.message || 'تعذّر تسجيل الحوالة.');
            return;
        }
        transferSheetOpen.value = false;
        notify('success', 'سُجّلت الحوالة — بتوصل لطابور الكاشير للتأكيد.');
    } catch {
        notify('warning', 'ما في اتصال — سجّل الحوالة لما يرجع النت.');
    } finally {
        transferBusy.value = false;
    }
}

// Advisory stock preview (§5 المرحلة 3): NEVER blocks an add — the tap lands
// instantly, the warning follows. Debounced so a burst of taps costs one
// request; failures are swallowed (submit re-checks authoritatively anyway).
let stockPreviewTimer = null;
let stockPreviewInFlight = false;
watch(
    () => cart.lines,
    () => {
        if (stockPreviewTimer) window.clearTimeout(stockPreviewTimer);
        if (!cart.lines.length || !isOnline.value) return;

        stockPreviewTimer = window.setTimeout(async () => {
            if (stockPreviewInFlight) return;
            stockPreviewInFlight = true;
            try {
                const result = await previewStock(props.table.id, cart.lines);
                const issues = result.ok ? result.data.issues ?? [] : [];
                if (issues.length) {
                    const short = issues.slice(0, 3)
                        .map((issue) => `${issue.ingredient} (متاح ${issue.available})`)
                        .join('، ');
                    notify('warning', `نفد المخزون من: ${short}.`);
                }
            } catch {
                // Offline mid-preview — the submit gate is the real check.
            } finally {
                stockPreviewInFlight = false;
            }
        }, 900);
    },
    { deep: true },
);

function repeatLastLine(index) {
    const line = props.lastRound?.lines?.[index];
    if (line) repeatLines([line]);
}

async function changeCovers(delta) {
    const next = clampCovers(covers.value + delta);
    const acceptedDelta = next - covers.value;
    if (acceptedDelta === 0) return;

    covers.value = next;
    pendingCoverDelta.value += acceptedDelta;
    await flushCoverChanges();
}

async function flushCoverChanges() {
    if (coverRequestRunning.value || pendingCoverDelta.value === 0) return;

    coverRequestRunning.value = true;
    const delta = Math.min(10, Math.max(-10, pendingCoverDelta.value));
    pendingCoverDelta.value -= delta;

    try {
        const response = await fetch(`/admin/waiter-orders/table/${props.table.id}/covers`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({ delta }),
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.message || 'تعذّر حفظ عدد الأشخاص.');

        confirmedCovers.value = clampCovers(result.covers);
        covers.value = clampCovers(confirmedCovers.value + pendingCoverDelta.value);
    } catch (error) {
        pendingCoverDelta.value = 0;
        covers.value = confirmedCovers.value;
        notify('error', error.message || 'تعذّر حفظ عدد الأشخاص.');
    } finally {
        coverRequestRunning.value = false;
        if (pendingCoverDelta.value !== 0) await flushCoverChanges();
    }
}
</script>

<template>
    <Head :title="`${isReviewMode ? 'مراجعة' : 'طلب'} طاولة ${table.number}`" />

    <main class="waiter-pos-page">
        <header class="page-heading">
            <Link class="back-to-floor" href="/admin/tables" aria-label="الرجوع إلى لوحة الصالة">
                <i class="bi bi-arrow-right"></i>
            </Link>
            <div class="heading-copy">
                <span>{{ isReviewMode ? (reviewOrder.roundLabel || 'مراجعة الجولة') : 'طلب جديد' }}</span>
                <h1>طاولة {{ table.number }}</h1>
            </div>
            <div class="heading-tools">
                <NotificationsBell class="waiter-live-bell" :urls="notificationUrls" />
                <span class="connection-state" :class="{ 'is-offline': !isOnline }" role="status">
                    <i :class="isOnline ? 'bi bi-wifi' : 'bi bi-cloud-slash'"></i>
                    {{ isOnline ? 'متصل' : 'أوفلاين — السلة محفوظة' }}
                </span>
            </div>
        </header>

        <div v-if="notice" class="page-notice" :class="`is-${notice.type}`" role="status">
            <i class="bi bi-info-circle"></i>
            <span>{{ notice.message }}</span>
            <button type="button" aria-label="إغلاق التنبيه" @click="notice = null">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <section v-if="isReviewMode" class="review-banner" aria-label="مراجعة الجولة مع الزبون">
            <span class="review-step"><i class="bi bi-chat-square-check"></i></span>
            <div>
                <strong>{{ reviewOrder.roundLabel || 'الجولة الجديدة' }} · راجع الأصناف مع الزبون</strong>
                <p>عدّل الكميات والمكونات هنا؛ هذه النسخة وحدها ستصل للمطبخ والبار.</p>
                <ul v-if="reviewOrder.changes.length" class="review-changes">
                    <li v-for="change in reviewOrder.changes" :key="change.id">
                        <b>{{ change.label }}{{ change.itemName ? ` — ${change.itemName}` : '' }}</b>
                        <span v-if="change.quantity !== null">الكمية المطلوبة: {{ change.quantity }}</span>
                        <span v-if="change.note">{{ change.note }}</span>
                    </li>
                </ul>
            </div>
        </section>

        <div v-if="carryOver.has_prior && !isReviewMode" class="carry-over" role="status">
            <i class="bi bi-layers-fill"></i>
            <div>
                <strong>جلسة الطاولة مفتوحة · {{ carryOver.orders_count }} {{ carryOver.orders_count === 1 ? 'جولة' : 'جولات' }}</strong>
                <span>الجولة الجديدة ستنضم لنفس الفاتورة · المتبقي {{ carryOverAmount }}</span>
            </div>
        </div>

        <SessionBar
            :covers="covers"
            :orders="sessionOrders"
            :customer="linkedCustomer?.name ?? null"
            :debt="linkedCustomer?.debt ?? 0"
            :currency="currency"
            :transfer-visible="transfer.enabled && transfer.eligible"
            @change-covers="changeCovers"
            @open-customer="customerSheetOpen = true"
            @open-transfer="transferSheetOpen = true"
        />

        <section class="menu-workspace" aria-label="منيو المطعم">
            <div class="menu-toolbar">
                <SearchBar v-model="search" />
                <CategoryChips v-model="selectedCategoryId" :categories="categories" />
            </div>

            <QuickStrips
                :last-round="lastRound"
                :quick-picks="quickPicks"
                @repeat-line="repeatLastLine"
                @repeat-all="repeatLines(lastRound?.lines ?? [])"
                @add-item="addItem"
            />

            <MenuGrid
                :items="filteredMenu"
                :cart-quantities="cart.itemQuantities"
                :currency="currency"
                @add-item="addItem"
            />
        </section>

        <div v-if="cartOpen" class="cart-backdrop" @click="cartOpen = false"></div>

        <CartSheet
            :open="cartOpen"
            :lines="cart.lines"
            :total="cart.total"
            :currency="currency"
            v-model:notes="submitNotes"
            :submitting="submitting"
            :mode="isReviewMode ? 'review' : 'new'"
            :round-label="reviewOrder?.roundLabel ?? ''"
            :staff-name="selectedStaff?.name ?? null"
            @close="cartOpen = false"
            @edit-line="editCartLine"
            @change-qty="cart.changeQty"
            @remove-line="cart.removeLine"
            @open-staff="staffSheetOpen = true"
            @submit="prepareSubmission"
        />

        <FabBar
            v-if="!cartOpen"
            :count="cart.unitCount"
            :total="cart.total"
            :currency="currency"
            :label="isReviewMode ? 'مراجعة واعتماد الجولة' : 'عرض السلة'"
            @open="cartOpen = true"
        />

        <LineSheet
            v-if="activeLineItem"
            :item="activeLineItem"
            :line="activeLine"
            :currency="currency"
            @close="closeLineSheet"
            @save="saveConfiguredLine"
        />

        <StaffSheet
            v-if="staffSheetOpen"
            :staff="eligibleStaff"
            :selected-id="staffEmployeeId"
            :currency="currency"
            @close="staffSheetOpen = false"
            @pick="pickStaff"
            @clear="staffEmployeeId = null; staffSheetOpen = false"
        />

        <CustomerSheet
            v-if="customerSheetOpen"
            :customer="linkedCustomer"
            :currency="currency"
            :busy="customerBusy"
            @close="customerSheetOpen = false"
            @link="handleCustomerLink"
            @detach="handleCustomerLink({ phone: '', detach: true })"
        />

        <TransferSheet
            v-if="transferSheetOpen"
            :details="transfer.details"
            :suggested-amount="sessionOutstanding"
            :currency="currency"
            :busy="transferBusy"
            @close="transferSheetOpen = false"
            @record="handleTransferRecord"
        />
    </main>
</template>

<style scoped>
.waiter-pos-page {
    --wp-primary: rgb(var(--primary-rgb, 22 101 52));
    --wp-primary-dark: color-mix(in srgb, var(--wp-primary) 78%, #04150d);
    min-height: 100vh;
    min-height: 100dvh;
    box-sizing: border-box;
    padding: .8rem clamp(.7rem, 2vw, 1.4rem) 96px;
    color: #1f2937;
    background: #f7f9f8;
}
.page-heading { display: flex; align-items: center; gap: .7rem; width: min(100%, 1180px); margin: 0 auto .65rem; }
.heading-copy { flex: 1; min-width: 0; }
.heading-tools { display: flex; align-items: center; gap: .45rem; flex: 0 0 auto; }
.waiter-live-bell :deep(.header-link) {
    display: grid;
    width: 44px;
    height: 44px;
    place-items: center;
    border: 1px solid rgba(15, 71, 49, .15);
    border-radius: 12px;
    color: var(--wp-primary);
    background: #fff;
}
.waiter-live-bell :deep(.bell-menu) { inset-inline-end: 0; inset-inline-start: auto; }
.page-heading span { color: #64748b; font-size: .76rem; font-weight: 700; }
.page-heading h1 { margin: .05rem 0 0; color: #17221c; font-size: 1.25rem; font-weight: 900; }
.back-to-floor {
    display: inline-flex; align-items: center; justify-content: center;
    width: 44px; height: 44px; flex: 0 0 44px;
    border: 1px solid rgba(15, 71, 49, .15); border-radius: 12px;
    color: var(--wp-primary); background: #fff; text-decoration: none;
}
.connection-state {
    display: inline-flex; align-items: center; gap: .35rem; min-height: 44px;
    padding-inline: .7rem; border-radius: 999px;
    color: #047857 !important; background: #ecfdf5; white-space: nowrap;
}
.connection-state.is-offline { color: #b45309 !important; background: #fff7ed; }
.page-notice, .carry-over {
    display: flex; align-items: center; gap: .65rem; width: min(100%, 1180px);
    box-sizing: border-box; margin: 0 auto .65rem; padding: .7rem .8rem;
    border: 1px solid #fde68a; border-radius: 12px; color: #92400e; background: #fffbeb;
}
.page-notice > span, .carry-over > div { flex: 1; min-width: 0; }
.carry-over strong, .carry-over span { display: block; }
.carry-over span { margin-top: .1rem; font-size: .78rem; }
.page-notice.is-success { border-color: #a7f3d0; color: #047857; background: #ecfdf5; }
.page-notice.is-error { border-color: #fecaca; color: #b91c1c; background: #fef2f2; }
.page-notice button {
    display: inline-flex; align-items: center; justify-content: center;
    width: 44px; height: 44px; border: 0; border-radius: 10px;
    color: currentColor; background: transparent; cursor: pointer;
}
.review-banner {
    display: flex; align-items: flex-start; gap: .7rem; width: min(100%, 1180px);
    box-sizing: border-box; margin: 0 auto .65rem; padding: .8rem;
    border: 1px solid #fbbf24; border-radius: 13px; background: #fffbeb; color: #78350f;
}
.review-step { display: inline-grid; place-items: center; flex: 0 0 34px; width: 34px; height: 34px; border-radius: 50%; background: #b45309; color: #fff; font-weight: 950; }
.review-banner > div { min-width: 0; flex: 1; }
.review-banner strong { font-size: .88rem; font-weight: 900; }
.review-banner p { margin: .12rem 0 0; color: #92400e; font-size: .75rem; line-height: 1.5; }
.review-changes { display: grid; gap: .3rem; margin: .5rem 0 0; padding: 0; list-style: none; }
.review-changes li { display: flex; flex-wrap: wrap; gap: .25rem .55rem; padding: .4rem .5rem; border-radius: 8px; background: #fff; font-size: .72rem; }
.review-changes li b { color: #9a3412; }
.review-changes li span { color: #64748b; }
.menu-workspace { width: min(100%, 1180px); margin-inline: auto; }
.menu-toolbar {
    position: sticky; top: 0; z-index: 20; margin-bottom: .55rem;
    padding: .5rem 0 .55rem; border-bottom: 1px solid rgba(15, 71, 49, .08);
    background: #f7f9f8;
}
.cart-backdrop { position: fixed; inset: 0; z-index: 1060; background: rgba(15, 23, 42, .45); }
@media (max-width: 560px) {
    .connection-state { width: 44px; padding: 0; justify-content: center; overflow: hidden; color: transparent !important; }
    .connection-state i { color: #047857; font-size: 1rem; }
    .connection-state.is-offline i { color: #b45309; }
}
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { scroll-behavior: auto !important; transition-duration: .01ms !important; }
}
</style>
