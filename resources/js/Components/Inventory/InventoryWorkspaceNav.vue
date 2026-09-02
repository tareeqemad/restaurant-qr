<script setup>
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps({ workspace: { type: Object, required: true } });
const openGroup = ref('');
const groups = computed(() => (props.workspace.groups ?? [])
    .map((group) => ({ ...group, items: (group.items ?? []).filter(Boolean) }))
    .filter((group) => group.items.length));
const activeGroup = computed(() => groups.value.find((group) => group.items.some((item) => item.key === props.workspace.active))?.id ?? 'home');
const shown = computed(() => groups.value.find((group) => group.id === (openGroup.value || activeGroup.value)) ?? groups.value[0]);

watch(activeGroup, () => {
    openGroup.value = '';
});
</script>

<template>
    <nav v-if="shown" class="inv-workspace" aria-label="مركز المخزون والمشتريات">
        <div class="inv-workspace__groups" role="tablist">
            <button v-for="group in groups" :key="group.id" type="button"
                    :id="`inventory-tab-${group.id}`"
                    role="tab"
                    :class="{ active: shown.id === group.id }"
                    :aria-selected="shown.id === group.id"
                    :aria-controls="`inventory-panel-${group.id}`"
                    @click="openGroup = group.id">
                <i class="bi" :class="group.icon"></i><span>{{ group.label }}</span>
                <small>{{ group.items.length }}</small>
            </button>
        </div>
        <div :id="`inventory-panel-${shown.id}`" class="inv-workspace__links" role="tabpanel"
             :aria-labelledby="`inventory-tab-${shown.id}`">
            <Link v-for="item in shown.items" :key="item.key" :href="item.href"
               :class="{ active: workspace.active === item.key }">
                <span>{{ item.label }}</span><i class="bi bi-chevron-left"></i>
            </Link>
        </div>
    </nav>
</template>

<style scoped>
.inv-workspace{position:sticky;z-index:14;top:62px;margin:0 0 10px;border:1px solid #dfe7e2;border-radius:15px;background:rgba(255,255,255,.97);box-shadow:0 7px 22px rgba(21,54,34,.05);backdrop-filter:blur(12px)}
.inv-workspace__groups{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:5px;padding:7px}
.inv-workspace__groups button{display:flex;min-width:0;min-height:40px;align-items:center;justify-content:center;gap:7px;padding:8px;border:0;border-radius:11px;color:#65736a;background:transparent;font-size:.76rem;font-weight:850;white-space:nowrap}
.inv-workspace__groups button i{font-size:.96rem}.inv-workspace__groups button small{display:grid;min-width:19px;height:19px;place-items:center;border-radius:99px;background:#eef2ef;font-size:.61rem}
.inv-workspace__groups button.active{color:#176b39;background:#eef7f1;box-shadow:inset 0 -2px #17713c}
.inv-workspace__links{display:flex;gap:6px;overflow-x:auto;padding:8px 10px;border-top:1px solid #edf1ee;scrollbar-width:thin}
.inv-workspace__links a{display:flex;flex:0 0 auto;align-items:center;gap:14px;min-height:36px;padding:7px 10px;border:1px solid #e1e8e3;border-radius:10px;color:#5c6b61;background:#fff;font-size:.72rem;font-weight:780}
.inv-workspace__links a i{font-size:.6rem;opacity:.55}.inv-workspace__links a.active{border-color:#94c5a4;color:#176b39;background:#f1f8f3}
@media(min-width:1181px){.inv-workspace{padding:6px}.inv-workspace__groups{padding:0}.inv-workspace__links{align-items:center;margin-top:4px;padding:6px 4px 0;border-top:1px solid #edf1ee;background:#fbfdfc}.inv-workspace__links a{min-height:34px;padding-block:6px}}
@media(max-width:820px){.inv-workspace{top:56px;border-radius:13px}.inv-workspace__groups{display:flex;overflow-x:auto;scrollbar-width:none}.inv-workspace__groups button{flex:1 0 92px}.inv-workspace__groups button small{display:none}}
@media(max-width:520px){.inv-workspace__groups button{flex:1 0 52px}.inv-workspace__groups button span{display:none}.inv-workspace__groups button i{font-size:1.05rem}.inv-workspace__links a{padding:7px 9px}}
</style>
