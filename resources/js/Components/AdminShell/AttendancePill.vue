<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';

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

function keydown(event) {
    if (event.key === 'Escape') closeSheet();
}

watch(() => props.attendance.open, (open) => {
    if (!open) sheetOpen.value = false;
});
watch(sheetOpen, (open) => { document.body.style.overflow = open ? 'hidden' : ''; });
onMounted(() => {
    timer = setInterval(() => { now.value = Date.now(); }, 30000);
    window.addEventListener('keydown', keydown);
});
onBeforeUnmount(() => {
    clearInterval(timer);
    window.removeEventListener('keydown', keydown);
    document.body.style.overflow = '';
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

    <Teleport to="body">
        <Transition name="attendance-popover">
            <div v-if="sheetOpen && attendance.open" class="attendance-backdrop" @click.self="closeSheet">
                <section class="attendance-sheet" role="dialog" aria-modal="true" aria-labelledby="attendance-sheet-title">
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

                    <p><i class="bi bi-info-circle"></i>أنهِ دوامك عند المغادرة فقط. سيظهر السجل للمدير فوراً دون تحديث الصفحة.</p>

                    <footer>
                        <button type="button" class="continue-button" @click="closeSheet">متابعة العمل</button>
                        <button type="button" class="checkout-button" :disabled="busy" @click="clockOut">
                            <span v-if="busy" class="spinner-border spinner-border-sm"></span><i v-else class="bi bi-box-arrow-right"></i>
                            {{ busy ? 'جارٍ الإنهاء…' : 'إنهاء الدوام' }}
                        </button>
                    </footer>
                </section>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.attendance-pill{display:inline-flex;margin-inline-end:.35rem}.attendance-button{position:relative;display:inline-flex;min-height:44px;align-items:center;gap:.48rem;padding:.25rem .72rem;border:1px solid rgba(var(--primary-rgb,22 115 67),.28);border-radius:12px;background:rgba(var(--primary-rgb,22 115 67),.08);color:rgb(var(--primary-rgb,22 115 67));font:inherit;white-space:nowrap}.attendance-button:hover{background:rgba(var(--primary-rgb,22 115 67),.13)}.attendance-button:disabled{opacity:.58;cursor:wait}.attendance-button.is-active{border-color:rgba(var(--primary-rgb,22 115 67),.36);background:rgb(var(--primary-rgb,22 115 67));color:#fff;box-shadow:0 6px 16px rgba(var(--primary-rgb,22 115 67),.18)}.state-icon{display:grid;width:30px;height:30px;place-items:center;border-radius:9px;background:rgba(var(--primary-rgb,22 115 67),.1);font-size:.84rem}.is-active .state-icon{background:rgba(255,255,255,.16)}.state-icon>i:empty{width:8px;height:8px;border-radius:50%;background:#fff;box-shadow:0 0 0 4px rgba(255,255,255,.16);animation:attendancePulse 1.8s ease-out infinite}.button-copy{display:grid;gap:.08rem;text-align:start}.button-copy small{font-size:.5rem;font-weight:720;opacity:.74}.button-copy strong{font-size:.7rem;font-weight:900}.chevron{margin-inline-start:.1rem;font-size:.55rem;opacity:.75}
.attendance-backdrop{position:fixed;inset:0;z-index:19000;display:grid;place-items:center;padding:1rem;background:rgba(12,28,19,.48);backdrop-filter:blur(3px)}.attendance-sheet{width:min(440px,100%);overflow:hidden;border:1px solid rgba(255,255,255,.72);border-radius:19px;background:#fff;box-shadow:0 28px 80px rgba(8,29,17,.25)}.attendance-sheet header{display:flex;align-items:center;gap:.65rem;padding:1rem;border-bottom:1px solid #e6ece8}.sheet-icon{display:grid;flex:0 0 44px;height:44px;place-items:center;border-radius:13px;background:#e9f5ed;color:rgb(var(--primary-rgb,22 115 67));font-size:1rem}.attendance-sheet header>div{display:grid;flex:1}.attendance-sheet header small{color:#7a8a81;font-size:.56rem}.attendance-sheet h2{margin:.08rem 0 0;color:#183526;font-size:.96rem;font-weight:900}.attendance-sheet header button{display:grid;flex:0 0 44px;height:44px;place-items:center;border:0;border-radius:11px;background:#f1f4f2;color:#5f7066}.shift-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.45rem;padding:1rem 1rem .7rem}.shift-facts span{display:grid;gap:.14rem;min-height:62px;align-content:center;padding:.48rem;border-radius:10px;background:#f4f8f5}.shift-facts small{color:#849189;font-size:.53rem}.shift-facts strong{overflow:hidden;color:#284437;font-size:.68rem;text-overflow:ellipsis;white-space:nowrap}.attendance-sheet>p{display:flex;gap:.4rem;margin:0 1rem .9rem;padding:.6rem;border-radius:10px;background:#f8faf9;color:#6d7d74;font-size:.58rem;line-height:1.7}.attendance-sheet footer{display:grid;grid-template-columns:1fr 1.2fr;gap:.5rem;padding:.8rem 1rem max(.8rem,env(safe-area-inset-bottom));border-top:1px solid #e6ece8}.attendance-sheet footer button{min-height:46px;border:0;border-radius:11px;font-size:.65rem;font-weight:850}.continue-button{background:#f0f4f1;color:#52665b}.checkout-button{background:#9d3f37;color:#fff}.checkout-button:disabled{opacity:.58;cursor:wait}
.attendance-popover-enter-active,.attendance-popover-leave-active{transition:opacity .16s ease}.attendance-popover-enter-active .attendance-sheet,.attendance-popover-leave-active .attendance-sheet{transition:transform .16s ease}.attendance-popover-enter-from,.attendance-popover-leave-to{opacity:0}.attendance-popover-enter-from .attendance-sheet,.attendance-popover-leave-to .attendance-sheet{transform:translateY(10px) scale(.985)}@keyframes attendancePulse{0%{box-shadow:0 0 0 0 rgba(255,255,255,.45)}100%{box-shadow:0 0 0 8px rgba(255,255,255,0)}}
@media(max-width:768px){.attendance-button{padding:.2rem .42rem}.button-copy small,.chevron{display:none}.state-icon{width:28px;height:28px}.button-copy strong{font-size:.64rem}}@media(max-width:480px){.attendance-backdrop{align-items:end;padding:0}.attendance-sheet{width:100%;border-radius:20px 20px 0 0}.shift-facts{grid-template-columns:repeat(2,minmax(0,1fr))}.shift-facts span:last-child{grid-column:1/-1}}@media(prefers-reduced-motion:reduce){.state-icon>i:empty{animation:none}.attendance-popover-enter-active,.attendance-popover-leave-active,.attendance-popover-enter-active .attendance-sheet,.attendance-popover-leave-active .attendance-sheet{transition:none}}
</style>
