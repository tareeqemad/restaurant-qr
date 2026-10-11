<script setup>
import DataPanel from '../Ui/DataPanel.vue'
defineProps({title:{type:String,required:true},description:{type:String,default:''},icon:{type:String,default:'bi-journal-text'},tone:{type:String,default:'green'},compact:{type:Boolean,default:false}})
</script>
<template>
    <DataPanel class="acc-panel" :title="title" :icon="icon" :compact="compact" :class="{'acc-panel--compact':compact}">
        <template v-if="description || $slots.actions" #actions>
            <slot name="actions" />
            <details v-if="description" class="acc-panel__help">
                <summary :aria-label="'تفاصيل '+title"><i class="bi bi-info-circle"></i></summary>
                <p>{{description}}</p>
            </details>
        </template>
        <slot />
        <template v-if="$slots.footer" #footer><slot name="footer" /></template>
    </DataPanel>
</template>
<style scoped>
.acc-panel { min-width:0; margin:0; border:1px solid #DFD7CB; border-radius:16px; background:#FFFDFA; box-shadow:none; overflow:visible; }
.acc-panel :deep(.card-header) { padding:18px 20px; background:transparent; }
.acc-panel :deep(.card-title) { color:#25343A; font-size:17px; font-weight:800; }
.acc-panel :deep(.card-body) { padding:20px; }
.acc-panel--compact :deep(.card-body) { padding:0; }
.acc-panel :deep(.card-footer) { padding:14px 20px; background:#F7F3ED; border-radius:0 0 16px 16px; }
.acc-panel__help { position:relative; }
.acc-panel__help summary { display:grid; width:36px; height:36px; min-height:36px; padding:0; place-items:center; list-style:none; color:#626B6D; cursor:pointer; border:1px solid #DFD7CB; border-radius:50%; }
.acc-panel__help summary::-webkit-details-marker { display:none; }
.acc-panel__help p { position:absolute; inset-inline-end:0; top:42px; z-index:8; width:min(290px,70vw); margin:0; padding:14px 16px; border:1px solid #DFD7CB; border-radius:12px; background:#FFFDFA; color:#626B6D; font-size:14px; line-height:1.7; box-shadow:0 12px 32px #25343A18; }
</style>
