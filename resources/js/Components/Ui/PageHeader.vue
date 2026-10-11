<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
defineProps({ title: { type: String, required: true }, subtitle: { type: String, default: null }, icon: { type: String, default: null }, crumbs: { type: Array, default: () => [] }, home: { type: Boolean, default: true } });
const page = usePage();
const homeUrl = computed(() => page.props.shell?.urls?.dashboard ?? '/admin');
</script>

<template>
    <header class="page-header sarw-page-heading">
        <div class="sarw-page-heading__copy">
            <nav v-if="home || crumbs.length" aria-label="مسار الصفحة" class="sarw-breadcrumbs">
                <Link v-if="home" :href="homeUrl">الرئيسية</Link>
                <template v-for="(crumb, i) in crumbs" :key="i"><i class="bi bi-chevron-left" aria-hidden="true"></i>
                    <Link v-if="crumb.url" :href="crumb.url">{{ crumb.label }}</Link><span v-else>{{ crumb.label }}</span>
                </template>
                <i class="bi bi-chevron-left" aria-hidden="true"></i><span aria-current="page">{{ title }}</span>
            </nav>
            <div class="sarw-page-heading__title">
                <span v-if="icon" class="sarw-page-heading__icon" aria-hidden="true"><i class="bi" :class="icon"></i></span>
                <div><h1 class="page-title">{{ title }}</h1><p v-if="subtitle">{{ subtitle }}</p></div>
            </div>
        </div>
        <div v-if="$slots.actions" class="sarw-page-heading__actions"><slot name="actions" /></div>
    </header>
</template>
