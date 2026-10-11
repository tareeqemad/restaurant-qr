<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { formPost } from '../../../Support/formPost';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    order: { type: Object, required: true },
});

const { ask } = useConfirm();
const cancelling = ref(null);
const reason = ref('');
const disposition = ref('return');
const transferOpen = ref(false);
const transferSearch = ref('');
const transferTargetId = ref(null);

const activeItems = computed(() => props.order.items.filter((item) => item.status !== 'cancelled'));
const selectedTransferTable = computed(() => props.order.session?.transferTables
    ?.find((table) => Number(table.id) === Number(transferTargetId.value)) ?? null);
const filteredTransferTables = computed(() => {
    const needle = transferSearch.value.trim().toLocaleLowerCase('ar');
    const tables = props.order.session?.transferTables ?? [];
    if (! needle) return tables;

    return tables.filter((table) => [table.number, table.name, table.zone]
        .filter(Boolean)
        .some((value) => String(value).toLocaleLowerCase('ar').includes(needle)));
});

const workflow = computed(() => {
    if (props.order.status === 'pending') {
        return {
            tone: 'amber', icon: 'bi-send-check', eyebrow: 'بانتظارك',
            title: 'راجع الطلب ثم اعتمده للمطبخ والبار',
            text: 'الاعتماد يرسل كل صنف إلى محطته ويخصم مكوّناته.',
        };
    }
    if (props.order.progress.ready > 0) {
        return {
            tone: 'green', icon: 'bi-bell-fill', eyebrow: 'جاهز الآن',
            title: `${props.order.progress.ready} صنف جاهز للتقديم`,
            text: 'قدّم الأصناف الخضراء بالأسفل؛ كل كبسة تحدّث الجلسة فوراً.',
        };
    }
    if (props.order.status === 'approved' || props.order.status === 'preparing') {
        return {
            tone: 'blue', icon: 'bi-fire', eyebrow: 'قيد التنفيذ',
            title: props.order.progress.preparing > 0 ? 'المطبخ أو البار يحضّر الطلب' : 'تم إرسال الطلب إلى محطاته',
            text: 'ستتحول الأصناف إلى جاهزة فور إنهاء المحطة لها.',
        };
    }
    if (props.order.progress.done || ['delivered', 'completed'].includes(props.order.status)) {
        return {
            tone: 'slate', icon: 'bi-check2-all', eyebrow: 'تم التسليم',
            title: 'الطلب وصل إلى الطاولة',
            text: 'تبقى الجلسة مفتوحة ويمكن للزبون إضافة طلب جديد عليها.',
        };
    }
    return {
        tone: props.order.status === 'cancelled' ? 'red' : 'slate',
        icon: props.order.status === 'cancelled' ? 'bi-x-circle' : 'bi-clock',
        eyebrow: props.order.statusLabel,
        title: props.order.status === 'cancelled' ? 'هذا الطلب ملغي' : 'تابع حالة الأصناف',
        text: props.order.cancelledReason || 'كل صنف ظاهر بحالته الحالية.',
    };
});

const openCancel = (scope, item = null) => {
    cancelling.value = { scope, item };
    reason.value = '';
    disposition.value = 'return';
};

const submitCancel = () => {
    if (! reason.value.trim()) return;
    const target = cancelling.value;
    if (target.scope === 'order') {
        formPost(props.order.urls.cancel, { reason: reason.value.trim() });
        return;
    }
    formPost(target.item.urls.cancel, {
        reason: reason.value.trim(),
        disposition: disposition.value,
    });
};

const approve = () => formPost(props.order.urls.approve);
const serve = (item) => formPost(item.urls.serve);

const unapprove = async () => {
    const yes = await ask({
        title: 'فك اعتماد الطلب؟',
        message: 'سيعود الطلب للانتظار وتُرجع مكوّناته إلى المخزون.',
        confirmLabel: 'فك الاعتماد',
        danger: true,
    });
    if (yes) formPost(props.order.urls.unapprove);
};

const openTransfer = () => {
    transferTargetId.value = null;
    transferSearch.value = '';
    transferOpen.value = true;
};

const transferSession = () => {
    if (! selectedTransferTable.value || ! props.order.session?.transferUrl) return;
    formPost(props.order.session.transferUrl, { target_table_id: selectedTransferTable.value.id });
};

const fmtQty = (qty) => (Number(qty) % 1 === 0 ? String(Number(qty)) : String(qty));
const ordersLabel = (count) => count === 1 ? 'طلب واحد' : count === 2 ? 'طلبان' : `${count} طلبات`;
const itemsLabel = (count) => count === 1 ? 'صنف واحد' : count === 2 ? 'صنفان' : `${count} أصناف`;
</script>

