<script setup>
defineOptions({ name: 'AccountTreeNode' })

const props = defineProps({
    node: { type: Object, required: true },
    selectedId: { type: Number, default: null },
    collapsed: { type: Object, required: true },
    hiddenIds: { type: Object, default: null },
    showInactive: { type: Boolean, default: false },
    canCreate: { type: Boolean, default: false },
    depth: { type: Number, default: 0 },
})

const emit = defineEmits(['select', 'toggle', 'add-child'])

function isVisible(node) {
    if (!props.showInactive && !node.isActive) return false
    return !(props.hiddenIds && props.hiddenIds.has(node.id))
}
</script>

<template>
    <li v-if="isVisible(node)" class="account-node" :class="{ inactive: !node.isActive }">
        <div
            class="account-node__row"
            :class="{ selected: selectedId === node.id }"
            :style="{ '--account-depth': Math.min(depth, 5) }"
            role="button"
            tabindex="0"
            :aria-pressed="selectedId === node.id"
            @click="emit('select', node.id)"
            @keydown.enter="emit('select', node.id)"
            @keydown.space.prevent="emit('select', node.id)"
        >
            <button
                v-if="node.hasChildren"
                type="button"
                class="account-node__fold"
                :aria-label="collapsed[node.id] ? 'عرض الحسابات الفرعية' : 'طي الحسابات الفرعية'"
                @click.stop="emit('toggle', node.id)"
            >
                <i class="bi" :class="collapsed[node.id] ? 'bi-chevron-left' : 'bi-chevron-down'"></i>
            </button>
            <span v-else class="account-node__leaf"><i class="bi bi-dot"></i></span>

            <bdi class="account-node__code">{{ node.code }}</bdi>
            <span class="account-node__identity">
                <strong>{{ node.name }}</strong>
                <small v-if="node.description">{{ node.description }}</small>
            </span>
            <span class="account-node__nature">{{ node.normalBalanceLabel }}</span>
            <span v-if="node.isSystem" class="account-node__badge system"><i class="bi bi-shield-lock-fill"></i> نظامي</span>
            <span v-if="!node.isActive" class="account-node__badge inactive"><i class="bi bi-pause-fill"></i> معطّل</span>
            <button
                v-if="canCreate"
                type="button"
                class="account-node__add"
                title="إضافة حساب فرعي"
                aria-label="إضافة حساب فرعي"
                @click.stop="emit('add-child', node)"
            >
                <i class="bi bi-plus-lg"></i>
            </button>
        </div>

        <ul v-if="node.hasChildren && !collapsed[node.id]" class="account-node__children">
            <AccountTreeNode
                v-for="child in node.children"
                :key="child.id"
                :node="child"
                :selected-id="selectedId"
                :collapsed="collapsed"
                :hidden-ids="hiddenIds"
                :show-inactive="showInactive"
                :can-create="canCreate"
                :depth="depth + 1"
                @select="emit('select', $event)"
                @toggle="emit('toggle', $event)"
                @add-child="emit('add-child', $event)"
            />
        </ul>
    </li>
</template>

<style scoped>
.account-node {
    list-style: none;
}

.account-node__row {
    display: grid;
    grid-template-columns: 34px 72px minmax(180px, 1fr) 62px auto auto 38px;
    min-height: 52px;
    align-items: center;
    gap: 8px;
    margin: 3px 0;
    padding: 5px 8px;
    padding-inline-start: calc(8px + (var(--account-depth) * 14px));
    border: 1px solid transparent;
    border-radius: 11px;
    color: var(--ink, #25343A);
    background: var(--surface, #FFFDFA);
    cursor: pointer;
    outline: 0;
    transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
}

.account-node__row:hover,
.account-node__row:focus-visible {
    border-color: var(--line, #DFD7CB);
    background: var(--surface, #FFFDFA);
}

.account-node__row.selected {
    border-color: var(--line, #DFD7CB);
    background: var(--surface, #FFFDFA);
    box-shadow: inset -3px 0 var(--brand, #A3422B);
}

.account-node__fold,
.account-node__add {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border: 0;
    border-radius: 9px;
    color: var(--brand, #A3422B);
    background: var(--surface, #FFFDFA);
}

.account-node__add {
    color: var(--muted, #626B6D);
    background: transparent;
    opacity: 0;
}

.account-node__row:hover .account-node__add,
.account-node__row.selected .account-node__add,
.account-node__add:focus-visible {
    opacity: 1;
}

.account-node__leaf {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    color: var(--muted, #626B6D);
    font-size: 1.2rem;
}

.account-node__code {
    width: max-content;
    min-width: 58px;
    padding: 4px 7px;
    border-radius: 7px;
    color: var(--brand, #A3422B);
    background: var(--surface, #FFFDFA);
    font-family: Consolas, monospace;
    font-size: .875rem;
    font-weight: 900;
    text-align: center;
}

.account-node__identity {
    display: grid;
    min-width: 0;
    gap: 1px;
}

.account-node__identity strong,
.account-node__identity small {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.account-node__identity strong {
    font-size: .875rem;
    font-weight: 850;
}

.account-node__identity small {
    color: var(--muted, #626B6D);
    font-size: .875rem;
}

.account-node__nature {
    color: var(--muted, #626B6D);
    font-size: .875rem;
    font-weight: 800;
}

.account-node__badge {
    display: inline-flex;
    min-height: 24px;
    align-items: center;
    gap: 4px;
    padding: 3px 7px;
    border-radius: 8px;
    font-size: .875rem;
    font-weight: 850;
    white-space: nowrap;
}

.account-node__badge.system {
    color: #4f5688;
    background: #eef0fb;
}

.account-node__badge.inactive {
    color: #9a5909;
    background: #fff1dd;
}

.account-node.inactive > .account-node__row {
    opacity: .72;
}

.account-node__children {
    margin: 0;
    padding: 0;
    border-inline-start: 1px dashed var(--line, #DFD7CB);
}

@media (max-width: 720px) {
    .account-node__row {
        grid-template-columns: 34px 62px minmax(0, 1fr) auto 38px;
        min-height: 56px;
        gap: 6px;
        padding-inline-start: calc(5px + (var(--account-depth) * 8px));
    }

    .account-node__nature,
    .account-node__badge.system {
        display: none;
    }

    .account-node__badge.inactive {
        grid-column: 4;
    }

    .account-node__add {
        opacity: 1;
    }
}

@media (prefers-reduced-motion: reduce) {
    .account-node__row {
        transition: none;
    }
}

.account-node__row{min-height:66px;gap:12px;padding-block:12px;border-radius:8px;border-bottom:1px solid #EAE3D9}.account-node__row.selected{background:#F3E3D8;border-color:#DFD7CB;box-shadow:inset -3px 0 #A3422B}.account-node__row:hover{background:#F7F3ED}.account-node__identity strong{font-size:15px}.account-node__identity small{font-size:13px;line-height:1.5}.account-node__code{padding:6px 9px;background:#F7F3ED;font-size:14px;border-radius:5px}.account-node__fold,.account-node__add{width:36px;height:36px;flex:0 0 36px}.account-node__children{border-inline-start:1px solid #DFD7CB;margin-inline-start:24px}
</style>
