<script setup>
/**
 * KPI strip — the Vue twin of <x-admin.stat-rail>. Same classes, same
 * stats array shape:
 *   [{ label, value, icon: 'bi-…', color: 'accent'|'primary'|'success'|…,
 *      trend: '+12%'|null, link: url|null }]
 */
defineProps({
    stats: { type: Array, default: () => [] },
});

const trendDown = (t) => String(t).startsWith('-');
const trimTrend = (t) => String(t).replace(/^[+-]/, '');
</script>

<template>
    <div class="stat-rail">
        <component :is="s.link ? 'a' : 'div'"
                   v-for="(s, i) in stats" :key="i"
                   class="stat-rail-item" :class="`stat-rail--${s.color ?? 'primary'}`"
                   :href="s.link || null">
            <div class="stat-rail-icon">
                <i class="bi" :class="s.icon ?? 'bi-bar-chart'"></i>
            </div>
            <div class="stat-rail-body">
                <div class="stat-rail-label">{{ s.label }}</div>
                <div class="stat-rail-value">{{ s.value }}</div>
                <div v-if="s.trend" class="stat-rail-trend" :class="trendDown(s.trend) ? 'stat-rail-trend--down' : 'stat-rail-trend--up'">
                    <i class="bi" :class="trendDown(s.trend) ? 'bi-arrow-down' : 'bi-arrow-up'"></i>
                    {{ trimTrend(s.trend) }}
                </div>
            </div>
        </component>

        <slot />
    </div>
</template>
