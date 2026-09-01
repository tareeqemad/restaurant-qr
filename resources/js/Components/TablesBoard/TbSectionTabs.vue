<script setup>
/**
 * The ONE navigation control: قسمي / sections / كل الصالة as a single tab
 * strip. The zone identical to "قسمي" is skipped (two tabs showing the
 * same 15 tables is a choice with no difference).
 */
import { nextTick, onMounted, ref, watch } from 'vue';

const props = defineProps({
    tabs: { type: Object, required: true },
    view: { type: String, required: true },
    rosterUrl: { type: String, default: null },
});

defineEmits(['set-view']);

const skipZone = (z) => props.tabs.showsMineTab
    && props.tabs.myZoneIds.length === 1
    && props.tabs.myZoneIds[0] === z.id;

const tablist = ref(null);
const revealActiveTab = () => nextTick(() => {
    tablist.value?.querySelector('[role="tab"][aria-selected="true"]')
        ?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
});

onMounted(revealActiveTab);
watch(() => props.view, revealActiveTab);
</script>

<template>
    <div ref="tablist" class="tb-tabs" role="tablist" aria-label="أقسام الصالة">
        <button v-if="tabs.showsMineTab" type="button" role="tab"
                :aria-selected="view === 'mine'"
                class="tb-tab tb-tab--mine" :class="{ 'is-active': view === 'mine' }"
                @click="$emit('set-view', 'mine')">
            <i class="bi bi-person-badge"></i>
            <span class="tb-tab-label">
                قسمي
                <small>{{ tabs.myZoneLabels.join(' · ') }}<template v-if="tabs.rosterCarried"> · توزيع سابق</template></small>
            </span>
            <span class="tb-tab-count">{{ tabs.mineCount }}</span>
        </button>

        <button v-for="z in tabs.sections" v-show="! skipZone(z)" :key="z.id" type="button" role="tab"
                :aria-selected="view === String(z.id)"
                class="tb-tab" :class="{ 'is-active': view === String(z.id) }"
                :style="{ '--sec-color': z.color }"
                @click="$emit('set-view', String(z.id))">
            <span class="tb-tab-dot"></span>
            <span class="tb-tab-label">{{ z.label }}</span>
            <span class="tb-tab-count">{{ z.count }}</span>
        </button>

        <button type="button" role="tab" :aria-selected="view === 'all'"
                class="tb-tab" :class="{ 'is-active': view === 'all' }"
                @click="$emit('set-view', 'all')">
            <i class="bi bi-grid-3x3-gap-fill"></i>
            <span class="tb-tab-label">كل الصالة</span>
            <span class="tb-tab-count">{{ tabs.allCount }}</span>
        </button>

        <a v-if="tabs.canManageRoster && rosterUrl" :href="rosterUrl" class="tb-tabs-manage" title="توزيع الأقسام">
            <i class="bi bi-people-fill"></i>
            التوزيع
        </a>
    </div>
</template>
