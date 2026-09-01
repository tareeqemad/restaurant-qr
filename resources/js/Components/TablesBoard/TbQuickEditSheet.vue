<script setup>
/**
 * Quick-edit sheet — the pencil's new home. A native Vue bottom sheet
 * (same sheet language as the waiter POS) that PATCHes the quick-update
 * endpoint, which runs TableController's full update pipeline: same
 * validation, renumber-snapshot notice, ghost-session sweep, broadcast.
 *
 * `active` is always sent as an explicit boolean: the rule is
 * sometimes|boolean, so omitting it means "leave unchanged" — never rely
 * on unchecked-checkbox semantics here.
 */
import { reactive, ref, watch } from 'vue';

const props = defineProps({
    row: { type: Object, default: null }, // open when non-null
    zones: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);

const form = reactive({ number: '', name: '', capacity: 1, zone_lookup_id: '', status: 'available', active: true });
const errors = ref({});
const saving = ref(false);

watch(() => props.row, (row) => {
    if (! row) return;
    form.number = row.number;
    form.name = row.name ?? '';
    form.capacity = row.capacity;
    form.zone_lookup_id = row.zoneId ? String(row.zoneId) : '';
    form.status = row.status;
    form.active = Boolean(row.activeFlag);
    errors.value = {};
});

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';

const save = async () => {
    if (! props.row || saving.value) return;
    saving.value = true;
    errors.value = {};

    try {
        const res = await fetch(props.row.urls.quickUpdate, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
                Accept: 'application/json',
                'X-HTTP-Method-Override': 'PATCH',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                _method: 'PATCH',
                number: form.number,
                name: form.name || null,
                capacity: Number(form.capacity),
                zone_lookup_id: form.zone_lookup_id || null,
                status: form.status,
                active: Boolean(form.active),
            }),
        });

        if (res.status === 422) {
            const data = await res.json().catch(() => ({}));
            errors.value = data.errors ?? {};
            return;
        }

        const data = res.ok ? await res.json().catch(() => null) : null;
        if (! data?.ok) {
            errors.value = { number: ['تعذّر الحفظ — حاول مجدداً.'] };
            return;
        }

        emit('saved', data);
    } catch {
        errors.value = { number: ['انقطع الاتصال — التعديل ما انحفظ.'] };
    } finally {
        saving.value = false;
    }
};

const err = (field) => errors.value?.[field]?.[0] ?? null;
</script>

