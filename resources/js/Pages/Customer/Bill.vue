<script setup>
/**
 * فاتورة الزبون — Wave 2. Totals come from the server formatted (Order
 * columns, never recomputed). Requesting the bill is a classic POST; the
 * bank-transfer declaration stays a REAL multipart form (proof image) so
 * the server keeps its upload pipeline untouched.
 */
import { ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import Toaster from '../../Components/Ui/Toaster.vue';
import { useToast } from '../../Composables/useToast';

const props = defineProps({
    sessionInfo: { type: Object, required: true },
    invoice: { type: Object, default: null },
    hasPendingChangeRequest: { type: Boolean, default: false },
    orders: { type: Array, required: true },
    totals: { type: Object, required: true },
    transfer: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const toast = useToast();
const page = usePage();

watch(() => page.props.flash, (flash) => {
    if (! flash) return;
    if (flash.success) toast.success(flash.success);
    if (flash.error) toast.error(flash.error);
    if (flash.warning) toast.warning(flash.warning);
    if (flash.info) toast.info(flash.info);
}, { immediate: true });

const billNote = ref(props.sessionInfo.billRequestNote ?? '');
const transferOpen = ref(false);
const requestBusy = ref(false);
const transferForm = useForm({
    sender_name: props.sessionInfo.defaultSenderName ?? '',
    amount: props.totals.totalRaw,
    notes: '',
    proof: null,
});

watch(() => props.totals.totalRaw, (amount) => {
    if (! transferForm.processing) transferForm.amount = amount;
});

const requestBill = () => {
    if (props.sessionInfo.billRequested || requestBusy.value) return;
    requestBusy.value = true;
    router.post(props.urls.requestBill, { note: billNote.value.trim() }, {
        preserveScroll: true,
        preserveState: true,
        showProgress: false,
        onFinish: () => { requestBusy.value = false; },
    });
};

const chooseProof = (event) => {
    transferForm.proof = event.target.files?.[0] ?? null;
};

const submitTransfer = () => {
    if (transferForm.processing) return;
    transferForm.post(props.urls.declareTransfer, {
        preserveScroll: true,
        preserveState: true,
        forceFormData: true,
        showProgress: false,
        onSuccess: () => {
            transferOpen.value = false;
            transferForm.reset('proof');
        },
    });
};
</script>

<template>
    <Head title="فاتورتك" />

    <div class="bl">
        <header class="bl-top">
            <div>
                <h1><i class="bi bi-receipt-cutoff"></i> فاتورتك</h1>
                <small>
                    <template v-if="sessionInfo.tableNumber">طاولة {{ sessionInfo.tableNumber }} · </template>
                    {{ orders.length }} {{ orders.length === 1 ? 'طلب' : 'طلبات' }}
                </small>
            </div>
            <Link :href="urls.track" class="bl-chip" view-transition><i class="bi bi-arrow-right"></i> طلباتي</Link>
        </header>

        <div v-if="hasPendingChangeRequest" class="bl-status bl-status--wait">
            <i class="bi bi-arrow-repeat"></i>
            <div>
                <strong>الإجمالي قيد التحديث</strong>
                طلب التعديل أو الإلغاء ما زال بانتظار الجرسون. لا تطلب الفاتورة ولا تحوّل المبلغ حتى يظهر القرار هنا.
            </div>
        </div>
        <div v-else-if="invoice" class="bl-status bl-status--ok">
            <i class="bi bi-check-circle-fill"></i>
            <div>
                <strong>صدرت الفاتورة</strong>
                رقمها {{ invoice.number }}.
                <template v-if="invoice.balanceDue">المتبقي <strong>{{ invoice.balance }}</strong>.</template>
                <template v-else>دفعتك مسجلة — صحتين!</template>
            </div>
        </div>
        <div v-else-if="sessionInfo.billRequested" class="bl-status bl-status--wait">
            <i class="bi bi-hourglass-split"></i>
            <div>
                <strong>وصل طلب الحساب</strong>
                بعثناه {{ sessionInfo.billRequestedAgo }} — الكاشير عم يجهزه.
            </div>
        </div>

        <div v-if="orders.length === 0" class="bl-empty">
            <i class="bi bi-receipt"></i>
            <h3>لا طلبات بعد</h3>
            <p>ما في أصناف على هذه الطاولة حتى الآن.</p>
            <Link :href="urls.menu" class="bl-cta" view-transition><i class="bi bi-list-ul"></i> المنيو</Link>
        </div>

        <template v-else>
            <section class="bl-card">
                <div v-for="order in orders" :key="order.id" class="bl-order">
                    <header>
                        <span class="bl-order-num"><i class="bi bi-hash"></i>{{ order.number }}</span>
                        <small>{{ order.createdAgo }}</small>
                    </header>
                    <div v-for="(it, i) in order.items" :key="i" class="bl-item" :class="{ 'is-cancelled': it.cancelled }">
                        <div class="bl-item-main">
                            <span class="bl-item-name">
                                <span class="bl-qty">×{{ it.qty }}</span>
                                {{ it.name }}
                                <small v-if="it.cancelled" class="bl-cancelled-tag">ملغي</small>
                            </span>
                            <small v-if="it.modifiers.length"><i class="bi bi-plus-circle-fill"></i> {{ it.modifiers.join(' • ') }}</small>
                            <small v-if="it.notes"><i class="bi bi-chat-left-text-fill"></i> {{ it.notes }}</small>
                        </div>
                        <span class="bl-item-price">{{ it.subtotal }}</span>
                    </div>
                </div>

                <div class="bl-totals">
                    <div class="bl-row"><span>المجموع</span><span>{{ totals.subtotal }}</span></div>
                    <div v-if="totals.tax" class="bl-row"><span>الضريبة</span><span>{{ totals.tax }}</span></div>
                    <div v-if="totals.service" class="bl-row"><span>الخدمة</span><span>{{ totals.service }}</span></div>
                    <div class="bl-grand"><span>الإجمالي</span><strong>{{ totals.total }}</strong></div>
                </div>
            </section>

            <div class="bl-note">
                <i class="bi bi-info-circle-fill"></i>
                <span>الدفع عند الكاشير أو مع الجرسون — الفاتورة الرسمية بتطلع من هناك.</span>
            </div>
        </template>

        <div v-if="hasPendingChangeRequest" class="bl-status bl-status--wait">
            <i class="bi bi-hourglass-split"></i>
            <div>
                <strong>الدفع متوقف مؤقتاً</strong>
                <span>بعد قرار الجرسون سيُحدّث الإجمالي تلقائياً ويمكنك طلب الحساب أو تسجيل التحويل.</span>
            </div>
        </div>

        <template v-else-if="! invoice">
            <div class="bl-request">
                <textarea v-model="billNote" maxlength="500"
                          placeholder="ملاحظة للكاشير (اختياري) — مثلاً: بدنا نقسم الحساب"></textarea>
                <button type="button" class="bl-request-btn" :disabled="sessionInfo.billRequested || requestBusy"
                        :aria-busy="requestBusy" @click="requestBill">
                    <i class="bi" :class="requestBusy ? 'bi-arrow-repeat bl-spin' : (sessionInfo.billRequested ? 'bi-check2-circle' : 'bi-receipt-cutoff')"></i>
                    {{ requestBusy ? 'جارٍ إرسال الطلب…' : (sessionInfo.billRequested ? 'انطلب الحساب' : (orders.length === 0 ? 'اطلب إغلاق الجلسة' : 'اطلب الحساب من الكاشير')) }}
                </button>
            </div>

            <div v-if="orders.length" class="bl-transfer">
                <div v-if="transfer.pending" class="bl-status bl-status--wait">
                    <i class="bi bi-hourglass-split"></i>
                    <div>
                        <strong>تم استلام إشعار تحويلك ({{ transfer.pending.amount }})</strong>
                        <span>الكاشير يتأكد منه في البنك الآن — رجاءً انتظر التأكيد قبل المغادرة.</span>
                    </div>
                </div>

                <template v-else>
                    <button type="button" class="bl-transfer-toggle" @click="transferOpen = ! transferOpen">
                        <i class="bi bi-bank"></i> دفعت تحويلاً بنكياً؟ سجّله هنا
                        <i class="bi ms-auto" :class="transferOpen ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                    </button>

                    <div v-if="transferOpen" class="bl-transfer-body">
                        <div v-if="transfer.details" class="bl-transfer-details">
                            <strong>حوّل إلى:</strong>
                            <pre>{{ transfer.details }}</pre>
                        </div>
                        <p v-else class="bl-transfer-hint">اسأل الكاشير عن رقم الحساب البنكي، ثم بعد التحويل سجّل التفاصيل هنا.</p>

                        <form class="bl-transfer-form" @submit.prevent="submitTransfer">
                            <label>اسم المُرسِل <span>*</span>
                                <input v-model="transferForm.sender_name" type="text" maxlength="120" required
                                       placeholder="الاسم كما يظهر في التحويل">
                                <small v-if="transferForm.errors.sender_name" class="bl-field-error">{{ transferForm.errors.sender_name }}</small>
                            </label>
                            <label>المبلغ المحوَّل <span>*</span>
                                <input v-model="transferForm.amount" type="number" step="0.01" min="0.01" max="99999999.99" required>
                                <small v-if="transferForm.errors.amount" class="bl-field-error">{{ transferForm.errors.amount }}</small>
                            </label>
                            <label>ملاحظة (اختياري)
                                <input v-model="transferForm.notes" type="text" maxlength="500" placeholder="رقم العملية مثلاً">
                                <small v-if="transferForm.errors.notes" class="bl-field-error">{{ transferForm.errors.notes }}</small>
                            </label>
                            <label>صورة وصل التحويل <span>*</span>
                                <input type="file" accept="image/jpeg,image/png,image/webp" required @change="chooseProof">
                                <small v-if="transferForm.errors.proof" class="bl-field-error">{{ transferForm.errors.proof }}</small>
                            </label>
                            <button type="submit" class="bl-transfer-btn" :disabled="transferForm.processing"
                                    :aria-busy="transferForm.processing">
                                <i class="bi" :class="transferForm.processing ? 'bi-arrow-repeat bl-spin' : 'bi-send-check'"></i>
                                {{ transferForm.processing ? 'جارٍ رفع الوصل…' : 'أبلغ الكاشير بالتحويل' }}
                            </button>
                        </form>
                    </div>
                </template>
            </div>
        </template>
    </div>

    <Toaster />
</template>

<style scoped>
.bl {
    min-height: 100dvh;
    background: #f8fafc;
    color: #0f172a;
    padding: 1rem .9rem 2.5rem;
    max-width: 640px;
    margin-inline: auto;
    display: flex;
    flex-direction: column;
    gap: .8rem;
}
.bl-top { display: flex; align-items: center; justify-content: space-between; }
.bl-top h1 { margin: 0; font-size: 1.2rem; font-weight: 900; display: flex; align-items: center; gap: .45rem; }
.bl-top h1 i { color: rgb(var(--primary-rgb)); }
.bl-top small { color: #64748b; }
.bl-chip {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    min-height: 40px;
    padding: 0 .9rem;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: #fff;
    color: #334155;
    font-size: .82rem;
    font-weight: 700;
    text-decoration: none;
}

.bl-status {
    display: flex;
    gap: .7rem;
    align-items: flex-start;
    border-radius: 14px;
    padding: .85rem .95rem;
    font-size: .84rem;
}
.bl-status > i { font-size: 1.15rem; margin-top: 1px; }
.bl-status strong { display: block; }
.bl-status--wait { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
.bl-status--ok { background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46; }

.bl-empty { text-align: center; padding: 3rem 1rem; color: #94a3b8; }
.bl-empty i { font-size: 2.6rem; }
.bl-empty h3 { margin: .8rem 0 .2rem; color: #334155; font-weight: 900; font-size: 1.05rem; }
.bl-empty p { margin: 0 0 1rem; font-size: .86rem; }
.bl-cta {
    min-height: 46px;
    padding: 0 1.4rem;
    border-radius: 13px;
    background: rgb(var(--primary-rgb));
    color: #fff;
    font-weight: 800;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: .45rem;
}

.bl-card {
    background: #fff;
    border: 1px solid #eef0f3;
    border-radius: 18px;
    overflow: hidden;
}
.bl-order { padding: .9rem 1rem 0; }
.bl-order header {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: .35rem;
}
.bl-order-num { font-weight: 900; font-size: .85rem; color: rgb(var(--primary-rgb)); }
.bl-order header small { color: #94a3b8; font-size: .72rem; }
.bl-item {
    display: flex;
    justify-content: space-between;
    gap: .7rem;
    padding: .45rem 0;
    border-bottom: 1px dashed #eef0f3;
}
.bl-item:last-child { border-bottom: 0; }
.bl-item.is-cancelled .bl-item-name { text-decoration: line-through; color: #94a3b8; }
.bl-item-main { display: flex; flex-direction: column; gap: .12rem; min-width: 0; }
.bl-item-name { font-size: .88rem; font-weight: 800; }
.bl-qty {
    display: inline-block;
    background: #f1f5f9;
    border-radius: 7px;
    padding: 0 .35rem;
    font-size: .72rem;
    font-weight: 900;
    color: #475569;
}
.bl-cancelled-tag { color: #b91c1c; font-size: .68rem; font-weight: 900; }
.bl-item-main small { color: #64748b; font-size: .72rem; }
.bl-item-price { font-weight: 900; font-size: .84rem; white-space: nowrap; }

.bl-totals { background: #f8fafc; border-top: 1px solid #eef0f3; padding: .8rem 1rem; margin-top: .6rem; }
.bl-row { display: flex; justify-content: space-between; font-size: .82rem; color: #475569; padding: .18rem 0; }
.bl-grand {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    border-top: 1px solid #e2e8f0;
    margin-top: .4rem;
    padding-top: .55rem;
}
.bl-grand span { font-weight: 800; color: #334155; }
.bl-grand strong { font-size: 1.25rem; font-weight: 900; color: rgb(var(--primary-rgb)); }

.bl-note {
    display: flex;
    gap: .55rem;
    align-items: flex-start;
    color: #64748b;
    font-size: .78rem;
    padding: 0 .3rem;
}

.bl-request { display: flex; flex-direction: column; gap: .55rem; }
.bl-request textarea {
    border: 1.5px solid #e2e8f0;
    border-radius: 13px;
    padding: .7rem .85rem;
    font: inherit;
    font-size: .86rem;
    resize: none;
    min-height: 64px;
    background: #fff;
}
.bl-request-btn {
    min-height: 50px;
    border: 0;
    border-radius: 13px;
    background: rgb(var(--primary-rgb));
    color: #fff;
    font: inherit;
    font-size: .95rem;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
}
.bl-request-btn:disabled { opacity: .6; }

.bl-transfer { display: flex; flex-direction: column; gap: .6rem; }
.bl-transfer-toggle {
    display: flex;
    align-items: center;
    gap: .5rem;
    min-height: 48px;
    padding: 0 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 13px;
    background: #fff;
    color: #334155;
    font: inherit;
    font-size: .88rem;
    font-weight: 800;
    cursor: pointer;
    width: 100%;
}
.bl-transfer-toggle .ms-auto { margin-inline-start: auto; }
.bl-transfer-body {
    background: #fff;
    border: 1px solid #eef0f3;
    border-radius: 14px;
    padding: .9rem 1rem;
    display: flex;
    flex-direction: column;
    gap: .7rem;
}
.bl-transfer-details { background: #f4f6f1; border-radius: 10px; padding: .6rem .8rem; font-size: .82rem; }
.bl-transfer-details pre {
    margin: .25rem 0 0;
    
    unicode-bidi: plaintext;
    font-family: monospace;
    white-space: pre-wrap;
    color: #17211b;
}
.bl-transfer-hint { margin: 0; color: #64748b; font-size: .8rem; }
.bl-transfer-form { display: flex; flex-direction: column; gap: .6rem; }
.bl-transfer-form label {
    display: flex;
    flex-direction: column;
    gap: .25rem;
    margin: 0;
    font-size: .8rem;
    font-weight: 700;
    color: #334155;
}
.bl-transfer-form label span { color: #b91c1c; }
.bl-transfer-form input {
    border: 1.5px solid #e2e8f0;
    border-radius: 10px;
    min-height: 44px;
    padding: 0 .75rem;
    font: inherit;
    font-size: .88rem;
}
.bl-transfer-form input[type="file"] { padding: .5rem .75rem; }
.bl-field-error { color: #b91c1c; font-size: .72rem; font-weight: 700; }
.bl-transfer-btn {
    min-height: 48px;
    border: 0;
    border-radius: 12px;
    background: rgb(var(--primary-rgb));
    color: #fff;
    font: inherit;
    font-size: .92rem;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .5rem;
}
.bl-transfer-btn:disabled { opacity: .62; cursor: wait; }
.bl-spin { animation: bl-spin .8s linear infinite; }
@keyframes bl-spin { to { transform: rotate(360deg); } }
</style>
