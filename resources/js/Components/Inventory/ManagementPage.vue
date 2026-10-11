<script setup>
defineProps({ mode: { type: String, default: 'records' } });
</script>

<template>
    <div class="management-page" :class="`management-page--${mode}`"><slot /></div>
</template>

<style scoped>
.management-page { min-width: 0; width: 100%; color: var(--ink, #25343A); font-size: 1rem; line-height: 1.65; }
.management-page > :deep(*) { min-width: 0; }
.management-page :deep(.data-panel) { margin-bottom: 20px; overflow: visible; }
.management-page :deep(.data-panel-filters) { background: var(--canvas, #F7F3ED) !important; border-color: var(--line, #DFD7CB) !important; }
.management-page :deep(.data-panel-filters form) { align-items: end; }
.management-page :deep(.form-label) { margin-bottom: 7px; color: var(--ink, #25343A); font-size: .9375rem; font-weight: 700; }
.management-page :deep(.form-control), .management-page :deep(.form-select) { min-height: 46px; border: 1px solid var(--line, #DFD7CB); border-radius: 8px; background-color: var(--surface, #FFFDFA); color: var(--ink, #25343A); font-size: .9375rem; }
.management-page :deep(.form-control:focus), .management-page :deep(.form-select:focus) { border-color: var(--brand, #A3422B); box-shadow: 0 0 0 3px var(--brand-soft, #F3E3D8); }
.management-page :deep(.form-text), .management-page :deep(small), .management-page :deep(.small) { font-size: .875rem; line-height: 1.65; }
.management-page :deep(.fs-11), .management-page :deep(.fs-12), .management-page :deep(.fs-13) { font-size: .875rem !important; }
.management-page :deep(.btn) { min-height: 44px; border-radius: 8px; font-size: .9375rem; font-weight: 700; }
.management-page :deep(.btn-sm) { min-height: 40px; font-size: .875rem; }
.management-page :deep(button:not([role="switch"])) { min-height: 44px; }
.management-page :deep(.table-responsive) { max-width: 100%; margin-bottom: 0; border: 1px solid var(--line, #DFD7CB); border-radius: 10px; scrollbar-width: thin; }
.management-page :deep(table) { width: 100%; margin-bottom: 0; font-size: .9375rem; font-variant-numeric: tabular-nums; }
.management-page :deep(thead th) { padding: 13px 14px; background: var(--surface-muted, #EFE9E0); color: var(--ink, #25343A); border-bottom: 1px solid var(--line, #DFD7CB); font-size: .875rem; font-weight: 750; white-space: nowrap; }
.management-page :deep(tbody td) { padding: 14px; vertical-align: middle; border-bottom-color: var(--line, #DFD7CB); }
.management-page :deep(tbody tr:last-child td) { border-bottom: 0; }
.management-page :deep(tbody tr:not(.table-danger):not(.table-warning):hover td) { background-color: var(--canvas, #F7F3ED); }
.management-page :deep(.badge) { padding: 5px 8px; border-radius: 6px; font-size: .875rem; line-height: 1.4; }
.management-page :deep(.management-help) { margin-block: 12px 18px; border: 1px solid var(--line, #DFD7CB); border-radius: 10px; background: var(--surface, #FFFDFA); }
.management-page :deep(.management-help > summary) { min-height: 48px; padding: 12px 16px; color: var(--ink, #25343A); font-size: .9375rem; font-weight: 700; cursor: pointer; }
.management-page :deep(.management-help[open] > summary) { border-bottom: 1px solid var(--line, #DFD7CB); }
.management-page :deep(.management-help__body) { padding: 16px; color: var(--muted, #626B6D); font-size: .875rem; }
.management-page--form { max-width: 1240px; margin-inline: auto; }
.management-page--form :deep(.card-body) { padding: 22px; }
.management-page--form :deep(.row.g-3) { --bs-gutter-x: 1.5rem; --bs-gutter-y: 1.25rem; }
.management-page--detail :deep(.nav-tabs) { gap: 5px; margin-block: 16px; padding: 5px; border: 1px solid var(--line, #DFD7CB); border-radius: 10px; background: var(--surface, #FFFDFA); }
.management-page--detail :deep(.nav-link) { min-height: 44px; border-radius: 7px; font-size: .9375rem; }
@media (max-width: 767px) {
    .management-page :deep(.data-panel-filters), .management-page--form :deep(.card-body) { padding: 14px !important; }
    .management-page :deep(tbody td), .management-page :deep(thead th) { padding: 12px; }
    .management-page :deep(.data-panel-header) { gap: 12px; align-items: start; flex-wrap: wrap; }
    .management-page :deep(.data-panel-actions) { margin-inline-start: 0; flex-wrap: wrap; }
}
@media print {
    .management-page { width: auto; max-width: none; font-size: 12px; }
    .management-page :deep(.table-responsive) { overflow: visible; }
}
</style>
