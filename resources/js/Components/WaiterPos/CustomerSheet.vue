<script setup>
import { ref, watch } from 'vue';
import { formatMoney } from '../../Composables/useMoney';

const props = defineProps({
    customer: { type: Object, default: null },
    currency: { type: Object, required: true },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits({
    close: () => true,
    link: (payload) => payload && typeof payload.phone === 'string',
    detach: () => true,
});

const phone = ref('');
const name = ref('');
const createIfMissing = ref(false);

watch(() => props.customer, () => {
    phone.value = '';
    name.value = '';
    createIfMissing.value = false;
});

function submitSearch() {
    if (props.busy || !phone.value.trim()) return;

    emit('link', {
        phone: phone.value.trim(),
        name: name.value.trim() || null,
        create_if_missing: createIfMissing.value,
    });
}
</script>

<template>
    <Teleport to="body">
        <div
            class="customer-sheet-backdrop"
            @click.self="emit('close')"
            @keydown.escape.window="emit('close')"
        >
            <section
                class="customer-sheet"
                role="dialog"
                aria-modal="true"
                aria-labelledby="customer-sheet-title"
            >
                <header class="customer-sheet__header">
                    <div class="customer-sheet__heading">
                        <span class="customer-sheet__heading-icon" aria-hidden="true">
                            <i class="bi bi-person-check"></i>
                        </span>
                        <div>
                            <h2 id="customer-sheet-title">زبون الطاولة</h2>
                            <p>{{ customer ? 'بيانات الزبون المرتبط بهذه الجلسة' : 'ابحث برقم الجوال واربطه بالطلب' }}</p>
                        </div>
                    </div>
                    <button type="button" class="customer-sheet__close" aria-label="إغلاق" @click="emit('close')">
                        <i class="bi bi-x-lg"></i>
                    </button>
                </header>

                <div v-if="customer" class="customer-sheet__body">
                    <div class="customer-card">
                        <span class="customer-card__avatar" aria-hidden="true">
                            <i class="bi bi-person"></i>
                        </span>
                        <div class="customer-card__details">
                            <small>الزبون المرتبط</small>
                            <strong>{{ customer.name }}</strong>
                            <span v-if="customer.debt > 0.009" class="customer-card__status customer-card__status--debt">
                                <i class="bi bi-exclamation-circle"></i>
                                عليه دين سابق {{ formatMoney(customer.debt, currency) }}
                            </span>
                            <span v-else class="customer-card__status customer-card__status--clean">
                                <i class="bi bi-check-circle"></i>
                                لا ديون سابقة
                            </span>
                        </div>
                    </div>

                    <div class="customer-sheet__actions">
                        <button type="button" class="customer-sheet__button customer-sheet__button--secondary" @click="emit('close')">
                            إغلاق
                        </button>
                        <button
                            type="button"
                            class="customer-sheet__button customer-sheet__button--danger"
                            :disabled="busy"
                            @click="emit('detach')"
                        >
                            <i class="bi" :class="busy ? 'bi-hourglass-split' : 'bi-person-x'"></i>
                            {{ busy ? 'جارٍ فك الربط…' : 'فك ربط الزبون' }}
                        </button>
                    </div>
                </div>

                <form v-else class="customer-sheet__body" novalidate @submit.prevent="submitSearch">
                    <label class="customer-field" for="customer-phone">
                        <span class="customer-field__label">
                            رقم الجوال
                            <small>مطلوب</small>
                        </span>
                        <span class="customer-field__control customer-field__control--phone">
                            <i class="bi bi-phone" aria-hidden="true"></i>
                            <input
                                id="customer-phone"
                                v-model="phone"
                                type="tel"
                                inputmode="tel"
                                autocomplete="tel"
                                dir="ltr"
                                placeholder="0599123456"
                                maxlength="32"
                                aria-describedby="customer-phone-help"
                                autofocus
                            >
                        </span>
                        <small id="customer-phone-help" class="customer-field__help">
                            أدخل الرقم كما يكتبه الزبون؛ سيجري البحث في سجل الزبائن تلقائياً.
                        </small>
                    </label>

                    <label class="customer-create-option" :class="{ 'is-on': createIfMissing }">
                        <input v-model="createIfMissing" type="checkbox">
                        <span class="customer-create-option__switch" aria-hidden="true">
                            <span></span>
                        </span>
                        <span class="customer-create-option__copy">
                            <strong>إنشاء زبون جديد إذا لم نجده</strong>
                            <small>فعّلها فقط عندما يكون الرقم لزبون جديد.</small>
                        </span>
                    </label>

                    <label v-if="createIfMissing" class="customer-field" for="customer-name">
                        <span class="customer-field__label">
                            اسم الزبون
                            <small>اختياري</small>
                        </span>
                        <span class="customer-field__control">
                            <i class="bi bi-person" aria-hidden="true"></i>
                            <input
                                id="customer-name"
                                v-model="name"
                                type="text"
                                autocomplete="name"
                                maxlength="120"
                                placeholder="مثال: محمد أحمد"
                            >
                        </span>
                    </label>

                    <p v-if="!phone.trim()" class="customer-sheet__prompt">
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        اكتب رقم الجوال أولاً لتفعيل زر الربط.
                    </p>

                    <div class="customer-sheet__actions">
                        <button type="button" class="customer-sheet__button customer-sheet__button--secondary" @click="emit('close')">
                            إلغاء
                        </button>
                        <button
                            type="submit"
                            class="customer-sheet__button customer-sheet__button--primary"
                            :disabled="busy || !phone.trim()"
                        >
                            <i class="bi" :class="busy ? 'bi-hourglass-split' : 'bi-link-45deg'"></i>
                            <span v-if="busy">جارٍ التحقق…</span>
                            <span v-else>{{ createIfMissing ? 'البحث أو الإنشاء والربط' : 'البحث وربط الزبون' }}</span>
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.customer-sheet-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1095;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(15, 23, 42, .55);
    backdrop-filter: blur(3px);
}

.customer-sheet {
    width: min(520px, 100%);
    max-height: calc(100vh - 40px);
    max-height: calc(100dvh - 40px);
    display: flex;
    flex-direction: column;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, .65);
    border-radius: 22px;
    background: #fff;
    box-shadow: 0 28px 70px -24px rgba(15, 23, 42, .58);
}

