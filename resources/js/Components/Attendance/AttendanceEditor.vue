<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    mode: { type: String, default: 'add' },
    record: { type: Object, default: null },
    staff: { type: Array, default: () => [] },
    defaults: { type: Object, required: true },
    submitting: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits({
    close: null,
    save: (payload) => Boolean(payload),
    exclude: (reason) => typeof reason === 'string',
});

const form = reactive({
    user_id: '',
    clock_in_at: '',
    clock_out_at: '',
    break_minutes: 0,
    notes: '',
    correction_reason: '',
});
const clientError = ref('');
const exclusionOpen = ref(false);
const exclusionReason = ref('');

const title = computed(() => {
    if (props.mode === 'add') return 'إضافة حضور يدوي';
    if (props.record?.needsReview) return `مراجعة دوام ${props.record.employee.name}`;
    return `تصحيح دوام ${props.record?.employee.name ?? ''}`;
});

function reset() {
    const record = props.record;
    Object.assign(form, props.mode === 'edit' && record ? {
        user_id: String(record.employee.id),
        clock_in_at: record.clockInValue,
        clock_out_at: record.needsReview ? '' : (record.clockOutValue ?? ''),
        break_minutes: record.breakMinutes ?? 0,
        notes: record.notes ?? '',
        correction_reason: record.needsReview ? 'تصحيح وقت الانصراف المنسي' : '',
    } : {
        user_id: '',
        clock_in_at: props.defaults.now,
        clock_out_at: '',
        break_minutes: 0,
        notes: '',
        correction_reason: '',
    });
    clientError.value = '';
    exclusionOpen.value = false;
    exclusionReason.value = '';
}

function close() {
    if (!props.submitting) emit('close');
}

function submit() {
    clientError.value = '';
    if (!form.user_id) clientError.value = 'اختر الموظف.';
    else if (!form.clock_in_at) clientError.value = 'أدخل وقت الحضور.';
    else if (form.correction_reason.trim().length < 3) clientError.value = 'اكتب سبباً واضحاً للحفظ.';
    if (clientError.value) return;

    emit('save', { ...form, correction_reason: form.correction_reason.trim() });
}

function exclude() {
    clientError.value = '';
    if (exclusionReason.value.trim().length < 3) {
        clientError.value = 'اكتب سبب استبعاد السجل.';
        return;
    }
    emit('exclude', exclusionReason.value.trim());
}

function onKeydown(event) {
    if (event.key === 'Escape' && props.open) close();
}

