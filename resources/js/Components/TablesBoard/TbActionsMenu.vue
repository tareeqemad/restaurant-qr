<script setup>
/**
 * Per-table pencil + ⋯ menu (v4's _table-actions partial). The pencil is
 * now a plain emit — no window-event bus, no Alpine scope to die after a
 * morph: the bug that survived two diagnoses cannot exist here.
 *
 * The ⋯ menu keeps v4's edge-flip: a 210px menu anchored to a small map
 * tile runs off-screen on edge columns, so measure on open and flip.
 */
import { nextTick, ref } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    row: { type: Object, required: true },
    transferTables: { type: Array, default: () => [] },
    open: { type: Boolean, default: false },
});

const emit = defineEmits(['toggle', 'quick-edit', 'transfer', 'destroy']);

const menuEl = ref(null);
const flipped = ref(false);
const transferTarget = ref('');

const toggle = async () => {
    emit('toggle', props.row.id);
    flipped.value = false;
    await nextTick();
    const m = menuEl.value;
    if (! m) return;
    const r = m.getBoundingClientRect();
    // Written in LTR client coordinates; checking both edges makes it
    // correct under RTL too (v4's exact check).
    flipped.value = r.left < 8 || r.right > window.innerWidth - 8;
};

const submitTransfer = () => {
    if (! transferTarget.value) return;
    emit('transfer', { row: props.row, targetId: Number(transferTarget.value) });
};
</script>

<template>
    <div class="ta-actions">
        <button v-if="row.perms.update" type="button" class="ta-quick-edit-btn"
                :aria-label="`تعديل سريع لطاولة ${row.number}`" title="تعديل سريع"
                @click.stop="$emit('quick-edit', row)">
            <i class="bi bi-pencil-fill"></i>
        </button>

        <div class="dropdown ta-more">
            <button type="button" class="ta-more-btn" :aria-expanded="open"
                    :aria-label="`إجراءات طاولة ${row.number}`" @click.stop="toggle">
                <i class="bi bi-three-dots"></i>
            </button>

            <ul v-show="open" ref="menuEl" class="dropdown-menu ta-more-menu show"
                :class="{ 'is-flipped': flipped }" @click.stop>
                <li v-if="row.sessionId && row.urls.cashier">
                    <Link class="dropdown-item" :href="row.urls.cashier"><i class="bi bi-cash-stack"></i> فتح التحصيل</Link>
                </li>
                <li v-if="row.urls.review">
                    <Link class="dropdown-item" :href="row.urls.review"><i class="bi bi-check2-square"></i> مراجعة الجولة الجديدة</Link>
                </li>
                <li>
                    <Link class="dropdown-item" :href="row.urls.order">
                        <i class="bi bi-layout-text-window-reverse"></i> {{ row.sessionId ? 'مساحة الطاولة' : 'تشغيل الطاولة' }}
                    </Link>
                </li>
                <li>
                    <a class="dropdown-item" :href="row.urls.qrPrint" target="_blank"><i class="bi bi-qr-code"></i> طباعة QR</a>
                </li>

                <template v-if="row.perms.transfer && row.sessionId && transferTables.length">
                    <li><hr class="dropdown-divider"></li>
                    <li class="ta-more-transfer">
                        <span class="ta-more-transfer-label"><i class="bi bi-arrow-left-right"></i> نقل الجلسة إلى</span>
                        <div class="ta-more-transfer-row">
                            <select v-model="transferTarget" class="ta-transfer-select" aria-label="نقل إلى طاولة">
                                <option value="">اختر طاولة...</option>
                                <option v-for="t in transferTables" :key="t.id" :value="t.id">
                                    طاولة {{ t.number }}{{ t.name ? ' - ' + t.name : '' }}
                                </option>
                            </select>
                            <button type="button" class="ta-transfer-go" :disabled="! transferTarget"
                                    @click="submitTransfer">نقل</button>
                        </div>
                    </li>
                </template>

                <template v-if="row.perms.delete">
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <button type="button" class="dropdown-item ta-more-danger" @click="$emit('destroy', row)">
                            <i class="bi bi-trash"></i> حذف الطاولة
                        </button>
                    </li>
                </template>
            </ul>
        </div>
    </div>
</template>
