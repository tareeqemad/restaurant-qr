<script setup>
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
</template>

<style scoped>
.sup-filter{display:flex;flex-wrap:wrap;gap:8px}.sup-filter label{display:flex;flex:1 1 280px;align-items:center;gap:8px;padding:0 12px;border:1px solid #dfe7e2;border-radius:11px;background:#fff}.sup-filter label input{width:100%;padding:10px 0;border:0;outline:0}.sup-filter>button:not(.btn){min-height:42px;padding:0 13px;border:1px solid #dfe7e2;border-radius:11px;color:#67766d;background:#fff;font-weight:750}.sup-filter>button.active{color:#176b39;border-color:#9bc8a8;background:#eef8f1}
.sup-list{display:grid;gap:8px;padding:4px}.sup-card{display:grid;grid-template-columns:46px minmax(0,1fr) 110px auto;align-items:center;gap:12px;padding:13px;border:1px solid #e1e8e3;border-radius:15px;background:#fff}.sup-card.muted{background:#fafbfa;opacity:.82}.sup-avatar{display:grid;width:46px;height:46px;place-items:center;border-radius:13px;color:#176b39;background:#eaf5ed;font-size:1.15rem}.sup-main{display:grid;gap:5px;min-width:0}.sup-title{display:flex;align-items:center;gap:8px}.sup-title a{color:#24382d;font-size:.9rem;font-weight:900}.sup-title span{padding:3px 7px;border-radius:99px;font-size:.57rem;font-weight:850}.sup-title .on{color:#14723c;background:#e8f6ec}.sup-title .off{color:#6e7a73;background:#edf0ee}.sup-contact{display:flex;flex-wrap:wrap;gap:10px;color:#687970;font-size:.67rem}.sup-contact span,.sup-contact a{display:flex;align-items:center;gap:4px;color:inherit}.sup-main>small{overflow:hidden;color:#849188;font-size:.62rem;text-overflow:ellipsis;white-space:nowrap}.sup-branches{display:flex;flex-wrap:wrap;gap:4px}.sup-branches span{padding:2px 6px;border-radius:7px;color:#617068;background:#f0f3f1;font-size:.55rem}.sup-stock{display:grid;text-align:center}.sup-stock small{color:#849087;font-size:.59rem}.sup-stock strong{color:#176b39;font-size:1rem}.sup-actions{display:flex;gap:5px}
@media(max-width:800px){.sup-card{grid-template-columns:42px minmax(0,1fr) auto}.sup-stock{grid-column:2;text-align:start;display:flex;gap:7px;align-items:center}.sup-actions{grid-column:3;grid-row:1/3;flex-direction:column}.sup-avatar{width:42px;height:42px}}
@media(max-width:520px){.sup-card{grid-template-columns:40px minmax(0,1fr)}.sup-actions{grid-column:1/-1;grid-row:auto;flex-direction:row}.sup-actions .btn-primary{flex:1}.sup-contact{display:grid;gap:4px}.sup-stock{grid-column:2}}
</style>
