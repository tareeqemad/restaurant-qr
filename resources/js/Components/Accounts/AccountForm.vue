<script setup>
import { computed } from 'vue'

const props = defineProps({
    form: { type: Object, required: true },
    parentOptions: { type: Array, default: () => [] },
    typeOptions: { type: Array, default: () => [] },
    balanceOptions: { type: Array, default: () => [] },
    balanceByType: { type: Object, default: () => ({}) },
    isSystem: { type: Boolean, default: false },
    hasJournalLines: { type: Boolean, default: false },
    variant: { type: String, default: 'page' },
    prefilledParent: { type: Object, default: null },
    parentNotice: { type: String, default: null },
    codeLocked: { type: Boolean, default: false },
    codeLockParent: { type: String, default: null },
    codeUnlocked: { type: Boolean, default: false },
})

const emit = defineEmits(['unlock-code'])

const isModal = computed(() => props.variant === 'modal')
const coreLocked = computed(() => props.isSystem || props.hasJournalLines)
const balanceLabel = computed(() => (
    props.balanceOptions.find((option) => option.value === props.form.normal_balance)?.label ?? ''
))
const activeInputId = computed(() => `account-active-${props.variant}`)

function syncBalance() {
    const expected = props.balanceByType[props.form.type]
    if (expected) props.form.normal_balance = expected
}
</script>

