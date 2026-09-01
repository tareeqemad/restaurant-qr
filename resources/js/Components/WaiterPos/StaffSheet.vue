<script setup>
/**
 * «وجبة موظف» — redesigned port of the classic <details> panel: pick the
 * employee the order lands on, see their REMAINING monthly balance before
 * charging (the classic screen only revealed it after submit). Selection
 * is client state; the server re-validates eligibility at submit.
 */
import { formatMoney } from '../../Composables/useMoney';

defineProps({
    staff: { type: Array, required: true },
    selectedId: { type: Number, default: null },
    currency: { type: Object, required: true },
});

const emit = defineEmits({
    close: () => true,
    pick: (id) => Number.isInteger(id),
    clear: () => true,
});
</script>

<template>
    <div class="sheet-backdrop" @click.self="emit('close')" @keydown.escape.window="emit('close')">
        <div class="sheet" role="dialog" aria-label="وجبة موظف">
            <header>
                <strong><i class="bi bi-person-badge"></i> وجبة موظف</strong>
                <button type="button" class="close" aria-label="إغلاق" @click="emit('close')">
                    <i class="bi bi-x-lg"></i>
                </button>
            </header>

            <p class="hint">الطلب بينحسب على بدل الموظف الشهري بدل ما يتفوتر عالطاولة.</p>

            <div class="list">
                <button
                    v-for="person in staff"
                    :key="person.id"
                    type="button"
                    class="person"
                    :class="{ 'is-selected': person.id === selectedId }"
                    @click="emit('pick', person.id)"
                >
                    <span class="name">{{ person.name }}</span>
                    <small>{{ person.job_title }} · {{ person.has_login ? 'له دخول' : 'بدون دخول' }}</small>
                    <span class="remaining" :class="{ 'is-low': person.remaining <= 0 }">
                        باقي له {{ formatMoney(person.remaining, currency) }}
                    </span>
                    <i v-if="person.id === selectedId" class="bi bi-check-circle-fill"></i>
                </button>

                <p v-if="!staff.length" class="empty">
                    ما في موظفين ببدل وجبات مفعّل — فعّله من ملف الموظف أولاً.
                </p>
            </div>

            <button v-if="selectedId" type="button" class="clear-pick" @click="emit('clear')">
                <i class="bi bi-x-circle"></i> إلغاء وضع وجبة موظف
            </button>
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
.hint { margin: .7rem 1rem 0; color: #6b7280; font-size: .82rem; }
.list { overflow-y: auto; overscroll-behavior: contain; padding: .6rem 1rem 1rem; display: grid; gap: .4rem; }
.person {
    display: flex; align-items: center; gap: .6rem;
    min-height: 52px; padding: .5rem .8rem;
    border: 1.5px solid #e5e7eb; border-radius: 12px; background: #fff;
    font-family: inherit; font-size: .92rem; cursor: pointer; text-align: start;
}
.person.is-selected {
    border-color: rgb(var(--primary-rgb, 22 101 52));
    background: color-mix(in srgb, rgb(var(--primary-rgb, 22 101 52)) 7%, #fff);
}
.person .name { flex: 1; font-weight: 700; color: #1f2937; }
.person small { color: #64748b; font-size: .7rem; }
.person .remaining { font-size: .78rem; font-weight: 700; color: #047857; }
.person .remaining.is-low { color: #b91c1c; }
.person > i { color: rgb(var(--primary-rgb, 22 101 52)); }
.empty { margin: .5rem 0; color: #9ca3af; font-size: .85rem; text-align: center; }
.clear-pick {
    margin: 0 1rem 1rem; min-height: 48px;
    border: 1.5px solid #fecaca; border-radius: 12px;
    background: #fff; color: #b91c1c; font-family: inherit; font-weight: 800; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
}
</style>
