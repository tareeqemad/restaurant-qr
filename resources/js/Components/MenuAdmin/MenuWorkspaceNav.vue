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
    --mw-primary: var(--brand, #A3422B);
    --mw-primary-deep: var(--ink, #25343A);
    display: flex;
    gap: .45rem;
    overflow-x: auto;
    scrollbar-width: thin;
    padding: .35rem;
    margin: -.2rem 0 1rem;
    border: 1px solid var(--canvas, #F7F3ED);
    border-radius: 12px;
    background: rgba(255, 255, 255, .9);
    box-shadow: none;
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
    color: var(--muted, #626B6D);
    font-size: .875rem;
    font-weight: 800;
    text-decoration: none;
    white-space: nowrap;
    scroll-snap-align: start;
    transition: background-color .16s ease, color .16s ease, transform .16s ease, box-shadow .16s ease;
}
.mw-link:hover { color: var(--mw-primary-deep); background: color-mix(in srgb, var(--brand, #A3422B) 7%, transparent); transform: translateY(-1px); }
.mw-link.active {
    color: #fff;
    background: var(--mw-primary);
    box-shadow: none;
}
.mw-link i { font-size: 1rem; }
.mw-link:focus-visible { outline: 3px solid color-mix(in srgb, var(--brand, #A3422B) 20%, transparent); outline-offset: 2px; }
@media (max-width: 575.98px) {
    .mw-nav { margin-inline: -.25rem; border-radius: 12px; }
    .mw-link { padding-inline: .75rem; }
}
@media (prefers-reduced-motion: reduce) {
    .mw-link { transition: none; }
    .mw-link:hover { transform: none; }
}

/* Dar Al Sarw management layout */
.mw-nav { margin: 0 0 22px; gap: 6px; padding: 6px; border-radius: 10px; background: var(--surface); border: 1px solid var(--line); } .mw-nav a { min-height: 46px; padding: 10px 16px; border-radius: 7px; font-size: .9375rem; } .mw-nav a.active { background: var(--brand-soft); color: var(--brand); }
</style>
