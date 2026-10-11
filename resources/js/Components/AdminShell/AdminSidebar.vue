<script setup>
import { computed, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import NavItem from './NavItem.vue';
const props = defineProps({ nav: { type: Array, required: true }, brand: { type: Object, required: true }, dashboardUrl: { type: String, required: true }, open: { type: Boolean, default: false } });
const emit = defineEmits(['close']);
const query = ref('');
const page = usePage();
const normalized = (value) => String(value || '').normalize('NFKD').replace(/[\u064b-\u065f\u0670]/g, '').trim().toLocaleLowerCase();
const filterNodes = (nodes, term) => nodes.flatMap((item) => {
    if (item.section) return [];
    if (normalized(item.label).includes(term)) return [item];
    const children = filterNodes(item.children || [], term);
    return children.length ? [{ ...item, children }] : [];
});
const filteredNav = computed(() => normalized(query.value) ? filterNodes(props.nav, normalized(query.value)) : props.nav);
const clearSearchOnEscape = (event) => {
    if (!query.value) return;
    event.stopPropagation();
    query.value = '';
};
watch(() => page.url, () => { query.value = ''; });
</script>

<template>
    <aside id="sidebar" class="sarw-sidebar" :class="{ 'is-mobile-open': open }"
           :role="open ? 'dialog' : undefined" :aria-modal="open ? 'true' : undefined" aria-label="قائمة الإدارة">
        <div class="sarw-sidebar-brand">
            <Link :href="dashboardUrl" class="sarw-brand" @click="emit('close')">
                <img :src="brand.logo" alt="" width="48" height="48"><span><strong>{{ brand.name }}</strong><small>مساحة إدارة المطعم</small></span>
            </Link>
            <button type="button" class="mobile-drawer__close sarw-icon-button" aria-label="إغلاق القائمة" @click="emit('close')"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <label class="sarw-nav-search"><i class="bi bi-search" aria-hidden="true"></i>
            <input v-model="query" type="search" placeholder="انتقل إلى…" aria-label="البحث في صفحات الإدارة" @keydown.esc="clearSearchOnEscape">
        </label>
        <div class="sarw-sidebar-caption">مساحات العمل</div>
        <nav class="sarw-sidebar-scroll" aria-label="التنقل الرئيسي">
            <ul class="sarw-nav"><NavItem v-for="(item, i) in filteredNav" :key="item.href || item.label || i" :item="item" :expanded="Boolean(query.trim())" @navigate="emit('close')" /></ul>
            <p v-if="!filteredNav.length" class="sarw-nav-empty" role="status">لا توجد صفحة بهذا الاسم</p>
        </nav>
        <div class="sarw-sidebar-foot"><span class="sarw-brand-line"></span><span>{{ brand.name }}</span></div>
    </aside>
</template>
