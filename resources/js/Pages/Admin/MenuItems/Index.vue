<script setup>
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import MenuWorkspaceNav from '../../../Components/MenuAdmin/MenuWorkspaceNav.vue';
import MenuItemCard from '../../../Components/MenuAdmin/MenuItemCard.vue';
import MenuSheet from '../../../Components/MenuAdmin/MenuSheet.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import { useConfirm } from '../../../Composables/useConfirm';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    navigation: { type: Array, default: () => [] },
    items: { type: Object, required: true },
    categories: { type: Array, default: () => [] },
    stations: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    filters: { type: Object, required: true },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();
const unavailableItem = ref(null);
const unavailableReason = ref('');
const cardSource = ref(null);
const itemCard = ref(null);
const itemCardLoading = ref(false);
const itemCardError = ref('');
let itemCardAbort = null;

const form = reactive({
    search: props.filters.search ?? '',
    categoryId: props.filters.categoryId ?? '',
    stationId: props.filters.stationId ?? '',
    status: props.filters.status ?? '',
});

const hasFilters = computed(() => Object.values(form).some(Boolean));
const visibleCount = computed(() => props.items.data?.length ?? 0);
const resultLabel = computed(() => {
    if (!hasFilters.value) return `${props.stats.total} صنف في المنيو`;
    return `${props.items.total} نتيجة مطابقة`;
});

const statusOptions = computed(() => [
    { value: '', label: 'الكل', count: props.stats.total, icon: 'bi-grid' },
    { value: 'available', label: 'متاح', count: props.stats.available, icon: 'bi-check-circle' },
    { value: 'unavailable', label: 'متوقف', count: props.stats.unavailable, icon: 'bi-pause-circle', tone: 'danger' },
    { value: 'featured', label: 'المميز', count: props.stats.featured, icon: 'bi-star-fill' },
    { value: 'without_recipe', label: 'بلا وصفة', count: props.stats.withoutRecipe, icon: 'bi-basket2', tone: 'warning' },
]);