watch(() => props.open, (open) => {
    document.body.style.overflow = open ? 'hidden' : '';
    if (open) reset();
});
watch(() => props.record?.id, () => { if (props.open) reset(); });
onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <Transition name="attendance-sheet">
            <div v-if="open" class="editor-backdrop" @click.self="close">
                <form class="attendance-editor" @submit.prevent="submit">
                    <header>
                        <span class="editor-icon"><i :class="record?.needsReview ? 'bi bi-shield-exclamation' : 'bi bi-clock-history'"></i></span>
                        <div>
                            <small>{{ mode === 'add' ? 'سجل إداري موثّق' : 'كل تصحيح يحفظ بالقيم القديمة والجديدة' }}</small>
                            <h2>{{ title }}</h2>
                        </div>
                        <button type="button" class="close-button" aria-label="إغلاق" @click="close"><i class="bi bi-x-lg"></i></button>
                    </header>

                    <div v-if="record?.needsReview" class="review-banner">
                        <i class="bi bi-info-circle-fill"></i>
                        <span><strong>هذا السجل غير داخل في مجموع الساعات</strong><small>أدخل وقت الانصراف الحقيقي وسبب التصحيح لاعتماده.</small></span>
                    </div>

                    <div v-if="clientError || Object.keys(errors).length" class="form-error">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span>{{ clientError || Object.values(errors)[0] }}</span>
                    </div>

                    <div class="editor-body">
                        <label class="wide">
                            <span>الموظف <b>*</b></span>
                            <select v-model="form.user_id" :disabled="mode === 'edit'" required>
                                <option value="">اختر الموظف</option>
                                <option v-for="member in staff" :key="member.id" :value="String(member.id)">
                                    {{ member.name }}{{ member.username ? ` — @${member.username}` : '' }}
                                </option>
                            </select>
                        </label>

                        <label>
                            <span>وقت الحضور <b>*</b></span>
                            <input v-model="form.clock_in_at" type="datetime-local" required>
                        </label>

                        <label>
                            <span>وقت الانصراف</span>
                            <input v-model="form.clock_out_at" type="datetime-local" :min="form.clock_in_at || undefined">
                            <small>اتركه فارغاً إذا كان الموظف ما زال يعمل.</small>
                        </label>

                        <label>
                            <span>الاستراحة بالدقائق</span>
                            <input v-model.number="form.break_minutes" type="number" inputmode="numeric" min="0" max="480">
                        </label>

                        <label class="reason">
                            <span>سبب الإضافة أو التصحيح <b>*</b></span>
                            <input v-model="form.correction_reason" maxlength="250" placeholder="مثلاً: نسي تسجيل الانصراف" required>
                            <small>السبب إجباري ويظهر في سجل التدقيق.</small>
                        </label>

                        <label class="wide">
                            <span>ملاحظة داخلية</span>
                            <textarea v-model="form.notes" rows="3" maxlength="500" placeholder="معلومة إضافية اختيارية…"></textarea>
                        </label>
                    </div>

                    <section v-if="mode === 'edit' && record?.can.delete" class="exclude-zone">
                        <button v-if="!exclusionOpen" type="button" class="exclude-trigger" @click="exclusionOpen = true">
                            <i class="bi bi-slash-circle"></i> استبعاد سجل أُنشئ بالخطأ
                        </button>
                        <div v-else class="exclude-form">
                            <p><strong>الاستبعاد لا يمسح أثر العملية.</strong><span>سيختفي من كشوف الساعات ويبقى السبب في سجل النشاطات.</span></p>
                            <input v-model="exclusionReason" maxlength="250" placeholder="سبب الاستبعاد…">
                            <button type="button" :disabled="submitting" @click="exclude">تأكيد الاستبعاد</button>
                        </div>
                    </section>

                    <footer>
                        <button type="button" class="secondary" @click="close">تراجع</button>
                        <button type="submit" class="primary" :disabled="submitting">
                            <span v-if="submitting" class="spinner-border spinner-border-sm"></span>
                            <i v-else class="bi bi-check2"></i>
                            {{ submitting ? 'جارٍ الحفظ…' : record?.needsReview ? 'اعتماد التصحيح' : 'حفظ السجل' }}
                        </button>
                    </footer>
                </form>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.editor-backdrop { position:fixed; inset:0; z-index:18000; display:grid; place-items:center; padding:1rem; background:rgba(13,29,20,.5); backdrop-filter:blur(3px); }
