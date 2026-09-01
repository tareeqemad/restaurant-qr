<script setup>
/**
 * Card wrapper — the Vue twin of <x-admin.data-panel>: Dashtic's native
 * card structure (header + optional filters body + body + footer), so
 * every migrated index page keeps the exact look of its Blade sibling.
 */
defineProps({
    title: { type: String, default: null },
    count: { type: [Number, String], default: null },
    icon: { type: String, default: null },
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div class="card custom-card data-panel">
        <div v-if="title || $slots.actions" class="card-header data-panel-header">
            <h3 v-if="title" class="card-title data-panel-title mb-0 d-inline-flex align-items-center gap-2">
                <i v-if="icon" class="bi text-accent" :class="icon"></i>
                <span>{{ title }}</span>
                <span v-if="count !== null" class="badge bg-primary-transparent ms-1">{{ count }}</span>
            </h3>
            <div v-if="$slots.actions" class="card-options data-panel-actions">
                <slot name="actions" />
            </div>
        </div>

        <div v-if="$slots.filters" class="card-body data-panel-filters border-block-end bg-light p-3">
            <slot name="filters" />
        </div>

        <div class="card-body" :class="{ 'p-3': compact }">
            <slot />
        </div>

        <div v-if="$slots.footer" class="card-footer">
            <slot name="footer" />
        </div>
    </div>
</template>