<template>
    <Head :title="`طلب ${order.number}`" />

    <PageHeader
        :title="`طلب ${order.number}`"
        :subtitle="order.session ? `ضمن جلسة طاولة ${order.session.tableLabel}` : order.typeLabel"
        icon="bi-receipt-cutoff"
        :crumbs="[{ label: 'طلبات الصالة', url: order.urls.index }]"
    >
        <template #actions>
            <span class="od-status" :class="`od-status--${order.statusColor}`">
                <span></span>{{ order.statusLabel }}
            </span>
        </template>
    </PageHeader>

    <section v-if="order.session" class="od-session" aria-label="ملخص الجلسة">
        <div class="od-table">
            <span class="od-table__icon"><i class="bi bi-grid-3x3-gap-fill"></i></span>
            <div>
                <small>الجلسة الحالية</small>
                <strong>طاولة {{ order.session.tableLabel }}</strong>
            </div>
        </div>

        <div class="od-session__facts">
            <span><i class="bi bi-people"></i> {{ order.session.coverCount || 1 }} أشخاص</span>
            <span><i class="bi bi-clock"></i> بدأت {{ order.session.openedAgo || order.session.openedAt }}</span>
            <span><i class="bi bi-receipt"></i> {{ ordersLabel(order.session.ordersCount) }} في الجلسة</span>
        </div>

        <button
            v-if="order.session.canTransfer"
            type="button"
            class="od-transfer-trigger"
            @click="openTransfer"
        >
            <i class="bi bi-arrow-left-right"></i>
            نقل الجلسة
        </button>
        <span v-else-if="order.session.transferBlockReason" class="od-transfer-blocked" :title="order.session.transferBlockReason">
            <i class="bi bi-lock"></i> النقل غير متاح
        </span>
    </section>

    <section class="od-workflow" :class="`od-workflow--${workflow.tone}`">
        <span class="od-workflow__icon"><i class="bi" :class="workflow.icon"></i></span>
        <div class="od-workflow__copy">
            <small>{{ workflow.eyebrow }}</small>
            <h2>{{ workflow.title }}</h2>
            <p>{{ workflow.text }}</p>
        </div>
        <button v-if="order.can.approve" type="button" class="od-primary" @click="approve">
            اعتماد وإرسال <i class="bi bi-arrow-left"></i>
        </button>
    </section>

    <div class="od-layout">
        <main class="od-main">
            <div v-if="order.customerNotes" class="od-note">
                <i class="bi bi-chat-square-text-fill"></i>
                <div><strong>ملاحظة الزبون</strong><p>{{ order.customerNotes }}</p></div>
            </div>
            <div v-if="order.cancelledReason" class="od-note od-note--danger">
                <i class="bi bi-x-octagon-fill"></i>
                <div><strong>سبب الإلغاء</strong><p>{{ order.cancelledReason }}</p></div>
            </div>

            <section class="od-card">
                <header class="od-card__head">
                    <div>
                        <small>تفاصيل التنفيذ</small>
                        <h2>{{ activeItems.length }} أصناف</h2>
                    </div>
                    <div class="od-progress" aria-label="تقدم الطلب">
                        <span v-if="order.progress.preparing"><b>{{ order.progress.preparing }}</b> تتحضّر</span>
                        <span v-if="order.progress.ready" class="is-ready"><b>{{ order.progress.ready }}</b> جاهزة</span>
                        <span v-if="order.progress.served"><b>{{ order.progress.served }}</b> قُدّمت</span>
                    </div>
                </header>

                <div class="od-items">
                    <article
                        v-for="item in order.items"
                        :key="item.id"
                        class="od-item"
                        :class="{ 'od-item--ready': item.status === 'ready', 'od-item--cancelled': item.status === 'cancelled' }"
                    >
                        <span class="od-item__qty">×{{ fmtQty(item.qty) }}</span>
                        <div class="od-item__body">
                            <div class="od-item__title">
                                <h3>{{ item.name }}</h3>
                                <strong>{{ item.subtotal }}</strong>
                            </div>
                            <div v-if="item.mods.length" class="od-item__mods">
                                <span v-for="(modifier, index) in item.mods" :key="index">
                                    {{ modifier.name }}<template v-if="modifier.delta"> {{ modifier.delta }}</template>
                                </span>
                            </div>
                            <p v-if="item.notes" class="od-item__note"><i class="bi bi-chat"></i> {{ item.notes }}</p>
                            <div class="od-item__meta">
                                <span class="od-station" :style="{ '--station': item.stationColor || '#64748b' }">
                                    <i class="bi bi-record-circle"></i>{{ item.stationName || 'بدون محطة' }}
                                </span>
                                <span class="od-line-status" :class="`od-line-status--${item.statusColor}`">{{ item.statusLabel }}</span>
                            </div>
                        </div>
                        <div class="od-item__actions">
                            <button
                                v-if="item.status === 'ready' && order.can.serve"
                                type="button"
                                class="od-serve"
                                @click="serve(item)"
                            >
                                <i class="bi bi-check2-all"></i> تم التقديم
                            </button>
                            <button
                                v-if="! ['cancelled', 'served'].includes(item.status) && order.can.cancelItems"
                                type="button"
                                class="od-cancel-line"
                                :aria-label="`إلغاء ${item.name}`"
                                @click="openCancel('item', item)"
                            >
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                    </article>
                </div>
            </section>
        </main>

        <aside class="od-side">
            <section class="od-card od-summary">
                <header class="od-card__head">
                    <div><small>الحساب</small><h2>ملخص الطلب</h2></div>
                    <i class="bi bi-wallet2"></i>
                </header>
                <div class="od-totals">
                    <div><span>المجموع الفرعي</span><strong>{{ order.totals.subtotal }}</strong></div>
                    <div v-if="order.totals.discount"><span>الخصم</span><strong class="is-discount">-{{ order.totals.discount }}</strong></div>
                    <div v-if="order.totals.tax"><span>الضريبة ({{ order.totals.taxRate }}%)</span><strong>{{ order.totals.tax }}</strong></div>
                    <div v-if="order.totals.service"><span>الخدمة ({{ order.totals.serviceRate }}%)</span><strong>{{ order.totals.service }}</strong></div>
                    <div class="od-total"><span>الإجمالي</span><strong>{{ order.totals.total }}</strong></div>
                </div>
            </section>

            <section class="od-card od-context">
                <h2>معلومات سريعة</h2>
                <dl>
                    <div><dt>أُرسل</dt><dd>{{ order.placedAgo }}</dd></div>
                    <div><dt>طريقة الطلب</dt><dd>{{ order.typeLabel }}</dd></div>
                    <div v-if="order.creatorName"><dt>أدخله</dt><dd>{{ order.creatorName }}</dd></div>
                    <div v-if="order.approverName"><dt>اعتمده</dt><dd>{{ order.approverName }}</dd></div>
                    <div v-if="order.session?.invoice"><dt>الفاتورة</dt><dd>{{ order.session.invoice.number }}</dd></div>
                </dl>
            </section>

            <details v-if="order.can.unapprove || order.can.cancelOrder" class="od-more">
                <summary><i class="bi bi-three-dots"></i> إجراءات إضافية</summary>
                <div>
                    <button v-if="order.can.unapprove" type="button" @click="unapprove">
                        <i class="bi bi-arrow-counterclockwise"></i> فك الاعتماد
                    </button>
                    <button v-if="order.can.cancelOrder" type="button" class="is-danger" @click="openCancel('order')">
                        <i class="bi bi-x-circle"></i> إلغاء الطلب بالكامل
                    </button>
                </div>
            </details>
        </aside>
    </div>

    <Teleport to="body">
        <Transition name="od-fade">
            <div v-if="transferOpen" class="od-overlay" @click.self="transferOpen = false">
                <section class="od-sheet od-transfer" role="dialog" aria-modal="true" aria-labelledby="transfer-title">
                    <header class="od-sheet__head">
                        <div>
                            <small>نقل الجلسة كاملة</small>
                            <h2 id="transfer-title">من طاولة {{ order.session.tableLabel }} إلى…</h2>
                        </div>
                        <button type="button" aria-label="إغلاق" @click="transferOpen = false"><i class="bi bi-x-lg"></i></button>
                    </header>

                    <div class="od-transfer__promise">
                        <i class="bi bi-link-45deg"></i>
                        <p>
                            ستبقى نفس الجلسة بكل ما فيها:
                            <strong>{{ ordersLabel(order.session.ordersCount) }}</strong>،
                            <strong>{{ itemsLabel(order.session.itemsCount) }}</strong>
                            <template v-if="order.session.invoice">، والفاتورة {{ order.session.invoice.number }}</template>.
                        </p>
                    </div>

                    <label class="od-transfer__search">
                        <i class="bi bi-search"></i>
                        <input v-model="transferSearch" type="search" placeholder="ابحث برقم الطاولة أو المنطقة…">
                    </label>

                    <div v-if="filteredTransferTables.length" class="od-table-options">
                        <button
                            v-for="table in filteredTransferTables"
                            :key="table.id"
                            type="button"
                            :class="{ 'is-selected': Number(transferTargetId) === Number(table.id) }"
                            @click="transferTargetId = table.id"
                        >
                            <span class="od-option__number">{{ table.number }}</span>
                            <span class="od-option__copy">
                                <strong>{{ table.name || `طاولة ${table.number}` }}</strong>
                                <small>{{ table.zone || 'الصالة' }} · {{ table.capacity }} مقاعد</small>
                            </span>
                            <i class="bi" :class="Number(transferTargetId) === Number(table.id) ? 'bi-check-circle-fill' : 'bi-circle'"></i>
                        </button>
                    </div>
                    <div v-else class="od-empty">
                        <i class="bi bi-grid-3x3-gap"></i>
                        <strong>لا توجد طاولة متاحة ونظيفة الآن</strong>
                        <span>نظّف طاولة أو أغلق جلستها أولاً ثم حاول من جديد.</span>
                    </div>

                    <footer class="od-sheet__actions">
                        <button type="button" class="od-secondary" @click="transferOpen = false">تراجع</button>
                        <button type="button" class="od-confirm-transfer" :disabled="! selectedTransferTable" @click="transferSession">
                            <template v-if="selectedTransferTable">نقل إلى طاولة {{ selectedTransferTable.number }}</template>
                            <template v-else>اختر الطاولة الجديدة</template>
                            <i class="bi bi-arrow-left-right"></i>
                        </button>
                    </footer>
                </section>
            </div>
        </Transition>

        <Transition name="od-fade">
            <div v-if="cancelling" class="od-overlay" @click.self="cancelling = null">
                <section class="od-sheet od-cancel" role="dialog" aria-modal="true">
                    <header class="od-sheet__head">
                        <div>
                            <small>إجراء يحتاج سبباً</small>
                            <h2>{{ cancelling.scope === 'order' ? `إلغاء الطلب ${order.number}` : `إلغاء ${cancelling.item.name}` }}</h2>
                        </div>
                        <button type="button" aria-label="إغلاق" @click="cancelling = null"><i class="bi bi-x-lg"></i></button>
                    </header>

                    <label class="od-field">
                        <span>سبب الإلغاء</span>
                        <textarea v-model="reason" rows="3" maxlength="500" placeholder="مثال: الزبون غيّر رأيه…"></textarea>
                    </label>

                    <div v-if="cancelling.scope === 'item'" class="od-disposition">
                        <span>ماذا حدث للمكوّنات؟</span>
                        <label :class="{ 'is-selected': disposition === 'return' }">
                            <input v-model="disposition" type="radio" value="return">
                            <i class="bi bi-arrow-return-right"></i>
                            <span><strong>لم يبدأ التحضير</strong><small>إرجاع المكوّنات للمخزون</small></span>
                        </label>
                        <label :class="{ 'is-selected': disposition === 'waste' }">
                            <input v-model="disposition" type="radio" value="waste">
                            <i class="bi bi-trash3"></i>
                            <span><strong>بدأ التحضير</strong><small>تسجيل المكوّنات كهدر</small></span>
                        </label>
                    </div>

                    <footer class="od-sheet__actions">
                        <button type="button" class="od-secondary" @click="cancelling = null">تراجع</button>
                        <button type="button" class="od-danger" :disabled="! reason.trim()" @click="submitCancel">تأكيد الإلغاء</button>
                    </footer>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.od-status { display:inline-flex; align-items:center; gap:.45rem; min-height:38px; padding:.45rem .8rem; border:1px solid var(--line); border-radius:12px; background:var(--surface); color:var(--ink); font-size:.875rem; font-weight:800; }