.customer-sheet__header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 18px 20px;
    color: #fff;
    background: linear-gradient(135deg, #105d42 0%, #197252 100%);
}

.customer-sheet__heading {
    min-width: 0;
    flex: 1;
    display: flex;
    align-items: center;
    gap: 12px;
}

.customer-sheet__heading-icon {
    width: 44px;
    height: 44px;
    flex: 0 0 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, .2);
    border-radius: 13px;
    background: rgba(255, 255, 255, .13);
    font-size: 1.15rem;
}

.customer-sheet__heading h2,
.customer-sheet__heading p {
    margin: 0;
}

.customer-sheet__heading h2 {
    color: inherit;
    font-size: 1rem;
    font-weight: 900;
    line-height: 1.45;
}

.customer-sheet__heading p {
    margin-top: 2px;
    color: rgba(255, 255, 255, .78);
    font-size: .76rem;
    line-height: 1.5;
}

.customer-sheet__close {
    width: 42px;
    height: 42px;
    flex: 0 0 42px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(255, 255, 255, .18);
    border-radius: 12px;
    color: #fff;
    background: rgba(255, 255, 255, .12);
    cursor: pointer;
    transition: background .18s ease, transform .18s ease;
}

.customer-sheet__close:hover {
    background: rgba(255, 255, 255, .22);
}

.customer-sheet__close:active {
    transform: scale(.96);
}

.customer-sheet__body {
    display: grid;
    gap: 15px;
    padding: 20px;
    overflow-y: auto;
    overscroll-behavior: contain;
}

.customer-field {
    display: grid;
    gap: 7px;
    margin: 0;
}

.customer-field__label {
    display: flex;
    align-items: center;
    gap: 8px;
    color: #283a31;
    font-size: .82rem;
    font-weight: 850;
}

.customer-field__label small {
    padding: 2px 7px;
    border-radius: 999px;
    color: #67766e;
    background: #f1f5f3;
    font-size: .65rem;
    font-weight: 750;
}

.customer-field__control {
    min-height: 52px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-inline: 14px;
    border: 1.5px solid #dce6e0;
    border-radius: 13px;
    background: #fff;
    color: #7b8a82;
    transition: border-color .18s ease, box-shadow .18s ease;
}

.customer-field__control:focus-within {
    border-color: #197252;
    box-shadow: 0 0 0 4px rgba(25, 114, 82, .1);
}

.customer-field__control input {
    width: 100%;
    min-width: 0;
    min-height: 48px;
    padding: 0;
    border: 0;
    outline: 0;
    color: #18251f;
    background: transparent;
    font-family: inherit;
    font-size: .95rem;
    font-weight: 700;
}

.customer-field__control--phone {
    direction: ltr;
}

.customer-field__control--phone input {
    direction: ltr;
    text-align: left;
    unicode-bidi: plaintext;
    font-variant-numeric: tabular-nums;
    letter-spacing: .035em;
}

.customer-field__control input::placeholder {
    color: #a6b1ab;
    font-weight: 500;
}

.customer-field__help {
    color: #74827a;
    font-size: .7rem;
    line-height: 1.55;
}

.customer-create-option {
    position: relative;
    min-height: 66px;
    display: flex;
    align-items: center;
    gap: 12px;
    margin: 0;
    padding: 11px 13px;
    border: 1px solid #dce6e0;
    border-radius: 14px;
    background: #f8faf9;
    cursor: pointer;
    transition: border-color .18s ease, background .18s ease, box-shadow .18s ease;
}

.customer-create-option:hover {
    border-color: #b9d1c4;
}

