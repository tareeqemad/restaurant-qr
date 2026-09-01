<script setup>
/**
 * لوحة التقييمات — Wave 5. Moderation is the whole job: find the review
 * that needs an answer, hide it with a reason, or bring it back.
 *
 * Hiding asks for a reason inline (it is stored and shown to the next
 * moderator, so "why was this hidden" is never a mystery). Deleting is
 * permanent and asks twice — once in the confirm sheet's wording, once by
 * requiring the danger button.
 *
 * The list refreshes itself every 30s: the portal doesn't broadcast new
 * reviews yet, and a fresh 1-star deserves to surface without a manual
 * reload. Paused while the tab is hidden or the moderator is typing.
 */
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { formPost } from '../../../Support/formPost';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    reviews: { type: Object, required: true },
    stats: { type: Object, required: true },
    filters: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();

const form = reactive({
    search: props.filters.search ?? '',
    status: props.filters.status ?? '',
    rating: props.filters.rating ?? '',
    period: props.filters.period ?? '',
});

const visit = (patch = {}) => {
    Object.assign(form, patch);
    router.get(props.urls.index, {
        search: form.search || undefined,
        status: form.status || undefined,
        rating: form.rating || undefined,
        period: form.period || undefined,
    }, { preserveState: true, preserveScroll: true });
};

const clear = () => visit({ search: '', status: '', rating: '', period: '' });
const hasFilters = computed(() => Boolean(form.search || form.status || form.rating || form.period));

// Quick lenses — the three questions a moderator actually opens this for.
const showLow = () => visit({ rating: 'low', status: 'published', period: '' });
const showHidden = () => visit({ status: 'hidden', rating: '', period: '' });
const showToday = () => visit({ period: 'today', status: '', rating: '' });

// ── Hide / unhide / delete ───────────────────────────────────────────
const hidingId = ref(null);
const hideReason = ref('');

const openHide = (review) => {
    hidingId.value = review.id;
    hideReason.value = '';
};

const submitHide = (review) => {
    formPost(review.urls.hide, { hidden_reason: hideReason.value.trim() });
};

const unhide = (review) => formPost(review.urls.unhide);

const destroy = async (review) => {
    const yes = await ask({
        title: `حذف التقييم نهائياً؟`,
        message: 'الحذف ما بينعكس. لو بدك بس تخفيه عن الزبائن، استخدم «إخفاء» بدل الحذف.',
        confirmLabel: 'احذف نهائياً',
        danger: true,
    });
    if (yes) formPost(review.urls.destroy, { _method: 'DELETE' });
};

// ── Quiet auto-refresh ───────────────────────────────────────────────
let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (document.hidden || hidingId.value) return;
        const el = document.activeElement;
        if (el && ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName)) return;
        router.reload({ only: ['reviews', 'stats'], preserveScroll: true });
    }, 30000);
});
onBeforeUnmount(() => clearInterval(timer));

const RATINGS = [
    { value: '', label: 'كل التقييمات' },
    { value: 'low', label: '★★ فأقل (تحتاج رد)' },
    { value: '5', label: '★★★★★' },
    { value: '4', label: '★★★★' },
    { value: '3', label: '★★★' },
    { value: '2', label: '★★' },
    { value: '1', label: '★' },
];
</script>

