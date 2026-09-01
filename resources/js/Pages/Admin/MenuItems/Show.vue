<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import MenuItemCard from '../../../Components/MenuAdmin/MenuItemCard.vue';
import MenuWorkspaceNav from '../../../Components/MenuAdmin/MenuWorkspaceNav.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';

defineOptions({ layout: AdminLayout });

defineProps({
    navigation: { type: Array, default: () => [] },
    item: { type: Object, required: true },
    sales: { type: Object, required: true },
    priceHistory: { type: Array, default: () => [] },
    promotions: { type: Array, default: () => [] },
    can: { type: Object, required: true },
    urls: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`كرت ${item.name}`" />

    <PageHeader :title="`كرت الصنف — ${item.name}`" icon="bi-card-checklist"
                subtitle="السعر الأساسي والعروض والتكلفة والمبيعات في سجل واحد">
        <template #actions>
            <Link :href="urls.index" class="btn btn-light" preserve-scroll>
                <i class="bi bi-arrow-right"></i> الأصناف
            </Link>
        </template>
    </PageHeader>

    <MenuWorkspaceNav :links="navigation" />

    <MenuItemCard :item="item" :sales="sales" :price-history="priceHistory"
                  :promotions="promotions" :can="can" :urls="urls" />
</template>
