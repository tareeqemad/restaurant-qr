<script setup>
const props = defineProps({
    lastRound: { type: Object, default: null },
    quickPicks: { type: Array, required: true },
});

const emit = defineEmits({
    'repeat-line': (index) => Number.isInteger(index) && index >= 0,
    'repeat-all': () => true,
    'add-item': (id) => Number.isFinite(Number(id)),
});

function lineDetails(line) {
    return [...(line.modifier_labels ?? []), line.line_notes].filter(Boolean).join('، ');
}
</script>

<template>
    <div
        v-if="lastRound?.lines?.length"
        class="quick-strip is-repeat"
        role="group"
        aria-label="الجولة الماضية"
    >
        <span class="strip-title"><i class="bi bi-arrow-repeat"></i> الجولة الماضية</span>
        <button type="button" class="repeat-all" @click="emit('repeat-all')">
            <i class="bi bi-cart-plus"></i>
            كرر الكل
        </button>
        <button
            v-for="(line, index) in lastRound.lines"
            :key="`${line.menu_item_id}-${index}`"
            type="button"
            class="strip-chip"
            :title="lineDetails(line) || undefined"
            @click="emit('repeat-line', index)"
        >
            {{ line.name }}
            <b v-if="line.quantity > 1">×{{ line.quantity }}</b>
        </button>
    </div>

    <div v-if="quickPicks.length" class="quick-strip" role="group" aria-label="الأكثر طلباً">
        <span class="strip-title"><i class="bi bi-star-fill"></i> الأكثر طلباً</span>
        <button
            v-for="item in quickPicks"
            :key="item.id"
            type="button"
            class="strip-chip"
            @click="emit('add-item', item.id)"
        >
            {{ item.name }}
        </button>
    </div>
</template>

<style scoped>
.quick-strip {
    display: flex;
    align-items: center;
    gap: .4rem;
    margin-bottom: .35rem;
    padding: .15rem 0 .35rem;
    overflow-x: auto;
    scrollbar-width: thin;
    -webkit-overflow-scrolling: touch;
}
.strip-title {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    gap: .3rem;
    color: var(--muted);
    font-size: 14px;
    font-weight: 800;
    white-space: nowrap;
}
.strip-title i { color: var(--wp-primary); }
.is-repeat .strip-title i { color: #b45309; }
.strip-chip,
.repeat-all {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    justify-content: center;
    gap: .3rem;
    min-height: 44px;
    box-sizing: border-box;
    padding: .4rem .8rem;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 700;
    white-space: nowrap;
    cursor: pointer;
}
.strip-chip {
    border: 1px solid color-mix(in srgb, var(--brand) 18%, transparent);
    color: var(--ink);
    background: var(--surface);
}
.strip-chip:hover { border-color: var(--wp-primary); color: var(--wp-primary); }
.strip-chip b { color: var(--wp-primary); }
.repeat-all {
    padding-inline: .85rem;
    border: 0;
    color: #FFFDFA;
    background: var(--wp-primary);
    font-weight: 800;
}
.repeat-all:hover { background: var(--wp-primary-dark); }

/* Task-specific component layout. */
.quick-strip{display:flex;align-items:center;gap:8px;padding:12px 0;margin:0 0 12px;border:0;border-bottom:1px solid var(--line);border-radius:0;background:transparent;overflow-x:auto}.strip-title{font-size:14px;color:var(--muted);min-width:max-content}.strip-chip{min-height:42px;padding:8px 12px;border:1px solid var(--line);border-radius:6px;background:var(--surface);font-size:14px}.repeat-all{min-height:42px;border-radius:6px;font-size:14px;color:var(--brand);background:var(--brand-soft)}
</style>
