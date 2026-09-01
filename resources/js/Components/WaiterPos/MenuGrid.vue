<script setup>
import MenuTile from './MenuTile.vue';

const props = defineProps({
    items: { type: Array, required: true },
    cartQuantities: { type: Object, required: true },
    currency: { type: Object, required: true },
});

const emit = defineEmits({
    'add-item': (id) => Number.isFinite(Number(id)),
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
    grid-template-columns: repeat(auto-fill, minmax(128px, 1fr));
    gap: .6rem;
}
.empty-grid {
    grid-column: 1 / -1;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: .3rem;
    padding: 2.5rem 1rem;
    color: #9ca3af;
    text-align: center;
}
.empty-grid i { font-size: 1.8rem; }
.empty-grid strong { color: #6b7280; }
.empty-grid span { font-size: 13px; }
</style>
