<script setup>
/**
 * مكون جديد — the catalogue form plus the branch/location "home" block.
 * All the behaviour lives in IngredientForm.vue, shared with the edit
 * screen exactly the way `_form.blade.php` was shared by both Blades.
 */
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import IngredientForm from '../../../Components/Ingredients/IngredientForm.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    units: { type: Array, required: true },
    suppliers: { type: Array, required: true },
    supplierIdsByBranch: { type: Object, default: () => ({}) },
    branches: { type: Array, default: () => [] },
    locationsByBranch: { type: Object, default: () => ({}) },
    activeBranchId: { type: [Number, String], default: null },
    canCreateBranch: { type: Boolean, default: false },
    today: { type: String, default: '' },
    currency: { type: Object, required: true },
    urls: { type: Object, required: true },
});
</script>

<template>
    <Head title="مكون جديد" />

    <PageHeader title="مكون جديد" icon="bi-plus-circle-fill"
                :crumbs="[{ label: 'المكونات', url: urls.index }]" />

    <DataPanel title="مكون جديد" icon="bi-plus-circle-fill">
        <template #actions>
            <a :href="urls.index" class="btn btn-light"><i class="bi bi-arrow-right"></i> رجوع للقائمة</a>
        </template>

        <IngredientForm mode="create"
                        :units="units"
                        :suppliers="suppliers"
                        :supplier-ids-by-branch="supplierIdsByBranch"
                        :branches="branches"
                        :locations-by-branch="locationsByBranch"
                        :active-branch-id="activeBranchId"
                        :can-create-branch="canCreateBranch"
                        :today="today"
                        :submit-url="urls.store"
                        :urls="urls" />
    </DataPanel>
</template>
