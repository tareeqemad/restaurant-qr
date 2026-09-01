<script setup>
defineProps({
    record: { type: Object, required: true },
});

defineEmits({
    edit: (record) => Boolean(record),
});
</script>

<template>
    <article class="attendance-record" :class="[`is-${record.status.key}`, { 'is-long': record.longOpen }]">
        <span class="record-accent" aria-hidden="true"></span>

        <header class="record-head">
            <div class="employee">
                <span class="avatar">{{ record.employee.name.trim().charAt(0) }}</span>
                <span class="employee-copy">
                    <strong>{{ record.employee.name }}</strong>
                    <small v-if="record.employee.username">@{{ record.employee.username }}</small>
                </span>
            </div>

            <span class="status-pill" :class="`is-${record.status.tone}`">
                <i :class="record.status.key === 'open' ? 'bi bi-broadcast-pin' : record.status.key === 'review' ? 'bi bi-exclamation-triangle-fill' : 'bi bi-check2-circle'"></i>
                {{ record.status.label }}
            </span>
        </header>

        <div v-if="record.needsReview" class="review-message">
            <i class="bi bi-shield-exclamation"></i>
            <span><strong>لم تُحتسب ساعات هذا السجل</strong><small>أدخل وقت الانصراف الفعلي ليصبح جاهزاً لكشف الرواتب.</small></span>
        </div>

        <div v-else-if="record.longOpen" class="long-message">
            <i class="bi bi-hourglass-split"></i>
            <span>السجل مفتوح منذ أكثر من 12 ساعة — راجعه قبل نهاية اليوم.</span>
        </div>

        <div class="time-grid">
            <span><small>اليوم</small><strong>{{ record.date }}</strong></span>
            <span><small>الحضور</small><strong>{{ record.clockIn }}</strong></span>
            <span><small>الانصراف</small><strong v-if="record.open" class="live-value"><i></i> مستمر</strong><strong v-else>{{ record.clockOut }}</strong></span>
            <span class="duration"><small>صافي العمل</small><strong>{{ record.duration }}</strong></span>
        </div>

        <p v-if="record.notes" class="record-notes"><i class="bi bi-chat-left-text"></i><span>{{ record.notes }}</span></p>

        <footer class="record-footer">
            <div class="record-meta">
                <span><i class="bi bi-fingerprint"></i>{{ record.source }}</span>
                <span v-if="record.breakMinutes"><i class="bi bi-cup-hot"></i>{{ record.breakMinutes }} د استراحة</span>
                <span v-if="record.editedBy"><i class="bi bi-pencil-square"></i>{{ record.editedBy }}</span>
                <span v-if="record.branch" class="branch" :style="{ '--branch-hue': record.branch.hue }"><i class="bi bi-building"></i>{{ record.branch.name }}</span>
            </div>

            <button v-if="record.can.update" type="button" class="edit-button" @click="$emit('edit', record)">
                <i class="bi bi-pencil"></i>
                <span>{{ record.needsReview ? 'مراجعة الآن' : 'تصحيح' }}</span>
            </button>
        </footer>
    </article>
</template>

<style scoped>
.attendance-record { position:relative; display:grid; gap:.72rem; padding:1rem; overflow:hidden; border:1px solid #dfe8e2; border-radius:16px; background:#fff; box-shadow:0 7px 22px rgba(26,55,38,.045); }
.attendance-record.is-open { border-color:#add7bd; background:linear-gradient(135deg,#fff 35%,#f1faf5); }
.attendance-record.is-review { border-color:#edcf98; background:linear-gradient(135deg,#fff 35%,#fffaf0); }
.record-accent { position:absolute; inset-block:0; inset-inline-start:0; width:4px; background:#9aa8a0; }
.is-open .record-accent { background:rgb(var(--primary-rgb, 22 115 67)); }
.is-review .record-accent { background:#d98b16; }
.record-head,.record-footer,.employee,.record-meta,.edit-button,.status-pill,.review-message,.long-message,.record-notes { display:flex; align-items:center; }
.record-head { justify-content:space-between; gap:.75rem; }
.employee { min-width:0; gap:.62rem; }
.avatar { display:grid; flex:0 0 42px; height:42px; place-items:center; border-radius:13px; background:#e9f4ed; color:rgb(var(--primary-rgb, 22 115 67)); font-size:1rem; font-weight:900; }
.employee-copy { display:grid; min-width:0; }
.employee-copy strong { overflow:hidden; color:#1c3528; font-size:.88rem; font-weight:900; text-overflow:ellipsis; white-space:nowrap; }
.employee-copy small { color:#8a9890; font-size:.61rem; }
.status-pill { gap:.34rem; padding:.32rem .58rem; border-radius:999px; font-size:.62rem; font-weight:850; white-space:nowrap; }
.status-pill.is-success { background:#e8f7ed; color:#0d7540; }
.status-pill.is-warning { background:#fff0d3; color:#9a5c00; }
.status-pill.is-muted { background:#f0f3f1; color:#68776f; }
.review-message,.long-message { gap:.55rem; padding:.62rem .7rem; border-radius:11px; background:#fff4de; color:#925900; }
.review-message > i,.long-message > i { font-size:1rem; }
.review-message span { display:grid; }
.review-message strong { font-size:.68rem; }
.review-message small { color:#a77a37; font-size:.58rem; }
.long-message { background:#fff8e9; font-size:.64rem; }
.time-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:.42rem; }
.time-grid > span { display:grid; gap:.15rem; min-height:54px; align-content:center; padding:.48rem .58rem; border-radius:10px; background:#f5f8f6; }
.time-grid small { color:#87948d; font-size:.56rem; }
.time-grid strong { color:#2a4135; font-size:.72rem; font-weight:850; }
.time-grid .duration strong { color:rgb(var(--primary-rgb, 22 115 67)); }
.live-value { display:flex; align-items:center; gap:.32rem; color:#0b7a40 !important; }
.live-value i { width:7px; height:7px; border-radius:50%; background:#13a457; box-shadow:0 0 0 4px rgba(19,164,87,.12); }
.record-notes { align-items:flex-start; gap:.4rem; margin:0; padding:.58rem .65rem; border-radius:10px; background:#fafcfb; color:#6f7f76; font-size:.62rem; line-height:1.75; }
.record-notes span { white-space:pre-line; }
.record-footer { justify-content:space-between; gap:.65rem; padding-top:.12rem; }
.record-meta { flex-wrap:wrap; gap:.38rem .7rem; color:#7e8c84; font-size:.58rem; }
.record-meta span { display:inline-flex; align-items:center; gap:.25rem; }
.record-meta .branch { padding:.16rem .4rem; border-radius:6px; background:hsl(var(--branch-hue) 55% 95%); color:hsl(var(--branch-hue) 42% 32%); }
.edit-button { min-height:44px; justify-content:center; gap:.38rem; padding:0 .78rem; border:1px solid #c7ddcf; border-radius:11px; background:#eff8f2; color:rgb(var(--primary-rgb, 22 115 67)); font-size:.65rem; font-weight:850; }
.is-review .edit-button { border-color:#e6c47e; background:#fff4dc; color:#955900; }
@media (max-width:640px) {
    .attendance-record { padding:.82rem; }
    .time-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    .record-footer { align-items:stretch; flex-direction:column; }
    .edit-button { width:100%; }
}
@media (prefers-reduced-motion:reduce) { * { scroll-behavior:auto !important; } }
</style>
