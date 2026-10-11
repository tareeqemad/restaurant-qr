<script setup>
defineProps({
    area: { type: String, required: true },
    mode: { type: String, default: 'records' },
})
</script>

<template>
    <div class="finance-workspace" :class="`finance-workspace--${mode}`">
        <header class="finance-workspace__heading">
            <span class="finance-workspace__area">{{ area }}</span>
            <slot name="heading" />
        </header>
        <div v-if="$slots.navigation" class="finance-workspace__navigation"><slot name="navigation" /></div>
        <section v-if="$slots.filters" class="finance-workspace__filters" aria-label="تصفية النتائج"><slot name="filters" /></section>
        <section v-if="$slots.metrics" class="finance-workspace__metrics" aria-label="ملخص الأرقام"><slot name="metrics" /></section>
        <div class="finance-workspace__content"><slot /></div>
    </div>
</template>

<style scoped>
.finance-workspace { --finance-ink:#25343A; --finance-muted:#626B6D; --finance-line:#DFD7CB; display:grid; gap:24px; min-width:0; color:var(--finance-ink); }
.finance-workspace__heading { padding-block:2px 18px; border-bottom:1px solid var(--finance-line); }
.finance-workspace__area { display:block; margin-bottom:8px; color:#A3422B; font-size:13px; font-weight:800; letter-spacing:.02em; }
.finance-workspace__heading :deep(.page-header) { margin:0!important; padding:0; border:0; background:none; box-shadow:none; }
.finance-workspace__heading :deep(.page-title) { font-size:clamp(24px,2.2vw,34px); line-height:1.35; }
.finance-workspace__navigation { min-width:0; }
.finance-workspace__navigation :deep(nav) { margin:0; }
.finance-workspace__filters { min-width:0; padding:18px 20px; border:1px solid var(--finance-line); border-radius:16px; background:#FFFDFA; }
.finance-workspace__filters :deep(>*) { margin:0!important; padding:0!important; border:0!important; background:transparent!important; box-shadow:none!important; }
.finance-workspace__filters :deep(form:not(.row)), .finance-workspace__filters :deep(>form) { flex-wrap:wrap; gap:14px; }
.finance-workspace__filters :deep(label) { min-width:0; }
.finance-workspace__filters :deep(.row) { --bs-gutter-x:16px; --bs-gutter-y:12px; }
.finance-workspace__metrics { min-width:0; }
.finance-workspace__metrics :deep(>*) { margin-bottom:0!important; }
.finance-workspace__content { display:grid; gap:24px; min-width:0; align-content:start; }
.finance-workspace__content :deep(>*) { min-width:0; margin-bottom:0; }
.finance-workspace :deep(input:not([type=checkbox]):not([type=radio]):not([type=hidden])), .finance-workspace :deep(select), .finance-workspace :deep(textarea) { min-height:44px; border-radius:9px; font-size:14px; }
.finance-workspace :deep(label>span), .finance-workspace :deep(.form-label) { color:#25343A; font-size:14px; font-weight:700; }
.finance-workspace :deep(table) { width:100%; font-size:14px; font-variant-numeric:tabular-nums; }
.finance-workspace :deep(th) { padding:14px 16px; background:#F7F3ED; color:#626B6D; font-size:13px; font-weight:800; white-space:nowrap; }
.finance-workspace :deep(td) { padding:16px; vertical-align:middle; border-color:#EAE3D9; }
.finance-workspace :deep(tbody tr:hover) { background:#F7F3ED; }
.finance-workspace :deep(tfoot) { border-top:2px solid #DFD7CB; background:#F7F3ED; font-weight:800; }
.finance-workspace :deep(bdi), .finance-workspace :deep(.amount) { font-variant-numeric:tabular-nums; }
.finance-workspace :deep(.table-responsive) { max-width:100%; border-radius:10px; }
.finance-workspace :deep(:is(.health-grid,.asset-stats,.metrics,.kpis,.metric-grid,.tax-metrics,.cu-stats,.ex-stats,.settlement-summary,.aging-summary)) { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:1px; overflow:hidden; border:1px solid #DFD7CB; border-radius:16px; background:#DFD7CB; }
.finance-workspace :deep(:is(.health-grid,.asset-stats,.metrics,.kpis,.metric-grid,.tax-metrics,.cu-stats,.ex-stats,.settlement-summary,.aging-summary)>article) { min-width:0; min-height:112px; margin:0; padding:20px; border:0; border-radius:0; background:#FFFDFA; box-shadow:none; }
.finance-workspace :deep(:is(.health-grid,.asset-stats,.metrics,.kpis,.metric-grid,.tax-metrics,.cu-stats,.ex-stats,.settlement-summary,.aging-summary) strong) { font-size:clamp(20px,1.65vw,28px); line-height:1.45; font-weight:800; }
.finance-workspace :deep(:is(.health-grid,.asset-stats,.metrics,.kpis,.metric-grid,.tax-metrics,.cu-stats,.ex-stats,.settlement-summary,.aging-summary) small) { font-size:13px; }
.finance-workspace :deep(:is(.acc-home-grid,.profit-layout,.asset-layout,.chart-layout,.recon-layout,.adjust-layout,.period-layout,.year-layout,.debt-detail-grid,.cp-layout,.form-layout)) { gap:24px; align-items:start; }
.finance-workspace :deep(:is(.create-form,.entry-form,.adjust-form,.account-page-form,.tax-form)) { gap:18px; }
.finance-workspace :deep(:is(.entry-head,.details,.manual-note,.branch-warning,.reversed)) { padding:18px 20px; }
.finance-workspace :deep(:is(.entry-form,.adjust-form,.account-page-form)>footer) { position:sticky; bottom:14px; z-index:3; gap:12px; padding:14px 18px; border:1px solid #DFD7CB; border-radius:12px; background:#FFFDFA; box-shadow:0 8px 28px #25343A12; }
.finance-workspace :deep(details:not(.closed-panel):not(.history)) { border-radius:12px; }
.finance-workspace :deep(summary) { min-height:40px; padding-block:8px; color:#626B6D; font-size:14px; cursor:pointer; }
.finance-workspace :deep(summary:focus-visible), .finance-workspace :deep(a:focus-visible), .finance-workspace :deep(button:focus-visible) { outline:3px solid #B7833866; outline-offset:3px; }
.finance-workspace :deep(:is(.cu-lenses,.ex-lenses,.refund-lenses,.mode-tabs,.mapping-tabs)) { flex-wrap:wrap; gap:8px; padding:0; border:0; background:transparent; }
.finance-workspace :deep(:is(.cu-lenses,.ex-lenses,.refund-lenses,.mode-tabs,.mapping-tabs)>button) { min-height:42px; padding:10px 16px; border:1px solid #DFD7CB; border-radius:9px; font-size:14px; background:#FFFDFA; color:#626B6D; }
.finance-workspace :deep(:is(.cu-lenses,.ex-lenses,.refund-lenses,.mode-tabs,.mapping-tabs)>button.active) { background:#25343A; color:#FFFDFA; border-color:#25343A; }
.finance-workspace--report .finance-workspace__content :deep(>.row) { display:grid; grid-template-columns:minmax(0,1.6fr) minmax(280px,1fr); gap:24px; margin:0; }
.finance-workspace--report .finance-workspace__content :deep(>.row>*) { width:auto; padding:0; margin:0; min-width:0; }
.finance-workspace--form { max-width:1320px; }
@media(min-width:1100px) {
    .finance-workspace :deep(.year-layout), .finance-workspace :deep(.period-layout) { grid-template-columns:minmax(280px,.7fr) minmax(0,1.7fr); }
    .finance-workspace :deep(.chart-layout) { grid-template-columns:minmax(0,1.65fr) minmax(290px,.7fr); }
}
@media(max-width:1050px) {
    .finance-workspace :deep(:is(.acc-home-grid,.profit-layout,.asset-layout,.chart-layout,.recon-layout,.adjust-layout,.period-layout,.year-layout,.debt-detail-grid,.cp-layout,.form-layout)) { grid-template-columns:minmax(0,1fr); }
    .finance-workspace--report .finance-workspace__content :deep(>.row) { grid-template-columns:minmax(0,1fr); }
}
@media(max-width:620px) {
    .finance-workspace,.finance-workspace__content { gap:18px; }
    .finance-workspace__filters { padding:14px; }
    .finance-workspace__heading { padding-bottom:14px; }
    .finance-workspace :deep(:is(.health-grid,.asset-stats,.metrics,.kpis,.metric-grid,.tax-metrics,.cu-stats,.ex-stats,.settlement-summary,.aging-summary)) { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .finance-workspace :deep(:is(.health-grid,.asset-stats,.metrics,.kpis,.metric-grid,.tax-metrics,.cu-stats,.ex-stats,.settlement-summary,.aging-summary)>article) { min-height:108px; padding:14px; }
    .finance-workspace :deep(:is(.entry-form,.adjust-form,.account-page-form)>footer) { bottom:8px; }
}
@media print {
    .finance-workspace { display:block; }
    .finance-workspace__navigation,.finance-workspace__filters,.finance-workspace__area { display:none; }
    .finance-workspace__content { gap:12px; }
}
</style>
