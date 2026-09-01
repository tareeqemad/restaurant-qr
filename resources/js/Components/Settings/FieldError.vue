<script setup>
defineProps({ message: { type: String, default: '' } })
</script>
<template><small v-if="message" class="field-error">{{ message }}</small></template>
<style scoped>.field-error { display: block; margin-top: 5px; color: #c42828; font-size: .75rem; }</style>
