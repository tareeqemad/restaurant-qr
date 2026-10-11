<script setup>
import MenuWorkspaceNav from './MenuWorkspaceNav.vue';
import PageHeader from '../Ui/PageHeader.vue';
import DataPanel from '../Ui/DataPanel.vue';
import StatRail from '../Ui/StatRail.vue';

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
        <PageHeader :title="title" :icon="icon" :subtitle="subtitle">
            <template v-if="$slots.actions" #actions><slot name="actions" /></template>
        </PageHeader>

        <MenuWorkspaceNav :links="navigation" />

        <StatRail v-if="stats.length" :stats="stats.map(item => ({ ...item, color: item.tone || 'primary' }))" />

        <div v-if="$slots.beforePanel" class="cw-before"><slot name="beforePanel" /></div>

        <DataPanel :title="panelTitle" :count="count" :icon="panelIcon">
            <template v-if="$slots.panelActions" #actions><slot name="panelActions" /></template>
            <template v-if="$slots.filters" #filters><slot name="filters" /></template>
            <p v-if="panelSubtitle" class="cw-panel-description">{{ panelSubtitle }}</p>
            <slot />
            <template v-if="$slots.footer" #footer><slot name="footer" /></template>
        </DataPanel>

        <div v-if="$slots.afterPanel" class="cw-after"><slot name="afterPanel" /></div>
    </main>
</template>

<style scoped>
.catalog-workspace { --catalog-primary: var(--brand, #A3422B); --catalog-primary-deep: var(--ink, #25343A); --catalog-primary-soft: var(--brand-soft, #F3E3D8); --catalog-line: var(--line, #DFD7CB); --catalog-muted: var(--muted, #626B6D); min-width: 0; color: var(--ink); }
.cw-before, .cw-after { margin-block: 18px; }
.cw-panel-description { max-width: 75ch; margin: 0 0 18px; color: var(--muted); font-size: .875rem; line-height: 1.7; }
.catalog-workspace :deep(.data-panel) { border-radius: 12px; }
.catalog-workspace :deep(.btn) { min-height: 44px; border-radius: 8px; font-size: .9375rem; }
.catalog-workspace :deep(.form-control), .catalog-workspace :deep(.form-select) { min-height: 46px; border-radius: 8px; }
</style>
