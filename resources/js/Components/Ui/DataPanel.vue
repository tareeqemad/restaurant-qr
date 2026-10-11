<script setup>
defineProps({ title: { type: String, default: null }, count: { type: [Number, String], default: null }, icon: { type: String, default: null }, compact: { type: Boolean, default: false } });
</script>

<template>
    <section class="card custom-card data-panel sarw-data-panel">
        <header v-if="title || $slots.actions" class="card-header data-panel-header">
            <h2 v-if="title" class="data-panel-title"><i v-if="icon" class="bi" :class="icon" aria-hidden="true"></i>
                <span>{{ title }}</span><span v-if="count !== null" class="sarw-count">{{ count }}</span>
            </h2>
            <div v-if="$slots.actions" class="data-panel-actions"><slot name="actions" /></div>
        </header>
        <div v-if="$slots.filters" class="data-panel-filters"><slot name="filters" /></div>
        <div class="card-body data-panel-body" :class="{ 'is-compact': compact }"><slot /></div>
        <footer v-if="$slots.footer" class="card-footer"><slot name="footer" /></footer>
    </section>
</template>
