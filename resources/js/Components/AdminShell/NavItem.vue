<script setup>
import { computed, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
const props = defineProps({ item: { type: Object, required: true }, depth: { type: Number, default: 1 }, expanded: { type: Boolean, default: false } });
const emit = defineEmits(['navigate']);
const hasChildren = computed(() => Boolean(props.item.children?.length));
const open = ref(Boolean(props.item.active));
const visible = computed(() => props.expanded || open.value);
watch(() => props.item.active, (active) => { if (active) open.value = true; });
</script>

<template>
    <li v-if="item.section" class="sarw-nav-section">{{ item.section }}</li>
    <li v-else class="sarw-nav-node" :class="{ 'is-active': item.active, 'is-nested': depth > 1 }">
        <button v-if="hasChildren" type="button" class="sarw-nav-link sarw-nav-toggle" :aria-expanded="visible" @click="open = !open">
            <i v-if="item.icon" :class="item.icon" aria-hidden="true"></i>
            <span class="sarw-nav-label">{{ item.label }}</span>
            <span v-if="item.badge" class="sarw-nav-badge" :class="`is-${item.badge.tone}`">{{ item.badge.value }}</span>
            <i class="bi bi-chevron-down sarw-nav-chevron" :class="{ 'is-open': visible }" aria-hidden="true"></i>
        </button>
        <a v-else-if="item.newTab" :href="item.href" class="sarw-nav-link" target="_blank" rel="noopener" :aria-current="item.active ? 'page' : undefined" @click="emit('navigate')">
            <i v-if="item.icon" :class="item.icon" aria-hidden="true"></i><span class="sarw-nav-label">{{ item.label }}</span>
            <span v-if="item.badge" class="sarw-nav-badge" :class="`is-${item.badge.tone}`">{{ item.badge.value }}</span>
            <i class="bi bi-arrow-up-left sarw-nav-external" aria-hidden="true"></i><span class="visually-hidden">يفتح في تبويب جديد</span>
        </a>
        <Link v-else :href="item.href" class="sarw-nav-link" :aria-current="item.active ? 'page' : undefined"
              :prefetch="item.active ? false : 'hover'" :cache-for="30000" @click="emit('navigate')">
            <i v-if="item.icon" :class="item.icon" aria-hidden="true"></i><span class="sarw-nav-label">{{ item.label }}</span>
            <span v-if="item.badge" class="sarw-nav-badge" :class="`is-${item.badge.tone}`">{{ item.badge.value }}</span>
        </Link>
        <ul v-if="hasChildren" v-show="visible" class="sarw-nav-children">
            <NavItem v-for="(child, i) in item.children" :key="child.href || child.label || i" :item="child" :depth="depth + 1" :expanded="expanded" @navigate="emit('navigate')" />
        </ul>
    </li>
</template>
