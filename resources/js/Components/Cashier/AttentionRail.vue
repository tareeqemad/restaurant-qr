<script setup>
import { formatMoney } from '../../Composables/useMoney';

defineProps({
    items: { type: Array, required: true },
    currency: { type: Object, required: true },
});

const emit = defineEmits({
    select: (selection) => Boolean(selection?.kind && Number(selection?.id)),
});

const icons = {
    transfer: 'bi-bank',
    change: 'bi-arrow-repeat',
    bill: 'bi-receipt',
    remote: 'bi-bag-check',
};
</script>

<template>
    <section class="attention-rail" aria-label="الأعمال ذات الأولوية">
        <header>
            <span><i class="bi bi-lightning-charge-fill"></i> الآن</span>
            <strong>{{ items.length }} مهمة</strong>
        </header>

        <div v-if="items.length" class="attention-scroll">
            <button
                v-for="item in items"
                :key="item.key"
                type="button"
                class="attention-item"
                :class="`is-${item.severity}`"
                @click="emit('select', item.selection)"
            >
                <i class="attention-icon bi" :class="icons[item.type] ?? 'bi-circle-fill'"></i>
                <span class="attention-copy">
                    <strong>{{ item.title }}</strong>
                    <small>{{ item.subtitle }}</small>
                </span>
                <b v-if="item.amount !== null">{{ formatMoney(item.amount, currency) }}</b>
                <i class="bi bi-chevron-left"></i>
            </button>
        </div>

        <p v-else class="attention-empty">
            <i class="bi bi-check2-circle"></i>
            لا توجد مهام عاجلة
        </p>
    </section>
</template>

<style scoped>
.attention-rail {
    display: grid;
    grid-template-columns: 112px minmax(0, 1fr);
    gap: .65rem;
    align-items: stretch;
    padding: .55rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 12px;
    background: var(--surface, #FFFDFA);
    box-shadow: none;
}
.attention-rail > header {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: .18rem;
    padding-inline: .55rem;
    border-inline-end: 1px solid var(--line, var(--line));
}
.attention-rail > header span {
    color: var(--cx-primary);
    font-size: .875rem;
    font-weight: 800;
}
.attention-rail > header strong { color: var(--muted, var(--muted)); font-size: .875rem; }
.attention-scroll {
    display: flex;
    gap: .5rem;
    min-width: 0;
    overflow-x: auto;
    scrollbar-width: thin;
    scroll-snap-type: x proximity;
}
.attention-item {
    display: grid;
    grid-template-columns: 30px minmax(150px, 1fr) auto 16px;
    gap: .48rem;
    align-items: center;
    min-width: min(330px, 80vw);
    min-height: 54px;
    padding: .45rem .55rem;
    border: 1px solid var(--line, var(--line));
    border-radius: 11px;
    color: var(--ink, var(--ink));
    background: var(--surface, #FFFDFA);
    text-align: start;
    cursor: pointer;
    scroll-snap-align: start;
}
.attention-item:hover { border-color: color-mix(in srgb, var(--brand, #A3422B) 35%, transparent); background: var(--surface, #FFFDFA); }
.attention-item.is-critical { border-inline-start: 3px solid #dc3545; }
.attention-item.is-warning { border-inline-start: 3px solid #d97706; }
.attention-icon {
    display: grid;
    place-items: center;
    width: 30px;
    height: 30px;
    border-radius: 9px;
    color: #b4232e;
    background: #fff1f2;
    font-size: .88rem;
}
.is-warning .attention-icon { color: #a85d00; background: #fff7e7; }
.attention-copy { display: flex; min-width: 0; flex-direction: column; }
.attention-copy strong, .attention-copy small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.attention-copy strong { font-size: .875rem; }
.attention-copy small { margin-top: .12rem; color: var(--muted, var(--muted)); font-size: .875rem; }
.attention-item > b { color: var(--ink, var(--ink)); font-size: .875rem; white-space: nowrap; }
.attention-item > .bi-chevron-left { color: var(--muted, var(--muted)); font-size: .875rem; }
.attention-empty {
    display: flex;
    align-items: center;
    gap: .45rem;
    margin: 0;
    color: var(--muted, var(--muted));
    font-size: .875rem;
    font-weight: 750;
}
@media (max-width: 720px) {
    .attention-rail { grid-template-columns: 1fr; }
    .attention-rail > header { flex-direction: row; justify-content: space-between; border-inline-end: 0; }
}

/* Task-specific component layout. */
.attention-rail{display:flex;align-items:stretch;gap:0;padding:0;border:1px solid var(--line);border-radius:8px;background:var(--surface);overflow:hidden}.attention-rail>header{display:flex;align-items:center;gap:8px;flex-shrink:0;padding:12px 16px;background:var(--canvas);border-inline-end:1px solid var(--line);font-size:14px}.attention-scroll{display:flex;gap:0;padding:0;overflow-x:auto;flex:1}.attention-item{border:0;border-inline-end:1px solid var(--line);border-radius:0;min-height:68px;padding:12px 16px;min-width:240px;background:transparent}.attention-copy strong{font-size:15px}.attention-copy small{font-size:14px}.attention-icon{border-radius:6px;width:32px;height:32px}.attention-empty{min-height:58px;padding:14px;font-size:14px}@media(max-width:650px){.attention-rail{flex-direction:column}.attention-rail>header{border-inline-end:0;border-bottom:1px solid var(--line);padding:10px 14px}}
</style>
