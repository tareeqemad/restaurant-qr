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
              class="collection-nav__item" :class="{ active: item.key === active }">
            <span class="collection-nav__icon"><i class="bi" :class="item.icon"></i></span>
            <span><strong>{{ item.label }}</strong><small>{{ item.hint }}</small></span>
        </Link>
    </nav>
</template>

<style scoped>
.collection-nav { display: flex; gap: .45rem; margin-bottom: .85rem; padding: .35rem; overflow-x: auto; border: 1px solid #e1e9e4; border-radius: 14px; background: #f7faf8; scrollbar-width: thin; }
.collection-nav__item { min-width: 155px; min-height: 50px; flex: 1; display: flex; align-items: center; gap: .55rem; padding: .45rem .6rem; border: 1px solid transparent; border-radius: 11px; color: #52675b; text-decoration: none; white-space: nowrap; }
.collection-nav__item:hover { color: #116e3c; background: #fff; }
.collection-nav__item.active { border-color: #a9d0b8; color: #0b6b38; background: #fff; box-shadow: 0 4px 14px rgba(25, 104, 61, .08); }
.collection-nav__icon { width: 34px; height: 34px; flex: 0 0 34px; display: grid; place-items: center; border-radius: 9px; background: #e8f2ec; color: #157542; font-size: .9rem; }
.collection-nav__item strong, .collection-nav__item small { display: block; }
.collection-nav__item strong { font-size: .76rem; }
.collection-nav__item small { margin-top: .08rem; color: #89968f; font-size: .6rem; }
@media (max-width: 575.98px) {
    .collection-nav { margin-inline: -.25rem; }
    .collection-nav__item { min-width: 135px; }
    .collection-nav__item small { display: none; }
}
</style>
