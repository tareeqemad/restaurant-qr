<script setup>
/**
 * One row of the chart tree plus its nested children — the Vue twin of the
 * old `_tree-node.blade.php`, which recursed by @include'ing itself.
 *
 * The component owns NO state. Expand/collapse, selection and the search
 * filter all live in Index.vue, because "توسيع الكل" / "طيّ الكل" and the
 * search's auto-expand have to reach across the whole tree at once — a
 * per-node local flag cannot be reached from the toolbar.
 */
defineOptions({ name: 'AccountNode' });

const props = defineProps({
    node: { type: Object, required: true },
    selectedId: { type: Number, default: null },
    // Reactive record of collapsed node ids, owned by Index.
    collapsed: { type: Object, required: true },
    // Set of ids filtered out by the search; null when no search is active.
    hiddenIds: { type: Object, default: null },
    showInactive: { type: Boolean, default: false },
});

defineEmits(['select', 'toggle']);

/**
 * The Blade hid inactive rows with `display:none` on the whole <li>, which
 * takes the subtree — active children included — with it. Reproduced on
 * purpose: changing it would change which accounts an accountant sees.
 */
function isVisible(node) {
    if (!props.showInactive && !node.isActive) return false;
    return !(props.hiddenIds && props.hiddenIds.has(node.id));
}
</script>

<template>
    <li v-if="isVisible(node)"
        :class="{ parent_li: node.hasChildren, 'acc-inactive': !node.isActive }">
        <span :class="{ selected: selectedId === node.id }"
              :title="node.title"
              @click="$emit('select', node.id)">
            <i v-if="node.hasChildren"
               class="bi acc-toggle"
               :class="collapsed[node.id] ? 'bi-plus-square' : 'bi-dash-square'"
               @click.stop="$emit('toggle', node.id)"></i>
            <i v-else class="bi bi-circle-fill acc-leaf-dot"></i>

            <span class="acc-code-chip">{{ node.code }}</span>
            <span class="acc-name-text">{{ node.name }}</span>
            <span class="acc-meta-text">· {{ node.normalBalanceLabel }}</span>

            <span v-if="node.isSystem" class="acc-badge-sys" title="حساب نظامي — كوده مقفول">
                <i class="bi bi-shield-fill-check"></i> نظامي
            </span>
            <span v-if="!node.isActive" class="acc-badge-off" title="معطّل">
                <i class="bi bi-pause-fill"></i> معطّل
            </span>
        </span>

        <ul v-if="node.hasChildren && !collapsed[node.id]">
            <AccountNode v-for="child in node.children" :key="child.id"
                         :node="child"
                         :selected-id="selectedId"
                         :collapsed="collapsed"
                         :hidden-ids="hiddenIds"
                         :show-inactive="showInactive"
                         @select="$emit('select', $event)"
                         @toggle="$emit('toggle', $event)" />
        </ul>
    </li>
</template>