.od-status > span { width:8px; height:8px; border-radius:50%; background:var(--muted); }
.od-status--warning > span { background:#d97706; box-shadow:0 0 0 5px #fff7ed; }
.od-status--info > span,.od-status--primary > span { background:#2563eb; box-shadow:0 0 0 5px #eff6ff; }
.od-status--success > span { background:#15803d; box-shadow:0 0 0 5px #ecfdf5; }
.od-status--danger > span { background:#dc2626; box-shadow:0 0 0 5px #fef2f2; }

.od-session { display:flex; align-items:center; gap:1rem; padding:.75rem .85rem; margin-bottom:.8rem; border:1px solid var(--line); border-radius:12px; background:var(--surface); box-shadow:none; }
.od-table { display:flex; align-items:center; gap:.65rem; min-width:max-content; }
.od-table__icon { display:grid; place-items:center; width:43px; height:43px; border-radius:12px; color:var(--brand); background:#eaf6ef; }
.od-table small,.od-card__head small,.od-workflow small,.od-sheet__head small { display:block; color:var(--brand); font-size:.875rem; font-weight:800; }
.od-table strong { display:block; margin-top:.05rem; color:var(--brand); font-size:1rem; }
.od-session__facts { display:flex; align-items:center; gap:.5rem; flex:1; min-width:0; overflow:auto; scrollbar-width:none; }
.od-session__facts span { display:inline-flex; align-items:center; gap:.35rem; min-width:max-content; padding:.42rem .65rem; border-radius:9px; background:var(--canvas); color:var(--brand); font-size:.875rem; font-weight:700; }
.od-session__facts i { color:var(--brand); }
.od-transfer-trigger { display:inline-flex; align-items:center; justify-content:center; gap:.45rem; min-height:42px; padding:.5rem .85rem; border:1px solid var(--brand); border-radius:11px; background:var(--surface); color:var(--brand); font:inherit; font-size:.875rem; font-weight:800; cursor:pointer; }
.od-transfer-trigger:hover { background:#edf8f1; }
.od-transfer-blocked { color:var(--brand); font-size:.875rem; font-weight:800; }

.od-workflow { display:flex; align-items:center; gap:.85rem; padding:1rem 1.1rem; margin-bottom:1rem; border:1px solid var(--line); border-inline-start:4px solid var(--muted); border-radius:12px; background:var(--surface); }
.od-workflow__icon { display:grid; place-items:center; flex:0 0 48px; height:48px; border-radius:12px; background:var(--canvas); color:var(--ink); font-size:1.25rem; }
.od-workflow__copy { flex:1; min-width:0; }
.od-workflow h2 { margin:.08rem 0; color:var(--brand); font-size:1.05rem; font-weight:800; }
.od-workflow p { margin:0; color:var(--brand); font-size:.875rem; }
.od-workflow--amber { border-inline-start-color:#d97706; background:var(--surface); }
.od-workflow--amber .od-workflow__icon { color:#b45309; background:#fff3dc; }
.od-workflow--green { border-inline-start-color:var(--brand); background:var(--surface); }
.od-workflow--green .od-workflow__icon { color:var(--brand); background:var(--brand-soft); animation:od-pulse 1.6s ease-in-out infinite; }
.od-workflow--blue { border-inline-start-color:#2563eb; background:var(--surface); }
.od-workflow--blue .od-workflow__icon { color:#2563eb; background:#dbeafe; }
.od-workflow--red { border-inline-start-color:#dc2626; }
.od-primary { display:inline-flex; align-items:center; justify-content:center; gap:.55rem; min-height:48px; padding:.7rem 1.2rem; border:0; border-radius:12px; background:var(--brand); color:#FFFDFA; font:inherit; font-size:.9rem; font-weight:800; box-shadow:none; cursor:pointer; }
.od-primary:hover { background:var(--brand); }

.od-layout { display:grid; grid-template-columns:minmax(0,1fr) 310px; gap:1rem; align-items:start; }
.od-main,.od-side { min-width:0; }
.od-side { display:flex; flex-direction:column; gap:.8rem; position:sticky; top:1rem; }
.od-card { overflow:hidden; border:1px solid var(--line); border-radius:12px; background:var(--surface); box-shadow:none; }
.od-card__head { display:flex; align-items:center; justify-content:space-between; gap:.8rem; padding:.85rem 1rem; border-bottom:1px solid var(--line); }
.od-card__head h2 { margin:.08rem 0 0; color:var(--brand); font-size:.98rem; font-weight:800; }
.od-card__head > i { color:var(--brand); font-size:1.15rem; }
.od-progress { display:flex; gap:.35rem; flex-wrap:wrap; justify-content:flex-end; }
.od-progress span { padding:.3rem .5rem; border-radius:12px; background:#f2f6f4; color:var(--brand); font-size:.875rem; font-weight:800; }
.od-progress span.is-ready { background:#dcfce7; color:#116c39; }
.od-progress b { color:var(--brand); }
.od-items { display:flex; flex-direction:column; }
.od-item { display:grid; grid-template-columns:46px minmax(0,1fr) auto; gap:.75rem; align-items:center; padding:.9rem 1rem; border-bottom:1px solid var(--line); transition:background .15s; }
.od-item:last-child { border-bottom:0; }
.od-item--ready { background:#f2fbf5; box-shadow:inset -3px 0 #22a25a; }
.od-item--cancelled { opacity:.52; background:#fafafa; }
.od-item--cancelled h3 { text-decoration:line-through; }
.od-item__qty { display:grid; place-items:center; width:42px; height:42px; border-radius:12px; background:#f2f6f4; color:var(--brand); font-size:.9rem; font-weight:800; }
.od-item__body { min-width:0; }
.od-item__title { display:flex; align-items:baseline; justify-content:space-between; gap:.7rem; }
.od-item__title h3 { margin:0; overflow:hidden; color:var(--brand); font-size:.93rem; font-weight:800; text-overflow:ellipsis; white-space:nowrap; }
.od-item__title strong { min-width:max-content; color:var(--brand); font-size:.875rem; }
.od-item__mods { display:flex; flex-wrap:wrap; gap:.28rem; margin-top:.35rem; }
.od-item__mods span { padding:.22rem .42rem; border:1px solid var(--line); border-radius:7px; background:#fafcfb; color:var(--brand); font-size:.875rem; font-weight:700; }
.od-item__note { margin:.35rem 0 0; color:#a65b0a; font-size:.875rem; font-weight:700; }
.od-item__meta { display:flex; align-items:center; gap:.35rem; margin-top:.4rem; }
.od-station,.od-line-status { display:inline-flex; align-items:center; gap:.28rem; padding:.22rem .43rem; border-radius:12px; font-size:.875rem; font-weight:800; }
.od-station { color:var(--station); background:color-mix(in srgb,var(--station) 10%,white); }
.od-line-status { background:var(--canvas); color:var(--ink); }
.od-line-status--success { color:#11723d; background:#dcfce7; }
.od-line-status--warning { color:#a45208; background:#fff3dc; }
.od-line-status--primary,.od-line-status--info { color:#1d4ed8; background:#dbeafe; }
.od-line-status--danger { color:#b91c1c; background:#fee2e2; }
.od-item__actions { display:flex; align-items:center; gap:.35rem; }
.od-serve { display:inline-flex; align-items:center; gap:.35rem; min-height:42px; padding:.55rem .75rem; border:0; border-radius:11px; background:var(--brand); color:#FFFDFA; font:inherit; font-size:.875rem; font-weight:800; cursor:pointer; }
.od-cancel-line { display:grid; place-items:center; width:38px; height:38px; border:1px solid #fecaca; border-radius:10px; background:var(--surface); color:#dc2626; cursor:pointer; }

.od-note { display:flex; align-items:flex-start; gap:.65rem; margin-bottom:.8rem; padding:.7rem .8rem; border:1px solid #f2d392; border-radius:12px; background:#fffaf0; color:#8b510d; }
.od-note strong { display:block; font-size:.875rem; }
.od-note p { margin:.12rem 0 0; font-size:.875rem; }
.od-note--danger { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
.od-totals { padding:.85rem 1rem; }
.od-totals > div { display:flex; justify-content:space-between; gap:.8rem; padding:.3rem 0; color:var(--brand); font-size:.875rem; }
.od-totals strong { color:var(--brand); }
.od-totals .is-discount { color:var(--brand); }
.od-totals .od-total { margin-top:.45rem; padding-top:.72rem; border-top:1px solid var(--line); color:var(--brand); font-size:.95rem; font-weight:800; }
.od-total strong { color:var(--brand); font-size:1.2rem; }
.od-context { padding:.9rem 1rem; }
.od-context h2 { margin:0 0 .55rem; color:var(--brand); font-size:.875rem; font-weight:800; }
.od-context dl { margin:0; }
.od-context dl > div { display:flex; justify-content:space-between; gap:.6rem; padding:.34rem 0; border-bottom:1px dashed var(--line); font-size:.875rem; }
.od-context dl > div:last-child { border-bottom:0; }
.od-context dt { color:var(--muted); font-weight:700; }
.od-context dd { margin:0; color:var(--brand); font-weight:800; text-align:end; }
.od-more { overflow:hidden; border:1px solid var(--line); border-radius:12px; background:var(--surface); }
.od-more summary { display:flex; align-items:center; gap:.4rem; padding:.75rem .85rem; color:var(--brand); font-size:.875rem; font-weight:800; cursor:pointer; list-style:none; }
.od-more > div { display:flex; gap:.45rem; padding:0 .75rem .75rem; }
.od-more button { flex:1; min-height:38px; border:1px solid var(--line); border-radius:8px; background:var(--canvas); color:var(--brand); font:inherit; font-size:.875rem; font-weight:800; cursor:pointer; }
.od-more button.is-danger { border-color:#fecaca; color:#b91c1c; background:#fffafa; }

.od-overlay { position:fixed; inset:0; z-index:19000; display:flex; align-items:center; justify-content:center; padding:1rem; background:rgba(8,28,18,.55); backdrop-filter:blur(3px); }
.od-sheet { width:min(580px,100%); max-height:min(760px,calc(100vh - 2rem)); overflow:auto; border-radius:12px; background:var(--surface); box-shadow:none; }
.od-sheet__head { display:flex; align-items:center; justify-content:space-between; gap:1rem; padding:1rem 1.1rem; border-bottom:1px solid var(--line); }
.od-sheet__head h2 { margin:.1rem 0 0; color:var(--brand); font-size:1.05rem; font-weight:800; }
.od-sheet__head > button { display:grid; place-items:center; width:40px; height:40px; border:1px solid var(--line); border-radius:8px; background:var(--surface); color:var(--brand); cursor:pointer; }
.od-transfer__promise { display:flex; align-items:flex-start; gap:.65rem; margin:1rem; padding:.75rem; border-radius:12px; background:#eff8f3; color:var(--brand); }
.od-transfer__promise i { font-size:1.2rem; }
.od-transfer__promise p { margin:0; font-size:.875rem; line-height:1.65; }
.od-transfer__search { display:flex; align-items:center; gap:.5rem; margin:0 1rem .75rem; padding:0 .75rem; border:1.5px solid var(--line); border-radius:12px; background:var(--surface); color:var(--brand); }
.od-transfer__search:focus-within { border-color:var(--brand); box-shadow:0 0 0 3px #edf8f1; }
.od-transfer__search input { width:100%; min-height:45px; border:0; outline:0; background:transparent; font:inherit; font-size:.875rem; }
.od-table-options { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:.5rem; max-height:330px; overflow:auto; padding:0 1rem 1rem; }
.od-table-options button { display:grid; grid-template-columns:44px minmax(0,1fr) 20px; align-items:center; gap:.55rem; min-height:68px; padding:.55rem; border:1.5px solid var(--line); border-radius:8px; background:var(--surface); color:var(--brand); text-align:start; cursor:pointer; }
.od-table-options button:hover,.od-table-options button.is-selected { border-color:var(--brand); background:#f0faf4; }
.od-option__number { display:grid; place-items:center; width:42px; height:42px; border-radius:11px; background:#edf3ef; color:var(--brand); font-size:1rem; font-weight:800; }
.od-option__copy { min-width:0; }
.od-option__copy strong,.od-option__copy small { display:block; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.od-option__copy strong { color:var(--brand); font-size:.875rem; }
.od-option__copy small { margin-top:.12rem; color:var(--brand); font-size:.875rem; }
.od-table-options button > i { color:var(--brand); }
.od-table-options button.is-selected > i { color:var(--brand); }
.od-empty { display:flex; flex-direction:column; align-items:center; gap:.25rem; padding:2rem 1rem; color:var(--brand); text-align:center; }
.od-empty i { margin-bottom:.25rem; font-size:1.6rem; }
.od-empty strong { color:var(--brand); font-size:.875rem; }
.od-empty span { font-size:.875rem; }
.od-sheet__actions { display:flex; gap:.55rem; padding:.85rem 1rem 1rem; border-top:1px solid var(--line); }
.od-sheet__actions button { min-height:46px; border:0; border-radius:8px; font:inherit; font-size:.875rem; font-weight:800; cursor:pointer; }
.od-secondary { flex:.45; background:#eff3f1; color:var(--brand); }
.od-confirm-transfer { flex:1; display:inline-flex; align-items:center; justify-content:center; gap:.5rem; background:var(--brand); color:#FFFDFA; }
.od-danger { flex:1; background:#dc2626; color:#FFFDFA; }
.od-sheet__actions button:disabled { opacity:.48; cursor:not-allowed; }
.od-cancel { width:min(480px,100%); }
.od-field { display:flex; flex-direction:column; gap:.35rem; padding:1rem; color:var(--brand); font-size:.875rem; font-weight:800; }
.od-field textarea { width:100%; padding:.7rem .75rem; border:1.5px solid var(--line); border-radius:11px; outline:0; resize:none; font:inherit; font-size:.875rem; }
.od-field textarea:focus { border-color:var(--brand); box-shadow:0 0 0 3px #edf8f1; }
.od-disposition { display:grid; grid-template-columns:1fr 1fr; gap:.5rem; padding:0 1rem 1rem; }
.od-disposition > span { grid-column:1/-1; color:var(--brand); font-size:.875rem; font-weight:800; }
.od-disposition label { display:flex; align-items:center; gap:.55rem; padding:.65rem; border:1.5px solid var(--line); border-radius:12px; cursor:pointer; }
.od-disposition label.is-selected { border-color:var(--brand); background:#f0faf4; }
.od-disposition input { position:absolute; opacity:0; pointer-events:none; }
.od-disposition label > i { color:var(--brand); font-size:1.05rem; }
.od-disposition strong,.od-disposition small { display:block; }
.od-disposition strong { color:var(--brand); font-size:.875rem; }
.od-disposition small { margin-top:.1rem; color:var(--brand); font-size:.875rem; }
.od-fade-enter-active,.od-fade-leave-active { transition:opacity .16s ease; }
.od-fade-enter-from,.od-fade-leave-to { opacity:0; }

@keyframes od-pulse { 50% { transform:scale(1.06); box-shadow:0 0 0 7px rgba(34,197,94,.08); } }

@media (max-width: 991.98px) {
    .od-layout { grid-template-columns:1fr; }
    .od-side { position:static; display:grid; grid-template-columns:1fr 1fr; }
    .od-more { grid-column:1/-1; }
}

@media (max-width: 767.98px) {
    .od-session { align-items:stretch; flex-wrap:wrap; }
    .od-session__facts { order:3; flex-basis:100%; }
    .od-transfer-trigger,.od-transfer-blocked { margin-inline-start:auto; }
    .od-workflow { align-items:flex-start; flex-wrap:wrap; }
    .od-primary { width:100%; }
    .od-side { display:flex; }
    .od-item { grid-template-columns:40px minmax(0,1fr); padding:.8rem; }
    .od-item__qty { width:38px; height:38px; }
    .od-item__actions { grid-column:1/-1; }
    .od-serve { flex:1; justify-content:center; }
    .od-cancel-line { width:42px; height:42px; }
    .od-table-options { grid-template-columns:1fr; }
    .od-disposition { grid-template-columns:1fr; }
}

@media (max-width: 420px) {
    .od-session { padding:.65rem; }
    .od-session__facts span { font-size:.875rem; }
    .od-progress { display:none; }
    .od-workflow { padding:.85rem; }
    .od-workflow__icon { flex-basis:42px; height:42px; }
    .od-sheet { align-self:flex-end; max-height:calc(100vh - .5rem); border-radius:12px 12px 0 0; }
    .od-overlay { align-items:flex-end; padding:.5rem 0 0; }
}

@media (prefers-reduced-motion: reduce) {
    .od-workflow--green .od-workflow__icon { animation:none; }
}

/* Operational layout and responsive hierarchy. */
.od-session{border-radius:8px;padding:14px 18px;margin-bottom:16px;background:var(--surface)}.od-workflow{border-radius:8px;padding:16px 18px;border-inline-start:4px solid var(--brand);background:var(--surface)}.od-workflow__icon{display:none}.od-workflow h2{font-size:20px}.od-layout{grid-template-columns:minmax(0,1fr) 270px;gap:20px;align-items:start}.od-side{position:sticky;top:16px;gap:14px}.od-card{border-radius:8px;box-shadow:none}.od-card__head{padding:16px;border-bottom:1px solid var(--line)}.od-item{display:grid;grid-template-columns:36px minmax(0,1fr) auto;gap:12px;padding:16px}.od-item__name{font-size:16px}.od-item__actions{gap:8px}.od-serve,.od-cancel-line{min-height:44px}.od-total{font-size:20px;padding-top:16px}.od-primary{min-height:48px;border-radius:8px}.od-overlay{padding:0;align-items:stretch;justify-content:flex-end}.od-sheet{height:100dvh;max-height:100dvh;width:min(100%,520px);border-radius:0;overflow-y:auto}.od-sheet__head{position:sticky;top:0;background:var(--surface);z-index:1;border-bottom:1px solid var(--line);padding:20px}.od-sheet__actions{position:sticky;bottom:0;background:var(--surface);padding:16px;border-top:1px solid var(--line)}@media(max-width:850px){.od-layout{grid-template-columns:1fr}.od-side{position:static;grid-row:1}.od-item{grid-template-columns:30px minmax(0,1fr)}.od-item__actions{grid-column:2;justify-content:flex-end}}
</style>
