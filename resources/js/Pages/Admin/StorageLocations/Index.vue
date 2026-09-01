<script setup>
/**
 * مواقع التخزين — one card per physical location (kitchen, bar, cellar…).
 *
 * Everything on a card is a server figure: the ingredient count, the
 * low-stock count (LocationInventoryService's own rule — per-location
 * threshold with a fallback to the ingredient's) and the stock value
 * (Σ qty × cost). Nothing is re-derived here.
 *
 * Two gates ride along as props because the retired Blade showed the
 * buttons to everybody: `can.manage` (create/edit/delete all resolve to
 * InventoryPolicy::manage) and per-card `blocksDelete`, which mirrors
 * destroy()'s "a location holding stock cannot be deleted" guard so the
 * card explains it instead of bouncing the click off a flash message.
 */
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { useToast } from '../../../Composables/useToast';
import { formatMoney } from '../../../Composables/useMoney';
import { formPost } from '../../../Support/formPost';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    locations: { type: Array, required: true },
    can: { type: Object, required: true },
    currency: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const { ask } = useConfirm();
const toast = useToast();

const headStyle = (loc) => ({
    background: `linear-gradient(135deg, ${loc.color} 0%, ${loc.color}dd 100%)`,
});

const money = (v) => formatMoney(v, props.currency);

const destroy = async (loc) => {
    if (loc.blocksDelete) {
        toast.warning(`«${loc.name}» فيه مخزون. انقل المخزون لموقع آخر أولاً.`);
        return;
    }
    const yes = await ask({
        title: `حذف الموقع «${loc.name}»؟`,
        message: 'الحذف ما بينعكس. لو بس بدك توقفه عن الاستخدام، عدّله واشطب «فعّال».',
        confirmLabel: 'احذف الموقع',
        danger: true,
    });
    if (yes) formPost(loc.urls.destroy, { _method: 'DELETE' });
};
</script>

<template>
    <Head title="مواقع التخزين" />

    <PageHeader title="مواقع التخزين" icon="bi-geo-alt-fill"
                subtitle="إدارة المخازن وتوزيع المخزون بينها">
        <template #actions>
            <a :href="urls.transfer" class="btn btn-light">
                <i class="bi bi-arrow-left-right"></i> نقل بين المواقع
            </a>
            <a v-if="can.manage" :href="urls.create" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> موقع جديد
            </a>
        </template>
    </PageHeader>

    <div v-if="locations.length" class="loc-grid">
        <article v-for="loc in locations" :key="loc.id"
                 class="loc-card"
                 :class="{ 'is-default': loc.isDefault, 'is-inactive': ! loc.active }">
            <!-- Header -->
            <div class="loc-card-head" :style="headStyle(loc)">
                <div class="badges">
                    <span v-if="loc.isDefault" class="pill" title="الموقع الافتراضي للفرع">
                        <i class="bi bi-star-fill"></i> افتراضي
                    </span>
                    <span v-if="! loc.active" class="pill muted">
                        <i class="bi bi-pause-circle-fill"></i> غير نشط
                    </span>
                </div>
                <h3 class="name">{{ loc.name }}</h3>
                <div class="meta">
                    <span v-if="loc.code" class="code">{{ loc.code }}</span>
                    <span v-if="loc.branchName" class="branch">
                        <i class="bi bi-building"></i> {{ loc.branchName }}
                    </span>
                </div>
            </div>

            <!-- Body -->
            <div class="loc-card-body">
                <div class="loc-card-desc">{{ loc.description || 'لا يوجد وصف' }}</div>

                <div class="loc-stats">
                    <div class="loc-stat" title="عدد المكونات المخزّنة في هذا الموقع">
                        <div class="label">المكونات</div>
                        <div class="value">{{ loc.ingredientsCount }}</div>
                    </div>
                    <div class="loc-stat" :class="{ 'is-warn': loc.lowStockCount > 0 }"
                         title="مكونات وصلت أو هبطت تحت حد إعادة الطلب">
                        <div class="label">
                            <i v-if="loc.lowStockCount > 0" class="bi bi-exclamation-triangle-fill"></i>
                            مخزون منخفض
                        </div>
                        <div class="value">{{ loc.lowStockCount }}</div>
                    </div>
                </div>

                <div class="loc-value-card" title="إجمالي قيمة المخزون = الكميات × التكلفة">
                    <div class="label"><i class="bi bi-coin"></i> قيمة المخزون</div>
                    <div class="amount">{{ money(loc.stockValue) }}</div>
                </div>
            </div>

            <!-- Actions -->
            <div class="loc-card-actions">
                <a :href="loc.urls.show" class="btn btn-primary flex-grow-1">
                    <i class="bi bi-eye"></i> تفاصيل
                </a>
                <a v-if="can.manage" :href="loc.urls.edit" class="btn btn-light btn-icon" title="تعديل">
                    <i class="bi bi-pencil"></i>
                </a>
                <button v-if="can.manage && ! loc.isDefault" type="button"
                        class="btn btn-outline-danger btn-icon"
                        :class="{ 'is-blocked': loc.blocksDelete }"
                        :title="loc.blocksDelete ? 'فيه مخزون — انقله أولاً' : 'حذف'"
                        @click="destroy(loc)">
                    <i class="bi" :class="loc.blocksDelete ? 'bi-lock' : 'bi-trash'"></i>
                </button>
            </div>
        </article>
    </div>

    <EmptyState v-else icon="bi-geo-alt"
                title="ما في مواقع تخزين بعد"
                message="أضف مواقع (مطبخ، بار، ثلاجة) لتوزيع المخزون عليها.">
        <template v-if="can.manage" #cta>
            <a :href="urls.create" class="btn btn-primary">
                <i class="bi bi-plus-lg"></i> موقع جديد
            </a>
        </template>
    </EmptyState>