<template>
    <section v-if="prefilledParent && !isModal" class="account-notice parent">
        <span><i class="bi bi-diagram-3-fill"></i></span>
        <div>
            <strong>حساب فرعي تحت {{ prefilledParent.code }} — {{ prefilledParent.name }}</strong>
            <small>النوع وطبيعة الرصيد مطابقان للحساب الأب تلقائياً.</small>
        </div>
    </section>

    <section v-if="parentNotice" class="account-notice parent">
        <span><i class="bi bi-diagram-3-fill"></i></span>
        <div>
            <strong>الموقع داخل الشجرة</strong>
            <small>{{ parentNotice }}</small>
        </div>
    </section>

    <section v-if="isSystem" class="account-notice locked">
        <span><i class="bi bi-shield-lock-fill"></i></span>
        <div>
            <strong>حساب نظامي محمي</strong>
            <small>يمكن تعديل الاسم والوصف والترتيب فقط؛ الكود والنوع وطبيعة الرصيد تعتمد عليها القيود التلقائية.</small>
        </div>
    </section>

    <section v-else-if="hasJournalLines" class="account-notice posted">
        <span><i class="bi bi-journal-check"></i></span>
        <div>
            <strong>الحساب مستخدم في قيود سابقة</strong>
            <small>حفاظاً على التقارير التاريخية، يبقى الكود والنوع وطبيعة الرصيد مقفلة.</small>
        </div>
    </section>

    <div class="account-form" :class="{ modal: isModal }">
        <section class="account-form__section">
            <header v-if="!isModal">
                <span><i class="bi bi-card-heading"></i></span>
                <div><strong>هوية الحساب</strong><small>كود واضح واسم يشرح استخدام الحساب.</small></div>
            </header>

            <div class="account-form__grid">
                <label class="code-field">
                    <span>كود الحساب *</span>
                    <div class="locked-input">
                        <input
                            v-model="form.code"
                            type="text"
                            class="form-control"
                            :class="{ invalid: form.errors.code, suggested: codeLocked, unlocked: codeUnlocked }"
                            required
                            maxlength="32"
                            placeholder="مثال: 5101"
                            :readonly="codeLocked"
                            :disabled="coreLocked"
                        >
                        <button
                            v-if="codeLocked"
                            type="button"
                            title="تعديل الكود المقترح"
                            aria-label="تعديل الكود المقترح"
                            @click="emit('unlock-code')"
                        >
                            <i class="bi bi-lock-fill"></i>
                        </button>
                    </div>
                    <small v-if="codeLocked">مقترح من تسلسل الحساب الأب {{ codeLockParent }}.</small>
                    <small v-else-if="codeUnlocked" class="warning">تأكد أن الكود اليدوي لا يكسر تسلسل الشجرة.</small>
                    <small v-else>{{ coreLocked ? 'مقفل بعد الاستخدام.' : 'رقم فريد يسهل قراءة الدفتر.' }}</small>
                </label>

                <label class="name-field">
                    <span>اسم الحساب *</span>
                    <input
                        id="acc-name-field"
                        v-model="form.name"
                        type="text"
                        class="form-control"
                        :class="{ invalid: form.errors.name }"
                        required
                        maxlength="191"
                        placeholder="مثال: مصاريف صيانة"
                    >
                    <small>استخدم اسماً يفهمه المحاسب والمدير دون شرح إضافي.</small>
                </label>

                <label v-if="isModal">
                    <span>نوع الحساب *</span>
                    <select v-model="form.type" class="form-select" required :disabled="coreLocked" @change="syncBalance">
                        <option v-for="type in typeOptions" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </select>
                </label>

                <label v-if="isModal">
                    <span>طبيعة الرصيد</span>
                    <input class="form-control" :value="balanceLabel" readonly disabled>
                    <small>تتحدد تلقائياً حسب النوع.</small>
                </label>

                <label v-if="isModal">
                    <span>ترتيب العرض</span>
                    <input v-model="form.display_order" type="number" class="form-control" min="0" max="65535">
                </label>

                <label class="description-field">
                    <span>وصف الاستخدام</span>
                    <textarea v-model="form.description" rows="2" class="form-control" placeholder="متى يستخدم هذا الحساب؟"></textarea>
                </label>
            </div>
        </section>

        <section v-if="!isModal" class="account-form__section">
            <header>
                <span><i class="bi bi-diagram-3"></i></span>
                <div><strong>مكان الحساب وطبيعته</strong><small>حدد المجموعة؛ النظام يضبط طبيعة الرصيد المحاسبية.</small></div>
            </header>

            <div class="account-form__grid classification">
                <label>
                    <span>نوع الحساب *</span>
                    <select v-model="form.type" class="form-select" required :disabled="coreLocked" @change="syncBalance">
                        <option v-for="type in typeOptions" :key="type.value" :value="type.value">{{ type.label }}</option>
                    </select>
                </label>
                <label>
                    <span>طبيعة الرصيد</span>
                    <input class="form-control" :value="balanceLabel" readonly disabled>
                    <small>تحدد تلقائياً حسب النوع.</small>
                </label>
                <label>
                    <span>ترتيب العرض</span>
                    <input v-model="form.display_order" type="number" class="form-control" min="0" max="65535">
                    <small>الأصغر يظهر أولاً داخل المستوى نفسه.</small>
                </label>
                <label class="parent-field">
                    <span>الحساب الأب</span>
                    <select v-model="form.parent_account_id" class="form-select">
                        <option value="">حساب رئيسي بدون أب</option>
                        <option v-for="option in parentOptions" :key="option.id" :value="option.id">{{ option.label }}</option>
                    </select>
                    <small>يجب أن يكون الأب من النوع نفسه.</small>
                </label>
            </div>
        </section>

        <label v-if="!isSystem" class="account-active">
            <input :id="activeInputId" v-model="form.is_active" type="checkbox">
            <span>
                <strong>الحساب نشط</strong>
                <small>الحساب المعطّل يبقى محفوظاً في القيود السابقة ولا يظهر للاستخدام الجديد.</small>
            </span>
        </label>
    </div>

    <section v-if="Object.keys(form.errors).length" class="account-errors" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
            <strong>راجع بيانات الحساب</strong>
            <small v-for="(message, field) in form.errors" :key="field">{{ message }}</small>
        </div>
    </section>
</template>

<style scoped>
.account-notice {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-bottom: 10px;
    padding: 11px 13px;
    border: 1px solid #bdd9c6;
    border-radius: 12px;
    color: #28543a;
    background: #f0f8f3;
}

.account-notice > span {
    display: grid;
    flex: 0 0 34px;
    width: 34px;
    height: 34px;
    place-items: center;
    border-radius: 9px;
    background: #deefe4;
}

