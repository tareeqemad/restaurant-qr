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
    border: 1px solid #e1e8e4;
    border-radius: 14px;
    background: #fff;
    box-shadow: 0 8px 24px -22px rgba(15, 49, 31, .6);
}
.attention-rail > header {
    display: flex;
    flex-direction: column;
    justify-content: center;
    gap: .18rem;
    padding-inline: .55rem;
    border-inline-end: 1px solid #edf1ef;
}
.attention-rail > header span {
    color: var(--cx-primary);
    font-size: .87rem;
    font-weight: 850;
}
.attention-rail > header strong { color: #647067; font-size: .72rem; }
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
    border: 1px solid #e7ece9;
    border-radius: 11px;
    color: #26332b;
    background: #fbfcfb;
    text-align: start;
    cursor: pointer;
    scroll-snap-align: start;
}
.attention-item:hover { border-color: rgba(var(--primary-rgb, 22 101 52), .35); background: #f7fbf8; }
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
.attention-copy strong { font-size: .76rem; }
.attention-copy small { margin-top: .12rem; color: #77837b; font-size: .66rem; }
.attention-item > b { color: #24372a; font-size: .72rem; white-space: nowrap; }
.attention-item > .bi-chevron-left { color: #a5afa8; font-size: .68rem; }
.attention-empty {
    display: flex;
    align-items: center;
    gap: .45rem;
    margin: 0;
    color: #44805a;
    font-size: .82rem;
    font-weight: 750;
}
@media (max-width: 720px) {
    .attention-rail { grid-template-columns: 1fr; }
    .attention-rail > header { flex-direction: row; justify-content: space-between; border-inline-end: 0; }
}
</style>
