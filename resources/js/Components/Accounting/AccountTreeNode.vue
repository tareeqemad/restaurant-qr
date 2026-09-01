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
    color: #2c3f34;
    background: #fff;
    cursor: pointer;
    outline: 0;
    transition: border-color .15s ease, background .15s ease, box-shadow .15s ease;
}

.account-node__row:hover,
.account-node__row:focus-visible {
    border-color: #d7e6dc;
    background: #f7faf8;
}

.account-node__row.selected {
    border-color: #8ebda0;
    background: #edf7f0;
    box-shadow: inset -3px 0 rgb(var(--primary-rgb, 31, 107, 80));
}

.account-node__fold,
.account-node__add {
    display: grid;
    width: 34px;
    height: 34px;
    place-items: center;
    border: 0;
    border-radius: 9px;
    color: rgb(var(--primary-rgb, 31, 107, 80));
    background: #edf5f0;
}

.account-node__add {
    color: #65746b;
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
    color: #a9b4ad;
    font-size: 1.2rem;
}

.account-node__code {
    width: max-content;
    min-width: 58px;
    padding: 4px 7px;
    border-radius: 7px;
    color: rgb(var(--primary-rgb, 31, 107, 80));
    background: #edf5f0;
    font-family: Consolas, monospace;
    font-size: .68rem;
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
    font-size: .72rem;
    font-weight: 850;
}

.account-node__identity small {
    color: #8a968e;
    font-size: .58rem;
}

.account-node__nature {
    color: #718078;
    font-size: .6rem;
    font-weight: 800;
}

.account-node__badge {
    display: inline-flex;
    min-height: 24px;
    align-items: center;
    gap: 4px;
    padding: 3px 7px;
    border-radius: 999px;
    font-size: .55rem;
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
    border-inline-start: 1px dashed #d6e3da;
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
</style>
