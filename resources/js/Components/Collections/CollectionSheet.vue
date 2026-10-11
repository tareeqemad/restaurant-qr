<script setup>
import { getCurrentInstance } from 'vue';
import DialogSurface from '../Ui/DialogSurface.vue';
const titleId = 'collection-title-' + getCurrentInstance().uid;
defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: '' },
    eyebrow: { type: String, default: '' },
    icon: { type: String, default: 'bi-cash-coin' },
    danger: { type: Boolean, default: false },
});

defineEmits(['close']);
</script>

<template>
    <DialogSurface :open="open" variant="sheet-end" :title-id="titleId" max-width="600px" @close="$emit('close')">
        <section class="collection-sheet" :class="{danger}">
            <header>
                <span class="collection-sheet__icon"><i class="bi" :class="icon"></i></span>
                <div><small v-if="eyebrow">{{eyebrow}}</small><h2 :id="titleId">{{title}}</h2></div>
                <button type="button" aria-label="إغلاق" @click="$emit('close')"><i class="bi bi-x-lg"></i></button>
            </header>
            <div class="collection-sheet__body"><slot /></div>
            <footer v-if="$slots.footer"><slot name="footer" /></footer>
        </section>
    </DialogSurface>
</template>
<style scoped>
.collection-sheet{display:flex;min-height:100%;flex-direction:column;background:#FFFDFA;color:#25343A}
.collection-sheet>header{display:grid;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:14px;padding:24px;border-bottom:1px solid #DFD7CB}
.collection-sheet__icon{display:grid;width:46px;height:46px;place-items:center;border:1px solid #DFD7CB;border-radius:12px;color:#A3422B;background:#F7F3ED;font-size:21px}
.collection-sheet.danger .collection-sheet__icon{color:#B83232;background:#FFF0F0}
.collection-sheet header small{font-size:13px;color:#626B6D}
.collection-sheet h2{margin:4px 0 0;font-size:22px;font-weight:800}
.collection-sheet header button{width:42px;height:42px;border:1px solid #DFD7CB;border-radius:50%;background:transparent;color:#626B6D}
.collection-sheet__body{flex:1;padding:24px;overflow-y:auto}
.collection-sheet>footer{display:flex;flex-wrap:wrap;gap:12px;justify-content:flex-end;position:sticky;bottom:0;padding:18px 24px;border-top:1px solid #DFD7CB;background:#F7F3ED}
.collection-sheet :deep(.btn){min-height:44px;font-size:14px}
.collection-sheet :deep(input:not([type=checkbox]):not([type=radio])),.collection-sheet :deep(select){min-height:44px;font-size:14px}
@media(max-width:600px){.collection-sheet>header,.collection-sheet__body{padding:18px}.collection-sheet>footer{padding:16px 18px}}
</style>
