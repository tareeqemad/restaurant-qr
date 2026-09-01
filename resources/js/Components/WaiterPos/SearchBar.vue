<script setup>
const props = defineProps({
    modelValue: { type: String, required: true },
});

const emit = defineEmits({
    'update:modelValue': (value) => typeof value === 'string',
});
</script>

<template>
    <div class="search-bar">
        <i class="bi bi-search" aria-hidden="true"></i>
        <label class="visually-hidden" for="waiter-menu-search">ابحث في المنيو</label>
        <input
            id="waiter-menu-search"
            type="search"
            :value="props.modelValue"
            placeholder="ابحث عن صنف..."
            autocomplete="off"
            inputmode="search"
            @input="emit('update:modelValue', $event.target.value)"
        >
        <button
            v-if="props.modelValue"
            type="button"
            class="clear-search"
            aria-label="مسح البحث"
            @click="emit('update:modelValue', '')"
        >
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
</template>

<style scoped>
.search-bar { position: relative; margin-bottom: .55rem; }
.search-bar input {
    width: 100%;
    min-height: 46px;
    box-sizing: border-box;
    padding: .62rem 2.55rem;
    border: 1px solid rgba(15, 71, 49, .18);
    border-radius: 10px;
    color: #1f2937;
    background: #fff;
    font-size: 14px;
}
.search-bar input:focus {
    outline: none;
    border-color: var(--wp-primary);
    box-shadow: 0 0 0 3px rgba(22, 101, 52, .12);
}
.search-bar > .bi-search {
    position: absolute;
    inset-inline-start: .85rem;
    top: 50%;
    z-index: 1;
    color: #9ca3af;
    transform: translateY(-50%);
    pointer-events: none;
}
.clear-search {
    position: absolute;
    inset-inline-end: .1rem;
    top: 50%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 44px;
    height: 44px;
    border: 0;
    border-radius: 9px;
    color: #64748b;
    background: transparent;
    transform: translateY(-50%);
    cursor: pointer;
}
.clear-search:hover { color: #1f2937; background: #f1f5f9; }
.visually-hidden {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}
</style>
