<script setup>
/**
 * Laravel paginator links → Bootstrap pagination. Feed it the paginator's
 * serialized `links` array ({ url, label, active }). Labels may carry
 * HTML entities (« ») — rendered via v-html, source is our own backend.
 */
import { Link } from '@inertiajs/vue3';

defineProps({
    links: { type: Array, default: () => [] },
});
</script>

<template>
    <nav v-if="links.length > 3" class="d-flex justify-content-center" aria-label="pagination">
        <ul class="pagination mb-0">
            <li v-for="(link, i) in links" :key="i"
                class="page-item" :class="{ active: link.active, disabled: ! link.url }">
                <Link v-if="link.url" class="page-link" :href="link.url" preserve-scroll v-html="link.label" />
                <span v-else class="page-link" v-html="link.label"></span>
            </li>
        </ul>
    </nav>
</template>
