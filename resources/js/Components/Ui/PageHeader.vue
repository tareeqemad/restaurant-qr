<script setup>
/**
 * Page title + breadcrumb — the Vue twin of <x-admin.breadcrumb>.
 * Same Dashtic .page-header markup, so relax-components.css styles apply.
 *
 *   <PageHeader title="سجل النشاطات" icon="bi-clock-history" subtitle="…"
 *               :crumbs="[{ label: 'الإدارة', url: '…' }]">
 *     <template #actions>…</template>
 *   </PageHeader>
 */
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    icon: { type: String, default: null },
    crumbs: { type: Array, default: () => [] },
    home: { type: Boolean, default: true },
});

const homeUrl = computed(() => usePage().props.shell?.urls?.dashboard ?? '/admin/dashboard');
</script>

<template>
    <div class="page-header">
        <div class="relax-page-main">
            <span v-if="icon" class="relax-page-icon" aria-hidden="true">
                <i class="bi" :class="icon"></i>
            </span>
            <div class="relax-page-copy">
                <h1 class="page-title my-auto">{{ title }}</h1>
                <p v-if="subtitle" class="relax-page-subtitle">{{ subtitle }}</p>
            </div>
        </div>

        <div class="relax-page-side">
            <div class="page-header-bredcrumb d-flex align-items-center gap-3">
                <ol class="breadcrumb mb-0">
                    <li v-if="home" class="breadcrumb-item">
                        <a :href="homeUrl">
                            <svg class="svg-icon" xmlns="http://www.w3.org/2000/svg" height="24" viewBox="0 0 24 24" width="24"><path d="M0 0h24v24H0V0z" fill="none"></path><path d="M12 3L2 12h3v8h6v-6h2v6h6v-8h3L12 3zm5 15h-2v-6H9v6H7v-7.81l5-4.5 5 4.5V18z"></path><path d="M7 10.19V18h2v-6h6v6h2v-7.81l-5-4.5z" opacity=".3"></path></svg>
                            <span class="breadcrumb-icon"> الرئيسية</span>
                        </a>
                    </li>
                    <li v-for="(crumb, i) in crumbs" :key="i" class="breadcrumb-item">
                        <a v-if="crumb.url" :href="crumb.url">{{ crumb.label }}</a>
                        <template v-else>{{ crumb.label }}</template>
                    </li>
                    <li class="breadcrumb-item active" aria-current="page">{{ title }}</li>
                </ol>
            </div>

            <div v-if="$slots.actions" class="relax-page-actions">
                <slot name="actions" />
            </div>
        </div>
    </div>
</template>
