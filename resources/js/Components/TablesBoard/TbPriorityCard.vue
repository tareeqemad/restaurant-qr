<script setup>
/**
 * One compact waiter task. The server owns priority and the allowed action;
 * this component makes the decision quick to scan and safe to tap.
 */
import TbFeedAction from './TbFeedAction.vue';

defineProps({
    row: { type: Object, required: true },
    busy: { type: Boolean, default: false },
});

defineEmits(['serve', 'ack', 'clean', 'close']);
</script>

<template>
    <article class="tb-priority-card" :class="`tb-urg-${row.triage.urgency}`" role="listitem">
        <div class="tb-priority-table">
            <small>طاولة</small>
            <strong>{{ row.number }}</strong>
        </div>

        <div class="tb-priority-copy">
            <div class="tb-priority-label">
                <i class="bi" :class="row.triage.icon"></i>
                <span>{{ row.triage.label }}</span>
            </div>
            <div class="tb-priority-meta">
                <span v-if="row.triage.since"><i class="bi bi-clock"></i>{{ row.triage.since }}</span>
                <span v-if="row.zoneLabel">
                    <i class="tb-zdot" :style="{ '--sec-color': row.zoneColor }"></i>{{ row.zoneLabel }}
                </span>
                <span v-if="row.waiterName"><i class="bi bi-person"></i>{{ row.waiterName }}</span>
                <span v-if="row.sessionId"><i class="bi bi-layers"></i>الجلسة {{ row.counts.session }}</span>
                <span v-if="row.counts.today > 0"><i class="bi bi-receipt"></i>اليوم {{ row.counts.today }}</span>
            </div>
            <div v-if="row.readyHandoff" class="tb-ready-preview">
                <div class="tb-ready-preview-head">
                    <strong><i class="bi bi-box-arrow-up-left"></i> خذ الجاهز الآن</strong>
                    <span>{{ row.readyHandoff.pieceCount }} قطعة · {{ row.readyHandoff.stationNames.join(' + ') }}</span>
                </div>
                <div class="tb-ready-items">
                    <span v-for="item in row.readyHandoff.items" :key="item.id">
                        <b>{{ item.qty }}× {{ item.name }}</b>
                        <small>{{ item.stationName }}</small>
                    </span>
                    <em v-if="row.readyHandoff.hiddenItems">+{{ row.readyHandoff.hiddenItems }} أصناف</em>
                </div>
                <small class="tb-ready-hint">زر «تم التسليم» يؤكد هذه الأصناف فقط؛ الباقي يظل قيد التحضير.</small>
            </div>
            <div v-if="row.pendingReview" class="tb-round-preview">
                <div class="tb-round-preview-head">
                    <strong>{{ row.pendingReview.roundLabel }}</strong>
                    <span>{{ row.pendingReview.pieceCount }} قطعة · {{ row.pendingReview.lineCount }} صنف</span>
                </div>
                <div class="tb-round-items">
                    <span v-for="item in row.pendingReview.items" :key="`${item.name}-${item.qty}`">
                        <b>{{ item.qty }}× {{ item.name }}</b>
                        <small v-if="item.exclusions?.length">بدون {{ item.exclusions.join('، ') }}</small>
                        <small v-else-if="item.notes">{{ item.notes }}</small>
                    </span>
                    <em v-if="row.pendingReview.hiddenItems">+{{ row.pendingReview.hiddenItems }} أصناف</em>
                </div>
                <p v-if="row.pendingReview.notes"><i class="bi bi-chat-right-text"></i>{{ row.pendingReview.notes }}</p>
            </div>
        </div>

        <div class="tb-priority-action">
            <TbFeedAction :row="row" :busy="busy"
                          @serve="$emit('serve', $event)" @ack="$emit('ack', $event)"
                          @clean="$emit('clean', $event)" @close="$emit('close', $event)" />
        </div>
    </article>
</template>

<style scoped>
.tb-priority-card { animation: tb-card-in .2s ease-out; }
.tb-ready-preview { display: grid; gap: .35rem; margin-top: .5rem; padding: .55rem .65rem; border: 1px solid #f2b6b6; border-radius: 10px; background: #fff7f7; }
.tb-ready-preview-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
.tb-ready-preview-head strong { color: #a5222c; font-size: .72rem; }
.tb-ready-preview-head span { color: #82545a; font-size: .62rem; }
.tb-ready-items { display: flex; flex-wrap: wrap; gap: .3rem; }
.tb-ready-items > span { display: grid; gap: .1rem; padding: .3rem .45rem; border-radius: 8px; background: #fde8e9; }
.tb-ready-items b { font-size: .67rem; }
.tb-ready-items small { color: #8f3941; font-size: .58rem; }
.tb-ready-items em { align-self: center; color: #82545a; font-size: .62rem; font-style: normal; }
.tb-ready-hint { color: #715d60; font-size: .59rem; }
.tb-round-preview { display: grid; gap: .35rem; margin-top: .5rem; padding: .55rem .65rem; border: 1px solid #e3ebe6; border-radius: 10px; background: #fbfdfc; }
.tb-round-preview-head { display: flex; align-items: center; justify-content: space-between; gap: .5rem; }
.tb-round-preview-head strong { font-size: .72rem; color: #0f6d40; }
.tb-round-preview-head span { color: #77857d; font-size: .62rem; }
.tb-round-items { display: flex; flex-wrap: wrap; gap: .3rem; }
.tb-round-items > span { display: grid; gap: .1rem; padding: .3rem .45rem; border-radius: 8px; background: #f0f6f2; }
.tb-round-items b { font-size: .67rem; }
.tb-round-items small { color: #b45309; font-size: .58rem; }
.tb-round-items em { align-self: center; color: #68776f; font-size: .62rem; font-style: normal; }
.tb-round-preview p { display: flex; align-items: center; gap: .3rem; margin: 0; color: #53645b; font-size: .63rem; }
@media (prefers-reduced-motion: reduce) {
    .tb-priority-card { animation: none; }
}
</style>
