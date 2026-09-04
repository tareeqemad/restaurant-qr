<script setup>
import { computed } from 'vue';

const props = defineProps({
    errors: { type: Object, default: () => ({}) },
    title: { type: String, default: 'راجع البيانات قبل الحفظ' },
});

const messages = computed(() => {
    const flattened = Object.values(props.errors ?? {}).flatMap((value) => Array.isArray(value) ? value : [value]);
    return [...new Set(flattened.filter(Boolean).map(String))];
});
</script>

<template>
    <aside v-if="messages.length" class="form-error-summary" role="alert" aria-live="assertive">
        <span class="form-error-summary__icon" aria-hidden="true">
            <i class="bi bi-exclamation-triangle-fill"></i>
        </span>
        <div>
            <strong>{{ title }}</strong>
            <ul>
                <li v-for="message in messages.slice(0, 5)" :key="message">{{ message }}</li>
            </ul>
            <small v-if="messages.length > 5">وهناك {{ messages.length - 5 }} ملاحظات إضافية.</small>
        </div>
    </aside>
</template>

<style scoped>
.form-error-summary {
    display: grid;
    grid-template-columns: 38px minmax(0, 1fr);
    gap: .7rem;
    margin-block: .75rem;
    padding: .75rem .85rem;
    border: 1px solid #fecaca;
    border-radius: 12px;
    color: #991b1b;
    background: #fff7f7;
}
.form-error-summary__icon {
    display: grid;
    width: 38px;
    height: 38px;
    place-items: center;
    border-radius: 10px;
    color: #b91c1c;
    background: #fee2e2;
}
.form-error-summary strong { display: block; font-size: .82rem; }
.form-error-summary ul { display: grid; gap: .2rem; margin: .3rem 0 0; padding-inline-start: 1.1rem; }
.form-error-summary li,
.form-error-summary small { font-size: .7rem; line-height: 1.55; }
@media (max-width: 540px) {
    .form-error-summary { grid-template-columns: 32px minmax(0, 1fr); padding: .65rem; }
    .form-error-summary__icon { width: 32px; height: 32px; }
}
</style>
