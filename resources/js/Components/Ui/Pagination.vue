<script setup>
/**
 * One paginator for every Inertia workspace.
 *
 * Laravel owns URLs and the condensed page window; this component owns all
 * user-facing copy, RTL order, accessibility and responsive presentation.
 * Endpoint labels are deliberately not rendered from the server, so a
 * missing translation can never leak `pagination.next` into the interface.
 */
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    links: { type: Array, default: () => [] },
    preserveState: { type: Boolean, default: false },
});

const previous = computed(() => props.links[0] ?? { url: null });
const next = computed(() => props.links.at(-1) ?? { url: null });
const middle = computed(() => props.links.slice(1, -1).map((link, index) => {
    const raw = String(link.label ?? '').trim();
    const page = /^\d+$/.test(raw) ? Number(raw) : null;

    return {
        ...link,
        key: link.url ?? `${raw}-${index}`,
        page,
        ellipsis: page === null && /(?:hellip|…|\.\.\.)/i.test(raw),
    };
}));

const currentPage = computed(() => middle.value.find((link) => link.active)?.page ?? 1);
const lastPage = computed(() => Math.max(
    currentPage.value,
    ...middle.value.map((link) => link.page ?? 0),
));
const hasPages = computed(() => lastPage.value > 1 || Boolean(previous.value.url) || Boolean(next.value.url));
const mobileHidden = (link) => link.page !== null
    && ! link.active
    && link.page !== 1
    && link.page !== lastPage.value
    && Math.abs(link.page - currentPage.value) > 1;
const compactHidden = (link) => link.page !== null
    && ! link.active
    && link.page !== 1
    && link.page !== lastPage.value;
</script>

<template>
    <nav v-if="hasPages" class="app-pagination" dir="rtl" aria-label="التنقل بين صفحات النتائج">
        <div class="app-pagination__inner">
            <p class="app-pagination__summary" aria-live="polite">
                <span>الصفحة</span>
                <strong>{{ currentPage }}</strong>
                <span>من</span>
                <strong>{{ lastPage }}</strong>
            </p>

            <div class="app-pagination__controls">
                <Link v-if="previous.url" class="app-pagination__nav" :href="previous.url"
                      preserve-scroll :preserve-state="preserveState" rel="prev"
                      aria-label="الانتقال إلى الصفحة السابقة">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    <span class="app-pagination__word">السابق</span>
                </Link>
                <span v-else class="app-pagination__nav is-disabled" aria-disabled="true">
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    <span class="app-pagination__word">السابق</span>
                </span>

                <ol class="app-pagination__pages" aria-label="أرقام الصفحات">
                    <li v-for="link in middle" :key="link.key"
                        :class="{ 'is-mobile-hidden': mobileHidden(link), 'is-compact-hidden': compactHidden(link) }">
                        <span v-if="link.ellipsis" class="app-pagination__ellipsis" aria-hidden="true">…</span>
                        <span v-else-if="link.active" class="app-pagination__page is-active"
                              aria-current="page" :aria-label="`الصفحة الحالية، ${link.page}`">
                            {{ link.page }}
                        </span>
                        <Link v-else-if="link.url && link.page" class="app-pagination__page" :href="link.url"
                              preserve-scroll :preserve-state="preserveState"
                              :aria-label="`الانتقال إلى الصفحة ${link.page}`">
                            {{ link.page }}
                        </Link>
                    </li>
                </ol>

                <Link v-if="next.url" class="app-pagination__nav" :href="next.url"
                      preserve-scroll :preserve-state="preserveState" rel="next"
                      aria-label="الانتقال إلى الصفحة التالية">
                    <span class="app-pagination__word">التالي</span>
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </Link>
                <span v-else class="app-pagination__nav is-disabled" aria-disabled="true">
                    <span class="app-pagination__word">التالي</span>
                    <i class="bi bi-chevron-left" aria-hidden="true"></i>
                </span>
            </div>
        </div>
    </nav>
</template>

<style scoped>
.app-pagination { width: 100%; color: #334155; }
.app-pagination__inner {
    display: grid;
    grid-template-columns: minmax(7rem, 1fr) auto minmax(7rem, 1fr);
    align-items: center;
    gap: 1rem;
    min-height: 58px;
}
.app-pagination__inner::after { content: ''; }
.app-pagination__summary {
    display: inline-flex;
    align-items: center;
    gap: .3rem;
    justify-self: start;
    margin: 0;
    color: #718078;
    font-size: .72rem;
    font-weight: 700;
    white-space: nowrap;
}
.app-pagination__summary strong {
    color: #164f35;
    font-size: .78rem;
    font-variant-numeric: tabular-nums;
}
.app-pagination__controls, .app-pagination__pages { display: flex; align-items: center; }
.app-pagination__controls { gap: .42rem; }
.app-pagination__pages { gap: .3rem; margin: 0; padding: 0; list-style: none; }
.app-pagination__nav, .app-pagination__page, .app-pagination__ellipsis {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 42px;
    min-height: 42px;
    border: 1px solid #dce6e0;
    border-radius: 11px;
    background: #fff;
    color: #355548;
    text-decoration: none;
    font-size: .78rem;
    font-weight: 800;
    line-height: 1;
    font-variant-numeric: tabular-nums;
    transition: border-color .16s ease, background-color .16s ease, color .16s ease, transform .16s ease;
}
.app-pagination__nav { gap: .4rem; min-width: 88px; padding-inline: .8rem; }
.app-pagination__ellipsis { border-color: transparent; background: transparent; color: #94a3b8; }
.app-pagination__nav:not(.is-disabled):hover, .app-pagination__page:not(.is-active):hover {
    border-color: #9fc9b2;
    background: #f1f8f4;
    color: #0f6b42;
    transform: translateY(-1px);
}
.app-pagination__page.is-active {
    border-color: #197149;
    background: #197149;
    color: #fff;
    box-shadow: 0 7px 16px -10px rgba(25, 113, 73, .8);
}
.app-pagination__nav.is-disabled {
    border-color: #e8eeeb;
    background: #f7f9f8;
    color: #b2bdb7;
    cursor: not-allowed;
}
.app-pagination__nav:focus-visible, .app-pagination__page:focus-visible {
    outline: 3px solid rgba(25, 113, 73, .2);
    outline-offset: 2px;
}
@media (max-width: 720px) {
    .app-pagination__inner {
        grid-template-columns: 1fr;
        justify-items: center;
        gap: .6rem;
        padding-block: .35rem;
    }
    .app-pagination__inner::after { display: none; }
    .app-pagination__summary { justify-self: center; grid-row: 2; }
    .app-pagination__controls { grid-row: 1; max-width: 100%; }
    .app-pagination__nav { min-width: 42px; padding-inline: .65rem; }
    .app-pagination__word { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); }
    .app-pagination__page, .app-pagination__ellipsis { min-width: 38px; min-height: 40px; }
    .is-mobile-hidden { display: none; }
}
@media (max-width: 420px) {
    .app-pagination__controls { gap: .25rem; }
    .app-pagination__pages { gap: .2rem; }
    .app-pagination__page, .app-pagination__ellipsis { min-width: 34px; min-height: 38px; border-radius: 9px; }
    .app-pagination__nav { min-width: 38px; min-height: 38px; border-radius: 9px; }
    .is-compact-hidden { display: none; }
}
@media (prefers-reduced-motion: reduce) {
    .app-pagination__nav, .app-pagination__page { transition: none; }
}
</style>
