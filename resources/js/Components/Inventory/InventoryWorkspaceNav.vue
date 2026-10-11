<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({ workspace: { type: Object, required: true } });
const openGroup = ref('');
const groups = computed(() => (props.workspace.groups ?? [])
    .map((group) => ({ ...group, items: (group.items ?? []).filter(Boolean) }))
    .filter((group) => group.items.length));
const activeGroup = computed(() => groups.value.find((group) => group.items.some((item) => item.key === props.workspace.active))?.id ?? 'home');
const shown = computed(() => groups.value.find((group) => group.id === (openGroup.value || activeGroup.value)) ?? groups.value[0]);

watch(activeGroup, () => {
    openGroup.value = '';
});
</script>

<template>
    <nav v-if="shown" class="inv-workspace sarw-inventory-nav" aria-label="مركز المخزون والمشتريات">
        <div class="inv-workspace__groups" role="tablist">
            <button v-for="group in groups" :key="group.id" type="button"
                    :id="`inventory-tab-${group.id}`"
                    role="tab"
                    :class="{ active: shown.id === group.id }"
                    :aria-selected="shown.id === group.id"
                    :aria-controls="`inventory-panel-${group.id}`"
                    @click="openGroup = group.id">
                <i class="bi" :class="group.icon" aria-hidden="true"></i><span>{{ group.label }}</span>
                <small>{{ group.items.length }}</small>
            </button>
        </div>
        <div :id="`inventory-panel-${shown.id}`" class="inv-workspace__links" role="tabpanel"
             :aria-labelledby="`inventory-tab-${shown.id}`">
            <Link v-for="item in shown.items" :key="item.key" :href="item.href"
               :class="{ active: workspace.active === item.key }">
                <span>{{ item.label }}</span><i class="bi bi-chevron-left" aria-hidden="true"></i>
            </Link>
        </div>
    </nav>
</template>

<style scoped>
/* Keep these selectors local: Vue's :global() would discard trailing descendants. */
.inv-workspace.sarw-inventory-nav {
    position: static;
    display: block;
    min-width: 0;
    max-width: 100%;
    margin: 0 0 22px;
    padding: 0;
    border: 1px solid var(--line, #DFD7CB);
    border-radius: 10px;
    background: var(--surface, #FFFDFA);
    box-shadow: none;
}
.sarw-inventory-nav .inv-workspace__groups {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 8px;
    padding: 8px;
}
.sarw-inventory-nav .inv-workspace__groups button {
    display: flex;
    min-width: 0;
    min-height: 48px;
    align-items: center;
    justify-content: center;
    gap: 7px;
    padding: 8px 12px;
    border: 0;
    border-radius: 8px;
    color: var(--muted, #626B6D);
    background: transparent;
    font: inherit;
    font-size: .875rem;
    font-weight: 750;
    white-space: nowrap;
    box-shadow: none;
}
.sarw-inventory-nav .inv-workspace__groups button span { display: inline; }
.sarw-inventory-nav .inv-workspace__groups button i { font-size: 1rem; }
.sarw-inventory-nav .inv-workspace__groups button small {
    display: grid;
    min-width: 22px;
    height: 22px;
    place-items: center;
    padding-inline: 4px;
    border-radius: 5px;
    background: var(--canvas, #F7F3ED);
    font-size: .875rem;
}
/* Extra local specificity beats the retained legacy deployment stylesheet. */
.sarw-inventory-nav .inv-workspace__groups button.active {
    color: var(--brand, #A3422B);
    background: var(--brand-soft, #F3E3D8);
    box-shadow: inset 0 -2px var(--brand, #A3422B);
}
.sarw-inventory-nav .inv-workspace__groups button:focus-visible,
.sarw-inventory-nav .inv-workspace__links a:focus-visible {
    outline: 2px solid var(--brand, #A3422B);
    outline-offset: 2px;
}
.sarw-inventory-nav .inv-workspace__links {
    display: flex;
    align-items: center;
    gap: 6px;
    min-width: 0;
    overflow-x: auto;
    margin: 0;
    padding: 8px;
    border-top: 1px solid var(--line, #DFD7CB);
    border-inline-start: 0;
    background: var(--canvas, #F7F3ED);
    scrollbar-width: thin;
}
.sarw-inventory-nav .inv-workspace__links a {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 12px;
    min-height: 44px;
    padding: 8px 12px;
    border: 1px solid var(--line, #DFD7CB);
    border-radius: 8px;
    background: var(--surface, #FFFDFA);
    color: var(--ink, #25343A);
    font-size: .875rem;
    font-weight: 700;
    text-decoration: none;
    white-space: nowrap;
}
.sarw-inventory-nav .inv-workspace__links a i { font-size: .875rem; opacity: .65; }
.sarw-inventory-nav .inv-workspace__links a.active {
    border-color: var(--brand, #A3422B);
    background: var(--brand, #A3422B);
    color: #fff;
}
@media(max-width:900px) {
    .sarw-inventory-nav .inv-workspace__groups {
        display: flex;
        overflow-x: auto;
        scrollbar-width: thin;
    }
    .sarw-inventory-nav .inv-workspace__groups button { flex: 0 0 auto; min-width: 132px; }
}
</style>
