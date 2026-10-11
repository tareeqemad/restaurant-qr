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
    border-radius: 12px;
    background: var(--surface);
    box-shadow: none;
}

.customer-sheet__header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding: 18px 20px;
    color: #FFFDFA;
    background: var(--canvas);
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
    border-radius: 12px;
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
    font-weight: 800;
    line-height: 1.45;
}

.customer-sheet__heading p {
    margin-top: 2px;
    color: rgba(255, 255, 255, .78);
    font-size: .875rem;
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
    color: #FFFDFA;
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
    color: var(--brand);
    font-size: .875rem;
    font-weight: 800;
}

.customer-field__label small {
    padding: 2px 7px;
    border-radius: 12px;
    color: var(--muted);
    background: var(--canvas);
    font-size: .875rem;
    font-weight: 750;
}

.customer-field__control {
    min-height: 52px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-inline: 14px;
    border: 1.5px solid var(--line);
    border-radius: 12px;
    background: var(--surface);
    color: var(--muted);
    transition: border-color .18s ease, box-shadow .18s ease;
}

.customer-field__control:focus-within {
    border-color: var(--brand);
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--brand) 10%, transparent);
}

.customer-field__control input {
    width: 100%;
    min-width: 0;
    min-height: 48px;
    padding: 0;
    border: 0;
    outline: 0;
    color: var(--ink);
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
    color: var(--muted);
    font-weight: 500;
}

.customer-field__help {
    color: var(--muted);
    font-size: .875rem;
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
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--canvas);
    cursor: pointer;
    transition: border-color .18s ease, background .18s ease, box-shadow .18s ease;
}

.customer-create-option:hover {
    border-color: var(--line);
}

.customer-create-option.is-on {
    border-color: var(--brand);
    background: var(--canvas);
    box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--brand) 5%, transparent);
}

.customer-create-option > input {
    position: absolute;
    width: 1px;
    height: 1px;
    opacity: 0;
    pointer-events: none;
}

.customer-create-option:focus-within {
    box-shadow: 0 0 0 4px color-mix(in srgb, var(--brand) 10%, transparent);
}

.customer-create-option__switch {
    width: 42px;
    height: 24px;
    flex: 0 0 42px;
    display: flex;
    align-items: center;
    padding: 3px;
    border-radius: 12px;
    background: var(--surface-muted);
    direction: ltr;
    transition: background .18s ease;
}

.customer-create-option__switch > span {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: var(--surface);
    box-shadow: none;
    transform: translateX(0);
    transition: transform .18s ease;
}

.customer-create-option.is-on .customer-create-option__switch {
    background: var(--brand);
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
    color: var(--brand);
    font-size: .875rem;
    font-weight: 800;
    line-height: 1.5;
}

.customer-create-option__copy small {
    color: var(--muted);
    font-size: .875rem;
    line-height: 1.5;
}

.customer-sheet__prompt {
    display: flex;
    align-items: center;
    gap: 7px;
    margin: -2px 0 0;
    color: #7F5525;
    font-size: .875rem;
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
    border-radius: 8px;
    font-family: inherit;
    font-size: .875rem;
    font-weight: 800;
    cursor: pointer;
    transition: border-color .18s ease, background .18s ease, transform .18s ease;
}

.customer-sheet__button:active:not(:disabled) {
    transform: translateY(1px);
}

.customer-sheet__button--secondary {
    min-width: 94px;
    border: 1px solid var(--line);
    color: var(--ink);
    background: var(--surface);
}

.customer-sheet__button--secondary:hover {
    border-color: var(--line);
    background: var(--canvas);
}

.customer-sheet__button--primary,
.customer-sheet__button--danger {
    flex: 1;
}

.customer-sheet__button--primary {
    border: 1px solid var(--brand);
    color: #FFFDFA;
    background: var(--brand);
    box-shadow: none;
}

.customer-sheet__button--primary:hover:not(:disabled) {
    background: var(--brand);
}

.customer-sheet__button--danger {
    border: 1px solid #fecaca;
    color: #b4232d;
    background: var(--surface);
}

.customer-sheet__button--danger:hover:not(:disabled) {
    background: var(--canvas);
}

.customer-sheet__button:disabled {
    border-color: var(--line);
    color: var(--muted);
    background: var(--canvas);
    box-shadow: none;
    cursor: not-allowed;
}

.customer-card {
    display: flex;
    align-items: center;
    gap: 13px;
    padding: 16px;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: var(--canvas);
}

.customer-card__avatar {
    width: 50px;
    height: 50px;
    flex: 0 0 50px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    color: var(--brand);
    background: var(--canvas);
    font-size: 1.25rem;
}

.customer-card__details {
    min-width: 0;
    display: grid;
    gap: 3px;
}

.customer-card__details > small {
    color: var(--muted);
    font-size: .875rem;
}

.customer-card__details > strong {
    overflow: hidden;
    color: var(--ink);
    font-size: .95rem;
    font-weight: 800;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.customer-card__status {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 2px;
    font-size: .875rem;
    font-weight: 800;
}

.customer-card__status--debt { color: #b4232d; }
.customer-card__status--clean { color: var(--brand); }

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
        border-radius: 12px 12px 0 0;
        box-shadow: none;
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
        border-top: 1px solid var(--line);
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

/* Task-specific component layout. */
.customer-sheet{border-radius:10px;background:var(--surface);box-shadow:none;max-height:90dvh;overflow-y:auto}.customer-sheet__header{padding:20px 24px;background:var(--surface);color:var(--ink);border-bottom:1px solid var(--line);gap:14px}.customer-sheet__header h2,.customer-sheet__header strong{font-size:22px;color:var(--ink)}.customer-sheet__header button{width:44px;height:44px;min-width:44px;border-radius:8px;background:var(--canvas);color:var(--ink)}.customer-sheet__body{padding:20px 24px}.customer-sheet__actions{padding:16px 24px;border-top:1px solid var(--line);background:var(--surface);position:sticky;bottom:0;z-index:2}.customer-sheet input:not([type=checkbox]):not([type=radio]),.customer-sheet select{min-height:48px;border-radius:6px;font-size:16px}.customer-sheet textarea{min-height:90px;border-radius:6px;font-size:15px}.modifier-options,.exclusion-options{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px}.modifier-option,.exclusion-option{min-height:52px;padding:12px;border-radius:6px;font-size:15px}.group-title{padding-bottom:12px;border-bottom:1px solid var(--line);margin-bottom:12px}.quantity-row{padding:16px 0}.quantity-stepper button{width:44px;height:44px}.save-action,.customer-sheet__button,.go{min-height:50px;border-radius:8px;font-size:16px}.person{border:0;border-bottom:1px solid var(--line);border-radius:0;padding:16px 0;min-height:70px}.person .name{font-size:16px}.bill-summary{border-radius:8px;padding:18px;background:var(--canvas)}.bill-summary__primary strong{font-size:27px}.session-round{border-radius:6px;margin-bottom:14px}.session-item{padding:14px 12px;gap:12px}.session-item__name-row strong{font-size:16px}.session-item__detail{font-size:14px;line-height:1.6}.session-item__cancel{min-height:42px}.customer-card{border-radius:8px;padding:18px;background:var(--canvas)}@media(max-width:600px){.customer-sheet__header,.customer-sheet__body{padding:18px}.customer-sheet__actions{padding:14px 18px}.modifier-options,.exclusion-options{grid-template-columns:1fr}}
</style>