.customer-create-option.is-on {
    border-color: #8fc1a8;
    background: #f0f8f3;
    box-shadow: inset 0 0 0 1px rgba(25, 114, 82, .05);
}

.customer-create-option > input {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    pointer-events: none;
}

.customer-create-option:focus-within {
    box-shadow: 0 0 0 4px rgba(25, 114, 82, .1);
}

.customer-create-option__switch {
    width: 42px;
    height: 24px;
    flex: 0 0 42px;
    display: flex;
    align-items: center;
    padding: 3px;
    border-radius: 999px;
    background: #cbd5cf;
    direction: ltr;
    transition: background .18s ease;
}

.customer-create-option__switch > span {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #fff;
    box-shadow: 0 2px 5px rgba(15, 23, 42, .2);
    transform: translateX(0);
    transition: transform .18s ease;
}

.customer-create-option.is-on .customer-create-option__switch {
    background: #197252;
}

.customer-create-option.is-on .customer-create-option__switch > span {
    transform: translateX(18px);
}

.customer-create-option__copy {
    min-width: 0;
    display: grid;
    gap: 2px;
}

.customer-create-option__copy strong {
    color: #283a31;
    font-size: .82rem;
    font-weight: 850;
    line-height: 1.5;
}

.customer-create-option__copy small {
    color: #74827a;
    font-size: .69rem;
    line-height: 1.5;
}

.customer-sheet__prompt {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: -2px 0 0;
    color: #7a6a45;
    font-size: .7rem;
    line-height: 1.5;
}

.customer-sheet__actions {
    display: flex;
    gap: 10px;
    padding-top: 2px;
}

.customer-sheet__button {
    min-height: 50px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 10px 18px;
    border-radius: 13px;
    font-family: inherit;
    font-size: .84rem;
    font-weight: 850;
    cursor: pointer;
    transition: border-color .18s ease, background .18s ease, transform .18s ease;
}

.customer-sheet__button:active:not(:disabled) {
    transform: translateY(1px);
}

.customer-sheet__button--secondary {
    min-width: 94px;
    border: 1px solid #dce6e0;
    color: #526159;
    background: #fff;
}

.customer-sheet__button--secondary:hover {
    border-color: #bdcbc3;
    background: #f8faf9;
}

.customer-sheet__button--primary,
.customer-sheet__button--danger {
    flex: 1;
}

.customer-sheet__button--primary {
    border: 1px solid #197252;
    color: #fff;
    background: #197252;
    box-shadow: 0 8px 18px -12px rgba(25, 114, 82, .9);
}

.customer-sheet__button--primary:hover:not(:disabled) {
    background: #125f43;
}

.customer-sheet__button--danger {
    border: 1px solid #fecaca;
    color: #b4232d;
    background: #fff7f7;
}

.customer-sheet__button--danger:hover:not(:disabled) {
    background: #fff0f0;
}

.customer-sheet__button:disabled {
    border-color: #dfe7e2;
    color: #95a19a;
    background: #edf2ef;
    box-shadow: none;
    cursor: not-allowed;
}

.customer-card {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 16px;
    border: 1px solid #dfe9e3;
    border-radius: 16px;
    background: #f7fbf8;
}

.customer-card__avatar {
    width: 50px;
    height: 50px;
    flex: 0 0 50px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 15px;
    color: #197252;
    background: #e5f4ea;
    font-size: 1.25rem;
}

.customer-card__details {
    min-width: 0;
    display: grid;
    gap: 3px;
}

.customer-card__details > small {
    color: #7a8981;
    font-size: .68rem;
}

.customer-card__details > strong {
    overflow: hidden;
    color: #1b2922;
    font-size: .95rem;
    font-weight: 900;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.customer-card__status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 2px;
    font-size: .73rem;
    font-weight: 800;
}

.customer-card__status--debt { color: #b4232d; }
.customer-card__status--clean { color: #137044; }

@media (max-width: 575.98px) {
    .customer-sheet-backdrop {
        align-items: flex-end;
        padding: 0;
        backdrop-filter: none;
    }

    .customer-sheet {
        width: 100%;
        max-height: 92vh;
        max-height: 92dvh;
        border-width: 0;
        border-radius: 20px 20px 0 0;
        box-shadow: 0 -18px 55px -22px rgba(15, 23, 42, .55);
    }

    .customer-sheet__header,
    .customer-sheet__body {
        padding-inline: 16px;
    }

    .customer-sheet__heading p {
        display: none;
    }

    .customer-sheet__actions {
        position: sticky;
        bottom: -20px;
        margin: 0 -16px -20px;
        padding: 12px 16px calc(12px + env(safe-area-inset-bottom));
        border-top: 1px solid #edf1ee;
        background: rgba(255, 255, 255, .96);
    }

    .customer-sheet__button--secondary {
        min-width: 82px;
    }
}

@media (prefers-reduced-motion: reduce) {
    .customer-sheet__close,
    .customer-sheet__button,
    .customer-create-option,
    .customer-create-option__switch,
    .customer-create-option__switch > span {
        transition: none;
    }
}
</style>
