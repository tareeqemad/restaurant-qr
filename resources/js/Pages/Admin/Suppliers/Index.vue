<script setup>
import ManagementPage from '../../../Components/Inventory/ManagementPage.vue';
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';
import StatRail from '../../../Components/Ui/StatRail.vue';
import { useConfirm } from '../../../Composables/useConfirm';

defineOptions({ layout: AdminLayout });
const props = defineProps({
    suppliers: { type: Object, required: true }, stats: { type: Object, required: true },
    filters: { type: Object, required: true }, can: { type: Object, required: true }, urls: { type: Object, required: true },
});
const filter = reactive({ search: props.filters.search ?? '', inactive: Boolean(props.filters.inactive) });
const hasFilters = computed(() => Boolean(filter.search || filter.inactive));
const visit = () => router.get(props.urls.index, { search: filter.search || undefined, inactive: filter.inactive ? 1 : undefined }, { preserveState: true, replace: true });
const clear = () => { filter.search = ''; filter.inactive = false; visit(); };
const confirm = useConfirm();
async function remove(supplier) {
    if (! await confirm.ask({ title: 'حذف المورّد؟', message: `سيُحذف ${supplier.name} نهائياً إذا لم يكن مرتبطاً بمكونات.`, danger: true, confirmLabel: 'حذف' })) return;
    router.delete(supplier.urls.destroy, { preserveScroll: true });
}
</script>

<template>
    <ManagementPage mode="records">
    <Head title="الموردون" />
    <PageHeader title="الموردون" icon="bi-truck" subtitle="ملف واحد لكل مورّد: تواصل، شروط توريد، مكونات وفواتير">
        <template #actions><a v-if="can.create" :href="urls.create" class="btn btn-primary"><i class="bi bi-plus-lg"></i> مورد جديد</a></template>
    </PageHeader>
    <StatRail :stats="[
        {label:'إجمالي الموردين',value:stats.total,icon:'bi-truck',color:'primary'},
        {label:'فعّالون',value:stats.active,icon:'bi-check-circle-fill',color:'success'},
        {label:'مرتبطون بمكونات',value:stats.linked,icon:'bi-link-45deg',color:'accent'},
        {label:'متوقفون',value:stats.inactive,icon:'bi-pause-circle',color:'muted'},
    ]" />

    <DataPanel title="دليل الموردين" :count="suppliers.total" icon="bi-person-lines-fill">
        <template #filters>
            <form class="sup-filter" @submit.prevent="visit">
                <label><i class="bi bi-search"></i><input v-model.trim="filter.search" placeholder="اسم المورد، الهاتف أو المسؤول"></label>
                <button type="button" :class="{active:filter.inactive}" @click="filter.inactive=!filter.inactive;visit()"><i class="bi bi-pause-circle"></i> المتوقفون فقط</button>
                <button class="btn btn-primary"><i class="bi bi-search"></i> بحث</button>
                <button v-if="hasFilters" type="button" class="btn btn-light" @click="clear"><i class="bi bi-x-circle"></i> مسح</button>
            </form>
        </template>

        <div v-if="suppliers.data.length" class="sup-list">
            <article v-for="supplier in suppliers.data" :key="supplier.id" class="sup-card" :class="{muted:!supplier.active}">
                <div class="sup-avatar"><i class="bi bi-truck"></i></div>
                <div class="sup-main">
                    <div class="sup-title"><a :href="supplier.urls.show">{{ supplier.name }}</a><span :class="supplier.active?'on':'off'">{{ supplier.active?'فعّال':'متوقف' }}</span></div>
                    <div class="sup-contact">
                        <span v-if="supplier.contactPerson"><i class="bi bi-person"></i>{{ supplier.contactPerson }}</span>
                        <a v-if="supplier.phone" :href="`tel:${supplier.phone}`"><i class="bi bi-telephone"></i>{{ supplier.phone }}</a>
                        <a v-if="supplier.email" :href="`mailto:${supplier.email}`"><i class="bi bi-envelope"></i>{{ supplier.email }}</a>
                    </div>
                    <small v-if="supplier.address"><i class="bi bi-geo-alt"></i>{{ supplier.address }}</small>
                    <div v-if="supplier.branches.length" class="sup-branches"><span v-for="branch in supplier.branches" :key="branch.id">{{ branch.name }}</span></div>
                </div>
                <div class="sup-stock"><small>مكونات مرتبطة</small><strong>{{ supplier.ingredientsCount }}</strong></div>
                <div class="sup-actions">
                    <a :href="supplier.urls.show" class="btn btn-primary btn-sm"><i class="bi bi-eye"></i> فتح الملف</a>
                    <a v-if="supplier.can.update" :href="supplier.urls.edit" class="btn btn-light btn-sm" title="تعديل"><i class="bi bi-pencil"></i></a>
                    <button v-if="supplier.can.delete" type="button" class="btn btn-outline-danger btn-sm" title="حذف" @click="remove(supplier)"><i class="bi bi-trash"></i></button>
                </div>
            </article>
        </div>
        <EmptyState v-else icon="bi-truck" title="لا يوجد موردون في هذه النتيجة" message="أضف أول مورّد أو غيّر البحث.">
            <template #cta><a v-if="can.create" :href="urls.create" class="btn btn-primary">مورد جديد</a></template>
        </EmptyState>
        <template #footer><Pagination :links="suppliers.links" /></template>
    </DataPanel>
    </ManagementPage>
