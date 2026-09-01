<script setup>
/**
 * توزيع الأقسام — "who covers what, today" (Wave 1).
 *
 * Same contract as the retired Volt screen: tapping a chip writes
 * immediately (no save button), carry-forward warns only on an empty
 * "today", all-branches mode has no floor to roster. Toggles are
 * optimistic — flip locally, POST, reconcile with the server's roster
 * map (every action returns it), revert on failure.
 */
import { computed, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { useToast } from '../../../Composables/useToast';
import '../../../../css/section-assignments.css';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    date: { type: String, required: true },
    branchLocked: { type: Boolean, required: true },
    sections: { type: Array, required: true },
    waiters: { type: Array, required: true },
    roster: { type: Object, required: true },
    carried: { type: Object, default: null },
    urls: { type: Object, required: true },
});

const toast = useToast();
const { ask } = useConfirm();

// Local mirror of the roster map — the optimistic layer.
const roster = reactive({ ...props.roster });
watch(() => props.roster, (fresh) => {
    Object.keys(roster).forEach((k) => delete roster[k]);
    Object.assign(roster, fresh);
});

const carried = ref(props.carried);
watch(() => props.carried, (v) => { carried.value = v; });

const pickedDate = ref(props.date);
watch(pickedDate, (d) => {
    if (d && d !== props.date) {
        router.get(props.urls.self, { date: d }, { preserveState: true, preserveScroll: true });
    }
});

const assignedCount = computed(() => Object.values(roster).reduce((n, ids) => n + ids.length, 0));
const assignedWaitersCount = computed(() => props.waiters.filter((w) => zonesFor(w.id).length > 0).length);
const coveredSectionsCount = computed(() => props.sections.filter((z) => (roster[z.id] ?? []).length > 0).length);
const uncoveredSections = computed(() => props.sections.filter((z) => (roster[z.id] ?? []).length === 0));
const isOn = (zoneId, userId) => (roster[zoneId] ?? []).includes(userId);
const zonesFor = (userId) => props.sections.filter((zone) => isOn(zone.id, userId));
const pending = ref(new Set());
const assignmentKey = (zoneId, userId) => `${zoneId}:${userId}`;
const isPending = (zoneId, userId) => pending.value.has(assignmentKey(zoneId, userId));

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const postJson = async (url, body) => {
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(body),
    });
    return { status: res.status, data: await res.json().catch(() => null) };
};

const applyRoster = (fresh) => {
    Object.keys(roster).forEach((k) => delete roster[k]);
    Object.assign(roster, fresh ?? {});
    // Any roster change can create/kill the carry-forward state — a fresh
    // visit recomputes it; cheap enough to just clear the banner locally
    // once ANYTHING is assigned today.
    if (assignedCount.value > 0) carried.value = null;
};

const toggle = async (zoneId, userId) => {
    const key = assignmentKey(zoneId, userId);
    if (pending.value.has(key)) return;

    const previous = [...(roster[zoneId] ?? [])];
    const wasOn = previous.includes(userId);
    roster[zoneId] = wasOn
        ? previous.filter((id) => id !== userId)
        : [...previous, userId];
    pending.value = new Set([...pending.value, key]);

    try {
        const { data } = await postJson(props.urls.toggle, {
            zone_lookup_id: zoneId,
            user_id: userId,
            date: props.date,
        });
        if (data?.ok) {
            applyRoster(data.roster);
        } else {
            roster[zoneId] = previous;
            toast.warning(data?.message ?? 'تعذّر الحفظ — حاول مجدداً.');
        }
    } catch {
        roster[zoneId] = previous;
        toast.error('انقطع الاتصال — التغيير ما انحفظ.');
    } finally {
        const fresh = new Set(pending.value);
        fresh.delete(key);
        pending.value = fresh;
    }
};

const copyPrevious = async () => {
    try {
        const { data } = await postJson(props.urls.copyPrevious, { date: props.date });
        if (data?.ok) {
            applyRoster(data.roster);
            toast.success(data.message);
        } else {
            toast.warning(data?.message ?? 'تعذّر النسخ.');
        }
    } catch {
        toast.error('انقطع الاتصال — ما انفذ النسخ.');
    }
};

const clearDay = async () => {
    const yes = await ask({
        title: 'مسح توزيع هذا اليوم بالكامل؟',
        confirmLabel: 'امسح',
        danger: true,
    });
    if (! yes) return;

    try {
        const { data } = await postJson(props.urls.clearDay, { date: props.date });
        if (data?.ok) {
            applyRoster(data.roster);
            carried.value = data.carried ?? null;
            toast[data.carried ? 'info' : 'success'](data.message);
        }
    } catch {
        toast.error('انقطع الاتصال — ما انفذ المسح.');
    }
};
</script>

