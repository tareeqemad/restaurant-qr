<script setup>
defineProps({ icon: { type: String, default: 'bi-inbox' }, title: { type: String, default: null }, message: { type: String, default: 'لا توجد بيانات' }, compact: { type: Boolean, default: false } });
</script>

<template>
    <div class="empty-state sarw-empty-state" :class="{ 'empty-state--compact': compact }">
        <span class="sarw-empty-state__icon" aria-hidden="true"><i class="bi" :class="icon"></i></span>
        <div><h3 v-if="title" class="empty-state-title">{{ title }}</h3><p class="empty-state-message">{{ message }}</p></div>
        <div v-if="$slots.cta" class="empty-state-cta"><slot name="cta" /></div>
    </div>
</template>
