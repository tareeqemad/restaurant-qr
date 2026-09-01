<script setup>
import MenuWorkspaceNav from './MenuWorkspaceNav.vue';

defineProps({
    navigation: { type: Array, default: () => [] },
    title: { type: String, required: true },
    subtitle: { type: String, default: '' },
    icon: { type: String, default: 'bi-journal-richtext' },
    stats: { type: Array, default: () => [] },
    panelTitle: { type: String, required: true },
    panelSubtitle: { type: String, default: '' },
    panelIcon: { type: String, default: 'bi-collection' },
    count: { type: [Number, String], default: null },
});
</script>

<template>
    <main class="catalog-workspace">
        <header class="cw-hero">
            <div class="cw-hero-copy">
                <span class="cw-eyebrow"><i class="bi bi-journal-richtext"></i> إدارة المنيو</span>
                <h1><i class="bi" :class="icon"></i>{{ title }}</h1>
                <p v-if="subtitle">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.actions" class="cw-actions"><slot name="actions" /></div>
        </header>

        <MenuWorkspaceNav :links="navigation" />

        <section v-if="stats.length" class="cw-stats" aria-label="ملخص الصفحة">
            <article v-for="item in stats" :key="item.label" :class="`is-${item.tone || 'primary'}`">
                <span class="cw-stat-icon"><i class="bi" :class="item.icon"></i></span>
                <div><small>{{ item.label }}</small><strong>{{ item.value }}</strong></div>
            </article>
        </section>

        <div v-if="$slots.beforePanel" class="cw-before"><slot name="beforePanel" /></div>

        <section class="cw-panel">
            <header class="cw-panel-head">
                <div class="cw-panel-title">
                    <span><i class="bi" :class="panelIcon"></i></span>
                    <div>
                        <h2>{{ panelTitle }} <b v-if="count !== null">{{ count }}</b></h2>
                        <p v-if="panelSubtitle">{{ panelSubtitle }}</p>
                    </div>
                </div>
                <div v-if="$slots.panelActions" class="cw-panel-actions"><slot name="panelActions" /></div>
            </header>

            <div v-if="$slots.filters" class="cw-filters"><slot name="filters" /></div>
            <div class="cw-panel-body"><slot /></div>
            <footer v-if="$slots.footer" class="cw-panel-footer"><slot name="footer" /></footer>
        </section>

        <div v-if="$slots.afterPanel" class="cw-after"><slot name="afterPanel" /></div>
    </main>
</template>

