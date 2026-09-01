<script setup>
/**
 * The cart sheet — the retired /cart PAGE reborn inside the menu. Rows
 * edit in place (server-synced steppers), totals honor the branch's tax
 * display mode (inclusive shows the final figure; exclusive shows the
 * subtotal + "added later" note), and identity fields appear only for
 * diners the system doesn't already know. Submit emits one independent round
 * to the parent menu: the server still owns pricing and idempotency, while the
 * phone stays on the same screen and can immediately build another round.
 * A phone number is optional: when supplied it links the visit to the internal
 * customer file; an empty value keeps the order anonymous.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    cart: { type: Object, required: true },      // useCustomerCart instance
    money: { type: Object, required: true },     // symbol/tax/service/display
    sessionInfo: { type: Object, required: true },
    submitting: { type: Boolean, default: false },
    hasPreviousOrders: { type: Boolean, default: false },
    orderingEnabled: { type: Boolean, default: true },
    t: { type: Function, required: true },
});

const emit = defineEmits(['close', 'submit']);

const customerPhone = ref(props.sessionInfo.defaultPhone ?? '');
const orderNotes = ref('');
const editingNotes = ref(null); // row id whose notes editor is open
const notesDraft = ref('');
let previousBodyOverflow = '';

const round = (n) => Math.round((Number(n) || 0) * 100) / 100;
const fmt = (n) => round(n).toFixed(2);

const tax = computed(() => (props.money.taxEnabled ? round(props.cart.total.value * (props.money.taxRate / 100)) : 0));
const service = computed(() => (props.money.serviceEnabled ? round(props.cart.total.value * (props.money.serviceRate / 100)) : 0));
const inclusive = computed(() => props.money.taxDisplay === 'inclusive');
const displayTotal = computed(() => (inclusive.value
    ? round(props.cart.total.value + tax.value + service.value)
    : props.cart.total.value));

const taxNote = computed(() => {
    const hasTax = props.money.taxEnabled && props.money.taxRate > 0;
    const hasService = props.money.serviceEnabled && props.money.serviceRate > 0;
    if (! hasTax && ! hasService) return null;
    const parts = [];
    if (hasTax) parts.push(`${props.t('tax_word') ?? 'الضريبة'} ${props.money.taxRate}%`);
    if (hasService) parts.push(`${props.t('service_word') ?? 'الخدمة'} ${props.money.serviceRate}%`);
    return inclusive.value
        ? `${props.t('included_note') ?? 'شامل'} ${parts.join(' + ')}`
        : `${parts.join(' + ')} ${props.t('added_later_note') ?? 'تُضاف على الفاتورة'}`;
});

const openNotes = (row) => {
    editingNotes.value = row.id;
    notesDraft.value = row.notes ?? '';
};
const saveNotes = (row) => {
    props.cart.setNotes(row, notesDraft.value.trim() || null);
    editingNotes.value = null;
};

const submit = () => {
    if (props.submitting || props.cart.rows.length === 0 || ! props.orderingEnabled) return;

    emit('submit', {
        customer_phone: ! props.sessionInfo.known ? customerPhone.value.trim() : null,
        notes: orderNotes.value.trim() || null,
    });
};

watch(() => props.sessionInfo.defaultPhone, (phone) => {
    if (phone) customerPhone.value = phone;
});
watch(() => props.cart.rows.length, (next, previous) => {
    if (previous > 0 && next === 0) {
        orderNotes.value = '';
        editingNotes.value = null;
    }
});
watch(() => props.open, (open) => {
    if (typeof document === 'undefined') return;

    if (open) {
        previousBodyOverflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
    } else {
        document.body.style.overflow = previousBodyOverflow;
    }
}, { immediate: true });
onBeforeUnmount(() => {
    if (typeof document !== 'undefined') document.body.style.overflow = previousBodyOverflow;
});
</script>

<template>
    <Teleport to="body">
        <Transition name="ct">
            <div v-if="open" class="ct-backdrop" @click.self="$emit('close')">
                <div class="ct-sheet" role="dialog" aria-modal="true" :aria-label="t('cart_title') ?? 'سلتك'">
                    <header class="ct-head">
                        <h3><i class="bi bi-bag"></i> {{ t('cart_title') ?? 'سلتك' }}
                            <span v-if="cart.count.value" class="ct-count">{{ cart.count.value }}</span>
                        </h3>
                        <button type="button" class="ct-close" aria-label="إغلاق" @click="$emit('close')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </header>

                    <div class="ct-body">
                        <div v-if="! orderingEnabled" class="ct-locked">
                            <i class="bi bi-phone"></i>
                            <div>
                                <strong>الجلسة مفتوحة من هاتف آخر</strong>
                                <span>تقدر تتابع كل الجولات هنا، والإضافة تتم من الهاتف الذي بدأ الجلسة أو من فريق الصالة.</span>
                            </div>
                        </div>
                        <div v-if="cart.rows.length === 0" class="ct-empty">
                            <i class="bi bi-bag"></i>
                            <p>{{ t('cart_empty') ?? 'سلتك فاضية — تصفح المنيو وأضف اللي بيعجبك' }}</p>
                        </div>

                        <div v-for="row in cart.rows" :key="row.id" class="ct-row" :class="{ 'is-pending': row._pending }">
                            <img v-if="row.image" :src="row.image" :alt="row.name" class="ct-thumb">
                            <div v-else class="ct-thumb ct-thumb--empty"><i class="bi bi-egg-fried"></i></div>

                            <div class="ct-row-body">
                                <div class="ct-row-top">
                                    <strong>{{ row.name }}</strong>
                                    <span class="ct-row-total">{{ fmt(row.subtotal) }} {{ money.symbol }}</span>
                                </div>
                                <small v-if="row.modifiers?.length" class="ct-row-mods">
                                    {{ row.modifiers.map(m => m.name).join('، ') }}
                                </small>
                                <div v-if="row.excluded_ingredients?.length" class="ct-row-exclusions">
                                    <span v-for="ingredient in row.excluded_ingredients" :key="ingredient.id">
                                        <i class="bi bi-x-circle-fill"></i> بدون {{ ingredient.name }}
                                    </span>
                                </div>
                                <small v-if="row.notes && editingNotes !== row.id" class="ct-row-notes" @click="openNotes(row)">
                                    <i class="bi bi-chat-left-text"></i> {{ row.notes }}
                                </small>

                                <div v-if="editingNotes === row.id" class="ct-notes-edit">
                                    <input v-model="notesDraft" maxlength="500" placeholder="ملاحظة للمطبخ…"
                                           @keydown.enter.prevent="saveNotes(row)">
                                    <button type="button" @click="saveNotes(row)"><i class="bi bi-check-lg"></i></button>
                                </div>

                                <div class="ct-row-foot">
                                    <div class="ct-stepper">
                                        <button type="button" aria-label="أنقص" :disabled="! orderingEnabled || row.quantity <= 1 || row._pending"
                                                @click="cart.setQuantity(row, row.quantity - 1)"><i class="bi bi-dash-lg"></i></button>
                                        <span>{{ row.quantity }}</span>
                                        <button type="button" aria-label="زد" :disabled="! orderingEnabled || row._pending"
                                                @click="cart.setQuantity(row, row.quantity + 1)"><i class="bi bi-plus-lg"></i></button>
                                    </div>
                                    <button v-if="orderingEnabled && editingNotes !== row.id && ! row.notes" type="button" class="ct-mini" @click="openNotes(row)">
                                        <i class="bi bi-chat-left-text"></i>
                                    </button>
                                    <button type="button" class="ct-mini ct-mini--del" aria-label="احذف" :disabled="! orderingEnabled" @click="cart.remove(row)">
                                        <i class="bi bi-trash3"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <template v-if="cart.rows.length > 0">
                            <div v-if="orderingEnabled && ! sessionInfo.known" class="ct-identity">
                                <div class="ct-id-grid">
                                    <label>
                                        <span>رقم الجوال <small>(اختياري)</small></span>
                                        <input v-model="customerPhone" maxlength="10" inputmode="numeric" autocomplete="tel"
                                               pattern="0[0-9]{9}" placeholder="0592632026">
                                    </label>
                                </div>
                                <small class="ct-id-hint">إذا أدخلته نربط الزيارة بملفك ونقاطك. يمكنك تركه فارغاً وإرسال الطلب مباشرة.</small>
                            </div>

                            <label v-if="orderingEnabled" class="ct-order-notes">
                                <span>{{ t('order_notes') ?? 'ملاحظة عامة للطلب (اختياري)' }}</span>
                                <textarea v-model="orderNotes" rows="2" maxlength="1000"></textarea>
                            </label>
                        </template>
                    </div>

                    <footer v-if="cart.rows.length > 0" class="ct-foot">
                        <p v-if="hasPreviousOrders" class="ct-round-note">
                            <i class="bi bi-plus-circle"></i>
                            جولة جديدة للمطبخ، وتُجمع تلقائياً مع طلباتك السابقة في فاتورة واحدة.
                        </p>
                        <div class="ct-totals">
                            <div class="ct-total-line">
                                <span>{{ inclusive ? (t('total_inclusive') ?? 'الإجمالي (شامل)') : (t('subtotal') ?? 'المجموع') }}</span>
                                <strong>{{ fmt(displayTotal) }} {{ money.symbol }}</strong>
                            </div>
                            <small v-if="taxNote" class="ct-tax-note">{{ taxNote }}</small>
                        </div>
                        <button type="button" class="ct-submit" :disabled="submitting || cart.busy.value || ! orderingEnabled" @click="submit">
                            <i class="bi" :class="submitting ? 'bi-arrow-repeat ct-spin' : 'bi-send'"></i>
                            {{ submitting
                                ? (t('sending') ?? 'عم نبعت…')
                                : (! orderingEnabled ? 'الجلسة مفتوحة من هاتف آخر' : (hasPreviousOrders ? 'أرسل الجولة الجديدة' : 'أرسل الطلب')) }}
                        </button>
                    </footer>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.ct-backdrop {
    position: fixed;
    inset: 0;
    z-index: 17500;
    background: rgba(15, 23, 42, .5);
    display: flex;
    align-items: flex-end;
    justify-content: center;
}
.ct-sheet {
    width: min(590px, calc(100% - 24px));
    max-height: min(860px, calc(100dvh - 24px));
    display: flex;
    flex-direction: column;
    background: #f8fafc;
    border-radius: 20px 20px 0 0;
    overflow: hidden;
}
@media (min-width: 768px) {
    .ct-backdrop { align-items: center; padding: 1rem; }
    .ct-sheet { border-radius: 20px; }
}

.ct-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: .9rem 1.1rem;
    background: #fff;
    border-bottom: 1px solid #eef2f6;
    flex-shrink: 0;
}
.ct-head h3 {
    margin: 0;
    font-size: 1.02rem;
    font-weight: 900;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.ct-head h3 > i { color: rgb(var(--primary-rgb)); }
.ct-count {
    background: rgb(var(--primary-rgb));
    color: #fff;
    border-radius: 999px;
    font-size: .72rem;
    padding: .1rem .5rem;
}
.ct-close {
    width: 42px;
    height: 42px;
    border: 0;
    border-radius: 10px;
    background: #f1f5f9;
    color: #475569;
    cursor: pointer;
}

.ct-body {
    flex: 1 1 auto;
    min-height: 0;
    padding: .9rem 1rem;
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
    display: flex;
    flex-direction: column;
    gap: .6rem;
}
.ct-empty { text-align: center; color: #94a3b8; padding: 2.5rem 1rem; }
.ct-empty i { font-size: 2.2rem; display: block; margin-bottom: .6rem; }
.ct-empty p { margin: 0; font-size: .9rem; font-weight: 600; }

.ct-row {
    display: flex;
    gap: .65rem;
    background: #fff;
    border: 1px solid #eef0f3;
    border-radius: 14px;
    padding: .6rem;
}
.ct-row.is-pending { opacity: .6; }
.ct-thumb { width: 58px; height: 58px; border-radius: 10px; object-fit: cover; flex-shrink: 0; }
.ct-thumb--empty {
    display: flex;
    align-items: center;
    justify-content: center;
    background: #f1f5f9;
    color: #cbd5e1;
    font-size: 1.3rem;
}
.ct-row-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: .25rem; }
.ct-row-top { display: flex; justify-content: space-between; gap: .5rem; align-items: baseline; }
.ct-row-top strong { font-size: .88rem; font-weight: 800; color: #0f172a; }
.ct-row-total { font-size: .84rem; font-weight: 900; color: rgb(var(--primary-rgb)); white-space: nowrap; }
.ct-row-mods { color: #64748b; font-size: .74rem; }
.ct-row-exclusions { display: flex; flex-wrap: wrap; gap: .22rem; }
.ct-row-exclusions span { display: inline-flex; align-items: center; gap: .18rem; padding: .16rem .4rem; border-radius: 999px; background: #fee2e2; color: #991b1b; font-size: .7rem; font-weight: 800; }
.ct-row-notes { color: #92400e; font-size: .74rem; cursor: pointer; }
.ct-notes-edit { display: flex; gap: .35rem; }
.ct-notes-edit input {
    flex: 1;
    border: 1.5px solid #e2e8f0;
    border-radius: 9px;
    padding: .35rem .6rem;
    font: inherit;
    font-size: .8rem;
}
.ct-notes-edit button {
    width: 38px;
    border: 0;
    border-radius: 9px;
    background: rgb(var(--primary-rgb));
    color: #fff;
    cursor: pointer;
}
.ct-row-foot { display: flex; align-items: center; gap: .45rem; margin-top: .15rem; }
.ct-stepper {
    display: inline-flex;
    align-items: center;
    background: #f1f5f9;
    border-radius: 10px;
    padding: 2px;
}
.ct-stepper button {
    width: 34px;
    height: 34px;
    border: 0;
    border-radius: 8px;
    background: transparent;
    color: #0f172a;
    font-size: .8rem;
    cursor: pointer;
}
.ct-stepper button:disabled { opacity: .35; }
.ct-stepper span { min-width: 1.8em; text-align: center; font-weight: 900; font-size: .9rem; }
.ct-mini {
    width: 34px;
    height: 34px;
    border: 0;
    border-radius: 9px;
    background: #f1f5f9;
    color: #64748b;
    cursor: pointer;
    font-size: .8rem;
}
.ct-mini--del { margin-inline-start: auto; color: #b91c1c; background: #fef2f2; }

.ct-identity, .ct-order-notes {
    background: #fff;
    border: 1px solid #eef0f3;
    border-radius: 14px;
    padding: .75rem .8rem;
}
.ct-id-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: .6rem; }
.ct-id-grid label { display: flex; flex-direction: column; gap: .3rem; margin: 0; }
.ct-id-grid span { font-size: .74rem; font-weight: 700; color: #334155; }
.ct-id-grid span small { color: #64748b; font-size: .7rem; font-weight: 600; }
.ct-id-grid input {
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    min-height: 42px;
    padding: 0 .7rem;
    font: inherit;
    font-size: .86rem;
}
.ct-id-hint { display: block; margin-top: .4rem; color: #94a3b8; font-size: .7rem; }
.ct-locked {
    display: flex;
    align-items: flex-start;
    gap: .6rem;
    padding: .75rem .8rem;
    border: 1px solid #bfdbfe;
    border-radius: 14px;
    background: #eff6ff;
    color: #1e40af;
}
.ct-locked div { display: flex; flex-direction: column; gap: .12rem; }
.ct-locked strong { font-size: .82rem; font-weight: 850; }
.ct-locked span { font-size: .73rem; color: #475569; line-height: 1.5; }
.ct-order-notes { display: flex; flex-direction: column; gap: .35rem; }
.ct-order-notes span { font-size: .78rem; font-weight: 700; color: #334155; }
.ct-order-notes textarea {
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    padding: .5rem .7rem;
    font: inherit;
    font-size: .84rem;
    resize: none;
}

.ct-foot {
    padding: .8rem 1rem calc(.8rem + env(safe-area-inset-bottom));
    background: #fff;
    border-top: 1px solid #eef2f6;
    display: flex;
    flex-direction: column;
    gap: .6rem;
    flex-shrink: 0;
}
.ct-totals { display: flex; flex-direction: column; }
.ct-round-note {
    margin: 0;
    display: flex;
    align-items: center;
    gap: .4rem;
    color: #166534;
    font-size: .74rem;
    font-weight: 750;
}
.ct-total-line { display: flex; justify-content: space-between; align-items: baseline; }
.ct-total-line span { font-size: .84rem; font-weight: 700; color: #475569; }
.ct-total-line strong { font-size: 1.15rem; font-weight: 900; color: #0f172a; }
.ct-tax-note { color: #94a3b8; font-size: .72rem; }
.ct-submit {
    min-height: 50px;
    border: 0;
    border-radius: 13px;
    background: rgb(var(--primary-rgb));
    color: #fff;
    font: inherit;
    font-size: 1rem;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
}
.ct-submit:disabled { opacity: .65; }
.ct-spin { animation: ct-rotate 1s linear infinite; }
@keyframes ct-rotate { to { transform: rotate(360deg); } }

.ct-enter-active, .ct-leave-active { transition: opacity .16s; }
.ct-enter-from, .ct-leave-to { opacity: 0; }
@media (max-width: 767px) {
    .ct-backdrop { padding: 0; }
    .ct-sheet {
        width: 100%;
        height: 100dvh;
        max-height: 100dvh;
        border-radius: 0;
    }
    .ct-head {
        padding-block-start: max(.75rem, env(safe-area-inset-top));
        padding-inline: max(.85rem, env(safe-area-inset-left)) max(.85rem, env(safe-area-inset-right));
    }
    .ct-body {
        padding-inline: max(.75rem, env(safe-area-inset-left)) max(.75rem, env(safe-area-inset-right));
    }
    .ct-foot {
        padding-inline: max(.75rem, env(safe-area-inset-left)) max(.75rem, env(safe-area-inset-right));
    }
}
@media (max-width: 380px) {
    .ct-row { gap: .5rem; padding: .52rem; }
    .ct-thumb { width: 50px; height: 50px; }
    .ct-row-top { align-items: flex-start; flex-direction: column; gap: .1rem; }
    .ct-row-total { font-size: .78rem; }
    .ct-stepper button, .ct-mini { width: 32px; height: 32px; }
    .ct-foot { gap: .45rem; }
    .ct-round-note { font-size: .68rem; }
}
@media (prefers-reduced-motion: reduce) {
    .ct-enter-active, .ct-leave-active { transition: none; }
}
</style>