.attendance-editor { width:min(680px,100%); max-height:calc(100dvh - 2rem); overflow:auto; border:1px solid rgba(255,255,255,.7); border-radius:20px; background:#fff; box-shadow:0 28px 80px rgba(12,35,21,.25); }
.attendance-editor header { display:flex; align-items:center; gap:.7rem; padding:1rem 1rem .85rem; border-bottom:1px solid #e6ece8; }
.editor-icon { display:grid; flex:0 0 44px; height:44px; place-items:center; border-radius:13px; background:#eaf5ee; color:rgb(var(--primary-rgb, 22 115 67)); font-size:1.05rem; }
.attendance-editor header > div { display:grid; flex:1; }
.attendance-editor header small { color:#7c8b83; font-size:.6rem; }
.attendance-editor h2 { margin:.12rem 0 0; color:#1c3428; font-size:1rem; font-weight:900; }
.close-button { display:grid; flex:0 0 44px; height:44px; place-items:center; border:0; border-radius:12px; background:#f1f5f2; color:#627269; }
.review-banner,.form-error { display:flex; align-items:flex-start; gap:.55rem; margin:.75rem 1rem 0; padding:.68rem .75rem; border-radius:11px; }
.review-banner { background:#fff3d9; color:#905600; }
.review-banner span { display:grid; }
.review-banner strong { font-size:.69rem; }
.review-banner small { color:#a77a39; font-size:.59rem; }
.form-error { background:#fff0ef; color:#ae342e; font-size:.67rem; }
.editor-body { display:grid; grid-template-columns:1fr 1fr; gap:.7rem; padding:1rem; }
.editor-body label { display:flex; flex-direction:column; gap:.34rem; min-width:0; color:#53675c; font-size:.66rem; font-weight:800; }
.editor-body label > span b { color:#b64a3e; }
.editor-body label > small { color:#8a9690; font-size:.56rem; font-weight:600; }
.editor-body .wide { grid-column:1/-1; }
.editor-body input,.editor-body select,.editor-body textarea,.exclude-form input { width:100%; min-height:46px; padding:.58rem .72rem; border:1px solid #dce6df; border-radius:11px; background:#fff; color:#263c30; font:inherit; outline:0; }
.editor-body input:focus,.editor-body select:focus,.editor-body textarea:focus,.exclude-form input:focus { border-color:#83b796; box-shadow:0 0 0 3px rgba(22,115,67,.09); }
.editor-body select:disabled { background:#f2f5f3; color:#728078; }
.editor-body textarea { resize:vertical; }
.exclude-zone { margin:0 1rem 1rem; padding-top:.8rem; border-top:1px solid #edf1ee; }
.exclude-trigger { min-height:44px; padding:0 .75rem; border:1px solid #efc9c6; border-radius:11px; background:#fff9f8; color:#a63a32; font-size:.63rem; font-weight:800; }
.exclude-form { display:grid; grid-template-columns:1fr auto; gap:.55rem; padding:.7rem; border-radius:12px; background:#fff4f2; }
.exclude-form p { grid-column:1/-1; display:grid; margin:0; color:#963a33; }
.exclude-form p strong { font-size:.65rem; }
.exclude-form p span { color:#ae6d68; font-size:.56rem; }
.exclude-form button { min-height:46px; padding:0 .8rem; border:0; border-radius:10px; background:#b43d34; color:#fff; font-size:.62rem; font-weight:850; }
.attendance-editor footer { position:sticky; z-index:2; bottom:0; display:flex; gap:.55rem; justify-content:flex-end; padding:.8rem 1rem; border-top:1px solid #e6ece8; background:rgba(255,255,255,.96); backdrop-filter:blur(8px); }
.attendance-editor footer button { min-width:130px; min-height:46px; border:0; border-radius:11px; font-size:.67rem; font-weight:850; }
.attendance-editor footer .secondary { background:#f1f4f2; color:#58685f; }
.attendance-editor footer .primary { background:rgb(var(--primary-rgb, 22 115 67)); color:#fff; box-shadow:0 7px 18px rgba(22,115,67,.18); }
.attendance-editor footer button:disabled { opacity:.55; cursor:wait; }
.attendance-sheet-enter-active,.attendance-sheet-leave-active { transition:opacity .17s ease; }
.attendance-sheet-enter-active .attendance-editor,.attendance-sheet-leave-active .attendance-editor { transition:transform .17s ease; }
.attendance-sheet-enter-from,.attendance-sheet-leave-to { opacity:0; }
.attendance-sheet-enter-from .attendance-editor,.attendance-sheet-leave-to .attendance-editor { transform:translateY(12px) scale(.985); }
@media (max-width:640px) {
    .editor-backdrop { align-items:end; padding:0; }
    .attendance-editor { width:100%; max-height:94dvh; border-radius:20px 20px 0 0; }
    .editor-body { grid-template-columns:1fr; padding:.85rem; }
    .editor-body .wide { grid-column:auto; }
    .exclude-zone { margin-inline:.85rem; }
    .exclude-form { grid-template-columns:1fr; }
    .attendance-editor footer { padding:.72rem .85rem max(.72rem,env(safe-area-inset-bottom)); }
    .attendance-editor footer button { min-width:0; flex:1; }
}
@media (prefers-reduced-motion:reduce) {
    .attendance-sheet-enter-active,.attendance-sheet-leave-active,.attendance-sheet-enter-active .attendance-editor,.attendance-sheet-leave-active .attendance-editor { transition:none; }
}
</style>
