<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import DialogSurface from '../Ui/DialogSurface.vue';

const props = defineProps({ attendance: { type: Object, required: true } });

const now = ref(Date.now());
const sheetOpen = ref(false);
const busy = ref(false);
let timer = null;

const duration = computed(() => {
    const open = props.attendance.open;
    if (!open) return '';
    const start = new Date(open.since).getTime();
    if (Number.isNaN(start)) return open.label ?? '';
    const minutes = Math.max(0, Math.floor((now.value - start) / 60000) - Number(open.breakMinutes || 0));
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;
    if (!hours) return `${rest} د`;
    return rest ? `${hours} س ${rest} د` : `${hours} س`;
});

const startedAt = computed(() => {
    const date = new Date(props.attendance.open?.since);
    return Number.isNaN(date.getTime()) ? '—' : date.toLocaleTimeString('ar', { hour: '2-digit', minute: '2-digit' });
});

function submit(url, afterSuccess = null) {
    if (!url || busy.value) return;
    busy.value = true;
    router.post(url, {}, {
        preserveScroll: true,
        preserveState: true,
        only: ['shell', 'flash'],
        onSuccess: () => { sheetOpen.value = false; afterSuccess?.(); },
        onFinish: () => { busy.value = false; },
    });
}

function clockIn() {
    submit(props.attendance.clockInUrl);
}

function clockOut() {
    submit(props.attendance.clockOutUrl);
}

function closeSheet() {
    if (!busy.value) sheetOpen.value = false;
}

watch(() => props.attendance.open, (open) => {
    if (!open) sheetOpen.value = false;
});

onMounted(() => {
    timer = setInterval(() => { now.value = Date.now(); }, 30000);

});
onBeforeUnmount(() => {
    clearInterval(timer);

});
</script>

<template>
    <div class="header-element attendance-pill">
        <button v-if="attendance.open" type="button" class="attendance-button is-active" :disabled="busy"
                aria-haspopup="dialog" :aria-expanded="sheetOpen" @click="sheetOpen = true">
            <span class="state-icon"><i></i></span>
            <span class="button-copy"><small>دوامك مستمر</small><strong>{{ duration }}</strong></span>
            <i class="bi bi-chevron-down chevron"></i>
        </button>

        <button v-else type="button" class="attendance-button" :disabled="busy" @click="clockIn">
            <span class="state-icon"><i class="bi bi-box-arrow-in-left"></i></span>
            <span class="button-copy"><small>الحضور</small><strong>{{ busy ? 'جارٍ التسجيل…' : 'ابدأ الدوام' }}</strong></span>
        </button>
    </div>

    <DialogSurface :open="sheetOpen && Boolean(attendance.open)" title-id="attendance-sheet-title" max-width="460px"
                   :close-on-backdrop="!busy" :close-on-escape="!busy" @close="closeSheet">
                <section v-if="attendance.open" class="attendance-sheet">
                    <header>
                        <span class="sheet-icon"><i class="bi bi-person-check-fill"></i></span>
                        <div><small>حضورك مسجّل</small><h2 id="attendance-sheet-title">أنت على رأس العمل</h2></div>
                        <button type="button" aria-label="إغلاق" @click="closeSheet"><i class="bi bi-x-lg"></i></button>
                    </header>

                    <div class="shift-facts">
                        <span><small>بدأت الساعة</small><strong>{{ startedAt }}</strong></span>
                        <span><small>المدة حتى الآن</small><strong>{{ duration }}</strong></span>
                        <span v-if="attendance.open.branch"><small>الفرع</small><strong>{{ attendance.open.branch }}</strong></span>
                    </div>

                    <p><i class="bi bi-info-circle"></i>أنهِ دوامك عند المغادرة.</p>

                    <footer>
                        <button type="button" class="continue-button" @click="closeSheet">متابعة العمل</button>
                        <button type="button" class="checkout-button" :disabled="busy" @click="clockOut">
                            <span v-if="busy" class="spinner-border spinner-border-sm"></span><i v-else class="bi bi-box-arrow-right"></i>
                            {{ busy ? 'جارٍ الإنهاء…' : 'إنهاء الدوام' }}
                        </button>
                    </footer>
                </section>
    </DialogSurface>
</template>

<style scoped>
.attendance-pill { display: inline-flex; }
.attendance-button { display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 5px 10px; border: 1px solid var(--line, #dfd7cb); border-radius: 8px; background: var(--surface, #fffdfa); color: var(--ink, #25343a); font: inherit; white-space: nowrap; }
.attendance-button:hover { background: var(--surface-muted, #efe9e0); }
.attendance-button.is-active { border-color: #a3c6b6; background: #edf7f1; color: #286951; }
.attendance-button:disabled { opacity: .6; cursor: wait; }
.state-icon { display: grid; place-items: center; width: 30px; height: 30px; border-radius: 6px; background: var(--surface-muted, #efe9e0); font-size: 16px; }
.is-active .state-icon { background: #dcefe4; }
.is-active .state-icon > i:empty { width: 8px; height: 8px; border-radius: 50%; background: #286951; }
.button-copy { display: grid; gap: 0; text-align: start; }
.button-copy small { font-size: 12px; color: var(--muted, #626b6d); }
.button-copy strong { font-size: 14px; font-weight: 700; }
.chevron { font-size: 10px; }
.attendance-sheet { width: 100%; background: var(--surface, #fffdfa); color: var(--ink, #25343a); }
.attendance-sheet header { display: flex; align-items: center; gap: 12px; padding: 22px; border-bottom: 1px solid var(--line, #dfd7cb); }
.sheet-icon { display: grid; place-items: center; flex: 0 0 44px; height: 44px; border-radius: 10px; background: var(--brand-soft, #f3e3d8); color: var(--brand, #a3422b); font-size: 21px; }
.attendance-sheet header > div { flex: 1; }
.attendance-sheet header small { font-size: 13px; color: var(--muted, #626b6d); }
.attendance-sheet h2 { margin: 3px 0 0; font-size: 20px; font-weight: 800; }
.attendance-sheet header button { width: 40px; height: 40px; border: 1px solid var(--line, #dfd7cb); border-radius: 8px; background: var(--surface, #fffdfa); color: var(--ink, #25343a); }
.shift-facts { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 10px; padding: 22px; }
.shift-facts > span { display: grid; gap: 6px; padding: 14px; border: 1px solid var(--line, #dfd7cb); border-radius: 9px; }
.shift-facts > span:last-child:nth-child(3) { grid-column: 1 / -1; }
.shift-facts small { color: var(--muted, #626b6d); font-size: 13px; }
.shift-facts strong { color: var(--ink, #25343a); font-size: 17px; }
.attendance-sheet > p { display: flex; align-items: center; gap: 8px; margin: 0 22px 22px; color: var(--muted, #626b6d); font-size: 14px; }
.attendance-sheet footer { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; padding: 18px 22px; border-top: 1px solid var(--line, #dfd7cb); }
.attendance-sheet footer button { min-height: 46px; border: 1px solid var(--line, #dfd7cb); border-radius: 8px; font: inherit; font-size: 14px; font-weight: 700; }
.continue-button { background: var(--surface, #fffdfa); color: var(--ink, #25343a); }
.attendance-sheet .checkout-button { background: var(--brand, #a3422b); color: white; border-color: var(--brand, #a3422b); }
.checkout-button:disabled { opacity: .6; cursor: wait; }
@media(max-width:760px) { .button-copy small,.chevron { display: none; } .attendance-button { padding: 4px 8px; } .state-icon { width: 24px; height: 24px; } }
</style>
