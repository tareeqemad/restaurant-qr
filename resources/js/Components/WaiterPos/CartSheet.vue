<script setup>
import { computed } from 'vue';
import { formatMoney } from '../../Composables/useMoney';
import DialogSurface from '../Ui/DialogSurface.vue';
import CartLine from './CartLine.vue';

const props = defineProps({
    open: { type: Boolean, required: true },
    lines: { type: Array, required: true },
    total: { type: Number, required: true },
    currency: { type: Object, required: true },
    notes: { type: String, default: '' },
    submitting: { type: Boolean, default: false },
    pending: { type: Boolean, default: false },
    mode: { type: String, default: 'new' },
    roundLabel: { type: String, default: '' },
    /** Selected staff-meal consumer's name (null = normal table order). */
    staffName: { type: String, default: null },
});

const emit = defineEmits({
    close: () => true,
    'edit-line': (index) => Number.isInteger(index) && index >= 0,
    'change-qty': (index, delta) => Number.isInteger(index) && Number.isInteger(delta),
    'remove-line': (index) => Number.isInteger(index) && index >= 0,
    'update:notes': (notes) => typeof notes === 'string',
    'open-staff': () => true,
    'check-orders': () => true,
    submit: (payload) => payload && typeof payload.notes === 'string',
});

const unitCount = computed(() => props.lines.reduce(
    (count, line) => count + Number(line.quantity || 0),
    0,
));
const isReview = computed(() => props.mode === 'review');

function forwardQuantity(index, delta) {
    emit('change-qty', index, delta);
}

function requestSubmit() {
    if (!props.lines.length || props.submitting) return;
    emit('submit', { notes: props.notes.trim() });
}
</script>

<template>
    <DialogSurface
        :open="open"
        variant="sheet-bottom"
        title-id="waiter-cart-title"
        max-width="640px"
        initial-focus=".close-cart"
        @close="emit('close')"
    >
    <aside
        class="cart-sheet"
        :aria-label="isReview ? 'مراجعة الجولة مع الزبون' : 'سلة الطلب'"
    >
        <header class="cart-header">
            <strong id="waiter-cart-title">
                <i class="bi" :class="isReview ? 'bi-person-check-fill' : 'bi-cart3'"></i>
                {{ isReview ? (roundLabel || 'مراجعة الجولة') : 'سلة الطلب' }}
            </strong>
            <span class="cart-count">{{ unitCount }}</span>
            <button type="button" class="close-cart" aria-label="إغلاق السلة" @click="emit('close')">
                <i class="bi bi-chevron-down"></i>
            </button>
        </header>

        <template v-if="lines.length">
            <div v-if="pending && !submitting" class="pending-order" role="status">
                <span>لم يصل تأكيد الإرسال. إعادة المحاولة تتحقق من الطلب نفسه.</span>
                <button type="button" @click="emit('check-orders')">عرض طلبات الطاولة</button>
            </div>
            <fieldset class="cart-lines" :disabled="pending || submitting">
                <CartLine
                    v-for="(line, index) in lines"
                    :key="line.id"
                    :line="line"
                    :index="index"
                    :currency="currency"
                    @edit="emit('edit-line', $event)"
                    @change-qty="forwardQuantity"
                    @remove="emit('remove-line', $event)"
                />
            </fieldset>

            <footer class="cart-footer">
                <div class="total-row">
                    <span>مجموع الأصناف</span>
                    <strong>{{ formatMoney(total, currency) }}</strong>
                </div>
                <fieldset class="cart-options-lock" :disabled="pending || submitting">
                <details class="cart-options" :open="Boolean(notes || staffName)">
                <summary>ملاحظة أو وجبة موظف</summary>
                <small class="tax-note">
                    <i class="bi bi-info-circle"></i>
                    الضريبة والخدمة تُحسب عند إرسال الطلب حسب الإعدادات.
                </small>
                <label class="notes-label" for="cart-general-notes">ملاحظة عامة (اختياري)</label>
                <input
                    id="cart-general-notes"
                    type="text"
                    class="notes-input"
                    :value="notes"
                    maxlength="1000"
                    placeholder="مثلاً: إرسال المشروبات أولاً"
                    @input="emit('update:notes', $event.target.value)"
                >
                <button v-if="!isReview" type="button" class="staff-meal" :class="{ 'is-on': staffName }" @click="emit('open-staff')">
                    <i class="bi bi-person-badge"></i>
                    <template v-if="staffName">وجبة موظف: <b>{{ staffName }}</b></template>
                    <template v-else>تسجيل وجبة موظف</template>
                </button>
                </details>
                </fieldset>

                <button
                    type="button"
                    class="submit-order"
                    :disabled="submitting"
                    @click="requestSubmit"
                >
                    <template v-if="submitting">
                        <i class="bi bi-hourglass-split"></i>
                        {{ isReview ? 'جارٍ اعتماد النسخة...' : 'جارٍ الإرسال...' }}
                    </template>
                    <template v-else>
                        <i class="bi" :class="isReview ? 'bi-check2-circle' : 'bi-send-check'"></i>
                        {{ isReview ? 'اعتماد وإرسال الجولة' : (pending ? 'التحقق وإعادة المحاولة' : 'إرسال الجولة') }}
                    </template>
                </button>
            </footer>
        </template>

        <div v-else class="empty-cart">
            <i class="bi bi-cart-x"></i>
            <p>{{ isReview ? 'لا تعتمد جولة فارغة — أضف الصنف المتفق عليه' : 'السلة فاضية — اختر أصنافاً من القائمة' }}</p>
        </div>
    </aside>
    </DialogSurface>
