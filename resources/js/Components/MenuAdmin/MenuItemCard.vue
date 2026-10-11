<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import EmptyState from '../Ui/EmptyState.vue';

const props = defineProps({
    item: { type: Object, required: true },
    sales: { type: Object, required: true },
    priceHistory: { type: Array, default: () => [] },
    promotions: { type: Array, default: () => [] },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const activeTab = ref('summary');

const tabs = computed(() => [
    { id: 'summary', label: 'الملخص', icon: 'bi-speedometer2' },
    { id: 'history', label: 'تاريخ السعر', icon: 'bi-clock-history', count: props.priceHistory.length },
    { id: 'offers', label: 'العروض', icon: 'bi-tag', count: props.promotions.length },
    { id: 'recipe', label: 'الوصفة', icon: 'bi-basket2', count: props.item.recipe.length },
]);

const promotionStatus = (status) => ({
    live: ['يعمل الآن', 'bi-broadcast', 'live'],
    upcoming: ['قادم', 'bi-calendar-event', 'upcoming'],
    expired: ['منتهي', 'bi-calendar-x', 'expired'],
    paused: ['متوقف', 'bi-pause-circle', 'paused'],
    deleted: ['محذوف', 'bi-trash3', 'deleted'],
    outside: ['خارج الوقت', 'bi-clock', 'outside'],
}[status] ?? [status, 'bi-circle', 'outside']);
</script>

<template>
    <article class="item-card">
        <section class="item-overview">
            <figure><img :src="item.imageUrl" :alt="item.name"></figure>

            <div class="item-copy">
                <div class="eyebrow">
                    <span>{{ item.category || 'بلا قسم' }}</span>
                    <span>{{ item.station || 'محطة القسم' }}</span>
                    <span v-if="item.sku">SKU: {{ item.sku }}</span>
                </div>
                <h2>{{ item.name }}</h2>
                <p>{{ item.description || 'لا يوجد وصف للزبون.' }}</p>
                <div class="item-flags">
                    <span :class="item.isAvailable ? 'available' : 'off'">
                        <i class="bi" :class="item.isAvailable ? 'bi-check-circle-fill' : 'bi-pause-circle-fill'"></i>
                        {{ item.isAvailable ? 'متاح للطلب' : 'متوقف' }}
                    </span>
                    <span v-if="item.isFeatured" class="featured"><i class="bi bi-star-fill"></i> مميز</span>
                    <span><i class="bi bi-clock"></i> {{ item.prepMinutes }} دقيقة</span>
                </div>
            </div>

            <aside class="price-now" :class="{ promoted: item.hasPromotion }">
                <small>{{ item.hasPromotion ? 'سعر العرض الآن' : 'سعر البيع الآن' }}</small>
                <div v-if="item.hasPromotion" class="old"><s>{{ item.basePrice }}</s><span>السعر الأساسي</span></div>
                <strong>{{ item.effectivePrice }}</strong>
                <em v-if="item.hasPromotion"><i class="bi bi-tag-fill"></i>{{ item.promotionName }}</em>
                <span v-else>لا يوجد عرض نشط</span>
            </aside>
        </section>

        <div class="card-actions">
            <Link v-if="can.update" :href="urls.edit" class="card-button primary">
                <i class="bi bi-pencil-square"></i> تحرير الصنف
            </Link>
            <Link v-if="can.createPromotion" :href="urls.createPromotion" class="card-button secondary">
                <i class="bi bi-tag"></i> إنشاء عرض
            </Link>
        </div>

        <nav class="card-tabs" aria-label="أقسام كرت الصنف">
            <button v-for="tab in tabs" :key="tab.id" type="button"
                    :class="{ active: activeTab === tab.id }" :aria-pressed="activeTab === tab.id"
                    @click="activeTab = tab.id">
                <i class="bi" :class="tab.icon"></i>
                <span>{{ tab.label }}</span>
                <b v-if="tab.count !== undefined">{{ tab.count }}</b>
            </button>
        </nav>

        <section v-if="activeTab === 'summary'" class="tab-content summary-tab">
            <div class="stat-grid">
                <article><i class="bi bi-basket2"></i><span><small>تكلفة الحصة</small><b>{{ item.cost }}</b></span></article>
                <article><i class="bi bi-graph-up-arrow"></i><span><small>ربح الحصة الآن</small><b>{{ item.profit }}</b></span></article>
                <article><i class="bi bi-percent"></i><span><small>الهامش الآن</small><b>{{ item.margin === null ? '—' : `${item.margin}%` }}</b></span></article>
                <article><i class="bi bi-bag-check"></i><span><small>الكمية المباعة</small><b>{{ sales.quantity }}</b></span></article>
                <article><i class="bi bi-cash-stack"></i><span><small>إجمالي المبيعات</small><b>{{ sales.revenue }}</b></span></article>
                <article><i class="bi bi-receipt"></i><span><small>متوسط سعر البيع</small><b>{{ sales.averagePrice }}</b></span></article>
            </div>

            <div class="price-rules">
                <header><i class="bi bi-lightbulb-fill"></i><div><small>قاعدة العمل</small><h3>كل رقم في مكانه الصحيح</h3></div></header>
                <div class="rule-grid">
                    <p><b>مادة المخزون</b><span>تكلفة شراء ومتوسط تكلفة، وليست سعر بيع الوجبة.</span></p>
                    <p><b>سعر صنف المنيو</b><span>السعر الأساسي الدائم للحصة.</span></p>
                    <p><b>سعر العرض</b><span>تخفيض مؤقت لا يغيّر السعر الأساسي.</span></p>
                    <p><b>سعر الطلب</b><span>لقطة محفوظة، لذلك لا تتغير الفاتورة القديمة.</span></p>
                </div>
                <footer v-if="sales.lastSoldAt"><i class="bi bi-clock-history"></i> آخر بيع: {{ sales.lastSoldAt }}</footer>
            </div>
        </section>

        <section v-else-if="activeTab === 'history'" class="tab-content panel">
            <header><div><small>سجل غير قابل لإعادة الكتابة</small><h3>تاريخ السعر الأساسي</h3></div><b>{{ priceHistory.length }} حركة</b></header>
            <div v-if="priceHistory.length" class="timeline">
                <article v-for="row in priceHistory" :key="row.id">
                    <i class="bi" :class="row.type === 'initial' ? 'bi-flag-fill' : 'bi-arrow-repeat'"></i>
                    <div>
                        <strong v-if="row.oldPrice"><s>{{ row.oldPrice }}</s><i class="bi bi-arrow-left"></i>{{ row.newPrice }}</strong>
                        <strong v-else>{{ row.newPrice }}</strong>
                        <p>{{ row.reason || 'تسجيل السعر الأساسي' }}</p>
                        <small>{{ row.changedAt }} · {{ row.changedBy }}</small>
                    </div>
                </article>
            </div>
            <EmptyState v-else icon="bi-clock-history" title="لا يوجد تاريخ سعر" message="تظهر أول حركة عند حفظ السعر أو تغييره." />
        </section>

        <section v-else-if="activeTab === 'offers'" class="tab-content panel">
            <header><div><small>منفصل عن السعر الأساسي</small><h3>عروض الصنف</h3></div></header>
            <div v-if="promotions.length" class="offer-list">
                <article v-for="offer in promotions" :key="offer.id" :class="`is-${offer.status}`">
                    <div class="offer-head">
                        <strong>{{ offer.name }}</strong>
                        <span :class="promotionStatus(offer.status)[2]">
                            <i class="bi" :class="promotionStatus(offer.status)[1]"></i>
                            {{ promotionStatus(offer.status)[0] }}
                        </span>
                    </div>
                    <div class="offer-value">
                        <b>{{ offer.valueLabel }}</b><span>{{ offer.typeLabel }}</span>
                        <em>{{ offer.hasPriceDiscount ? `سعره ${offer.offerPrice}` : 'ميزة بلا خفض للسعر' }}</em>
                    </div>
                    <small><i class="bi bi-calendar3"></i>{{ offer.scheduleLabel }}</small>
                    <small><i class="bi bi-bullseye"></i>{{ offer.scope }}</small>
                </article>
            </div>
            <EmptyState v-else icon="bi-tag" title="لا توجد عروض لهذا الصنف" message="السعر الأساسي يبقى ظاهراً حتى تنشئ عرضاً." />
            <Link :href="urls.promotions" class="inline-link">إدارة كل العروض <i class="bi bi-arrow-left"></i></Link>
        </section>

        <section v-else class="tab-content panel">
            <header><div><small>تكلفة ومخزون</small><h3>وصفة الحصة الواحدة</h3></div><b>{{ item.recipe.length }} مكوّن</b></header>
            <div v-if="item.recipe.length" class="recipe-list">
                <template v-for="row in item.recipe" :key="row.id">
                    <Link v-if="row.url" :href="row.url">
                        <span><i class="bi bi-box-seam"></i><strong>{{ row.ingredient }}</strong><small v-if="row.optional">اختياري</small></span>
                        <b>{{ row.quantity }} {{ row.unit }}</b><i class="bi bi-chevron-left"></i>
                    </Link>
                    <div v-else>
                        <span><i class="bi bi-box-seam"></i><strong>{{ row.ingredient }}</strong><small v-if="row.optional">اختياري</small></span>
                        <b>{{ row.quantity }} {{ row.unit }}</b><i></i>
                    </div>
                </template>
            </div>
            <EmptyState v-else icon="bi-basket2" title="لا توجد وصفة" message="لن تُخصم مكونات من المخزون حتى تضيف وصفة للحصة." />
            <div v-if="item.allergens.length || item.modifierGroups.length" class="meta-tags">
                <span v-for="allergen in item.allergens" :key="allergen"><i class="bi bi-exclamation-diamond"></i>{{ allergen }}</span>
                <span v-for="group in item.modifierGroups" :key="group"><i class="bi bi-sliders"></i>{{ group }}</span>
            </div>
        </section>
    </article>
</template>

<style scoped>
.item-card{--card-primary:var(--brand, #A3422B);--card-deep:var(--ink, #25343A);--card-line:var(--brand-soft, #F3E3D8);display:grid;gap:.75rem;color:var(--ink, #25343A)}.item-overview{display:grid;grid-template-columns:125px minmax(0,1fr) minmax(190px,.5fr);gap:.8rem;padding:.8rem;border:1px solid var(--card-line);border-radius:12px;background:var(--surface, #FFFDFA)}.item-overview figure{overflow:hidden;margin:0;min-height:125px;border-radius:12px;background:var(--canvas, #F7F3ED)}.item-overview img{width:100%;height:100%;object-fit:cover}.item-copy{display:flex;min-width:0;flex-direction:column;justify-content:center}.eyebrow,.item-flags{display:flex;flex-wrap:wrap;gap:.3rem}.eyebrow span,.item-flags span{display:inline-flex;align-items:center;gap:.24rem;padding:.2rem .42rem;border-radius:12px;color:var(--muted, #626B6D);background:var(--canvas, #F7F3ED);font-size:.875rem;font-weight:800}.item-copy h2{margin:.38rem 0 .14rem;color:var(--card-deep);font-size:1.22rem;font-weight:950}.item-copy p{margin:0 0 .5rem;color:var(--muted, #626B6D);font-size:.875rem;line-height:1.65}.item-flags .available{color:var(--ink, #25343A);background:var(--canvas, #F7F3ED)}.item-flags .off{color:#a22837;background:#fff0f2}.item-flags .featured{color:#765007;background:#fff4d6}.price-now{display:flex;min-width:0;flex-direction:column;justify-content:center;align-items:flex-start;padding:.8rem;border:1px solid var(--card-line);border-radius:12px;background:var(--canvas, #F7F3ED)}.price-now.promoted{border-color:#eccb76;background:#fff9e8}.price-now>small,.price-now>span{color:var(--muted, #626B6D);font-size:.875rem}.price-now>strong{color:var(--card-primary);font-size:1.38rem;font-weight:950}.price-now .old{display:flex;align-items:center;gap:.35rem;color:#9a6d0e;font-size:.875rem}.price-now>em{display:inline-flex;gap:.25rem;color:#8a5d05;font-size:.875rem;font-style:normal;font-weight:900}.card-actions{display:flex;flex-wrap:wrap;gap:.45rem}.card-button{min-height:44px;display:inline-flex;align-items:center;justify-content:center;gap:.35rem;padding:.55rem .8rem;border:1px solid var(--card-line);border-radius:11px;font-size:.875rem;font-weight:900}.card-button.primary{color:#fff;border-color:var(--card-primary);background:var(--card-primary)}.card-button.secondary{color:var(--card-primary);background:var(--surface, #FFFDFA)}.card-tabs{display:grid;grid-template-columns:repeat(4,1fr);padding:.28rem;border:1px solid var(--card-line);border-radius:12px;background:var(--canvas, #F7F3ED)}.card-tabs button{min-height:46px;display:flex;align-items:center;justify-content:center;gap:.35rem;border:0;border-radius:10px;color:var(--muted, #626B6D);background:transparent;font-size:.875rem;font-weight:900}.card-tabs button.active{color:var(--card-deep);background:var(--surface, #FFFDFA);box-shadow:none}.card-tabs b{min-width:20px;padding:.1rem .3rem;border-radius:12px;background:var(--canvas, #F7F3ED);font-size:.875rem}.tab-content{min-height:250px}.stat-grid{display:grid;grid-template-columns:repeat(3,1fr);overflow:hidden;border:1px solid var(--card-line);border-radius:12px;background:var(--surface, #FFFDFA)}.stat-grid article{display:flex;align-items:center;gap:.5rem;min-width:0;padding:.72rem;border-inline-start:1px solid var(--canvas, #F7F3ED);border-top:1px solid var(--canvas, #F7F3ED)}.stat-grid article:nth-child(-n+3){border-top:0}.stat-grid article:nth-child(3n+1){border-inline-start:0}.stat-grid article>i{width:36px;height:36px;display:grid;place-items:center;flex:0 0 36px;border-radius:10px;color:var(--card-primary);background:var(--canvas, #F7F3ED)}.stat-grid span{display:flex;min-width:0;flex-direction:column}.stat-grid small{color:var(--muted, #626B6D);font-size:.875rem}.stat-grid b{overflow:hidden;color:var(--ink, #25343A);font-size:.875rem;text-overflow:ellipsis;white-space:nowrap}.price-rules{margin-top:.7rem;overflow:hidden;border:1px solid var(--card-line);border-radius:12px;background:var(--surface, #FFFDFA)}.price-rules>header,.panel>header{display:flex;align-items:center;justify-content:space-between;gap:.55rem;padding:.72rem .82rem;border-bottom:1px solid var(--canvas, #F7F3ED)}.price-rules>header{justify-content:flex-start}.price-rules>header>i{color:#c38a13}.price-rules small,.panel>header small{color:var(--muted, #626B6D);font-size:.875rem;font-weight:800}.price-rules h3,.panel>header h3{margin:.06rem 0 0;color:var(--card-deep);font-size:.875rem;font-weight:950}.rule-grid{display:grid;grid-template-columns:1fr 1fr;gap:.48rem;padding:.65rem}.rule-grid p{display:flex;flex-direction:column;margin:0;padding:.55rem;border-radius:10px;background:var(--canvas, #F7F3ED)}.rule-grid b{font-size:.875rem}.rule-grid span{color:var(--muted, #626B6D);font-size:.875rem;line-height:1.55}.price-rules footer{display:flex;gap:.3rem;padding:.55rem .75rem;border-top:1px solid var(--canvas, #F7F3ED);color:var(--muted, #626B6D);font-size:.875rem}.panel{overflow:hidden;border:1px solid var(--card-line);border-radius:12px;background:var(--surface, #FFFDFA)}.panel>header>b{padding:.15rem .42rem;border-radius:12px;color:var(--card-primary);background:var(--canvas, #F7F3ED);font-size:.875rem}.timeline{padding:.3rem .75rem}.timeline article{display:grid;grid-template-columns:34px 1fr;gap:.5rem;padding:.62rem 0;border-bottom:1px solid var(--canvas, #F7F3ED)}.timeline article:last-child{border-bottom:0}.timeline article>i{width:32px;height:32px;display:grid;place-items:center;border-radius:9px;color:var(--card-primary);background:var(--canvas, #F7F3ED)}.timeline article>div{display:flex;flex-direction:column}.timeline strong{display:flex;align-items:center;gap:.32rem;color:var(--ink, #25343A);font-size:.875rem}.timeline strong s{color:var(--muted, #626B6D);font-weight:700}.timeline p{margin:.08rem 0;color:var(--muted, #626B6D);font-size:.875rem}.timeline small{color:var(--muted, #626B6D);font-size:.875rem}.offer-list{display:grid;gap:.45rem;padding:.65rem}.offer-list article{padding:.6rem;border:1px solid var(--brand-soft, #F3E3D8);border-inline-start:4px solid var(--line, #DFD7CB);border-radius:11px}.offer-list article.is-live{border-inline-start-color:var(--card-primary);background:var(--surface, #FFFDFA)}.offer-list article.is-upcoming{border-inline-start-color:#3583a2}.offer-list article.is-expired,.offer-list article.is-deleted{opacity:.65}.offer-head{display:flex;align-items:center;justify-content:space-between;gap:.45rem}.offer-head>strong{font-size:.875rem}.offer-head>span{display:inline-flex;align-items:center;gap:.2rem;padding:.14rem .38rem;border-radius:12px;font-size:.875rem;font-weight:900}.offer-head .live{color:var(--ink, #25343A);background:var(--canvas, #F7F3ED)}.offer-head .upcoming{color:#246781;background:#eaf5fa}.offer-head .paused,.offer-head .outside{color:#7a5a12;background:#fff5db}.offer-head .expired,.offer-head .deleted{color:#6e7873;background:#eef1ef}.offer-value{display:flex;align-items:baseline;gap:.38rem;margin:.32rem 0}.offer-value b{color:var(--card-primary);font-size:.875rem}.offer-value span,.offer-value em{color:var(--muted, #626B6D);font-size:.875rem;font-style:normal}.offer-value em{margin-inline-start:auto}.offer-list article>small{display:inline-flex;align-items:center;gap:.2rem;margin-inline-end:.6rem;color:var(--muted, #626B6D);font-size:.875rem}.inline-link{min-height:44px;display:inline-flex;align-items:center;gap:.3rem;margin:0 .75rem .6rem;color:var(--card-primary);font-size:.875rem;font-weight:900}.recipe-list{display:grid;padding:.3rem .75rem}.recipe-list>a,.recipe-list>div{min-height:48px;display:grid;grid-template-columns:1fr auto 14px;align-items:center;gap:.45rem;border-bottom:1px solid var(--canvas, #F7F3ED);color:var(--ink, #25343A)}.recipe-list>a:last-child,.recipe-list>div:last-child{border-bottom:0}.recipe-list span{display:flex;align-items:center;gap:.35rem}.recipe-list span>i{color:var(--card-primary)}.recipe-list strong,.recipe-list>b{font-size:.875rem}.recipe-list small{padding:.08rem .25rem;border-radius:12px;color:#7b5a0e;background:#fff4d7;font-size:.875rem}.meta-tags{display:flex;flex-wrap:wrap;gap:.3rem;padding:.6rem .75rem;border-top:1px solid var(--canvas, #F7F3ED)}.meta-tags span{display:inline-flex;align-items:center;gap:.24rem;padding:.18rem .38rem;border-radius:12px;color:var(--muted, #626B6D);background:var(--canvas, #F7F3ED);font-size:.875rem}
@media(max-width:720px){.item-overview{grid-template-columns:88px minmax(0,1fr)}.item-overview figure{min-height:96px}.price-now{grid-column:1/-1}.card-tabs{display:flex;overflow-x:auto;scrollbar-width:none}.card-tabs button{min-width:max-content;flex:1;padding-inline:.65rem}.stat-grid{grid-template-columns:repeat(2,1fr)}.stat-grid article:nth-child(-n+3){border-top:1px solid var(--canvas, #F7F3ED)}.stat-grid article:nth-child(-n+2){border-top:0}.stat-grid article:nth-child(3n+1){border-inline-start:1px solid var(--canvas, #F7F3ED)}.stat-grid article:nth-child(odd){border-inline-start:0}.rule-grid{grid-template-columns:1fr}.item-copy h2{font-size:1rem}}
@media(max-width:420px){.item-overview{grid-template-columns:1fr}.item-overview figure{aspect-ratio:16/8}.card-actions>*{flex:1}.offer-value{flex-wrap:wrap}.offer-value em{width:100%;margin:0}}
@media(prefers-reduced-motion:reduce){*{scroll-behavior:auto!important}}

/* Dar Al Sarw management layout */
.item-card { --card-primary: var(--brand); --card-deep: var(--ink); --card-line: var(--line); gap: 20px; } .item-overview { padding: 22px; gap: 20px; border-radius: 12px; grid-template-columns: 140px minmax(0,1fr) minmax(190px,.55fr); } .item-overview h2 { color: var(--ink); font-size: 1.5rem; } .price-now { padding: 20px; border-radius: 10px; background: var(--brand-soft); color: var(--ink); } .card-tabs button { min-height: 46px; border-radius: 8px; } .panel { padding: 22px; border-radius: 12px; } @media(max-width:850px) { .item-overview { grid-template-columns: 100px minmax(0,1fr); } .price-now { grid-column: 1/-1; } }
</style>
