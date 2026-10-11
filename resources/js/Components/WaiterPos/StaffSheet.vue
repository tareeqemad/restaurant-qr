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
    border-radius: 12px 12px 0 0; background: var(--surface); overflow: hidden;
    box-shadow: none;
}
header {
    display: flex; align-items: center; gap: .6rem;
    padding: .9rem 1rem; color: #FFFDFA;
    background: color-mix(in srgb, var(--brand) 78%, var(--brand-dark));
    font-weight: 800;
}
header > strong { flex: 1; display: inline-flex; align-items: center; gap: .45rem; }
.close {
    width: 44px; height: 44px; border: 0; border-radius: 10px;
    display: inline-flex; align-items: center; justify-content: center;
    color: #FFFDFA; background: rgba(255, 255, 255, .16); cursor: pointer;
}
.hint { margin: .7rem 1rem 0; color: var(--muted); font-size: .875rem; }
.list { overflow-y: auto; overscroll-behavior: contain; padding: .6rem 1rem 1rem; display: grid; gap: .4rem; }
.person {
    display: flex; align-items: center; gap: .6rem;
    min-height: 52px; padding: .5rem .8rem;
    border: 1.5px solid var(--line); border-radius: 12px; background: var(--surface);
    font-family: inherit; font-size: .92rem; cursor: pointer; text-align: start;
}
.person.is-selected {
    border-color: var(--brand);
    background: color-mix(in srgb, var(--brand) 7%, var(--surface));
}
.person .name { flex: 1; font-weight: 700; color: var(--ink); }
.person small { color: #315B6B; font-size: .875rem; }
.person .remaining { font-size: .875rem; font-weight: 700; color: var(--brand); }
.person .remaining.is-low { color: #b91c1c; }
.person > i { color: var(--brand); }
.empty { margin: .5rem 0; color: var(--muted); font-size: .875rem; text-align: center; }
.clear-pick {
    margin: 0 1rem 1rem; min-height: 48px;
    border: 1.5px solid #fecaca; border-radius: 12px;
    background: var(--surface); color: #b91c1c; font-family: inherit; font-weight: 800; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center; gap: .4rem;
}

/* Task-specific component layout. */
.sheet{border-radius:10px;background:var(--surface);box-shadow:none;max-height:90dvh;overflow-y:auto}.sheet > header{padding:20px 24px;background:var(--surface);color:var(--ink);border-bottom:1px solid var(--line);gap:14px}.sheet > header h2,.sheet > header strong{font-size:22px;color:var(--ink)}.sheet > header button{width:44px;height:44px;min-width:44px;border-radius:8px;background:var(--canvas);color:var(--ink)}.list{padding:20px 24px}.sheet > footer{padding:16px 24px;border-top:1px solid var(--line);background:var(--surface);position:sticky;bottom:0;z-index:2}.sheet input:not([type=checkbox]):not([type=radio]),.sheet select{min-height:48px;border-radius:6px;font-size:16px}.sheet textarea{min-height:90px;border-radius:6px;font-size:15px}.modifier-options,.exclusion-options{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:8px}.modifier-option,.exclusion-option{min-height:52px;padding:12px;border-radius:6px;font-size:15px}.group-title{padding-bottom:12px;border-bottom:1px solid var(--line);margin-bottom:12px}.quantity-row{padding:16px 0}.quantity-stepper button{width:44px;height:44px}.save-action,.customer-sheet__button,.go{min-height:50px;border-radius:8px;font-size:16px}.person{border:0;border-bottom:1px solid var(--line);border-radius:0;padding:16px 0;min-height:70px}.person .name{font-size:16px}.bill-summary{border-radius:8px;padding:18px;background:var(--canvas)}.bill-summary__primary strong{font-size:27px}.session-round{border-radius:6px;margin-bottom:14px}.session-item{padding:14px 12px;gap:12px}.session-item__name-row strong{font-size:16px}.session-item__detail{font-size:14px;line-height:1.6}.session-item__cancel{min-height:42px}.customer-card{border-radius:8px;padding:18px;background:var(--canvas)}@media(max-width:600px){.sheet > header,.list{padding:18px}.sheet > footer{padding:14px 18px}.modifier-options,.exclusion-options{grid-template-columns:1fr}}
</style>
