<script setup>
/**
 * ربط زبون بالجلسة — redesigned port of the classic <details> panel.
 * One surface, two moments: a LINKED view (name/debt/detach) and a SEARCH
 * form (phone-first, create-on-miss switch).
 * All truth lives server-side (the sheet only relays §-contract calls).
 */
import { ref, watch } from 'vue';
import { formatMoney } from '../../Composables/useMoney';

const props = defineProps({
    customer: { type: Object, default: null },   // { name, debt } | null
    currency: { type: Object, required: true },
    busy: { type: Boolean, default: false },
});

const emit = defineEmits({
    close: () => true,
    link: (payload) => payload && typeof payload.phone === 'string',
    detach: () => true,
});

const phone = ref('');
const name = ref('');
const createIfMissing = ref(false);

watch(() => props.customer, () => {
    // A fresh link resets the form for the next table.
    phone.value = '';
    name.value = '';
    createIfMissing.value = false;
});

function submitSearch() {
    if (props.busy || !phone.value.trim()) return;
    emit('link', {
        phone: phone.value.trim(),
        name: name.value.trim() || null,
        create_if_missing: createIfMissing.value,
    });
}
</script>

<template>
    <div class="sheet-backdrop" @click.self="emit('close')" @keydown.escape.window="emit('close')">
        <div class="sheet" role="dialog" aria-label="ربط زبون">
            <header>
                <strong><i class="bi bi-person-check"></i> زبون الطاولة</strong>
                <button type="button" class="close" aria-label="إغلاق" @click="emit('close')">
                    <i class="bi bi-x-lg"></i>
                </button>
            </header>

            <div class="body">
                <template v-if="customer">
                    <div class="linked">
                        <i class="bi bi-person-circle"></i>
                        <div>
                            <strong>{{ customer.name }}</strong>
                            <span v-if="customer.debt > 0.009" class="debt">
                                عليه دين سابق {{ formatMoney(customer.debt, currency) }}
                            </span>
                            <span v-else class="clean">لا ديون سابقة</span>
                        </div>
                    </div>
                    <button type="button" class="detach" :disabled="busy" @click="emit('detach')">
                        <i class="bi bi-x-circle"></i> فك ربط الزبون
                    </button>
                </template>

                <template v-else>
                    <label class="field">
                        <span>رقم الجوال</span>
                        <input v-model="phone" type="tel" inputmode="tel"
                               placeholder="0599…" maxlength="32" @keyup.enter="submitSearch">
                    </label>
                    <label class="toggle">
                        <input v-model="createIfMissing" type="checkbox">
                        <span>أضف الزبون إن لم يوجد</span>
                    </label>
                    <label v-if="createIfMissing" class="field">
                        <span>اسم الزبون (اختياري)</span>
                        <input v-model="name" type="text" maxlength="120" placeholder="أبو العبد">
                    </label>
                    <button type="button" class="go" :disabled="busy || !phone.trim()" @click="submitSearch">
                        <i class="bi" :class="busy ? 'bi-hourglass-split' : 'bi-link-45deg'"></i>
                        {{ createIfMissing ? 'ابحث أو أنشئ واربط' : 'ابحث واربط' }}
                    </button>
                </template>
            </div>
        </div>
    </div>
</template>

<style scoped>
.sheet-backdrop {
    position: fixed; inset: 0; z-index: 1080;
    display: flex; align-items: flex-end; justify-content: center;
    background: rgba(15, 23, 42, .5);
}
.sheet {
    width: 100%; max-width: 560px; max-height: 88vh; max-height: 88dvh;
    display: flex; flex-direction: column;
    border-radius: 18px 18px 0 0; background: #fff; overflow: hidden;
    box-shadow: 0 -18px 60px -12px rgba(15, 23, 42, .4);
}
header {
    display: flex; align-items: center; gap: .6rem;
    padding: .9rem 1rem; color: #fff;
    background: color-mix(in srgb, rgb(var(--primary-rgb, 22 101 52)) 78%, #04150d);
    font-weight: 800;
}
header > strong { flex: 1; display: inline-flex; align-items: center; gap: .45rem; }
.close {
    width: 44px; height: 44px; border: 0; border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #fff; background: rgba(255, 255, 255, .16); cursor: pointer;
}
.body { padding: 1rem; overflow-y: auto; overscroll-behavior: contain; display: grid; gap: .8rem; }
.linked { display: flex; align-items: center; gap: .7rem; }
.linked > i { font-size: 2rem; color: rgb(var(--primary-rgb, 22 101 52)); }
.linked strong { display: block; color: #1f2937; }
.linked .debt { color: #b91c1c; font-size: .82rem; font-weight: 700; }
.linked .clean { color: #047857; font-size: .82rem; font-weight: 700; }
.detach {
    min-height: 48px; border: 1.5px solid #fecaca; border-radius: 12px;
    background: #fff; color: #b91c1c; font-family: inherit; font-weight: 800; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
}
.field span { display: block; margin-bottom: .35rem; color: #374151; font-size: .82rem; font-weight: 700; }
.field input {
    width: 100%; min-height: 48px; box-sizing: border-box;
    padding: .55rem .7rem; border: 1.5px solid #e5e7eb; border-radius: 12px;
    font-family: inherit; font-size: .95rem;
}
.field input:focus { outline: none; border-color: rgb(var(--primary-rgb, 22 101 52)); }
.toggle { display: flex; align-items: center; gap: .55rem; min-height: 44px; font-size: .88rem; font-weight: 700; color: #374151; cursor: pointer; }
.toggle input { width: 18px; height: 18px; accent-color: rgb(var(--primary-rgb, 22 101 52)); }
.go {
    min-height: 50px; border: 0; border-radius: 12px;
    background: rgb(var(--primary-rgb, 22 101 52)); color: #fff;
    font-family: inherit; font-size: .95rem; font-weight: 800; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: .45rem;
}
.go:disabled { opacity: .6; cursor: not-allowed; }
</style>