</template>

<style scoped>
.cart-sheet {
    /* DialogSurface teleports outside the page's CSS variable scope. */
    --wp-primary: var(--brand);
    --wp-primary-dark: var(--brand-hover);
    display: flex;
    flex-direction: column;
    width: 100%;
    max-height: 84vh;
    max-height: 84dvh;
    box-sizing: border-box;
    overflow: hidden;
    border: 1px solid var(--line);
    border-bottom: 0;
    border-radius: 12px 12px 0 0;
    background: var(--surface);
}
.cart-header {
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: .9rem 1rem;
    color: #FFFDFA;
    background: var(--brand-dark);
    font-size: 15px;
    font-weight: 800;
}
.cart-header > strong { flex: 1; }
.cart-count { padding: .1rem .6rem; border-radius: 8px; background: rgba(255, 255, 255, .22); font-size: 14px; font-weight: 800; }
.close-cart {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: 0;
    border-radius: 10px;
    color: #FFFDFA;
    background: rgba(255, 255, 255, .16);
    font-size: 1.05rem;
    cursor: pointer;
}
.close-cart:hover { background: rgba(255, 255, 255, .24); }
.cart-lines { min-width: 0; min-height: 0; margin: 0; border: 0; overflow-y: auto; padding: .25rem 0; overscroll-behavior: contain; }
.cart-options-lock { min-width: 0; margin: 0; padding: 0; border: 0; }
.pending-order { display: grid; gap: .4rem; padding: .7rem 1rem; color: #92400e; background: #F8F0E2; font-size: 14px; }
.pending-order button { min-height: 44px; border: 1px solid #f59e0b; border-radius: 8px; background: var(--surface); color: inherit; font: inherit; cursor: pointer; border-color: var(--accent); }
.cart-footer { padding: .9rem 1rem 1rem; border-top: 1px solid var(--line); background: var(--surface); }
.total-row { display: flex; align-items: baseline; justify-content: space-between; gap: .75rem; margin-bottom: .5rem; }
.total-row > span { color: var(--ink); font-size: 15px; font-weight: 700; }
.total-row strong { color: var(--ink); font-size: 1.55rem; font-weight: 800; }
.tax-note { display: block; margin-bottom: .6rem; color: var(--muted); font-size: 14px; }
.cart-options { margin-bottom: .6rem; }
.cart-options summary { display: list-item; min-height: 44px; padding: .65rem .35rem; box-sizing: border-box; color: var(--ink); font-size: 14px; cursor: pointer; }
.notes-label {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
.notes-input {
    width: 100%;
    min-height: 44px;
    box-sizing: border-box;
    margin-bottom: .6rem;
    padding: .5rem .6rem;
    border: 1px solid var(--line);
    border-radius: 8px;
    font-size: 14px;
}
.notes-input:focus { outline: 2px solid color-mix(in srgb, var(--wp-primary) 30%, transparent); border-color: var(--wp-primary); }
.submit-order {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .45rem;
    width: 100%;
    min-height: 50px;
    padding: .75rem;
    border: 0;
    border-radius: 8px;
    color: #FFFDFA;
    background: var(--wp-primary);
    box-shadow: none;
    font-size: 15.5px;
    font-weight: 800;
    cursor: pointer;
}
.submit-order:hover:not(:disabled) { background: var(--wp-primary-dark); }
.submit-order:disabled { opacity: .7; cursor: wait; }
.staff-meal {
    display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
    width: 100%; min-height: 44px; margin-bottom: .6rem;
    border: 1.5px dashed var(--line); border-radius: 10px;
    background: var(--surface); color: var(--ink);
    font-family: inherit; font-size: .875rem; font-weight: 700; cursor: pointer;
}
.staff-meal.is-on {
    border-style: solid; border-color: #b45309;
    background: var(--canvas); color: #92400e;
}
.empty-cart { padding: 2.2rem 1rem; color: var(--muted); text-align: center; }
.empty-cart i { display: block; margin-bottom: .4rem; font-size: 1.8rem; }
.empty-cart p { margin: 0; font-size: 14px; }

/* Task-specific component layout. */
.cart-sheet{max-height:90dvh;border-radius:12px 12px 0 0;background:var(--surface)}.cart-header{padding:20px 24px;background:var(--surface);color:var(--ink);border-bottom:1px solid var(--line);gap:12px}.cart-header strong{font-size:22px}.cart-header strong>i{display:none}.cart-count{background:var(--brand-soft);color:var(--brand);border-radius:6px;min-width:30px}.close-cart{background:var(--canvas);color:var(--ink);width:44px;height:44px;border-radius:8px}.cart-lines{padding:0 24px}.cart-footer{padding:20px 24px;border-top:1px solid var(--line);background:var(--surface);gap:16px}.total-row{padding:0 0 16px;border-bottom:1px solid var(--line)}.total-row strong{font-size:27px}.cart-options{padding:12px;background:var(--canvas);border:1px solid var(--line);border-radius:6px}.cart-options summary{min-height:24px;font-size:14px}.submit-order{min-height:54px;border-radius:8px;font-size:17px}.pending-order{margin:16px 24px;padding:14px;line-height:1.7;border-radius:6px}
</style>