<template>
    <Head title="توزيع الأقسام" />

    <PageHeader title="توزيع الصالة" icon="bi-people"
                subtitle="حدّد أقسام كل جرسون؛ ويمكن للجرسون تغطية أكثر من قسم" />

    <div class="sa-wrap">
        <div class="sa-head">
            <div class="sa-head-copy">
                <h3>من يغطي كل قسم اليوم؟</h3>
                <p>تظهر هنا الحسابات النشطة بدور «جرسون» فقط. اختر لكل جرسون قسماً أو أكثر؛ الحفظ فوري ويؤثر على «قسمي»، مهامه وتنبيهاته.</p>
            </div>
            <div class="sa-head-tools">
                <input v-model="pickedDate" type="date" class="sa-date" aria-label="تاريخ الخدمة">
                <button type="button" class="sa-btn" @click="copyPrevious">
                    <i class="bi bi-clipboard-check"></i> انسخ توزيع الأمس
                </button>
                <button v-if="assignedCount > 0" type="button" class="sa-btn sa-btn--danger" @click="clearDay">
                    <i class="bi bi-x-circle"></i> مسح اليوم
                </button>
            </div>
        </div>

        <div v-if="carried" class="sa-note sa-note--warn">
            <i class="bi bi-arrow-repeat"></i>
            ما في توزيع لليوم — توزيع {{ carried.label }}
            لسا شغّال تلقائياً على لوحات الجرسونية. اضغط «انسخ توزيع الأمس» لتثبيته، أو وزّع من جديد.
        </div>

        <div v-if="branchLocked" class="sa-note sa-note--warn">
            <i class="bi bi-exclamation-triangle-fill"></i>
            أنت في وضع «كل الفروع». اختر فرعاً محدداً من الأعلى — التوزيع خاص بصالة فرع واحد.
        </div>

        <div v-else-if="sections.length === 0" class="sa-note">
            <i class="bi bi-info-circle"></i>
            ما في أقسام على طاولات هذا الفرع بعد. عرّف الأقسام ثم اربط كل طاولة بقسمها.
            <a :href="urls.manageZones">إدارة الأقسام</a>
        </div>

        <div v-else-if="waiters.length === 0" class="sa-note">
            <i class="bi bi-info-circle"></i>
            ما في حسابات نشطة بدور «جرسون» مرتبطة بهذا الفرع.
        </div>

        <template v-else>
            <div class="sa-summary" aria-label="ملخص التوزيع">
                <div class="sa-summary-item">
                    <span>الموزعون</span>
                    <strong>{{ assignedWaitersCount }} <small>من {{ waiters.length }}</small></strong>
                </div>
                <div class="sa-summary-item">
                    <span>الأقسام المغطاة</span>
                    <strong>{{ coveredSectionsCount }} <small>من {{ sections.length }}</small></strong>
                </div>
                <div class="sa-summary-item" :class="{ 'is-alert': uncoveredSections.length > 0 }">
                    <span>بحاجة لتغطية</span>
                    <strong>{{ uncoveredSections.length }}</strong>
                </div>
            </div>

            <div v-if="uncoveredSections.length > 0" class="sa-note sa-note--warn">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <strong>أقسام بلا تغطية:</strong>
                <span>{{ uncoveredSections.map((z) => z.label).join('، ') }}</span>
            </div>

            <div class="sa-grid">
                <section v-for="w in waiters" :key="w.id" class="sa-waiter" :class="{ 'is-unassigned': zonesFor(w.id).length === 0 }">
                    <header class="sa-waiter-head">
                        <span class="sa-avatar"><i class="bi bi-person"></i></span>
                        <span class="sa-waiter-copy">
                            <strong>{{ w.name }}</strong>
                            <small>{{ w.roleLabel }}</small>
                        </span>
                        <span v-if="w.onShift" class="sa-shift"><span class="sa-onshift"></span> حاضر الآن</span>
                        <span v-else class="sa-shift is-off">غير مسجل حضور</span>
                    </header>

                    <div class="sa-waiter-status">
                        <span v-if="zonesFor(w.id).length > 0">
                            يغطي <strong>{{ zonesFor(w.id).length }}</strong>
                            {{ zonesFor(w.id).length === 1 ? 'قسم' : 'أقسام' }}
                        </span>
                        <span v-else><i class="bi bi-info-circle"></i> لم يُسند له قسم اليوم</span>
                    </div>

                    <div class="sa-zone-options" role="group" :aria-label="`أقسام ${w.name}`">
                        <button v-for="z in sections" :key="`${w.id}-${z.id}`" type="button"
                                class="sa-zone-option"
                                :class="{ 'is-on': isOn(z.id, w.id), 'is-saving': isPending(z.id, w.id) }"
                                :style="{ '--sec-color': z.color }"
                                :aria-pressed="isOn(z.id, w.id)"
                                :disabled="isPending(z.id, w.id)"
                                @click="toggle(z.id, w.id)">
                            <span class="sa-section-dot"></span>
                            <span class="sa-zone-copy">
                                <strong>{{ z.label }}</strong>
                                <small>{{ z.tablesCount }} طاولة</small>
                            </span>
                            <i class="bi" :class="isPending(z.id, w.id) ? 'bi-arrow-repeat' : (isOn(z.id, w.id) ? 'bi-check-circle-fill' : 'bi-plus-circle')"></i>
                        </button>
                    </div>
                </section>
            </div>

            <section class="sa-coverage">
                <header>
                    <div>
                        <strong>تغطية الأقسام</strong>
                        <small>مراجعة سريعة قبل بدء الخدمة</small>
                    </div>
                    <span>{{ assignedCount }} عملية ربط</span>
                </header>
                <div class="sa-coverage-grid">
                    <article v-for="z in sections" :key="`coverage-${z.id}`" :style="{ '--sec-color': z.color }"
                             :class="{ 'is-empty': (roster[z.id] ?? []).length === 0 }">
                        <span class="sa-section-dot"></span>
                        <div>
                            <strong>{{ z.label }}</strong>
                            <small v-if="(roster[z.id] ?? []).length > 0">
                                {{ waiters.filter((w) => isOn(z.id, w.id)).map((w) => w.name).join('، ') }}
                            </small>
                            <small v-else>بلا جرسون</small>
                        </div>
                    </article>
                </div>
            </section>

            <div class="sa-legend">
                <span><span class="sa-onshift"></span> مسجّل حضور الآن</span>
                <span>الجرسون يرى مهام وتنبيهات كل الأقسام المحددة له، وأي طاولة سبق أن استلم مسؤوليتها.</span>
                <span>إن لم يوجد أي توزيع محفوظ، يبقى كل فريق الصالة على الوضع العام حتى لا تضيع الطلبات.</span>
            </div>
        </template>
    </div>
</template>
