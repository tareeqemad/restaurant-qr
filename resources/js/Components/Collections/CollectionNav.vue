<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    items: { type: Array, default: () => [] },
    active: { type: String, required: true },
});
</script>

<template>
    <nav v-if="items.length > 1" class="collection-nav" aria-label="مساحات التحصيل">
        <Link v-for="item in items" :key="item.key" :href="item.url"
              :aria-current="item.key === active ? 'page' : undefined" :title="item.hint" class="collection-nav__item" :class="{ active: item.key === active }">
            <span class="collection-nav__icon"><i class="bi" :class="item.icon"></i></span>
            <span><strong>{{ item.label }}</strong></span>
        </Link>
    </nav>
</template>

<style scoped>
.collection-nav{display:flex;gap:6px;overflow-x:auto;padding:0 0 14px;margin:0;border-bottom:1px solid #DFD7CB;scrollbar-width:thin}.collection-nav__item{display:flex;flex:0 0 auto;align-items:center;gap:10px;min-height:46px;padding:10px 16px;border-radius:9px;color:#626B6D;text-decoration:none;white-space:nowrap}.collection-nav__item:hover{background:#EFE9E0;color:#25343A}.collection-nav__item.active{background:#25343A;color:#FFFDFA}.collection-nav__icon{display:grid;place-items:center;font-size:17px}.collection-nav__item strong{font-size:14px;font-weight:750}
</style>
