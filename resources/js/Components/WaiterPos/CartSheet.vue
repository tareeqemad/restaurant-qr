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
            <div class="cart-lines">
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
            </div>

            <footer class="cart-footer">
                <div class="total-row">
                    <span>المجموع</span>
                    <strong>{{ formatMoney(total, currency) }}</strong>
                </div>
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
                    <template v-else>وجبة موظف؟</template>
                </button>

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
                        {{ isReview ? 'اعتماد وإرسال الجولة' : 'إرسال الجولة' }}
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
    display: flex;
    flex-direction: column;
    width: 100%;
    max-height: 84vh;
    max-height: 84dvh;
    box-sizing: border-box;
    overflow: hidden;
    border: 2px solid var(--wp-primary);
    border-bottom: 0;
    border-radius: 18px 18px 0 0;
    background: #fff;
}
.cart-header {
    display: flex;
    align-items: center;
    gap: .6rem;
    padding: .9rem 1rem;
    color: #fff;
    background: var(--wp-primary-dark);
    font-size: 15px;
    font-weight: 800;
}
.cart-header > strong { flex: 1; }
.cart-count { padding: .1rem .6rem; border-radius: 999px; background: rgba(255, 255, 255, .22); font-size: 13px; font-weight: 800; }
.close-cart {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: 0;
    border-radius: 10px;
    color: #fff;
    background: rgba(255, 255, 255, .16);
    font-size: 1.05rem;
    cursor: pointer;
}
.close-cart:hover { background: rgba(255, 255, 255, .24); }
.cart-lines { min-height: 0; overflow-y: auto; padding: .25rem 0; overscroll-behavior: contain; }
.cart-footer { padding: .9rem 1rem 1rem; border-top: 1px solid #e5e7eb; background: #f8fafc; }
.total-row { display: flex; align-items: baseline; justify-content: space-between; gap: .75rem; margin-bottom: .5rem; }
.total-row > span { color: #374151; font-size: 15px; font-weight: 700; }
.total-row strong { color: var(--wp-primary); font-size: 1.55rem; font-weight: 800; }
.tax-note { display: block; margin-bottom: .6rem; color: #6b7280; font-size: 11px; }
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
    border: 1px solid #e5e7eb;
    border-radius: 8px;
    font-size: 13px;
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
    border-radius: 12px;
    color: #fff;
    background: var(--wp-primary);
    box-shadow: 0 6px 16px -8px rgba(22, 101, 52, .7);
    font-size: 15.5px;
    font-weight: 800;
    cursor: pointer;
}
.submit-order:hover:not(:disabled) { background: var(--wp-primary-dark); }
.submit-order:disabled { opacity: .7; cursor: wait; }
.staff-meal {
    display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
    width: 100%; min-height: 44px; margin-bottom: .6rem;
    border: 1.5px dashed #cbd5e1; border-radius: 10px;
    background: #fff; color: #475569;
    font-family: inherit; font-size: .85rem; font-weight: 700; cursor: pointer;
}
.staff-meal.is-on {
    border-style: solid; border-color: #b45309;
    background: #fffbeb; color: #92400e;
}
.empty-cart { padding: 2.2rem 1rem; color: #9ca3af; text-align: center; }
.empty-cart i { display: block; margin-bottom: .4rem; font-size: 1.8rem; }
.empty-cart p { margin: 0; font-size: 13px; }
</style>
