<script setup>
/**
 * Empty-state block — the Vue twin of <x-admin.empty-state>. Same markup,
 * so relax-components.css's .empty-state styles apply unchanged.
 */
defineProps({
    icon: { type: String, default: 'bi-inbox' },
    title: { type: String, default: null },
    message: { type: String, default: 'لا توجد بيانات' },
    compact: { type: Boolean, default: false },
});
</script>

<template>
    <div class="empty-state" :class="{ 'empty-state--compact': compact }">
        <div class="empty-state-illustration">
            <div class="empty-state-icon-ring">
                <div class="empty-state-icon">
                    <i class="bi" :class="icon"></i>
                </div>
            </div>
            <svg class="empty-state-decor" viewBox="0 0 200 200" aria-hidden="true">
                <circle cx="30" cy="100" r="2.5" fill="currentColor" opacity=".35"/>
                <circle cx="170" cy="100" r="2.5" fill="currentColor" opacity=".35"/>
                <circle cx="100" cy="30" r="2" fill="currentColor" opacity=".25"/>
                <circle cx="100" cy="170" r="2" fill="currentColor" opacity=".25"/>
                <circle cx="50" cy="50" r="1.5" fill="currentColor" opacity=".2"/>
                <circle cx="150" cy="150" r="1.5" fill="currentColor" opacity=".2"/>
                <circle cx="50" cy="150" r="1.5" fill="currentColor" opacity=".2"/>
                <circle cx="150" cy="50" r="1.5" fill="currentColor" opacity=".2"/>
            </svg>
        </div>

        <h4 v-if="title" class="empty-state-title">{{ title }}</h4>
        <p class="empty-state-message">{{ message }}</p>

        <div v-if="$slots.cta" class="empty-state-cta">
            <slot name="cta" />
        </div>
    </div>
</template>
