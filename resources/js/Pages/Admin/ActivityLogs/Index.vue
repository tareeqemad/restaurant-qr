<script setup>
/**
 * سجل النشاطات — Wave 0's gate page: the first real screen on the new
 * AdminLayout chrome. Deliberately simple (filters + table + pagination)
 * so it proves the whole foundation without page-specific noise.
 */
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import Pagination from '../../../Components/Ui/Pagination.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    logs: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
});

const form = reactive({
    event: props.filters.event ?? '',
    date: props.filters.date ?? '',
});

const hasFilters = () => Boolean(props.filters.event || props.filters.date);

const search = () => {
    router.get(props.urls.index, {
        event: form.event || undefined,
        date: form.date || undefined,
    }, { preserveState: true, preserveScroll: true });
};

const clear = () => {
    form.event = '';
    form.date = '';
    search();
};
</script>

<template>
    <Head title="سجل النشاطات" />

    <PageHeader title="سجل النشاطات" icon="bi-clock-history"
                subtitle="كل أحداث النظام: من فعل ماذا ومتى" />

    <DataPanel title="سجل الأحداث" :count="logs.total" icon="bi-clock-history">
        <template #actions v-if="hasFilters()">
            <button type="button" class="btn btn-light" @click="clear">
                <i class="bi bi-x-circle"></i> مسح
            </button>
        </template>

        <template #filters>
            <form class="row g-2" @submit.prevent="search">
                <div class="col-md-6">
                    <input v-model="form.event" class="form-control"
                           placeholder="🔍 نوع الحدث (مثلاً: order.approved)">
                </div>
                <div class="col-md-3">
                    <input v-model="form.date" type="date" class="form-control">
                </div>
                <div class="col-12 text-center mt-2">
                    <button type="submit" class="btn btn-primary px-5">
                        <i class="bi bi-funnel"></i> استعلام
                    </button>
                </div>
            </form>
        </template>

        <div class="table-responsive">
            <table class="table table-sm align-middle">
                <thead class="bg-light">
                    <tr>
                        <th>الوقت</th>
                        <th>الحدث</th>
                        <th>الوصف</th>
                        <th>المستخدم</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="log in logs.data" :key="log.id">
                        <td><small>{{ log.time }}</small></td>
                        <td><code>{{ log.event }}</code></td>
                        <td>{{ log.description }}</td>
                        <td>{{ log.causer ?? '—' }}</td>
                        <td><small class="text-muted">{{ log.ip }}</small></td>
                    </tr>
                    <tr v-if="logs.data.length === 0">
                        <td colspan="5">
                            <EmptyState icon="bi-clock-history" title="ما في سجلات"
                                        message="لم تُسجل أي أحداث ضمن معايير البحث الحالية." />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template #footer>
            <Pagination :links="logs.links" />
        </template>
    </DataPanel>
</template>
