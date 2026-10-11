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
    border: 1px solid color-mix(in srgb, var(--brand) 18%, transparent);
    border-radius: 8px;
    color: var(--ink);
    background: var(--surface);
    font-size: 14px;
}
.search-bar input:focus {
    outline: none;
    border-color: var(--wp-primary);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand) 12%, transparent);
}
.search-bar > .bi-search {
    position: absolute;
    inset-inline-start: .85rem;
    top: 50%;
    z-index: 1;
    color: var(--muted);
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
    border-radius: 8px;
    color: #315B6B;
    background: transparent;
    transform: translateY(-50%);
    cursor: pointer;
}
.clear-search:hover { color: var(--ink); background: var(--canvas); }
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

/* Task-specific component layout. */
.search-bar{border-radius:8px;background:var(--surface);border:1px solid var(--line);min-height:50px}.search-bar input{height:50px;font-size:16px;padding-inline:42px}.clear-search{width:42px;height:42px;border-radius:6px}.search-bar>i{font-size:18px}
</style>