.account-notice > div {
    display: grid;
    gap: 2px;
}

.account-notice strong {
    font-size: .72rem;
}

.account-notice small {
    color: #708078;
    font-size: .62rem;
    line-height: 1.55;
}

.account-notice.locked {
    border-color: #ead1a5;
    color: #875009;
    background: #fff7e9;
}

.account-notice.locked > span {
    background: #ffebc9;
}

.account-notice.posted {
    border-color: #bdd8e7;
    color: #245d78;
    background: #f0f8fc;
}

.account-notice.posted > span {
    background: #deeff7;
}

.account-form {
    display: grid;
    gap: 11px;
}

.account-form__section {
    overflow: hidden;
    border: 1px solid #dde7e0;
    border-radius: 15px;
    background: #fff;
}

.account-form.modal .account-form__section {
    overflow: visible;
    border: 0;
    border-radius: 0;
}

.account-form__section > header {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 12px 14px;
    border-bottom: 1px solid #edf1ee;
    background: #f9fbfa;
}

.account-form__section > header > span {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border-radius: 9px;
    color: rgb(var(--primary-rgb, 31, 107, 80));
    background: #eaf4ee;
}

.account-form__section > header > div {
    display: grid;
}

.account-form__section > header strong {
    font-size: .75rem;
}

.account-form__section > header small {
    color: #839087;
    font-size: .61rem;
}

.account-form__grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 11px;
    padding: 14px;
}

.account-form.modal .account-form__grid {
    padding: 0;
}

.account-form__grid label {
    display: grid;
    align-content: start;
    gap: 5px;
}

.account-form__grid label > span {
    color: #4f6056;
    font-size: .66rem;
    font-weight: 850;
}

.account-form__grid label > small {
    color: #8b978f;
    font-size: .57rem;
    line-height: 1.45;
}

.account-form__grid label > small.warning {
    color: #9b5a08;
}

.name-field {
    grid-column: span 2;
}

.description-field,
.parent-field {
    grid-column: 1 / -1;
}

.locked-input {
    display: flex;
}

.locked-input input {
    min-width: 0;
    border-start-end-radius: 0;
    border-end-end-radius: 0;
}

.locked-input button {
    display: grid;
    flex: 0 0 44px;
    width: 44px;
    place-items: center;
    border: 1px solid #b7d5c1;
    border-inline-start: 0;
    border-start-end-radius: 10px;
    border-end-end-radius: 10px;
    color: rgb(var(--primary-rgb, 31, 107, 80));
    background: #edf7f0;
}

.form-control,
.form-select {
    min-height: 44px;
}

.form-control.invalid {
    border-color: #d94b4b;
}

.form-control.suggested {
    border-color: #8dbda0;
    background: #f5fbf7;
}

.form-control.unlocked {
    border-color: #dba652;
}

.account-active {
    display: flex;
    min-height: 62px;
    align-items: center;
    gap: 10px;
    padding: 11px 13px;
    border: 1px solid #dde7e0;
    border-radius: 13px;
    background: #fff;
    cursor: pointer;
}

.account-active input {
    width: 21px;
    height: 21px;
    accent-color: rgb(var(--primary-rgb, 31, 107, 80));
}

.account-active > span {
    display: grid;
}

.account-active strong {
    font-size: .72rem;
}

.account-active small {
    color: #849188;
    font-size: .59rem;
}

.account-errors {
    display: flex;
    align-items: flex-start;
    gap: 9px;
    margin-top: 10px;
    padding: 11px 13px;
    border: 1px solid #efbcbc;
    border-radius: 12px;
    color: #a52d2d;
    background: #fff3f3;
}

.account-errors > div {
    display: grid;
    gap: 2px;
}

.account-errors strong {
    font-size: .7rem;
}

.account-errors small {
    font-size: .6rem;
}

@media (max-width: 720px) {
    .account-form__grid {
        grid-template-columns: 1fr;
    }

    .name-field,
    .description-field,
    .parent-field {
        grid-column: auto;
    }
}
</style>
