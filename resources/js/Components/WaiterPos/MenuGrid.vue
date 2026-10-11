<script setup>
import MenuTile from './MenuTile.vue';

const props = defineProps({
    items: { type: Array, required: true },
    cartQuantities: { type: Object, required: true },
    currency: { type: Object, required: true },
});

const emit = defineEmits({
    'add-item': (id) => Number.isFinite(Number(id)),
    'customize-item': (id) => Number.isFinite(Number(id)),
});
</script>

<template>
    <div class="menu-grid" aria-live="polite">
        <MenuTile
            v-for="item in items"
            :key="item.id"
            :item="item"
            :quantity="Number(cartQuantities[String(item.id)] ?? 0)"
            :currency="currency"
            @add-item="emit('add-item', $event)"
            @customize-item="emit('customize-item', $event)"
        />

        <div v-if="items.length === 0" class="empty-grid">
            <i class="bi bi-search"></i>
            <strong>لا توجد أصناف مطابقة</strong>
            <span>غيّر البحث أو اختر تصنيفاً آخر.</span>
        </div>
    </div>
</template>

<style scoped>
.menu-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(158px, 1fr));
    gap: .75rem;
}
.empty-grid {
    grid-column: 1 / -1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .3rem;
    padding: 2.5rem 1rem;
    color: var(--muted);
    text-align: center;
}
.empty-grid i { font-size: 1.8rem; }
.empty-grid strong { color: var(--muted); }
.empty-grid span { font-size: 14px; }

/* Task-specific component layout. */
.menu-grid{grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px}.empty-grid{min-height:260px;padding:40px 20px;border:1px dashed var(--line);border-radius:8px}@media(max-width:500px){.menu-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}}
</style>
