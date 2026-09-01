<script setup>
/**
 * Customer workspace for cashier and management. All common actions stay on
 * this page; history is split into calm tabs so a frequent customer never
 * turns the screen into several stacked tables.
 */
import { computed, ref } from "vue";
import { Head, Link, router, useForm } from "@inertiajs/vue3";
import AdminLayout from "../../../Layouts/AdminLayout.vue";
import EmptyState from "../../../Components/Ui/EmptyState.vue";
import PageHeader from "../../../Components/Ui/PageHeader.vue";
import { useConfirm } from "../../../Composables/useConfirm";

defineOptions({ layout: AdminLayout });

const props = defineProps({
    customer: { type: Object, required: true },
    branches: { type: Array, default: () => [] },
    visitedBranches: { type: Array, default: () => [] },
    reservations: { type: Array, default: () => [] },
    reviews: { type: Array, default: () => [] },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();
const tab = ref("overview");
const sheet = ref(null);

const editForm = useForm({
    name: props.customer.name,
    phone: props.customer.phone,
    email: props.customer.email ?? "",
    gender: props.customer.gender ?? "unspecified",
    birthday: props.customer.birthday ?? "",
    default_branch_id: props.customer.defaultBranchId ?? "",
});
const smsForm = useForm({
    message: `مرحباً ${props.customer.name}، معك إدارة المطعم.`,
});
const blockForm = useForm({ reason: "" });

const tabs = computed(() => [
    { key: "overview", label: "الملخص", icon: "bi-grid", count: null },
    {
        key: "reservations",
        label: "الحجوزات",
        icon: "bi-calendar2-check",
        count: props.reservations.length,
    },
    {
        key: "reviews",
        label: "التقييمات",
        icon: "bi-star",
        count: props.reviews.length,
    },
]);

function closeSheet() {
    sheet.value = null;
    editForm.clearErrors();
    smsForm.clearErrors();
    blockForm.clearErrors();
}

function saveCustomer() {
    editForm.put(props.urls.update, {
        preserveScroll: true,
        onSuccess: closeSheet,
    });
}

function sendSms() {
    smsForm.post(props.urls.sms, {
        preserveScroll: true,
        onSuccess: closeSheet,
    });
}

function blockCustomer() {
    blockForm.post(props.urls.block, {
        preserveScroll: true,
        onSuccess: closeSheet,
    });
}

async function unblockCustomer() {
    const yes = await ask({
        title: "إلغاء حظر الزبون؟",
        message: "سيستطيع الموظفون التعامل معه بصورة طبيعية من جديد.",
        confirmLabel: "إلغاء الحظر",
    });
    if (yes) router.post(props.urls.unblock, {}, { preserveScroll: true });
}

async function deleteCustomer() {
    const yes = await ask({
        title: "حذف ملف الزبون؟",
        message:
            "سيُخفى الملف من الدليل. لا تُحذف الفواتير أو القيود المالية المرتبطة به.",
        confirmLabel: "حذف الملف",
        danger: true,
    });
    if (yes) router.delete(props.urls.destroy);
}
</script>

<template>
    <Head :title="customer.name" />

    <PageHeader
        :title="customer.name"
        icon="bi-person-vcard"
        subtitle="'ملف زبون داخلي مرتبط برقم الجوال — بلا حساب دخول'"
        :crumbs="[{ label: 'الزبائن', url: urls.index }]"
    >
        <template #actions>
            <button
                v-if="can.notify"
                type="button"
                class="btn btn-primary"
                @click="sheet = 'sms'"
            >
                <i class="bi bi-chat-dots-fill"></i> إرسال SMS
            </button>
            <button
                v-if="can.update"
                type="button"
                class="btn btn-light"
                @click="sheet = 'edit'"
            >
                <i class="bi bi-pencil"></i> تعديل
            </button>
        </template>
    </PageHeader>

    <div v-if="customer.isBlocked" class="cp-block-alert">
        <i class="bi bi-slash-circle-fill"></i>
        <div>
            <strong>هذا الزبون محظور</strong
            ><span>{{ customer.blockedReason || "لم يُسجّل سبب." }}</span>
        </div>
        <button v-if="can.block" type="button" @click="unblockCustomer">
            إلغاء الحظر
        </button>
    </div>

    <div class="cp-layout">
        <aside class="cp-profile">
            <div
                class="cp-profile__hero"
                :style="{ '--hue': (customer.id * 47) % 360 }"
            >
                <span class="cp-avatar">{{ customer.initial }}</span>
                <div>
                    <h2>{{ customer.name }}</h2>
                    <span
                        class="cp-state"
                        :class="{ blocked: customer.isBlocked }"
                    >
                        <i
                            class="bi"
                            :class="
                                customer.isBlocked
                                    ? 'bi-slash-circle'
                                    : 'bi-check-circle-fill'
                            "
                        ></i>
                        {{ customer.isBlocked ? "محظور" : "نشط" }}
                    </span>
                </div>
            </div>

            <div class="cp-contact">
                <a :href="`tel:${customer.phone}`"
                    ><i class="bi bi-telephone-fill"></i>{{ customer.phone }}</a
                >
                <span v-if="customer.email"
                    ><i class="bi bi-envelope"></i>{{ customer.email }}</span
                >
                <span
                    ><i class="bi bi-building"></i
                    >{{
                        customer.defaultBranchName || "لا يوجد فرع مفضّل"
                    }}</span
                >
                <span
                    ><i class="bi bi-calendar3"></i>مسجّل
                    {{ customer.createdAgo }}</span
                >
            </div>

            <div class="cp-mini-stats">
                <span
                    ><b>{{ customer.counts.orders }}</b
                    ><small>طلب</small></span
                >
                <span
                    ><b>{{ customer.counts.invoices }}</b
                    ><small>فاتورة</small></span
                >
                <span
                    ><b>{{ customer.counts.reservations }}</b
                    ><small>حجز</small></span
                >
            </div>

            <div v-if="customer.loyalty" class="cp-loyalty">
                <div>
                    <i class="bi bi-stars"></i
                    ><strong>{{ customer.loyalty.points }}</strong
                    ><small>نقطة متاحة</small>
                </div>
                <span>{{ customer.loyalty.tier }}</span>
            </div>

            <div class="cp-side-actions">
                <button v-if="can.notify" type="button" @click="sheet = 'sms'">
                    <i class="bi bi-chat-dots"></i> رسالة
                </button>
                <button v-if="can.update" type="button" @click="sheet = 'edit'">
                    <i class="bi bi-pencil"></i> تعديل
                </button>
                <button
                    v-if="can.block && !customer.isBlocked"
                    type="button"
                    class="warn"
                    @click="sheet = 'block'"
                >
                    <i class="bi bi-slash-circle"></i> حظر
                </button>
                <button
                    v-if="can.block && customer.isBlocked"
                    type="button"
                    @click="unblockCustomer"
                >
                    <i class="bi bi-arrow-counterclockwise"></i> فك الحظر
                </button>
            </div>
        </aside>

        <main class="cp-workspace">
            <section
                class="cp-debt"
                :class="{ owing: customer.debt.amount > 0 }"
            >
                <div class="cp-debt__icon"><i class="bi bi-wallet2"></i></div>
                <div>
                    <small>الرصيد المفتوح</small>
                    <strong>{{ customer.debt.formatted }}</strong>
                    <span v-if="customer.debt.amount > 0"
                        >على {{ customer.debt.invoiceCount }} فاتورة</span
                    >
                    <span v-else>لا توجد ديون مستحقة</span>
                </div>
                <div v-if="customer.debt.creditLimit" class="cp-credit">
                    <small>السقف الائتماني</small
                    ><b>{{ customer.debt.creditLimit }}</b>
                </div>
                <div class="cp-credit cp-credit--advance">
                    <small>رصيد مقدم متاح</small
                    ><b>{{ customer.advance.formatted }}</b>
                </div>
                <a
                    :href="urls.debt"
                    class="btn"
                    :class="
                        customer.debt.amount > 0 ? 'btn-danger' : 'btn-light'
                    "
                >
                    سجل الدين والتحصيل <i class="bi bi-chevron-left"></i>
                </a>
            </section>

            <nav class="cp-tabs" aria-label="أقسام ملف الزبون">
                <button
                    v-for="item in tabs"
                    :key="item.key"
                    type="button"
                    :class="{ active: tab === item.key }"
                    @click="tab = item.key"
                >
                    <i class="bi" :class="item.icon"></i>{{ item.label }}
                    <b v-if="item.count !== null">{{ item.count }}</b>
                </button>
            </nav>

            <section v-if="tab === 'overview'" class="cp-panel">
                <div class="cp-section-title">
                    <div>
                        <small>نظرة سريعة</small>
                        <h3>علاقة الزبون بالمطعم</h3>
                    </div>
                </div>

                <div class="cp-overview-grid">
                    <article class="cp-overview-card">
                        <i class="bi bi-geo-alt-fill"></i>
                        <div>
                            <small>الفروع التي زارها</small
                            ><strong>{{ visitedBranches.length }}</strong>
                        </div>
                    </article>
                    <article class="cp-overview-card">
                        <i class="bi bi-arrow-repeat"></i>
                        <div>
                            <small>إجمالي الزيارات</small
                            ><strong>{{
                                customer.loyalty?.totalVisits ??
                                customer.counts.orders
                            }}</strong>
                        </div>
                    </article>
                    <article class="cp-overview-card">
                        <i class="bi bi-cash-coin"></i>
                        <div>
                            <small>إجمالي الإنفاق</small
                            ><strong>{{
                                customer.loyalty?.totalSpent ?? "—"
                            }}</strong>
                        </div>
                    </article>
                </div>

                <div class="cp-branches">
                    <h4>الفروع التي زارها</h4>
                    <div v-if="visitedBranches.length" class="cp-branch-list">
                        <span
                            v-for="branch in visitedBranches"
                            :key="branch.id"
                            :style="{ '--hue': branch.hue }"
                        >
                            <i class="bi bi-building"></i>{{ branch.name
                            }}<b>{{ branch.visits }} زيارة</b>
                        </span>
                    </div>
                    <p v-else class="cp-empty-line">
                        لا توجد زيارات مسجّلة بعد.
                    </p>
                </div>
            </section>

            <section v-else-if="tab === 'reservations'" class="cp-panel">
                <div class="cp-section-title">
                    <div>
                        <small>آخر 50 حجزاً</small>
                        <h3>سجل الحجوزات</h3>
                    </div>
                </div>
                <div v-if="reservations.length" class="cp-timeline">
                    <article
                        v-for="reservation in reservations"
                        :key="reservation.id"
                    >
                        <span class="cp-timeline__dot"></span>
                        <div class="cp-timeline__main">
                            <strong>{{ reservation.reference }}</strong>
                            <span
                                >{{ reservation.branch || "فرع غير محدد" }} ·
                                {{ reservation.partySize }} أشخاص</span
                            >
                            <small v-if="reservation.table"
                                >طاولة {{ reservation.table }}</small
                            >
                        </div>
                        <div class="cp-timeline__when">
                            <b>{{ reservation.reservedFor }}</b
                            ><small>{{ reservation.relative }}</small>
                        </div>
                        <span
                            class="badge"
                            :class="`bg-${reservation.statusColor}`"
                            >{{ reservation.status }}</span
                        >
                    </article>
                </div>
                <EmptyState
                    v-else
                    icon="bi-calendar-x"
                    title="لا توجد حجوزات"
                    message="حجوزات الزبون المستقبلية والسابقة ستظهر هنا."
                />
            </section>

            <section v-else class="cp-panel">
                <div class="cp-section-title">
                    <div>
                        <small>آخر 20 تقييماً</small>
                        <h3>آراء الزبون</h3>
                    </div>
                </div>
                <div v-if="reviews.length" class="cp-reviews">
                    <article v-for="review in reviews" :key="review.id">
                        <div class="cp-stars">
                            <i
                                v-for="n in 5"
                                :key="n"
                                class="bi"
                                :class="
                                    n <= review.rating
                                        ? 'bi-star-fill'
                                        : 'bi-star'
                                "
                            ></i>
                        </div>
                        <strong v-if="review.title">{{ review.title }}</strong>
                        <p>{{ review.body || "بدون تعليق مكتوب." }}</p>
                        <small
                            >{{ review.branch || "فرع غير محدد" }} ·
                            {{ review.createdAgo }}</small
                        >
                    </article>
                </div>
                <EmptyState
                    v-else
                    icon="bi-star"
                    title="لا توجد تقييمات"
                    message="لا يوجد تقييم مرتبط بهذا الزبون."
                />
            </section>

            <button
                v-if="can.delete"
                type="button"
                class="cp-delete"
                @click="deleteCustomer"
            >
                <i class="bi bi-trash3"></i> حذف ملف الزبون
            </button>
        </main>
    </div>

    <Teleport to="body">
        <Transition name="cp-sheet">
            <div v-if="sheet" class="cp-backdrop" @click.self="closeSheet">
                <section class="cp-sheet" role="dialog" aria-modal="true">
                    <header>
                        <div>
                            <small v-if="sheet === 'sms'">تواصل مباشر</small>
                            <small v-else-if="sheet === 'edit'"
                                >بيانات الملف</small
                            >
                            <small v-else>إجراء إداري</small>
                            <h3>
                                {{
                                    sheet === "sms"
                                        ? "إرسال رسالة SMS"
                                        : sheet === "edit"
                                          ? "تعديل بيانات الزبون"
                                          : "حظر الزبون"
                                }}
                            </h3>
                        </div>
                        <button
                            type="button"
                            aria-label="إغلاق"
                            @click="closeSheet"
                        >
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </header>

                    <form v-if="sheet === 'sms'" @submit.prevent="sendSms">
                        <p class="cp-note">
                            <i class="bi bi-phone"></i> ستُرسل إلى
                            <bdi>{{ customer.phone }}</bdi>
                        </p>
                        <label
                            ><span>نص الرسالة</span
                            ><textarea
                                v-model="smsForm.message"
                                rows="6"
                                maxlength="500"
                                required
                            ></textarea>
                        </label>
                        <small class="cp-error">{{
                            smsForm.errors.message
                        }}</small>
                        <footer>
                            <button
                                type="button"
                                class="btn btn-light"
                                @click="closeSheet"
                            >
                                إلغاء</button
                            ><button
                                class="btn btn-primary"
                                :disabled="smsForm.processing"
                            >
                                <i class="bi bi-send"></i> إرسال الآن
                            </button>
                        </footer>
                    </form>

                    <form
                        v-else-if="sheet === 'edit'"
                        @submit.prevent="saveCustomer"
                    >
                        <div class="cp-form-grid">
                            <label
                                ><span>الاسم *</span
                                ><input
                                    v-model="editForm.name"
                                    required
                                    maxlength="120"
                            /></label>
                            <label
                                ><span>رقم الجوال *</span
                                ><input v-model="editForm.phone" required
                            /></label>
                            <label
                                ><span>الإيميل</span
                                ><input v-model="editForm.email" type="email"
                            /></label>
                            <label
                                ><span>الجنس</span
                                ><select v-model="editForm.gender">
                                    <option value="unspecified">
                                        غير محدد
                                    </option>
                                    <option value="male">ذكر</option>
                                    <option value="female">أنثى</option>
                                </select></label
                            >
                            <label
                                ><span>تاريخ الميلاد</span
                                ><input v-model="editForm.birthday" type="date"
                            /></label>
                            <label
                                ><span>الفرع المفضّل</span
                                ><select v-model="editForm.default_branch_id">
                                    <option value="">غير محدد</option>
                                    <option
                                        v-for="branch in branches"
                                        :key="branch.id"
                                        :value="branch.id"
                                    >
                                        {{ branch.name }}
                                    </option>
                                </select></label
                            >
                        </div>
                        <div
                            v-if="Object.keys(editForm.errors).length"
                            class="cp-form-errors"
                        >
                            <span
                                v-for="error in editForm.errors"
                                :key="error"
                                >{{ error }}</span
                            >
                        </div>
                        <footer>
                            <button
                                type="button"
                                class="btn btn-light"
                                @click="closeSheet"
                            >
                                إلغاء</button
                            ><button
                                class="btn btn-primary"
                                :disabled="editForm.processing"
                            >
                                <i class="bi bi-check2"></i> حفظ
                            </button>
                        </footer>
                    </form>

                    <form v-else @submit.prevent="blockCustomer">
                        <p class="cp-note cp-note--warn">
                            <i class="bi bi-exclamation-triangle"></i> الحظر
                            يمنع التعامل غير المقصود، ولا يحذف أي طلب أو فاتورة.
                        </p>
                        <label
                            ><span>سبب الحظر *</span
                            ><textarea
                                v-model="blockForm.reason"
                                rows="4"
                                maxlength="255"
                                required
                                placeholder="اكتب سبباً واضحاً للموظفين…"
                            ></textarea>
                        </label>
                        <small class="cp-error">{{
                            blockForm.errors.reason
                        }}</small>
                        <footer>
                            <button
                                type="button"
                                class="btn btn-light"
                                @click="closeSheet"
                            >
                                تراجع</button
                            ><button
                                class="btn btn-warning"
                                :disabled="blockForm.processing"
                            >
                                <i class="bi bi-slash-circle"></i> تأكيد الحظر
                            </button>
                        </footer>
                    </form>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.cp-block-alert {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    margin-bottom: 0.9rem;
    padding: 0.75rem 1rem;
    border: 1px solid #efb2b2;
    border-radius: 13px;
    background: #fff4f4;
    color: #a82f2f;
}
.cp-block-alert > i {
    font-size: 1.35rem;
}
.cp-block-alert div {
    display: flex;
    flex-direction: column;
}
.cp-block-alert span {
    font-size: 0.75rem;
}
.cp-block-alert button {
    margin-inline-start: auto;
    border: 0;
    border-radius: 9px;
    padding: 0.45rem 0.8rem;
    background: #fff;
    color: #9c2929;
    font-weight: 800;
}
.cp-layout {
    display: grid;
    grid-template-columns: minmax(245px, 300px) minmax(0, 1fr);
    gap: 1rem;
    align-items: start;
}
.cp-profile,
.cp-panel,
.cp-debt {
    border: 1px solid #e3ebe6;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 5px 20px rgba(24, 67, 43, 0.045);
}
.cp-profile {
    position: sticky;
    top: 88px;
    overflow: hidden;
}
.cp-profile__hero {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    padding: 1rem;
    background: linear-gradient(
        145deg,
        hsl(var(--hue) 45% 96%),
        hsl(var(--hue) 42% 91%)
    );
}
.cp-avatar {
    width: 60px;
    height: 60px;
    border-radius: 19px;
    display: grid;
    place-items: center;
    background: linear-gradient(
        145deg,
        hsl(var(--hue) 55% 48%),
        hsl(var(--hue) 60% 33%)
    );
    color: #fff;
    font-size: 1.55rem;
    font-weight: 900;
    box-shadow: 0 7px 18px hsl(var(--hue) 40% 35% / 0.2);
}
.cp-profile h2 {
    margin: 0 0 0.25rem;
    color: #14271d;
    font-size: 1.05rem;
}
.cp-state {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    color: #14733f;
    font-size: 0.7rem;
    font-weight: 800;
}
.cp-state.blocked {
    color: #b63131;
}
.cp-contact {
    padding: 0.9rem 1rem;
    display: grid;
    gap: 0.55rem;
    border-bottom: 1px solid #edf1ee;
}
.cp-contact a,
.cp-contact span {
    display: flex;
    align-items: center;
    gap: 0.55rem;
    min-width: 0;
    color: #53675d;
    font-size: 0.76rem;
    text-decoration: none;
}
.cp-contact i {
    width: 18px;
    color: #1b7b49;
}
.cp-mini-stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    padding: 0.75rem;
    gap: 0.4rem;
}
.cp-mini-stats span {
    padding: 0.45rem 0.2rem;
    border-radius: 10px;
    background: #f5f8f6;
    text-align: center;
}
.cp-mini-stats b,
.cp-mini-stats small {
    display: block;
}
.cp-mini-stats b {
    color: #183728;
}
.cp-mini-stats small {
    color: #839188;
    font-size: 0.64rem;
}
.cp-loyalty {
    margin: 0 0.75rem 0.75rem;
    padding: 0.7rem;
    border: 1px solid #f0dfb5;
    border-radius: 12px;
    background: #fffbef;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.cp-loyalty div {
    display: grid;
    grid-template-columns: auto auto;
    gap: 0.1rem 0.4rem;
    align-items: center;
}
.cp-loyalty i {
    grid-row: 1 / 3;
    color: #c48b18;
    font-size: 1.15rem;
}
.cp-loyalty strong {
    color: #76520e;
}
.cp-loyalty small {
    color: #977536;
    font-size: 0.62rem;
}
.cp-loyalty > span {
    font-size: 0.68rem;
    color: #7d5a17;
    font-weight: 800;
}
.cp-side-actions {
    padding: 0 0.75rem 0.75rem;
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.4rem;
}
.cp-side-actions button {
    min-height: 40px;
    border: 1px solid #dfe8e3;
    border-radius: 10px;
    background: #fff;
    color: #345247;
    font-size: 0.74rem;
    font-weight: 750;
}
.cp-side-actions .warn {
    color: #a86a12;
    border-color: #eed4aa;
    background: #fffaf1;
}
.cp-workspace {
    min-width: 0;
}
.cp-debt {
    min-height: 92px;
    padding: 0.85rem 1rem;
    display: grid;
    grid-template-columns: 48px minmax(140px, 1fr) repeat(3, auto);
    gap: 0.85rem;
    align-items: center;
    margin-bottom: 0.8rem;
}
.cp-debt.owing {
    border-color: #ebaaaa;
    background: linear-gradient(90deg, #fff 55%, #fff7f7);
}
.cp-debt__icon {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display: grid;
    place-items: center;
    background: #eaf6ef;
    color: #177543;
    font-size: 1.2rem;
}
.cp-debt.owing .cp-debt__icon {
    color: #b52e2e;
    background: #feecec;
}
.cp-debt > div:nth-child(2) {
    display: flex;
    flex-direction: column;
}
.cp-debt small,
.cp-debt span {
    color: #7f8d85;
    font-size: 0.68rem;
}
.cp-debt strong {
    font-size: 1.22rem;
    color: #183426;
}
.cp-debt.owing strong {
    color: #b52e2e;
}
.cp-credit {
    display: flex;
    flex-direction: column;
    padding-inline: 1rem;
    border-inline-start: 1px solid #e3e9e5;
}
.cp-credit b {
    font-size: 0.82rem;
}
.cp-credit--advance b {
    color: #177543;
}
.cp-tabs {
    display: flex;
    gap: 0.35rem;
    padding: 0.35rem;
    margin-bottom: 0.8rem;
    border: 1px solid #e3ebe6;
    border-radius: 13px;
    background: #f5f8f6;
    overflow-x: auto;
}
.cp-tabs button {
    flex: 1;
    min-width: 125px;
    min-height: 42px;
    border: 0;
    border-radius: 10px;
    background: transparent;
    color: #66796f;
    font-weight: 750;
}
.cp-tabs button.active {
    background: #fff;
    color: #106b3b;
    box-shadow: 0 3px 12px rgba(22, 78, 45, 0.08);
}
.cp-tabs i {
    margin-inline-end: 0.4rem;
}
.cp-tabs b {
    margin-inline-start: 0.35rem;
    padding: 0.12rem 0.38rem;
    border-radius: 99px;
    background: #e8f5ed;
    font-size: 0.65rem;
}
.cp-panel {
    min-height: 360px;
    padding: 1rem;
}
.cp-section-title {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 1rem;
}
.cp-section-title small {
    color: #87968e;
}
.cp-section-title h3 {
    margin: 0.08rem 0 0;
    color: #173326;
    font-size: 1.05rem;
}
.cp-overview-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.65rem;
}
.cp-overview-card {
    min-height: 90px;
    padding: 0.8rem;
    border: 1px solid #e8eee9;
    border-radius: 13px;
    background: #fafcfb;
    display: flex;
    align-items: center;
    gap: 0.7rem;
}
.cp-overview-card > i {
    width: 40px;
    height: 40px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: #e9f6ee;
    color: #177544;
    font-size: 1.05rem;
}
.cp-overview-card div {
    display: flex;
    flex-direction: column;
}
.cp-overview-card small {
    color: #819087;
    font-size: 0.68rem;
}
.cp-overview-card strong {
    color: #19382a;
    font-size: 0.95rem;
}
.cp-branches {
    margin-top: 1.25rem;
}
.cp-branches h4 {
    font-size: 0.85rem;
    color: #44594e;
}
.cp-branch-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}
.cp-branch-list span {
    padding: 0.55rem 0.7rem;
    border-radius: 11px;
    background: hsl(var(--hue) 55% 96%);
    color: hsl(var(--hue) 45% 30%);
    font-size: 0.73rem;
}
.cp-branch-list b {
    margin-inline-start: 0.5rem;
    padding: 0.12rem 0.35rem;
    border-radius: 99px;
    background: #fff;
    font-size: 0.63rem;
}
.cp-empty-line {
    color: #8b9790;
    font-size: 0.75rem;
}
.cp-timeline {
    display: grid;
}
.cp-timeline article {
    display: grid;
    grid-template-columns: 14px minmax(180px, 1fr) minmax(130px, auto) auto;
    gap: 0.7rem;
    align-items: center;
    padding: 0.75rem 0;
    border-bottom: 1px solid #edf1ee;
}
.cp-timeline__dot {
    width: 9px;
    height: 9px;
    border-radius: 50%;
    background: #1b7c49;
    box-shadow: 0 0 0 4px #e7f4ec;
}
.cp-timeline__main,
.cp-timeline__when {
    display: flex;
    flex-direction: column;
}
.cp-timeline__main strong {
    color: #1d382b;
}
.cp-timeline__main span,
.cp-timeline__main small,
.cp-timeline__when small {
    color: #85938b;
    font-size: 0.68rem;
}
.cp-timeline__when {
    text-align: end;
}
.cp-timeline__when b {
    color: #495e53;
    font-size: 0.73rem;
}
.cp-reviews {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.65rem;
}
.cp-reviews article {
    padding: 0.85rem;
    border: 1px solid #e8eee9;
    border-radius: 13px;
    background: #fafcfb;
}
.cp-stars {
    color: #d99b20;
    margin-bottom: 0.35rem;
}
.cp-reviews strong {
    display: block;
    color: #243c30;
}
.cp-reviews p {
    margin: 0.35rem 0;
    color: #596c62;
    font-size: 0.76rem;
    line-height: 1.65;
}
.cp-reviews small {
    color: #89958f;
    font-size: 0.66rem;
}
.cp-delete {
    margin-top: 0.8rem;
    border: 0;
    background: transparent;
    color: #b54a4a;
    font-size: 0.72rem;
}
.cp-backdrop {
    position: fixed;
    inset: 0;
    z-index: 1090;
    display: flex;
    justify-content: flex-start;
    background: rgba(11, 26, 18, 0.43);
    backdrop-filter: blur(2px);
}
.cp-sheet {
    width: min(520px, 94vw);
    height: 100%;
    padding: 1rem;
    overflow-y: auto;
    background: #fff;
    box-shadow: 18px 0 50px rgba(0, 0, 0, 0.2);
}
.cp-sheet header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding-bottom: 0.9rem;
    border-bottom: 1px solid #e8eeea;
}
.cp-sheet header small {
    color: #7b8e83;
}
.cp-sheet header h3 {
    margin: 0.1rem 0 0;
    font-size: 1.08rem;
    color: #19392a;
}
.cp-sheet header button {
    width: 40px;
    height: 40px;
    border: 0;
    border-radius: 11px;
    background: #f3f6f4;
}
.cp-sheet form {
    display: grid;
    gap: 0.8rem;
    padding-top: 1rem;
}
.cp-sheet label {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    color: #4c6156;
    font-size: 0.74rem;
    font-weight: 700;
}
.cp-sheet input,
.cp-sheet select,
.cp-sheet textarea {
    width: 100%;
    min-height: 45px;
    padding: 0.65rem 0.75rem;
    border: 1px solid #dce5df;
    border-radius: 10px;
    outline: 0;
    background: #fff;
}
.cp-sheet textarea {
    resize: vertical;
}
.cp-sheet input:focus,
.cp-sheet select:focus,
.cp-sheet textarea:focus {
    border-color: #187947;
    box-shadow: 0 0 0 3px rgba(24, 121, 71, 0.08);
}
.cp-form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.75rem;
}
.cp-note {
    padding: 0.7rem;
    border-radius: 10px;
    background: #eef7f2;
    color: #406153;
    font-size: 0.75rem;
}
.cp-note--warn {
    background: #fff7e8;
    color: #8a631d;
}
.cp-error,
.cp-form-errors {
    color: #bd3434;
    font-size: 0.7rem;
}
.cp-form-errors {
    display: grid;
    gap: 0.2rem;
    padding: 0.6rem;
    border-radius: 9px;
    background: #fff2f2;
}
.cp-sheet footer {
    display: flex;
    justify-content: flex-end;
    gap: 0.5rem;
    padding-top: 0.8rem;
    margin-top: 0.4rem;
    border-top: 1px solid #e8eeea;
}
.cp-sheet-enter-active,
.cp-sheet-leave-active {
    transition: opacity 0.18s ease;
}
.cp-sheet-enter-active .cp-sheet,
.cp-sheet-leave-active .cp-sheet {
    transition: transform 0.18s ease;
}
.cp-sheet-enter-from,
.cp-sheet-leave-to {
    opacity: 0;
}
.cp-sheet-enter-from .cp-sheet,
.cp-sheet-leave-to .cp-sheet {
    transform: translateX(-100%);
}
@media (max-width: 900px) {
    .cp-layout {
        grid-template-columns: 1fr;
    }
    .cp-profile {
        position: static;
    }
    .cp-profile__hero {
        justify-content: center;
    }
    .cp-contact {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
    .cp-debt {
        grid-template-columns: 44px 1fr auto;
    }
    .cp-credit {
        display: none;
    }
}
@media (max-width: 600px) {
    .cp-contact {
        grid-template-columns: 1fr;
    }
    .cp-debt {
        grid-template-columns: 42px 1fr;
    }
    .cp-debt > a {
        grid-column: 1 / -1;
    }
    .cp-overview-grid,
    .cp-reviews {
        grid-template-columns: 1fr;
    }
    .cp-timeline article {
        grid-template-columns: 12px 1fr auto;
    }
    .cp-timeline__when {
        grid-column: 2 / -1;
        text-align: start;
    }
    .cp-form-grid {
        grid-template-columns: 1fr;
    }
}
</style>
