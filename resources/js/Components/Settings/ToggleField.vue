<script setup>
const props = defineProps({
    modelValue: { type: Boolean, default: false },
    label: { type: String, required: true },
    description: { type: String, default: '' },
    icon: { type: String, default: '' },
    disabled: { type: Boolean, default: false },
    warning: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])
function toggle() { if (!props.disabled) emit('update:modelValue', !props.modelValue) }
</script>

<template>
    <button type="button" class="toggle-field" :class="{ 'is-on': modelValue, 'is-disabled': disabled }" :disabled="disabled" :aria-pressed="modelValue" @click="toggle">
        <span v-if="icon" class="toggle-field__icon" aria-hidden="true"><i class="bi" :class="icon"></i></span>
        <span class="toggle-field__copy">
            <strong>{{ label }}</strong>
            <small v-if="description">{{ description }}</small>
            <em v-if="warning">{{ warning }}</em>
        </span>
        <span class="toggle-field__switch" aria-hidden="true"><span /></span>
    </button>
</template>

<style scoped>
.toggle-field { display: flex; width: 100%; min-height: 78px; align-items: center; gap: 12px; padding: 13px 14px; border: 1px solid #dfe7e2; border-radius: 14px; color: #27372e; background: #fff; text-align: start; transition: border-color .18s ease, background .18s ease, transform .18s ease; }
.toggle-field:hover:not(:disabled) { border-color: #9fc5ac; transform: translateY(-1px); }
.toggle-field.is-on { border-color: #a5d2b3; background: #f3faf5; }
.toggle-field__icon { display: grid; flex: 0 0 36px; width: 36px; height: 36px; place-items: center; border-radius: 11px; background: #f0f4f1; }
.is-on .toggle-field__icon { color: #166534; background: #dff2e5; }
.toggle-field__copy { display: grid; flex: 1; gap: 2px; }
.toggle-field__copy strong { font-size: .91rem; }
.toggle-field__copy small { color: #7a877f; font-size: .76rem; line-height: 1.5; }
.toggle-field__copy em { margin-top: 4px; color: #ad5c00; font-size: .72rem; font-style: normal; }
.toggle-field__switch { display: flex; flex: 0 0 42px; width: 42px; height: 24px; align-items: center; justify-content: flex-start; padding: 3px; border-radius: 999px; background: #c8d0cb; transition: background .18s ease; }
.toggle-field__switch span { width: 18px; height: 18px; border-radius: 50%; background: #fff; box-shadow: 0 1px 4px rgba(0, 0, 0, .16); transition: transform .18s ease; }
.is-on .toggle-field__switch { background: #17713c; }
.is-on .toggle-field__switch { justify-content: flex-end; }
.toggle-field.is-disabled { cursor: not-allowed; opacity: .62; }
</style>
