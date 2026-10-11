<script setup>
import { Link } from '@inertiajs/vue3';
defineProps({ stats: { type: Array, default: () => [] } });
const trendDown = (value) => String(value).startsWith('-');
const trimTrend = (value) => String(value).replace(/^[+-]/, '');
</script>

<template>
    <div class="stat-rail sarw-stat-rail">
        <component :is="s.link ? Link : 'div'" v-for="(s, i) in stats" :key="i" class="stat-rail-item"
                   :class="`stat-rail--${s.color ?? 'primary'}`" :href="s.link || undefined">
            <div class="stat-rail-body"><div class="stat-rail-label">{{ s.label }}</div><div class="stat-rail-value">{{ s.value }}</div>
                <div v-if="s.trend" class="stat-rail-trend" :class="trendDown(s.trend) ? 'stat-rail-trend--down' : 'stat-rail-trend--up'">
                    <i class="bi" :class="trendDown(s.trend) ? 'bi-arrow-down' : 'bi-arrow-up'" aria-hidden="true"></i>{{ trimTrend(s.trend) }}
                </div>
            </div>
            <span class="stat-rail-icon" aria-hidden="true"><i class="bi" :class="s.icon ?? 'bi-bar-chart'"></i></span>
        </component><slot />
    </div>
</template>