</template>

<style scoped>
.loc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(330px, 100%), 1fr));
    gap: 1rem;
}

.loc-card {
    background: #fff;
    border: 1px solid rgba(15, 71, 49, .08);
    border-radius: 14px;
    overflow: hidden;
    transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    display: flex;
    flex-direction: column;
    position: relative;
}
.loc-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 14px 32px rgba(15, 71, 49, .09);
    border-color: rgba(15, 71, 49, .16);
}
.loc-card.is-inactive { opacity: .65; }
.loc-card.is-default::before {
    content: '';
    position: absolute;
    top: 0;
    bottom: 0;
    inset-inline-start: 0;
    width: 4px;
    background: var(--accent);
    z-index: 2;
}

.loc-card-head {
    position: relative;
    padding: 1.15rem 1.15rem 1rem;
    color: #fff;
    overflow: hidden;
}
.loc-card-head .name {
    font-size: 1.35rem;
    font-weight: 800;
    margin: 0;
    line-height: 1.25;
    color: #fff;
}
.loc-card-head .meta {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem .65rem;
    align-items: center;
    margin-top: .5rem;
    font-size: 12.5px;
    color: rgba(255, 255, 255, .92);
}
.loc-card-head .meta .code {
    font-family: ui-monospace, SFMono-Regular, monospace;
    background: rgba(255, 255, 255, .2);
    padding: 2px 9px;
    border-radius: 999px;
    font-size: 11.5px;
}
.loc-card-head .meta .branch {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
}
.loc-card-head .badges {
    position: absolute;
    top: .9rem;
    inset-inline-start: 1rem;
    display: flex;
    gap: .35rem;
}
.loc-card-head .pill {
    font-size: 10.5px;
    font-weight: 700;
    background: rgba(255, 255, 255, .22);
    color: #fff;
    padding: 3px 9px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    gap: .25rem;
}
.loc-card-head .pill.muted { background: rgba(0, 0, 0, .2); }

.loc-card-body { padding: 1rem 1.1rem; flex-grow: 1; }
.loc-card-desc {
    font-size: 12.5px;
    color: #6c757d;
    line-height: 1.55;
    margin-bottom: .85rem;
    min-height: 1em;
}

.loc-stats {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: .55rem;
    margin-bottom: .85rem;
}
.loc-stat {
    background: rgba(15, 71, 49, .04);
    border-radius: 10px;
    padding: .55rem .75rem;
    text-align: center;
}
.loc-stat .label {
    font-size: 11px;
    color: #6c757d;
    font-weight: 600;
    margin-bottom: 2px;
}
.loc-stat .value {
    font-size: 1.5rem;
    font-weight: 800;
    line-height: 1;
    color: var(--primary);
}
.loc-stat.is-warn { background: rgba(220, 53, 69, .06); }
.loc-stat.is-warn .value { color: #b02a37; }

.loc-value-card {
    background: linear-gradient(135deg, rgba(15, 71, 49, .06), rgba(15, 71, 49, .015));
    border: 1px solid rgba(15, 71, 49, .1);
    border-radius: 10px;
    padding: .75rem 1rem;
    text-align: center;
}
.loc-value-card .label {
    font-size: 11.5px;
    color: #6c757d;
    font-weight: 600;
    margin-bottom: 3px;
}
.loc-value-card .amount {
    font-size: 1.6rem;
    font-weight: 800;
    color: var(--primary);
    font-variant-numeric: tabular-nums;
    line-height: 1.05;
}

.loc-card-actions {
    display: flex;
    gap: .4rem;
    padding: .7rem 1.1rem .9rem;
    border-top: 1px solid rgba(15, 71, 49, .06);
    background: #fafbfa;
}
.loc-card-actions .btn { min-height: 44px; }
.loc-card-actions .btn-icon {
    width: 44px;
    min-width: 44px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
}
.loc-card-actions .btn-icon.is-blocked { opacity: .6; }
</style>
