<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { serverHealth } from '../../Support/serverHealth';
const props = defineProps({
    lastSuccessfulAt: { type: [Number, String], default: null },
    failed: { type: Boolean, default: false },
    refreshing: { type: Boolean, default: false },
    staleAfterMs: { type: Number, default: 20000 },
});
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
const now = ref(Date.now());
const health = computed(() => serverHealth({ ...props, online: online.value, now: now.value }));
const updateConnection = () => { online.value = navigator.onLine; now.value = Date.now(); };
let timer;
onMounted(() => {
    timer = window.setInterval(() => { now.value = Date.now(); }, 1000);
    window.addEventListener('online', updateConnection);
    window.addEventListener('offline', updateConnection);
});
onBeforeUnmount(() => {
    window.clearInterval(timer);
    window.removeEventListener('online', updateConnection);
    window.removeEventListener('offline', updateConnection);
});
</script>
<template>
    <span class="server-health" :class="`server-health--${health.state}`" :title="health.lastSuccessLabel">
        <span class="server-health__label" role="status"><i aria-hidden="true"></i>{{ health.label }}</span>
        <span class="server-health__time">{{ health.lastSuccessLabel }}</span>
    </span>
</template>
<style scoped>
.server-health{display:inline-flex;flex-wrap:wrap;align-items:center;gap:4px 10px;color:inherit;font-size:14px;line-height:1.5}
.server-health__label{display:inline-flex;align-items:center;gap:6px}.server-health__label i{width:8px;height:8px;flex:none;border-radius:50%;background:#18814c}
.server-health--offline .server-health__label i,.server-health--failed .server-health__label i{background:#c34237}
.server-health--stale .server-health__label i,.server-health--unknown .server-health__label i{background:#b78338}
.server-health__time{font-size:13px;opacity:.85;font-variant-numeric:tabular-nums}
</style>