<template>
    <Teleport to="body">
        <Transition name="qe">
            <div v-if="row" class="qe-backdrop" @click.self="$emit('close')">
                <div class="qe-sheet" role="dialog" aria-modal="true" :aria-label="`تعديل طاولة ${row.number}`">
                    <header class="qe-head">
                        <h3><i class="bi bi-pencil-fill"></i> تعديل طاولة {{ row.number }}</h3>
                        <button type="button" class="qe-close" aria-label="إغلاق" @click="$emit('close')">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </header>

                    <form class="qe-body" @submit.prevent="save">
                        <div class="qe-grid">
                            <label class="qe-field">
                                <span>رقم الطاولة *</span>
                                <input v-model="form.number" class="form-control" required maxlength="16">
                                <small v-if="err('number')" class="qe-err">{{ err('number') }}</small>
                            </label>

                            <label class="qe-field">
                                <span>السعة *</span>
                                <input v-model.number="form.capacity" type="number" min="1" max="50" class="form-control" required>
                                <small v-if="err('capacity')" class="qe-err">{{ err('capacity') }}</small>
                            </label>
                        </div>

                        <label class="qe-field">
                            <span>الاسم (اختياري)</span>
                            <input v-model="form.name" class="form-control" maxlength="255" placeholder="مثلاً: طاولة العائلة">
                            <small v-if="err('name')" class="qe-err">{{ err('name') }}</small>
                        </label>

                        <div class="qe-grid">
                            <label class="qe-field">
                                <span>القسم</span>
                                <select v-model="form.zone_lookup_id" class="form-select">
                                    <option value="">بدون قسم</option>
                                    <option v-for="z in zones" :key="z.id" :value="String(z.id)">{{ z.label }}</option>
                                </select>
                                <small v-if="err('zone_lookup_id')" class="qe-err">{{ err('zone_lookup_id') }}</small>
                            </label>

                            <label class="qe-field">
                                <span>الحالة *</span>
                                <select v-model="form.status" class="form-select">
                                    <option value="available">متاحة</option>
                                    <option value="occupied">مشغولة</option>
                                    <option value="reserved">محجوزة</option>
                                    <option value="out_of_service">خارج الخدمة</option>
                                </select>
                                <small v-if="err('status')" class="qe-err">{{ err('status') }}</small>
                            </label>
                        </div>

                        <label class="qe-toggle">
                            <input v-model="form.active" type="checkbox">
                            <span>الطاولة فعّالة (تظهر للزبائن وعلى اللوحات)</span>
                        </label>

                        <div class="qe-actions">
                            <button type="button" class="qe-btn qe-btn--ghost" @click="$emit('close')">إلغاء</button>
                            <button type="submit" class="qe-btn qe-btn--save" :disabled="saving">
                                <i class="bi" :class="saving ? 'bi-arrow-repeat qe-spin' : 'bi-check-lg'"></i>
                                {{ saving ? 'جارٍ الحفظ…' : 'حفظ' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.qe-backdrop {
    position: fixed;
    inset: 0;
    z-index: 18000;
    background: rgba(15, 23, 42, .45);
    display: flex;
    align-items: flex-end;
    justify-content: center;
}
.qe-sheet {
    width: min(560px, 100%);
    max-height: 92dvh;
    overflow-y: auto;
    background: #fff;
    border-radius: 18px 18px 0 0;
    box-shadow: 0 -18px 50px -20px rgba(15, 23, 42, .45);
}
@media (min-width: 768px) {
    .qe-backdrop { align-items: center; padding: 1rem; }
    .qe-sheet { border-radius: 18px; }
}

.qe-head {
    position: sticky;
    top: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.15rem .8rem;
    background: #fff;
    border-bottom: 1px solid #eef2f6;
}
.qe-head h3 {
    margin: 0;
    font-size: 1.02rem;
    font-weight: 800;
    color: #0f172a;
    display: flex;
    align-items: center;
    gap: .5rem;
}
.qe-head h3 > i { color: rgb(var(--primary-rgb)); }
.qe-close {
    width: 44px;
    height: 44px;
    border: 0;
    border-radius: 10px;
    background: #f1f5f9;
    color: #475569;
    cursor: pointer;
}

.qe-body { padding: 1rem 1.15rem 1.25rem; display: flex; flex-direction: column; gap: .85rem; }
.qe-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .85rem; }

.qe-field { display: flex; flex-direction: column; gap: .35rem; margin: 0; }
.qe-field > span { font-size: .8rem; font-weight: 700; color: #334155; }
.qe-field .form-control, .qe-field .form-select { min-height: 46px; }
.qe-err { color: #b91c1c; font-weight: 600; }

.qe-toggle {
    display: flex;
    align-items: center;
    gap: .6rem;
    min-height: 44px;
    margin: 0;
    font-size: .86rem;
    font-weight: 600;
    color: #334155;
    cursor: pointer;
}
.qe-toggle input { width: 19px; height: 19px; accent-color: rgb(var(--primary-rgb)); }

.qe-actions { display: flex; gap: .6rem; margin-top: .25rem; }
.qe-btn {
    flex: 1;
    min-height: 48px;
    border: 0;
    border-radius: 12px;
    font: inherit;
    font-size: .95rem;
    font-weight: 800;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .45rem;
}
.qe-btn--ghost { background: #f1f5f9; color: #334155; }
.qe-btn--save { background: rgb(var(--primary-rgb)); color: #fff; }
.qe-btn--save:disabled { opacity: .65; }
.qe-spin { animation: qe-rotate 1s linear infinite; }
@keyframes qe-rotate { to { transform: rotate(360deg); } }

.qe-enter-active, .qe-leave-active { transition: opacity .16s; }
.qe-enter-from, .qe-leave-to { opacity: 0; }
@media (prefers-reduced-motion: reduce) {
    .qe-enter-active, .qe-leave-active { transition: none; }
}
</style>
