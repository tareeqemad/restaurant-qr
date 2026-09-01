<script setup>
import { ref, watch } from 'vue';

const props = defineProps({
    open: { type: Boolean, required: true },
    busy: { type: Boolean, default: false },
    error: { type: String, default: '' },
    errors: { type: Object, default: () => ({}) },
    result: { type: Object, default: null },
});

const emit = defineEmits({
    close: () => true,
    submit: (payload) => Boolean(payload?.name && payload?.phone),
});

const name = ref('');
const phone = ref('');

watch(() => props.open, (open) => {
    if (! open) return;
    name.value = '';
    phone.value = '';
});

function submit() {
    if (props.busy || ! name.value.trim() || ! phone.value.trim()) return;
    emit('submit', { name: name.value.trim(), phone: phone.value.trim() });
}
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="sheet-layer" @click.self="emit('close')">
            <section class="customer-sheet" role="dialog" aria-modal="true" aria-labelledby="customer-create-title">
                <header>
                    <div><span>سجل الزبائن</span><h2 id="customer-create-title">{{ result ? 'تم حفظ الزبون' : 'إضافة زبون' }}</h2></div>
                    <button type="button" aria-label="إغلاق" :disabled="busy" @click="emit('close')"><i class="bi bi-x-lg"></i></button>
                </header>

                <div v-if="result" class="saved-card">
                    <span class="saved-icon"><i class="bi bi-person-check-fill"></i></span>
                    <strong>{{ result.name }}</strong>
                    <small>{{ result.phone }}</small>
                    <p>{{ result.created ? 'تم إنشاء ملف الزبون وربطه بسجل الطلبات والنقاط.' : 'هذا الرقم مسجّل مسبقاً؛ استُخدم الملف نفسه.' }}</p>
                    <button type="button" class="primary" @click="emit('close')">تم</button>
                </div>

                <form v-else @submit.prevent="submit">
                    <p class="hint">الرقم هو هوية الزبون في كل الفروع؛ إذا كان موجوداً لن ننشئ نسخة ثانية.</p>
                    <div v-if="error" class="sheet-error"><i class="bi bi-exclamation-circle"></i> {{ error }}</div>
                    <label><span>اسم الزبون</span><input v-model="name" maxlength="120" autocomplete="name" autofocus><small v-if="errors.name">{{ errors.name[0] }}</small><small v-if="errors.customer_name">{{ errors.customer_name[0] }}</small></label>
                    <label><span>رقم الجوال</span><input v-model="phone" type="tel" maxlength="32" inputmode="tel" autocomplete="tel" placeholder="0599…"><small v-if="errors.phone">{{ errors.phone[0] }}</small><small v-if="errors.customer_phone">{{ errors.customer_phone[0] }}</small></label>
                    <footer><button type="button" class="secondary" :disabled="busy" @click="emit('close')">إلغاء</button><button type="submit" class="primary" :disabled="busy || !name.trim() || !phone.trim()">{{ busy ? 'جاري الحفظ…' : 'حفظ الزبون' }}</button></footer>
                </form>
            </section>
        </div>
    </Teleport>
</template>

<style scoped>
.sheet-layer { position: fixed; z-index: 1200; inset: 0; display: grid; place-items: center; padding: 1rem; background: rgba(15, 27, 19, .48); backdrop-filter: blur(3px); }
.customer-sheet { width: min(480px, 100%); max-height: calc(100dvh - 2rem); box-sizing: border-box; padding: 1rem; border: 1px solid #dce5df; border-radius: 18px; background: #fff; box-shadow: 0 24px 70px -28px rgba(0, 0, 0, .58); overflow-y: auto; }
header { display: flex; align-items: center; justify-content: space-between; } header > div { display: flex; flex-direction: column; } header span { color: #68776e; font-size: .68rem; font-weight: 750; } header h2 { margin: .08rem 0 0; color: #26382d; font-size: 1rem; } header button { display: grid; width: 44px; height: 44px; place-items: center; border: 1px solid #dfe6e2; border-radius: 10px; color: #617067; background: #fff; }
.hint { margin: .8rem 0 0; padding: .7rem; border-radius: 11px; color: #5f6e65; background: #f4f8f5; font-size: .72rem; line-height: 1.7; }
.sheet-error { margin-top: .6rem; padding: .55rem; border-radius: 9px; color: #922d36; background: #fff0f1; font-size: .7rem; }
label { display: grid; gap: .3rem; margin-top: .7rem; color: #53645a; font-size: .7rem; font-weight: 800; } input { min-height: 46px; box-sizing: border-box; padding: .55rem .65rem; border: 1px solid #d7e1da; border-radius: 10px; background: #fff; font: inherit; } label small { color: #a62f38; }
footer { display: flex; gap: .5rem; margin-top: 1rem; } button { min-height: 44px; font: inherit; font-weight: 800; cursor: pointer; } footer button, .saved-card > button { flex: 1; padding-inline: .9rem; border-radius: 11px; } .secondary { border: 1px solid #dce4df; color: #536159; background: #fff; } .primary { border: 1px solid rgb(var(--primary-rgb, 22 101 52)); color: #fff; background: rgb(var(--primary-rgb, 22 101 52)); } button:disabled { opacity: .5; }
.saved-card { display: grid; justify-items: center; gap: .35rem; margin-top: .8rem; padding: 1rem; border-radius: 15px; color: #28543a; background: #f0f8f2; text-align: center; }.saved-icon { display: grid; width: 54px; height: 54px; place-items: center; border-radius: 17px; color: #fff; background: rgb(var(--primary-rgb, 22 101 52)); font-size: 1.35rem; }.saved-card strong { margin-top: .3rem; font-size: 1rem; }.saved-card small { color: #66756c; }.saved-card p { margin: .45rem 0; color: #66756c; font-size: .72rem; line-height: 1.65; }.saved-card > button { width: 100%; margin-top: .6rem; }
@media (max-width: 560px) { .sheet-layer { align-items: end; padding: 0; }.customer-sheet { width: 100%; border-radius: 20px 20px 0 0; } }
</style>
