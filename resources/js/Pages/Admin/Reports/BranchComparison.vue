<script setup>
/**
 * مقارنة الفروع — owner-only KPI matrix: 12 KPIs down, branches across,
 * best branch green, worst red.
 *
 * `rows` and `branches` are joined ONLY by integer position, and
 * best_idx/worst_idx are indices into `rows`. So the whole matrix arrives
 * pre-rendered as `cells[kpiIndex][branchIndex]` — text, isBest, isWorst.
 * Nothing here sorts, filters or re-keys anything: a single client-side
 * re-order would land every trophy on the wrong branch.
 *
 * The CSV button is a native <a href> on purpose — the endpoint streams a
 * text/csv response, and an Inertia visit would try to parse it as a page.
 */
import { reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import DataPanel from '../../../Components/Ui/DataPanel.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    from: { type: String, required: true },
    to: { type: String, required: true },
    branches: { type: Array, default: () => [] },
    rows: { type: Array, default: () => [] },
    rowCount: { type: Number, default: 0 },
    kpiDefs: { type: Array, default: () => [] },
    cells: { type: Array, default: () => [] },
    canExport: { type: Boolean, default: false },
    exportUrl: { type: String, required: true },
    notesText: { type: Array, default: () => [] },
    currency: { type: Object, required: true },
    urls: { type: Object, required: true },
});

const form = reactive({ from: props.from, to: props.to });

// Explicit submit only — no auto-submit on change, same as the Blade.
const submit = () => router.get(props.urls.self, { from: form.from, to: form.to }, {
    preserveState: true,
    preserveScroll: true,
});
</script>

<template>
    <Head title="مقارنة الفروع" />

    <PageHeader title="مقارنة الفروع" icon="bi-arrows-collapse"
                subtitle="نظرة جنباً إلى جنب على KPIs لكل فرع — الأخضر هو الأفضل، الأحمر يحتاج انتباه."
                :crumbs="[{ label: 'التقارير', url: urls.reportsIndex }]" />

    <DataPanel title="مصفوفة الـ KPIs" icon="bi-grid-3x3-gap-fill" :count="rowCount">
        <template #actions>
            <!-- Streamed CSV: native navigation, never router.visit(). -->
            <a v-if="rowCount > 0 && canExport" :href="exportUrl" class="btn btn-sm btn-success">
                <i class="bi bi-file-earmark-spreadsheet me-1"></i> تنزيل CSV
            </a>
        </template>

        <template #filters>
            <form class="row g-2 align-items-end" @submit.prevent="submit">
                <div class="col-md-3">
                    <label class="form-label fs-12 mb-1">من</label>
                    <input v-model="form.from" type="date" name="from" class="form-control form-control-sm">
                </div>
                <div class="col-md-3">
                    <label class="form-label fs-12 mb-1">إلى</label>
                    <input v-model="form.to" type="date" name="to" class="form-control form-control-sm">
                </div>
                <div class="col-md-6 text-md-end">
                    <span class="badge bg-success-transparent text-success">أخضر = الأفضل</span>
                    <span class="badge bg-danger-transparent text-danger">أحمر = الأسوأ</span>
                </div>
                <div class="col-12 text-center mt-2">
                    <button type="submit" class="btn btn-primary btn-sm px-5"><i class="bi bi-search"></i> استعلام</button>
                </div>
            </form>
        </template>

        <EmptyState v-if="rowCount === 0"
                    icon="bi-building"
                    title="لا فروع نشطة"
                    message="فعّل فروعاً من شاشة إدارة الفروع لرؤية المقارنة." />

        <template v-else>
            <div class="table-responsive">
                <table class="table table-sm align-middle vendor-compare-table mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="position-sticky start-0 bg-light bc-kpi-col">الـ KPI</th>
                            <th v-for="branch in branches" :key="branch.id" class="text-end bc-branch-col">
                                <div class="fw-bold">{{ branch.name }}</div>
                                <small v-if="branch.code" class="text-muted">{{ branch.code }}</small>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(kpi, kpiIdx) in kpiDefs" :key="kpi.key">
                            <td class="position-sticky start-0 bg-white fw-semibold bc-kpi-cell">
                                {{ kpi.label }}
                                <small v-if="kpi.directionHint" class="text-muted d-block fs-11">{{ kpi.directionHint }}</small>
                            </td>
                            <td v-for="(cell, branchIdx) in cells[kpiIdx]" :key="branchIdx"
                                class="text-end"
                                :class="cell.isBest ? 'bg-success-transparent text-success fw-bold'
                                        : (cell.isWorst ? 'bg-danger-transparent text-danger fw-bold' : '')">
                                {{ cell.text }}
                                <i v-if="cell.isBest" class="bi bi-trophy-fill text-success ms-1" title="الأفضل"></i>
                                <i v-else-if="cell.isWorst" class="bi bi-exclamation-triangle-fill text-danger ms-1" title="الأسوأ"></i>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-3 fs-12 text-muted">
                <i class="bi bi-info-circle"></i>
                <strong>ملاحظات:</strong>
                <!-- One element per note: Vue's default whitespace: 'condense'
                     deletes whitespace-only text nodes between <template> items,
                     which ran all three sentences into one string. -->
                <span v-for="(note, i) in notesText" :key="i" class="note">{{ note }}</span>
            </div>
        </template>
    </DataPanel>
</template>

<style scoped>
.note { display: inline-block; margin-inline-end: .75rem; }

.vendor-compare-table th,
.vendor-compare-table td { white-space: nowrap; }
.bc-kpi-col { z-index: 2; min-width: 220px; }
.bc-kpi-cell { z-index: 1; }
.bc-branch-col { min-width: 160px; }
.form-control-sm { min-height: 38px; }
</style>