<template>
    <Head title="التقييمات" />

    <PageHeader title="تقييمات الزبائن" icon="bi-star-fill"
                subtitle="اقرأ، ردّ، وأخفِ اللي بيخالف الأصول — الإخفاء بيتسجّل بسببه" />

    <StatRail :stats="[
        { label: 'إجمالي التقييمات', value: stats.total, icon: 'bi-star', color: 'primary' },
        { label: 'متوسط التقييم', value: `${stats.avg} ★`, icon: 'bi-star-fill', color: 'accent' },
        { label: 'منخفضة (★★ فأقل)', value: stats.low, icon: 'bi-emoji-frown', color: 'warning' },
        { label: 'منخفضة اليوم', value: stats.lowToday, icon: 'bi-exclamation-triangle-fill', color: 'danger' },
        { label: 'جديدة اليوم', value: stats.newToday, icon: 'bi-bell', color: 'success' },
    ]" />

    <div class="rv-lenses">
        <button type="button" class="rv-lens rv-lens--low" @click="showLow">
            <i class="bi bi-emoji-frown-fill"></i> منخفضة ومنشورة
        </button>
        <button type="button" class="rv-lens" @click="showHidden">
            <i class="bi bi-eye-slash-fill"></i> المخفية ({{ stats.hidden }})
        </button>
        <button type="button" class="rv-lens" @click="showToday">
            <i class="bi bi-calendar-day"></i> اليوم ({{ stats.newToday }})
        </button>
    </div>

    <DataPanel title="التقييمات" :count="reviews.total" icon="bi-chat-square-quote">
        <template v-if="hasFilters" #actions>
            <button type="button" class="btn btn-light" @click="clear">
                <i class="bi bi-x-circle"></i> مسح الفلاتر
            </button>
        </template>

        <template #filters>
            <form class="row g-2" @submit.prevent="visit()">
                <div class="col-md-4">
                    <input v-model="form.search" class="form-control"
                           placeholder="🔍 اسم، جوال، نص التقييم، سبب الإخفاء…">
                </div>
                <div class="col-md-3">
                    <select v-model="form.status" class="form-select" @change="visit()">
                        <option value="">كل الحالات</option>
                        <option value="published">منشور</option>
                        <option value="hidden">مخفي</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <select v-model="form.rating" class="form-select" @change="visit()">
                        <option v-for="r in RATINGS" :key="r.value" :value="r.value">{{ r.label }}</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select v-model="form.period" class="form-select" @change="visit()">
                        <option value="">كل الفترات</option>
                        <option value="today">اليوم</option>
                        <option value="7d">آخر ٧ أيام</option>
                        <option value="30d">آخر ٣٠ يوم</option>
                    </select>
                </div>
            </form>
        </template>

        <div class="rv-list">
            <article v-for="r in reviews.data" :key="r.id" class="rv-card" :class="{ 'is-hidden': r.status === 'hidden' }">
                <header class="rv-head">
                    <div class="rv-stars" :class="r.rating <= 2 ? 'is-low' : ''">
                        <i v-for="n in 5" :key="n" class="bi" :class="n <= r.rating ? 'bi-star-fill' : 'bi-star'"></i>
                    </div>
                    <div class="rv-who">
                        <strong>{{ r.customerName ?? 'زبون' }}</strong>
                        <small>
                            {{ r.createdAgo }}
                            <template v-if="r.branchName"> · {{ r.branchName }}</template>
                            <template v-if="r.tableNumber"> · طاولة {{ r.tableNumber }}</template>
                        </small>
                    </div>
                    <span v-if="r.status === 'hidden'" class="rv-tag"><i class="bi bi-eye-slash-fill"></i> مخفي</span>
                </header>

                <div class="rv-body">
                    <strong v-if="r.title">{{ r.title }}</strong>
                    <p v-if="r.body">{{ r.body }}</p>
                    <p v-else class="rv-empty-body">— بلا نص —</p>
                </div>

                <div v-if="r.status === 'hidden' && r.hiddenReason" class="rv-reason">
                    <i class="bi bi-info-circle-fill"></i>
                    <span>سبب الإخفاء: {{ r.hiddenReason }}<template v-if="r.hiddenByName"> — {{ r.hiddenByName }}</template></span>
                </div>

                <!-- Hiding asks for the reason inline; it is stored and shown
                     to whoever looks at this review next. -->
                <div v-if="hidingId === r.id" class="rv-hideform">
                    <input v-model="hideReason" class="form-control" maxlength="255"
                           placeholder="سبب الإخفاء (اختياري بس مفيد)…"
                           @keydown.enter.prevent="submitHide(r)">
                    <button type="button" class="btn btn-warning" @click="submitHide(r)">
                        <i class="bi bi-eye-slash"></i> أخفِ
                    </button>
                    <button type="button" class="btn btn-light" @click="hidingId = null">تراجع</button>
                </div>

                <footer v-else class="rv-actions">
                    <button v-if="r.status !== 'hidden' && r.can.hide" type="button"
                            class="btn btn-sm btn-outline-warning" @click="openHide(r)">
                        <i class="bi bi-eye-slash"></i> إخفاء
                    </button>
                    <button v-if="r.status === 'hidden' && r.can.unhide" type="button"
                            class="btn btn-sm btn-outline-success" @click="unhide(r)">
                        <i class="bi bi-eye"></i> إعادة نشر
                    </button>
                    <button v-if="r.can.delete" type="button" class="btn btn-sm btn-outline-danger rv-del"
                            @click="destroy(r)">
                        <i class="bi bi-trash3"></i> حذف
                    </button>
                </footer>
            </article>

            <EmptyState v-if="reviews.data.length === 0" icon="bi-chat-square-quote"
                        title="ما في تقييمات هون"
                        :message="hasFilters ? 'جرّب تمسح الفلاتر.' : 'أول ما يقيّم زبون، بيظهر هون تلقائياً.'" />
        </div>

        <template #footer>
            <Pagination :links="reviews.links" />
        </template>
    </DataPanel>
