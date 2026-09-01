<script setup>
import { Link } from '@inertiajs/vue3';

defineProps({
    links: { type: Array, default: () => [] },
});
</script>

<template>
    <nav class="mw-nav" aria-label="أقسام إدارة المنيو">
        <Link v-for="link in links" :key="link.key" :href="link.href"
           class="mw-link" :class="{ active: link.active }">
            <i class="bi" :class="link.icon"></i>
            <span>{{ link.label }}</span>
        </Link>
    </nav>
</template>

<style scoped>
.mw-nav {
    --mw-primary: rgb(var(--primary-rgb, 31, 107, 80));
    --mw-primary-deep: #123f31;
    display: flex;
    gap: .45rem;
    overflow-x: auto;
    scrollbar-width: thin;
    padding: .35rem;
    margin: -.2rem 0 1rem;
    border: 1px solid #e6ece8;
    border-radius: 16px;
    background: rgba(255, 255, 255, .9);
    box-shadow: 0 8px 24px rgba(18, 63, 49, .045);
}
.mw-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .45rem;
    flex: 0 0 auto;
    min-height: 44px;
    padding: .55rem .9rem;
    border-radius: 12px;
    color: #52645a;
    font-size: .82rem;
    font-weight: 800;
    text-decoration: none;
    white-space: nowrap;
    scroll-snap-align: start;
    transition: background-color .16s ease, color .16s ease, transform .16s ease, box-shadow .16s ease;
}
.mw-link:hover { color: var(--mw-primary-deep); background: rgba(var(--primary-rgb, 31, 107, 80), .07); transform: translateY(-1px); }
.mw-link.active {
    color: #fff;
    background: var(--mw-primary);
    box-shadow: 0 6px 16px rgba(var(--primary-rgb, 31, 107, 80), .2);
}
.mw-link i { font-size: 1rem; }
.mw-link:focus-visible { outline: 3px solid rgba(var(--primary-rgb, 31, 107, 80), .2); outline-offset: 2px; }
@media (max-width: 575.98px) {
    .mw-nav { margin-inline: -.25rem; border-radius: 14px; }
    .mw-link { padding-inline: .75rem; }
}
@media (prefers-reduced-motion: reduce) {
    .mw-link { transition: none; }
    .mw-link:hover { transform: none; }
}
</style>