function visit(patch = {}) {
    Object.assign(form, patch);
    router.get(props.urls.index, {
        search: form.search || undefined,
        category_id: form.categoryId || undefined,
        station_id: form.stationId || undefined,
        status: form.status || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function clear() {
    visit({ search: '', categoryId: '', stationId: '', status: '' });
}

function clearSearch() {
    if (!form.search) return;
    visit({ search: '' });
}

function requestToggle(item) {
    if (!item.isAvailable) {
        router.patch(item.urls.toggle, {}, { preserveScroll: true });
        return;
    }

    unavailableItem.value = item;
    unavailableReason.value = item.unavailableReason ?? '';
}

function markUnavailable() {
    if (!unavailableItem.value) return;

    router.patch(unavailableItem.value.urls.toggle, {
        reason: unavailableReason.value.trim() || null,
    }, {
        preserveScroll: true,
        onSuccess: () => { unavailableItem.value = null; },
    });
}

async function destroy(item) {
    const yes = await ask({
        title: `حذف «${item.name}»؟`,
        message: 'سيختفي الصنف من المنيو، بينما تبقى الطلبات والفواتير القديمة محفوظة.',
        confirmLabel: 'حذف الصنف',
        danger: true,
    });

    if (yes) router.delete(item.urls.destroy, { preserveScroll: true });
}

async function recomputeCosts() {
    const yes = await ask({
        title: 'إعادة احتساب تكاليف الوصفات؟',
        message: 'سيعاد حساب تكلفة كل صنف من أسعار مكوناته الحالية دون تغيير سعر البيع.',
        confirmLabel: 'احتسب التكاليف',
    });

    if (yes) router.post(props.urls.recomputeCosts, {}, { preserveScroll: true });
}

const marginTone = (margin) => margin === null ? 'muted' : (margin >= 60 ? 'good' : (margin >= 30 ? 'warn' : 'bad'));
const imageFallback = (event, item) => {
    event.target.onerror = null;
    event.target.src = item.placeholderUrl;
};

async function openItemCard(item) {
    itemCardAbort?.abort();
    const controller = new AbortController();
    itemCardAbort = controller;
    cardSource.value = item;
    itemCard.value = null;
    itemCardError.value = '';
    itemCardLoading.value = true;

    try {
        const response = await fetch(item.urls.show, {
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal: controller.signal,
        });

        if (!response.ok) throw new Error('تعذر تحميل كرت الصنف.');
        itemCard.value = await response.json();
    } catch (error) {
        if (error.name !== 'AbortError') itemCardError.value = error.message || 'تعذر تحميل كرت الصنف.';
    } finally {
        if (itemCardAbort === controller) itemCardLoading.value = false;
    }
}

function closeItemCard() {
    itemCardAbort?.abort();
    itemCardAbort = null;
    cardSource.value = null;
    itemCard.value = null;
    itemCardError.value = '';
    itemCardLoading.value = false;
}

onBeforeUnmount(() => itemCardAbort?.abort());
</script>

<template>
    <Head title="إدارة أصناف المنيو" />

    <main class="mi-page">
        <header class="mi-hero">
            <div class="mi-hero-copy">
                <span class="mi-eyebrow"><i class="bi bi-journal-richtext"></i> إدارة المنيو</span>
                <h1>أصناف المنيو</h1>
                <p>تحكّم بالسعر والوصفة والتوفر ومحطة التحضير من مكان واحد.</p>
            </div>

            <div class="mi-hero-actions">
                <button v-if="can.recomputeCosts" type="button" class="mi-button mi-button-secondary" @click="recomputeCosts">
                    <i class="bi bi-calculator"></i>
                    <span>تحديث التكاليف</span>
                </button>
                <a v-if="can.create" :href="urls.create" class="mi-button mi-button-primary">
                    <i class="bi bi-plus-lg"></i>
                    <span>صنف جديد</span>
                </a>
            </div>
        </header>

        <MenuWorkspaceNav :links="navigation" />

        <section class="mi-command" aria-labelledby="menu-filter-title">
            <div class="mi-command-heading">
                <div>
                    <span class="mi-command-kicker">وصول سريع</span>
                    <h2 id="menu-filter-title">ابحث واتخذ القرار</h2>
                </div>
                <span class="mi-result-count">
                    <b>{{ items.total }}</b> {{ hasFilters ? 'نتيجة مطابقة' : 'صنف في المنيو' }}
                </span>
            </div>

            <div class="mi-status-tabs" aria-label="فلترة الأصناف حسب الحالة">
                <button v-for="option in statusOptions" :key="option.value || 'all'" type="button"
                        :class="['mi-status-tab', option.tone ? `is-${option.tone}` : '', { active: form.status === option.value }]"
                        :aria-pressed="form.status === option.value" @click="visit({ status: option.value })">
                    <i class="bi" :class="option.icon"></i>
                    <span>{{ option.label }}</span>
                    <b>{{ option.count }}</b>
                </button>
            </div>

            <form class="mi-filter" @submit.prevent="visit()">
                <label class="mi-field mi-search-field">
                    <span class="mi-field-label">ابحث عن صنف</span>
                    <span class="mi-input-wrap">
                        <i class="bi bi-search"></i>
                        <input v-model="form.search" type="search" placeholder="اسم الصنف أو الرمز SKU" @keyup.esc="clearSearch">
                        <button v-if="form.search" type="button" class="mi-input-clear" aria-label="مسح البحث" @click="clearSearch">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </span>
                </label>

                <label class="mi-field">
                    <span class="mi-field-label">القسم</span>
                    <span class="mi-select-wrap">
                        <i class="bi bi-grid"></i>
                        <select v-model="form.categoryId" @change="visit()">
                            <option value="">كل الأقسام</option>
                            <option v-for="category in categories" :key="category.id" :value="category.id">{{ category.name }}</option>
                        </select>
                    </span>
                </label>

                <label class="mi-field">
                    <span class="mi-field-label">محطة التحضير</span>
                    <span class="mi-select-wrap">
                        <i class="bi bi-fire"></i>
                        <select v-model="form.stationId" @change="visit()">
                            <option value="">كل المحطات</option>
                            <option v-for="station in stations" :key="station.id" :value="station.id">{{ station.name }}</option>
                        </select>
                    </span>
                </label>

                <button type="submit" class="mi-button mi-button-primary mi-search-submit">
                    <i class="bi bi-search"></i>
                    <span>بحث</span>
                </button>

                <button v-if="hasFilters" type="button" class="mi-button mi-button-ghost mi-clear-filters" @click="clear">
                    <i class="bi bi-arrow-counterclockwise"></i>
                    <span>مسح الفلاتر</span>
                </button>
            </form>
        </section>

        <section class="mi-results" aria-labelledby="menu-items-title">
            <header class="mi-results-heading">
                <div>
                    <h2 id="menu-items-title">الأصناف</h2>
                    <p>{{ resultLabel }}</p>
                </div>
                <span v-if="items.total" class="mi-page-count">معروض {{ visibleCount }} من {{ items.total }}</span>
            </header>

            <div class="mi-grid">
                <article v-for="item in items.data" :key="item.id" class="mi-card"
                         :class="{ 'is-off': !item.isAvailable, 'has-shortage': item.shortages.length }">
                    <div class="mi-card-main">
                        <figure class="mi-photo">
                            <img :src="item.imageUrl" :alt="item.name" loading="lazy" @error="imageFallback($event, item)">
                            <div class="mi-photo-flags">
                                <span v-if="item.isFeatured" class="mi-flag mi-flag-featured" title="صنف مميز">
                                    <i class="bi bi-star-fill"></i>
                                </span>
                                <span class="mi-flag mi-flag-status" :class="item.isAvailable ? 'is-available' : 'is-off'">
                                    <i class="bi" :class="item.isAvailable ? 'bi-check-circle-fill' : 'bi-pause-circle-fill'"></i>
                                    {{ item.isAvailable ? 'متاح' : 'متوقف' }}
                                </span>
                            </div>
                        </figure>

                        <div class="mi-body">
                            <header class="mi-title">
                                <div>
                                    <h3>{{ item.name }}</h3>
                                    <small v-if="item.sku">{{ item.sku }}</small>
                                </div>
                                <div class="mi-price" :class="{ promoted: item.hasPromotion }">
                                    <small v-if="item.hasPromotion"><s>{{ item.basePrice }}</s> عرض</small>
                                    <strong>{{ item.price }}</strong>
                                    <em v-if="item.hasPromotion">{{ item.promotionName }}</em>
                                </div>
                            </header>

                            <div class="mi-context">
                                <span><i class="bi bi-grid"></i> {{ item.category || 'بلا قسم' }}</span>
                                <span><i class="bi bi-fire"></i> {{ item.station || 'محطة القسم' }}</span>
                                <span><i class="bi bi-clock"></i> {{ item.prepMinutes || '—' }} د</span>
                            </div>

                            <p v-if="item.description" class="mi-description">{{ item.description }}</p>

                            <div v-if="item.shortages.length" class="mi-alert mi-alert-danger">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <div>
                                    <strong>لا يمكن تحضيره الآن</strong>
                                    <span>ناقص: {{ item.shortages.map(shortage => shortage.ingredient).join('، ') }}</span>
                                </div>
                            </div>
                            <div v-else-if="!item.recipe.length" class="mi-alert mi-alert-warning">
                                <i class="bi bi-info-circle-fill"></i>
                                <div>
                                    <strong>الوصفة غير مكتملة</strong>
                                    <span>لن يُخصم المخزون عند تحضير هذا الصنف.</span>
                                </div>
                            </div>
                            <div v-else-if="!item.isAvailable && item.unavailableReason" class="mi-alert mi-alert-muted">
                                <i class="bi bi-pause-circle-fill"></i>
                                <div>
                                    <strong>سبب الإيقاف</strong>
                                    <span>{{ item.unavailableReason }}</span>
                                </div>
                            </div>

                            <details v-if="item.recipe.length" class="mi-recipe">
                                <summary>
                                    <span><i class="bi bi-basket2-fill"></i> المكونات</span>
                                    <b>{{ item.recipe.length }}</b>
                                </summary>
                                <div class="mi-recipe-list">
                                    <span v-for="(row, index) in item.recipe" :key="index">
                                        {{ row.name }} <b>{{ row.quantity }} {{ row.unit }}</b>
                                    </span>
                                </div>
                            </details>

                            <div v-if="item.allergens.length || item.modifierGroups.length" class="mi-tags">
                                <span v-for="allergen in item.allergens" :key="allergen.name" class="mi-tag mi-tag-allergen">
                                    {{ allergen.icon }} {{ allergen.name }}
                                </span>
                                <span v-for="group in item.modifierGroups" :key="group" class="mi-tag">
                                    <i class="bi bi-sliders2"></i> {{ group }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="mi-economics" aria-label="ملخص تكلفة وربح الصنف">
                        <span>
                            <small>التكلفة</small>
                            <b>{{ item.cost || 'غير محسوبة' }}</b>
                        </span>
                        <span>
                            <small>ربح الحصة</small>
                            <b>{{ item.profit || '—' }}</b>
                        </span>
                        <span :class="`tone-${marginTone(item.margin)}`">
                            <small>الهامش</small>
                            <b>{{ item.margin === null ? '—' : `${item.margin}%` }}</b>
                        </span>
                    </div>

                    <footer class="mi-actions">
                        <button type="button" class="mi-button mi-button-secondary mi-card-action" @click="openItemCard(item)">
                            <i class="bi bi-card-checklist"></i>
                            <span>كرت الصنف</span>
                        </button>
                        <Link v-if="item.can.update" :href="item.urls.edit" class="mi-button mi-button-primary mi-edit-action">
                            <i class="bi bi-pencil-square"></i>
                            <span>تحرير الصنف</span>
                        </Link>
                        <button v-if="item.can.toggle" type="button" class="mi-button mi-button-secondary"
                                :class="{ 'is-enable': !item.isAvailable }" @click="requestToggle(item)">
                            <i class="bi" :class="item.isAvailable ? 'bi-pause-fill' : 'bi-play-fill'"></i>
                            <span>{{ item.isAvailable ? 'إيقاف مؤقت' : 'إعادة الإتاحة' }}</span>
                        </button>
                        <button v-if="item.can.delete" type="button" class="mi-icon-button mi-delete"
                                :aria-label="`حذف ${item.name}`" title="حذف الصنف" @click="destroy(item)">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </footer>
                </article>

                <EmptyState v-if="items.data.length === 0" class="mi-empty" icon="bi-egg-fried"
                            title="لا توجد أصناف مطابقة"
                            :message="hasFilters ? 'جرّب مسح الفلاتر أو تغيير عبارة البحث.' : 'ابدأ بإضافة أول صنف مع وصفته وسعره.'">
                    <template v-if="hasFilters" #cta>
                        <button type="button" class="mi-button mi-button-secondary" @click="clear">
                            <i class="bi bi-arrow-counterclockwise"></i> مسح الفلاتر
                        </button>
                    </template>
                    <template v-else-if="can.create" #cta>
                        <a :href="urls.create" class="mi-button mi-button-primary"><i class="bi bi-plus-lg"></i> صنف جديد</a>
                    </template>
                </EmptyState>
            </div>

            <div v-if="items.links?.length > 3" class="mi-pagination">
                <Pagination :links="items.links" />
            </div>
        </section>
    </main>

    <MenuSheet :open="Boolean(unavailableItem)" title="إيقاف الصنف مؤقتًا" icon="bi-pause-circle"
               subtitle="أضف سببًا واضحًا ليعرف فريق العمل ما يلزم لإعادته" @close="unavailableItem = null">
        <div v-if="unavailableItem" class="mi-stop-form">
            <div class="mi-stop-item">
                <i class="bi bi-egg-fried"></i>
                <div><small>الصنف</small><strong>{{ unavailableItem.name }}</strong></div>
            </div>
            <label>
                <span>سبب عدم التوفر <small>(اختياري)</small></span>
                <textarea v-model="unavailableReason" class="form-control" rows="4"
                          placeholder="مثلاً: نفاد الدجاج، توقف الفرن، متوفر غدًا"></textarea>
            </label>
            <p><i class="bi bi-info-circle"></i> يمكنك إعادة إتاحته لاحقًا بلمسة واحدة.</p>
        </div>
        <template #footer>
            <button type="button" class="mi-button mi-button-secondary" @click="unavailableItem = null">تراجع</button>
            <button type="button" class="mi-button mi-button-warning" @click="markUnavailable">
                <i class="bi bi-pause-fill"></i> إيقاف مؤقت
            </button>
        </template>
    </MenuSheet>

    <MenuSheet :open="Boolean(cardSource)" :title="cardSource ? `كرت ${cardSource.name}` : 'كرت الصنف'"
               subtitle="السعر والتكلفة والمبيعات والوصفة في مكان واحد" icon="bi-card-checklist"
               wide mobile-bottom @close="closeItemCard">
        <div v-if="itemCardLoading" class="mi-card-loading" role="status" aria-live="polite">
            <span><i class="bi bi-card-checklist"></i></span>
            <strong>نجهز كرت الصنف…</strong>
            <p>نجمع آخر سعر وتكلفة ومبيعات وعروض دون مغادرة القائمة.</p>
        </div>

        <div v-else-if="itemCardError" class="mi-card-error" role="alert">
            <i class="bi bi-exclamation-circle"></i>
            <div><strong>تعذر فتح الكرت</strong><p>{{ itemCardError }}</p></div>
            <button type="button" class="mi-button mi-button-secondary" @click="openItemCard(cardSource)">
                <i class="bi bi-arrow-clockwise"></i> حاول مجدداً
            </button>
        </div>

        <MenuItemCard v-else-if="itemCard" v-bind="itemCard" />
    </MenuSheet>
</template>

<style scoped>
.mi-page {
    --mi-primary: rgb(var(--primary-rgb, 31, 107, 80));
    --mi-primary-deep: #123f31;
    --mi-primary-soft: rgba(var(--primary-rgb, 31, 107, 80), .08);
    --mi-line: #dfe8e3;
    --mi-muted: #6d7f76;
    --mi-surface: #fff;
    color: var(--mi-primary-deep);
}
.mi-card-loading{--mi-primary:rgb(var(--primary-rgb,31,107,80));--mi-primary-deep:#123f31;--mi-primary-soft:rgba(var(--primary-rgb,31,107,80),.08);min-height:380px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center}.mi-card-loading>span{width:58px;height:58px;display:grid;place-items:center;margin-bottom:.7rem;border-radius:18px;color:var(--mi-primary);background:var(--mi-primary-soft);font-size:1.35rem}.mi-card-loading strong{color:var(--mi-primary-deep);font-size:.9rem}.mi-card-loading p{max-width:340px;margin:.3rem 0;color:#78877f;font-size:.7rem;line-height:1.7}.mi-card-error{min-height:260px;display:flex;align-items:center;justify-content:center;flex-wrap:wrap;gap:.7rem;padding:1.2rem;text-align:center}.mi-card-error>i{color:#b32636;font-size:1.5rem}.mi-card-error>div{display:flex;flex-direction:column}.mi-card-error strong{color:#742330}.mi-card-error p{margin:.15rem 0;color:#7d6a6d;font-size:.7rem}

.mi-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    min-height: 112px;
    margin-bottom: .8rem;
    padding: 1rem 1.15rem;
    border: 1px solid rgba(var(--primary-rgb, 31, 107, 80), .14);
    border-radius: 18px;
    background:
        radial-gradient(circle at 12% 20%, rgba(var(--primary-rgb, 31, 107, 80), .09), transparent 28%),
        linear-gradient(135deg, #fff, #f7faf8);
    box-shadow: 0 10px 30px rgba(18, 63, 49, .045);
}

.mi-hero-copy { min-width: 0; }
.mi-eyebrow,
.mi-command-kicker {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    color: var(--mi-primary);
    font-size: .72rem;
    font-weight: 900;
}
.mi-hero h1 { margin: .22rem 0 .16rem; color: var(--mi-primary-deep); font-size: clamp(1.4rem, 2.4vw, 1.85rem); font-weight: 950; }
.mi-hero p { margin: 0; color: var(--mi-muted); font-size: .82rem; }
.mi-hero-actions { display: flex; gap: .55rem; flex: 0 0 auto; }

.mi-button,
.mi-icon-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .42rem;
    min-height: 44px;
    border: 1px solid transparent;
    border-radius: 11px;
    padding: .58rem .85rem;
    font: inherit;
    font-size: .78rem;
    font-weight: 900;
    line-height: 1;
    text-decoration: none;
    cursor: pointer;
    transition: border-color .16s ease, background-color .16s ease, color .16s ease, transform .16s ease, box-shadow .16s ease;
}
.mi-button:hover,
.mi-icon-button:hover { transform: translateY(-1px); }
.mi-button:focus-visible,
.mi-icon-button:focus-visible,
.mi-status-tab:focus-visible,
.mi-input-clear:focus-visible { outline: 3px solid rgba(var(--primary-rgb, 31, 107, 80), .2); outline-offset: 2px; }
.mi-button-primary { color: #fff; background: var(--mi-primary); box-shadow: 0 7px 16px rgba(var(--primary-rgb, 31, 107, 80), .17); }
.mi-button-primary:hover { color: #fff; background: var(--mi-primary-deep); }
.mi-button-secondary { color: var(--mi-primary-deep); border-color: var(--mi-line); background: #fff; }
.mi-button-secondary:hover { color: var(--mi-primary); border-color: rgba(var(--primary-rgb, 31, 107, 80), .3); background: var(--mi-primary-soft); }
.mi-button-secondary.is-enable { color: var(--mi-primary); border-color: rgba(var(--primary-rgb, 31, 107, 80), .24); background: var(--mi-primary-soft); }
.mi-button-ghost { color: #6b7b73; border-color: transparent; background: #f3f6f4; }
.mi-button-warning { color: #674807; border-color: #e5ba58; background: #f7d37b; }
.mi-icon-button { width: 44px; flex: 0 0 44px; padding: 0; }

.mi-command {
    margin-bottom: 1.05rem;
    padding: .95rem;
    border: 1px solid var(--mi-line);
    border-radius: 18px;
    background: var(--mi-surface);
    box-shadow: 0 9px 26px rgba(18, 63, 49, .045);
}
.mi-command-heading,
.mi-results-heading {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 1rem;
}
.mi-command-heading { margin-bottom: .75rem; }
.mi-command-heading h2,
.mi-results-heading h2 { margin: .12rem 0 0; color: var(--mi-primary-deep); font-size: 1.03rem; font-weight: 950; }
.mi-result-count,
.mi-page-count { color: var(--mi-muted); font-size: .72rem; white-space: nowrap; }
.mi-result-count b { color: var(--mi-primary); }

.mi-status-tabs {
    display: flex;
    gap: .42rem;
    overflow-x: auto;
    padding-bottom: .62rem;
    margin-bottom: .72rem;
    border-bottom: 1px solid #edf2ef;
    scrollbar-width: thin;
}
.mi-status-tab {
    display: inline-flex;
    align-items: center;
    gap: .38rem;
    flex: 0 0 auto;
    min-height: 44px;
    padding: .48rem .7rem;
    border: 1px solid var(--mi-line);
    border-radius: 999px;
    color: #54675d;
    background: #fff;
    font-size: .74rem;
    font-weight: 850;
    white-space: nowrap;
    cursor: pointer;
}
.mi-status-tab b { min-width: 24px; padding: .08rem .35rem; border-radius: 999px; color: #496058; background: #edf3ef; font-size: .68rem; text-align: center; }
.mi-status-tab.active { color: #fff; border-color: var(--mi-primary); background: var(--mi-primary); }
.mi-status-tab.active b { color: #fff; background: rgba(255, 255, 255, .18); }
.mi-status-tab.is-danger.active { border-color: #a72c3b; background: #a72c3b; }
.mi-status-tab.is-warning.active { color: #5b3d05; border-color: #efc669; background: #f7d783; }
.mi-status-tab.is-warning.active b { color: #5b3d05; background: rgba(255, 255, 255, .42); }

.mi-filter { display: grid; grid-template-columns: minmax(260px, 1.35fr) minmax(150px, .72fr) minmax(150px, .72fr) auto auto; gap: .55rem; align-items: end; }
.mi-field { display: flex; flex-direction: column; gap: .3rem; min-width: 0; }
.mi-field-label { color: #4d6258; font-size: .68rem; font-weight: 850; }
.mi-input-wrap,
.mi-select-wrap {
    display: flex;
    align-items: center;
    gap: .45rem;
    min-height: 46px;
    padding-inline: .72rem;
    border: 1px solid var(--mi-line);
    border-radius: 11px;
    color: #829087;
    background: #fff;
    transition: border-color .16s ease, box-shadow .16s ease;
}
.mi-input-wrap:focus-within,
.mi-select-wrap:focus-within { border-color: rgba(var(--primary-rgb, 31, 107, 80), .62); box-shadow: 0 0 0 3px rgba(var(--primary-rgb, 31, 107, 80), .09); }
.mi-input-wrap input,
.mi-select-wrap select { width: 100%; min-width: 0; border: 0; outline: 0; color: #273d33; background: transparent; font: inherit; font-size: .76rem; }
.mi-select-wrap select { min-height: 42px; cursor: pointer; }
.mi-input-clear { display: inline-grid; place-items: center; width: 36px; height: 36px; flex: 0 0 36px; border: 0; border-radius: 9px; color: #6f7f77; background: #f2f5f3; cursor: pointer; }
.mi-search-submit,
.mi-clear-filters { align-self: end; }

.mi-results { padding-bottom: 1.25rem; }
.mi-results-heading { margin: 0 .15rem .72rem; }
.mi-results-heading p { margin: .12rem 0 0; color: var(--mi-muted); font-size: .72rem; }
.mi-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(430px, 100%), 1fr)); gap: .8rem; }
.mi-card {
    overflow: hidden;
    display: flex;
    flex-direction: column;
    min-width: 0;
    border: 1px solid var(--mi-line);
    border-radius: 17px;
    background: #fff;
    box-shadow: 0 8px 24px rgba(18, 63, 49, .045);
}
.mi-card.has-shortage { border-color: #e9b3b9; }
.mi-card.is-off { background: #fafcfb; }
.mi-card-main { display: grid; grid-template-columns: 138px minmax(0, 1fr); gap: .85rem; padding: .78rem; }
.mi-photo { position: relative; overflow: hidden; min-height: 166px; margin: 0; border-radius: 13px; background: #edf2ef; }
.mi-photo img { display: block; width: 100%; height: 100%; min-height: 166px; object-fit: cover; }
.mi-card.is-off .mi-photo img { filter: grayscale(.65); opacity: .72; }
.mi-photo-flags { position: absolute; inset: .45rem .45rem auto; display: flex; align-items: flex-start; justify-content: space-between; gap: .3rem; }
.mi-flag { display: inline-flex; align-items: center; gap: .25rem; min-height: 27px; padding: .2rem .45rem; border-radius: 999px; box-shadow: 0 3px 10px rgba(15, 23, 42, .14); font-size: .62rem; font-weight: 950; backdrop-filter: blur(6px); }
.mi-flag-featured { width: 27px; justify-content: center; padding: 0; color: #7a5004; background: rgba(255, 239, 181, .96); }
.mi-flag-status { margin-inline-start: auto; }
.mi-flag-status.is-available { color: #075c3a; background: rgba(231, 250, 239, .96); }
.mi-flag-status.is-off { color: #9c2635; background: rgba(255, 235, 238, .96); }
.mi-body { display: flex; flex-direction: column; gap: .52rem; min-width: 0; }
.mi-title { display: flex; align-items: flex-start; gap: .6rem; }
.mi-title > div { min-width: 0; flex: 1; }
.mi-title h3 { overflow: hidden; margin: 0; color: #172d23; font-size: 1rem; font-weight: 950; text-overflow: ellipsis; white-space: nowrap; }
.mi-title small { display: block; overflow: hidden; margin-top: .08rem; color: #88968e; font-size: .66rem; text-overflow: ellipsis; white-space: nowrap; }
.mi-price { display: flex; align-items: flex-end; flex-direction: column; color: var(--mi-primary); white-space: nowrap; }
.mi-price > strong { color: var(--mi-primary); font-size: .98rem; }
.mi-price small { display: flex; align-items: center; gap: .25rem; margin: 0; color: #a0690b; font-size: .58rem; font-weight: 900; }
.mi-price small s { color: #9a6c72; font-size: .68rem; font-weight: 700; }
.mi-price em { overflow: hidden; max-width: 115px; color: #8b650d; font-size: .54rem; font-style: normal; text-overflow: ellipsis; }
.mi-context { display: flex; gap: .34rem .58rem; flex-wrap: wrap; color: #65766d; font-size: .68rem; }
.mi-context span { display: inline-flex; align-items: center; gap: .22rem; }
.mi-description { display: -webkit-box; overflow: hidden; margin: 0; color: #718078; font-size: .72rem; line-height: 1.55; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
.mi-alert { display: flex; align-items: flex-start; gap: .48rem; padding: .5rem .58rem; border-radius: 10px; font-size: .68rem; }
.mi-alert > i { margin-top: .08rem; }
.mi-alert div { display: flex; flex-direction: column; min-width: 0; }
.mi-alert strong { font-weight: 950; }
.mi-alert span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.mi-alert-danger { color: #9d2634; background: #fff0f2; }
.mi-alert-danger span { color: #bb5964; }
.mi-alert-warning { color: #785407; background: #fff7e3; }
.mi-alert-warning span { color: #927630; }
.mi-alert-muted { color: #5f6f67; background: #f0f4f2; }
.mi-recipe { border: 1px solid #e5ede8; border-radius: 10px; background: #fbfcfb; }
.mi-recipe summary { display: flex; align-items: center; justify-content: space-between; gap: .5rem; min-height: 40px; padding: .42rem .56rem; color: #385047; font-size: .7rem; font-weight: 900; cursor: pointer; list-style: none; }
.mi-recipe summary::-webkit-details-marker { display: none; }
.mi-recipe summary span { display: inline-flex; align-items: center; gap: .32rem; }
.mi-recipe summary b { min-width: 23px; padding: .05rem .3rem; border-radius: 999px; color: var(--mi-primary); background: var(--mi-primary-soft); text-align: center; }
.mi-recipe-list { display: flex; gap: .3rem; flex-wrap: wrap; padding: 0 .5rem .5rem; }
.mi-recipe-list span { padding: .18rem .38rem; border-radius: 7px; color: #52655b; background: #eef4f0; font-size: .64rem; }
.mi-recipe-list b { color: var(--mi-primary); }
.mi-tags { display: flex; gap: .3rem; flex-wrap: wrap; }
.mi-tag { padding: .18rem .4rem; border: 1px solid #e2e9e4; border-radius: 999px; color: #5a6c62; font-size: .62rem; }
.mi-tag-allergen { color: #8f2530; border-color: #f0d8db; background: #fff7f8; }
.mi-economics { display: grid; grid-template-columns: repeat(3, 1fr); border-top: 1px solid #edf2ef; border-bottom: 1px solid #edf2ef; background: #f8faf9; }
.mi-economics span { display: flex; align-items: center; justify-content: space-between; gap: .35rem; min-width: 0; padding: .48rem .72rem; border-inline-start: 1px solid #e8eeea; }
.mi-economics span:first-child { border-inline-start: 0; }
.mi-economics small { color: #7c8b83; font-size: .62rem; }
.mi-economics b { overflow: hidden; color: #30463b; font-size: .68rem; text-overflow: ellipsis; white-space: nowrap; }
.mi-economics .tone-good b { color: var(--mi-primary); }
.mi-economics .tone-warn b { color: #966508; }
.mi-economics .tone-bad b { color: #a52332; }
.mi-actions { display: flex; gap: .42rem; padding: .62rem .78rem; background: #fff; }
.mi-edit-action { flex: 1; }
.mi-card-action { flex: .72; }
.mi-delete { margin-inline-start: auto; color: #aa2d3b; border-color: #f0d9dc; background: #fff8f8; }
.mi-delete:hover { color: #fff; border-color: #aa2d3b; background: #aa2d3b; }
.mi-empty { grid-column: 1 / -1; min-height: 260px; }
.mi-pagination { margin-top: .9rem; padding: .75rem; border: 1px solid var(--mi-line); border-radius: 14px; background: #fff; }
.mi-stop-form { display: flex; flex-direction: column; gap: 1rem; }
.mi-stop-item { display: flex; align-items: center; gap: .6rem; padding: .75rem; border-radius: 12px; color: #72500a; background: #fff8e7; }
.mi-stop-item > i { display: inline-grid; place-items: center; width: 38px; height: 38px; border-radius: 10px; background: rgba(229, 180, 69, .18); }
.mi-stop-item div { display: flex; flex-direction: column; }
.mi-stop-item small { color: #997c3d; font-size: .64rem; }
.mi-stop-form label { display: flex; flex-direction: column; gap: .35rem; }
.mi-stop-form label > span { color: #34463d; font-size: .78rem; font-weight: 900; }
.mi-stop-form label small { color: #839087; font-weight: 600; }
.mi-stop-form p { margin: 0; color: #77867e; font-size: .72rem; }

@media (max-width: 1199.98px) {
    .mi-filter { grid-template-columns: minmax(240px, 1fr) 1fr 1fr auto; }
    .mi-clear-filters { grid-column: 1 / -1; justify-self: start; }
}

@media (max-width: 767.98px) {
    .mi-hero { align-items: flex-start; min-height: auto; }
    .mi-filter { grid-template-columns: 1fr 1fr; }
    .mi-search-field { grid-column: 1 / -1; }
    .mi-search-submit { width: 100%; }
    .mi-clear-filters { grid-column: auto; width: 100%; }
    .mi-grid { grid-template-columns: 1fr; }
}

@media (max-width: 575.98px) {
    .mi-hero { flex-direction: column; padding: .9rem; border-radius: 16px; }
    .mi-hero-actions { width: 100%; }
    .mi-hero-actions .mi-button { flex: 1; padding-inline: .55rem; }
    .mi-command { padding: .78rem; border-radius: 16px; }
    .mi-command-heading { align-items: flex-start; }
    .mi-result-count { padding-top: .2rem; }
    .mi-filter { grid-template-columns: 1fr; }
    .mi-search-field,
    .mi-clear-filters { grid-column: auto; }
    .mi-card-main { grid-template-columns: 1fr; gap: .68rem; }
    .mi-photo { min-height: 0; aspect-ratio: 16 / 8; }
    .mi-photo img { min-height: 0; height: 100%; }
    .mi-title h3 { white-space: normal; }
    .mi-economics span { flex-direction: column; align-items: flex-start; padding: .45rem .58rem; }
    .mi-actions { flex-wrap: wrap; }
    .mi-edit-action { flex-basis: calc(55% - 48px); }
    .mi-card-action { flex-basis: 40%; }
    .mi-actions .mi-button-secondary { flex: 1; }
    .mi-delete { margin-inline-start: 0; }
    .mi-page-count { display: none; }
}

@media (prefers-reduced-motion: reduce) {
    .mi-button,
    .mi-icon-button,
    .mi-input-wrap,
    .mi-select-wrap { transition: none; }
    .mi-button:hover,
    .mi-icon-button:hover { transform: none; }
}
</style>