<style scoped>
.catalog-workspace {
    --catalog-primary: rgb(var(--primary-rgb, 31, 107, 80));
    --catalog-primary-deep: #123f31;
    --catalog-primary-soft: rgba(var(--primary-rgb, 31, 107, 80), .08);
    --catalog-line: #dfe8e3;
    --catalog-muted: #6d7f76;
    color: var(--catalog-primary-deep);
}
.cw-hero {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    min-height: 108px;
    margin-bottom: .8rem;
    padding: 1rem 1.15rem;
    border: 1px solid rgba(var(--primary-rgb, 31, 107, 80), .14);
    border-radius: 18px;
    background:
        radial-gradient(circle at 12% 20%, rgba(var(--primary-rgb, 31, 107, 80), .09), transparent 28%),
        linear-gradient(135deg, #fff, #f7faf8);
    box-shadow: 0 10px 30px rgba(18, 63, 49, .045);
}
.cw-hero-copy { min-width: 0; }
.cw-eyebrow { display: inline-flex; align-items: center; gap: .35rem; color: var(--catalog-primary); font-size: .7rem; font-weight: 900; }
.cw-hero h1 { display: flex; align-items: center; gap: .5rem; margin: .2rem 0 .12rem; color: var(--catalog-primary-deep); font-size: clamp(1.35rem, 2.3vw, 1.78rem); font-weight: 950; }
.cw-hero h1 > i { display: inline-grid; place-items: center; width: 37px; height: 37px; border-radius: 11px; color: var(--catalog-primary); background: var(--catalog-primary-soft); font-size: 1rem; }
.cw-hero p { margin: 0; color: var(--catalog-muted); font-size: .8rem; }
.cw-actions { display: flex; gap: .5rem; flex: 0 0 auto; }
.cw-actions :deep(.btn),
.cw-panel-actions :deep(.btn) { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; min-height: 44px; border-radius: 11px; font-size: .78rem; font-weight: 900; }
.cw-actions :deep(.btn-primary),
.cw-panel-actions :deep(.btn-primary) { border-color: var(--catalog-primary); background: var(--catalog-primary); box-shadow: 0 7px 16px rgba(var(--primary-rgb, 31, 107, 80), .16); }
.cw-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)); gap: .55rem; margin-bottom: .85rem; }
.cw-stats article { display: flex; align-items: center; gap: .62rem; min-height: 70px; padding: .65rem .75rem; border: 1px solid var(--catalog-line); border-radius: 14px; background: #fff; box-shadow: 0 6px 18px rgba(18, 63, 49, .035); }
.cw-stat-icon { display: inline-grid; place-items: center; width: 38px; height: 38px; flex: 0 0 38px; border-radius: 11px; color: var(--catalog-primary); background: var(--catalog-primary-soft); }
.cw-stats article > div { display: flex; flex-direction: column; min-width: 0; }
.cw-stats small { overflow: hidden; color: #78887f; font-size: .64rem; text-overflow: ellipsis; white-space: nowrap; }
.cw-stats strong { color: var(--catalog-primary-deep); font-size: 1rem; font-weight: 950; }
.cw-stats .is-warning .cw-stat-icon { color: #986708; background: #fff7df; }
.cw-stats .is-danger .cw-stat-icon { color: #a32b39; background: #fff0f2; }
.cw-stats .is-info .cw-stat-icon { color: #266a84; background: #eaf6fa; }
.cw-stats .is-muted .cw-stat-icon { color: #6b7971; background: #eef2f0; }
.cw-before,
.cw-after { margin-block: .75rem; }
.cw-panel { overflow: hidden; border: 1px solid var(--catalog-line); border-radius: 18px; background: #fff; box-shadow: 0 9px 28px rgba(18, 63, 49, .045); }
.cw-panel-head { display: flex; align-items: center; justify-content: space-between; gap: 1rem; min-height: 72px; padding: .8rem .95rem; border-bottom: 1px solid #edf2ef; }
.cw-panel-title { display: flex; align-items: center; gap: .58rem; min-width: 0; }
.cw-panel-title > span { display: inline-grid; place-items: center; width: 40px; height: 40px; flex: 0 0 40px; border-radius: 11px; color: var(--catalog-primary); background: var(--catalog-primary-soft); }
.cw-panel-title h2 { display: flex; align-items: center; gap: .42rem; margin: 0; color: var(--catalog-primary-deep); font-size: 1rem; font-weight: 950; }
.cw-panel-title h2 b { min-width: 26px; padding: .08rem .36rem; border-radius: 999px; color: var(--catalog-primary); background: var(--catalog-primary-soft); font-size: .68rem; text-align: center; }
.cw-panel-title p { margin: .12rem 0 0; color: var(--catalog-muted); font-size: .67rem; }
.cw-panel-actions { display: flex; gap: .4rem; }
.cw-filters { padding: .7rem .95rem; border-bottom: 1px solid #edf2ef; background: #fafcfb; }
.cw-panel-body { padding: .85rem; }
.cw-panel-footer { padding: .7rem .85rem; border-top: 1px solid #edf2ef; background: #fafcfb; }

@media (max-width: 767.98px) {
    .cw-stats { grid-template-columns: repeat(2, 1fr); }
}
@media (max-width: 575.98px) {
    .cw-hero { align-items: flex-start; flex-direction: column; min-height: auto; padding: .88rem; border-radius: 16px; }
    .cw-actions { width: 100%; }
    .cw-actions :deep(.btn) { flex: 1; }
    .cw-stats { gap: .42rem; }
    .cw-stats article { min-height: 62px; padding: .52rem .58rem; }
    .cw-stat-icon { width: 34px; height: 34px; flex-basis: 34px; }
    .cw-panel { border-radius: 16px; }
    .cw-panel-head { align-items: flex-start; padding: .72rem .78rem; }
    .cw-panel-body { padding: .7rem; }
    .cw-filters { padding: .62rem .7rem; }
}
@media (prefers-reduced-motion: reduce) {
    .cw-actions :deep(.btn),
    .cw-panel-actions :deep(.btn) { transition: none; }
}
</style>