</template>

<style scoped>
.sup-filter{display:flex;flex-wrap:wrap;gap:8px}.sup-filter label{display:flex;flex:1 1 280px;align-items:center;gap:8px;padding:0 12px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:11px;background:var(--surface, #FFFDFA)}.sup-filter label input{width:100%;padding:10px 0;border:0;outline:0}.sup-filter>button:not(.btn){min-height:42px;padding:0 13px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:11px;color:var(--muted, #626B6D);background:var(--surface, #FFFDFA);font-weight:750}.sup-filter>button.active{color:var(--ink, #25343A);border-color:var(--brand, #A3422B);background:var(--canvas, #F7F3ED)}
.sup-list{display:grid;gap:8px;padding:4px}.sup-card{display:grid;grid-template-columns:46px minmax(0,1fr) 110px auto;align-items:center;gap:12px;padding:13px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;background:var(--surface, #FFFDFA)}.sup-card.muted{background:var(--canvas, #F7F3ED);opacity:.82}.sup-avatar{display:grid;width:46px;height:46px;place-items:center;border-radius:12px;color:var(--ink, #25343A);background:var(--canvas, #F7F3ED);font-size:1.15rem}.sup-main{display:grid;gap:5px;min-width:0}.sup-title{display:flex;align-items:center;gap:8px}.sup-title a{color:var(--ink, #25343A);font-size:.9rem;font-weight:900}.sup-title span{padding:3px 7px;border-radius:12px;font-size:.875rem;font-weight:850}.sup-title .on{color:var(--brand, #A3422B);background:var(--canvas, #F7F3ED)}.sup-title .off{color:var(--muted, #626B6D);background:var(--canvas, #F7F3ED)}.sup-contact{display:flex;flex-wrap:wrap;gap:10px;color:var(--muted, #626B6D);font-size:.875rem}.sup-contact span,.sup-contact a{display:flex;align-items:center;gap:4px;color:inherit}.sup-main>small{overflow:hidden;color:var(--muted, #626B6D);font-size:.875rem;text-overflow:ellipsis;white-space:nowrap}.sup-branches{display:flex;flex-wrap:wrap;gap:4px}.sup-branches span{padding:2px 6px;border-radius:7px;color:var(--muted, #626B6D);background:var(--canvas, #F7F3ED);font-size:.875rem}.sup-stock{display:grid;text-align:center}.sup-stock small{color:var(--muted, #626B6D);font-size:.875rem}.sup-stock strong{color:var(--ink, #25343A);font-size:1rem}.sup-actions{display:flex;gap:5px}
@media(max-width:800px){.sup-card{grid-template-columns:42px minmax(0,1fr) auto}.sup-stock{grid-column:2;text-align:start;display:flex;gap:7px;align-items:center}.sup-actions{grid-column:3;grid-row:1/3;flex-direction:column}.sup-avatar{width:42px;height:42px}}
@media(max-width:520px){.sup-card{grid-template-columns:40px minmax(0,1fr)}.sup-actions{grid-column:1/-1;grid-row:auto;flex-direction:row}.sup-actions .btn-primary{flex:1}.sup-contact{display:grid;gap:4px}.sup-stock{grid-column:2}}

/* Dar Al Sarw management layout */
.sup-filter { gap: 12px; } .sup-filter label { min-height: 48px; border-radius: 8px; background: var(--surface); } .sup-list { grid-template-columns: repeat(auto-fill,minmax(min(340px,100%),1fr)); gap: 18px; } .sup-card { padding: 20px; border-radius: 12px; }
</style>