</template>

<style scoped>
.rv-lenses { display: flex; gap: .5rem; flex-wrap: wrap; margin-bottom: .9rem; }
.rv-lens {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    min-height: 42px;
    padding: 0 1rem;
    border: 1px solid #e2e8f0;
    border-radius: 999px;
    background: #fff;
    color: #334155;
    font: inherit;
    font-size: .84rem;
    font-weight: 700;
    cursor: pointer;
}
.rv-lens--low { border-color: #fecaca; color: #b91c1c; background: #fff5f5; }

.rv-list { display: flex; flex-direction: column; gap: .7rem; }
.rv-card {
    border: 1px solid #eef0f3;
    border-radius: 14px;
    padding: .85rem 1rem;
    background: #fff;
    display: flex;
    flex-direction: column;
    gap: .5rem;
}
.rv-card.is-hidden { background: #f8fafc; opacity: .85; }

.rv-head { display: flex; align-items: center; gap: .7rem; flex-wrap: wrap; }
.rv-stars { color: #f59e0b; font-size: .9rem; letter-spacing: .05em; }
.rv-stars.is-low { color: #dc2626; }
.rv-who { display: flex; flex-direction: column; min-width: 0; }
.rv-who strong { font-size: .9rem; color: #0f172a; }
.rv-who small { font-size: .74rem; color: #94a3b8; }
.rv-tag {
    margin-inline-start: auto;
    background: #f1f5f9;
    color: #475569;
    border-radius: 999px;
    padding: .15rem .6rem;
    font-size: .72rem;
    font-weight: 800;
}

.rv-body strong { display: block; font-size: .92rem; color: #0f172a; margin-bottom: .2rem; }
.rv-body p { margin: 0; font-size: .86rem; color: #475569; line-height: 1.65; }
.rv-empty-body { color: #cbd5e1 !important; font-style: italic; }

.rv-reason {
    display: flex;
    gap: .45rem;
    align-items: flex-start;
    background: #f1f5f9;
    border-radius: 10px;
    padding: .5rem .7rem;
    font-size: .78rem;
    color: #475569;
}

.rv-hideform { display: flex; gap: .5rem; flex-wrap: wrap; }
.rv-hideform input { flex: 1 1 240px; }

.rv-actions { display: flex; gap: .4rem; flex-wrap: wrap; }
.rv-del { margin-inline-start: auto; }
</style>
