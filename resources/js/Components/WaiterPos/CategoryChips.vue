<script setup>
const props = defineProps({
    modelValue: { type: [String, Number], required: true },
    categories: { type: Array, required: true },
});

const emit = defineEmits({
    'update:modelValue': (value) => typeof value === 'string' || Number.isFinite(value),
});

function selectCategory(id) {
    emit('update:modelValue', id === '' ? '' : String(id));
}
</script>

<template>
    <div class="category-chips" role="group" aria-label="تصنيفات المنيو">
        <button
            type="button"
            class="category-chip"
            :class="{ 'is-active': modelValue === '' }"
            :aria-pressed="modelValue === ''"
            @click="selectCategory('')"
        >
            الكل
        </button>
        <button
            v-for="category in categories"
            :key="category.id"
            type="button"
            class="category-chip"
            :class="{ 'is-active': String(modelValue) === String(category.id) }"
            :aria-pressed="String(modelValue) === String(category.id)"
            @click="selectCategory(category.id)"
        >
            {{ category.name }}
            <span>{{ category.count }}</span>
        </button>
    </div>
</template>

<style scoped>
.category-chips {
    display: flex;
    gap: .4rem;
    padding-bottom: .2rem;
    overflow-x: auto;
    scrollbar-width: thin;
    -webkit-overflow-scrolling: touch;
}
.category-chip {
    display: inline-flex;
    flex: 0 0 auto;
    align-items: center;
    gap: .35rem;
    min-height: 44px;
    padding: .35rem .85rem;
    border: 1px solid rgba(15, 71, 49, .18);
    border-radius: 999px;
    color: #2f4f3f;
    background: #fff;
    font-size: 13px;
    font-weight: 600;
    white-space: nowrap;
    cursor: pointer;
    transition: color .12s ease, border-color .12s ease, background-color .12s ease;
}
.category-chip:hover { border-color: rgba(15, 71, 49, .4); }
.category-chip.is-active { border-color: var(--wp-primary-dark); color: #fff; background: var(--wp-primary-dark); }
.category-chip span {
    padding-inline: .4rem;
    border-radius: 999px;
    background: rgba(0, 0, 0, .08);
    font-size: 11px;
    font-weight: 700;
}
.category-chip.is-active span { background: rgba(255, 255, 255, .22); }
@media (prefers-reduced-motion: reduce) {
    .category-chip { transition: none; }
}
</style>
