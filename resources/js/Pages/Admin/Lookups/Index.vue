<script setup>
import ManagementPage from '../../../Components/Inventory/ManagementPage.vue';
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/Ui/PageHeader.vue';
import EmptyState from '../../../Components/Ui/EmptyState.vue';
import { useConfirm } from '../../../Composables/useConfirm';
import { useToast } from '../../../Composables/useToast';

defineOptions({ layout: AdminLayout });
const props = defineProps({ groups:Array, activeGroup:String, rows:Array, rowsByGroup:Object, can:Object, urls:Object });
const selectedGroup = ref(props.activeGroup);
const groupState = reactive(props.groups.map(group => ({ ...group })));
const rowsState = reactive(Object.fromEntries(props.groups.map(group => [
    group.key,
    [...(props.rowsByGroup?.[group.key] ?? (group.key === props.activeGroup ? props.rows : []))],
])));
const meta = computed(() => groupState.find(group => group.key === selectedGroup.value) || groupState[0]);
const rows = computed(() => rowsState[selectedGroup.value] || []);
const dialog = ref(null);
const editing = ref(null);
const saving = ref(false);
const rowAction = ref(null);
const { ask } = useConfirm();
const toast = useToast();
const form = useForm({ group: props.activeGroup, label:'', code:'', color:'#14804a', icon:'', display_order:0, is_active:true });
const previewStyle = computed(() => ({ color: form.color || '#52665b', backgroundColor: `${form.color || '#94a3b8'}18`, borderColor: `${form.color || '#94a3b8'}45` }));
const apiOptions = { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' } };

function selectGroup(group, updateHistory = true) {
    if (!group || group.key === selectedGroup.value) return;
    selectedGroup.value = group.key;
    form.group = group.key;
    form.clearErrors();

    if (updateHistory && typeof window !== 'undefined') {
        window.history.pushState({ lookupGroup: group.key }, '', group.url);
    }
}

function syncGroupFromAddress() {
    const key = new URL(window.location.href).searchParams.get('group');
    const group = groupState.find(candidate => candidate.key === key);
    if (group) selectGroup(group, false);
}

onMounted(() => window.addEventListener('popstate', syncGroupFromAddress));
onBeforeUnmount(() => window.removeEventListener('popstate', syncGroupFromAddress));

function openCreate(){ editing.value=null; form.reset(); form.clearErrors(); form.group=selectedGroup.value; form.color='#14804a'; form.is_active=true; dialog.value?.showModal(); }
function openEdit(row){ editing.value=row; form.clearErrors(); Object.assign(form,{ group:selectedGroup.value,label:row.label,code:row.code||'',color:row.color||'#94a3b8',icon:row.icon||'',display_order:row.displayOrder,is_active:row.active }); dialog.value?.showModal(); }
function close(){ dialog.value?.close(); }

function payload() {
    return {
        group: form.group,
        label: form.label,
        code: form.code,
        color: form.color,
        icon: form.icon,
        display_order: form.display_order,
        is_active: form.is_active,
    };
}

function upsertRow(row) {
    const groupRows = rowsState[row.group] || (rowsState[row.group] = []);
    const index = groupRows.findIndex(item => item.id === row.id);
    if (index === -1) groupRows.push(row);
    else groupRows.splice(index, 1, row);
    groupRows.sort((first, second) => first.displayOrder - second.displayOrder || first.id - second.id);

    const group = groupState.find(item => item.key === row.group);
    if (group) group.count = groupRows.filter(item => item.active && !item.deleted).length;
}

function applyApiError(error) {
    const errors = error.response?.data?.errors;
    if (errors) {
        Object.entries(errors).forEach(([field, messages]) => {
            form.setError(field, Array.isArray(messages) ? messages[0] : messages);
        });
        return;
    }
    toast.error(error.response?.data?.message || 'تعذر إتمام العملية. حاول مرة أخرى.');
}

async function submit() {
    form.clearErrors();
    saving.value = true;
    try {
        const response = editing.value
            ? await axios.put(editing.value.updateUrl, payload(), apiOptions)
            : await axios.post(props.urls.store, payload(), apiOptions);
        upsertRow(response.data.row);
        toast.success(response.data.message);
        close();
    } catch (error) {
        applyApiError(error);
    } finally {
        saving.value = false;
    }
}

async function remove(row){
    if(!await ask({title:'إخفاء القيمة؟',message:'ستبقى السجلات السابقة مرتبطة بها، لكنها لن تظهر في الاختيارات الجديدة.',confirmLabel:'إخفاء',danger:true})) return;
    rowAction.value = row.id;
    try {
        const response = await axios.delete(row.deleteUrl, apiOptions);
        upsertRow(response.data.row);
        toast.success(response.data.message);
    } catch (error) {
        applyApiError(error);
    } finally {
        rowAction.value = null;
    }
}

async function restore(row){
    rowAction.value = row.id;
    try {
        const response = await axios.post(row.restoreUrl, {}, apiOptions);
        upsertRow(response.data.row);
        toast.success(response.data.message);
    } catch (error) {
        applyApiError(error);
    } finally {
        rowAction.value = null;
    }
}
</script>
<template>
    <ManagementPage mode="records">
<Head title="إدارة الثوابت" />
<PageHeader title="إدارة الثوابت" icon="bi-sliders" subtitle="قوائم قصيرة مشتركة يستخدمها النظام في النماذج والتقارير"><template #actions><button v-if="can.create" type="button" class="btn btn-primary" @click="openCreate"><i class="bi bi-plus-lg"></i> قيمة جديدة</button></template></PageHeader>
<nav class="groups" aria-label="مجموعات الثوابت"><button v-for="group in groupState" :key="group.key" type="button" :class="{active:group.key===selectedGroup}" :aria-current="group.key===selectedGroup?'page':undefined" @click="selectGroup(group)"><i class="bi" :class="group.icon"></i><span>{{group.label}}</span><b>{{group.count}}</b></button></nav>
<section class="workspace"><header><div><small>القائمة الحالية</small><h2><i class="bi" :class="meta.icon"></i>{{meta.label}}</h2><p>{{meta.subtitle}}</p></div><span>{{rows.length}} قيمة</span></header><div v-if="rows.length" class="rows"><article v-for="row in rows" :key="row.id" :class="{deleted:row.deleted}"><span class="token" :style="{color:row.color,backgroundColor:`${row.color}18`,borderColor:`${row.color}45`}"><i class="bi" :class="row.icon||'bi-circle-fill'"></i></span><div class="identity"><strong>{{row.label}}</strong><code>{{row.code||'بلا كود'}}</code></div><span class="order">ترتيب {{row.displayOrder}}</span><span class="state" :data-state="row.deleted?'deleted':row.active?'active':'inactive'">{{row.deleted?'محذوف':row.active?'مفعّل':'معطّل'}}</span><span v-if="row.system" class="system"><i class="bi bi-shield-lock"></i> نظامية</span><div class="actions"><button v-if="row.deleted" type="button" :disabled="rowAction===row.id" @click="restore(row)"><i class="bi bi-arrow-counterclockwise"></i> استرجاع</button><template v-else><button v-if="can.update" type="button" @click="openEdit(row)"><i class="bi bi-pencil"></i> تعديل</button><button v-if="can.delete&&!row.system" type="button" class="danger" :disabled="rowAction===row.id" @click="remove(row)"><i class="bi bi-eye-slash"></i></button></template></div></article></div><EmptyState v-else icon="bi-list-ul" title="لا توجد قيم بعد" message="أضف أول قيمة لهذه القائمة؛ ستظهر فوراً في مواضع استخدامها." /></section>
<dialog ref="dialog" class="lookup-dialog" @close="form.clearErrors()"><form @submit.prevent="submit"><header><span><i class="bi" :class="editing?'bi-pencil-square':'bi-plus-circle'"></i></span><div><small>{{editing?'تعديل قيمة':'قيمة جديدة'}}</small><h2>{{meta.label}}</h2></div><button type="button" @click="close"><i class="bi bi-x-lg"></i></button></header><div v-if="editing?.system" class="hint"><i class="bi bi-shield-lock"></i> القيمة نظامية؛ الكود ثابت لحماية الترابط، ويمكن تعديل العرض والحالة.</div><div class="form-grid"><label class="wide"><span>التسمية *</span><input v-model="form.label" class="form-control" maxlength="120" required><em>{{form.errors.label}}</em></label><label><span>الترتيب</span><input v-model.number="form.display_order" type="number" min="0" max="9999" class="form-control"><em>{{form.errors.display_order}}</em></label><label><span>الكود الاختياري</span><input v-model="form.code" class="form-control" maxlength="60" pattern="[a-zA-Z0-9_]+" :readonly="editing?.system" placeholder="maintenance"><em>{{form.errors.code}}</em></label><label><span>أيقونة Bootstrap</span><input v-model="form.icon" class="form-control" placeholder="bi-cash-coin"><em>{{form.errors.icon}}</em></label><label><span>اللون</span><div class="color"><input v-model="form.color" type="color"><bdi>{{form.color}}</bdi></div></label><label class="switch"><input v-model="form.is_active" type="checkbox"><span><strong>مفعّل</strong><small>يظهر في القوائم الجديدة</small></span></label></div><div class="preview"><small>المعاينة</small><span :style="previewStyle"><i class="bi" :class="form.icon||'bi-circle-fill'"></i>{{form.label||'اسم القيمة'}}</span></div><footer><button type="button" class="btn btn-light" @click="close">إلغاء</button><button class="btn btn-primary" :disabled="saving"><i class="bi" :class="saving?'bi-arrow-repeat spin':'bi-check2'"></i>{{saving?'جارٍ الحفظ...':editing?'حفظ التعديل':'إضافة القيمة'}}</button></footer></form></dialog>
    </ManagementPage>
</template>
<style scoped>
.groups{display:flex;gap:.45rem;overflow-x:auto;padding:.55rem;background:var(--surface, #FFFDFA);border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;margin:.8rem 0}.groups button{min-width:max-content;display:flex;align-items:center;gap:.45rem;padding:.55rem .7rem;border:0;border-radius:9px;background:transparent;color:var(--muted, #626B6D);font-size:.875rem;font-weight:750;cursor:pointer}.groups button b{background:var(--canvas, #F7F3ED);border-radius:12px;padding:.08rem .4rem;font-size:.875rem}.groups button.active{background:var(--brand, #A3422B);color:#fff}.groups button.active b{background:#ffffff2a}.workspace{background:var(--surface, #FFFDFA);border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;overflow:hidden}.workspace>header{display:flex;justify-content:space-between;align-items:center;padding:1rem;border-bottom:1px solid var(--canvas, #F7F3ED)}.workspace h2{font-size:1rem;margin:.1rem 0;display:flex;align-items:center;gap:.4rem}.workspace header small,.workspace header p{font-size:.875rem;color:var(--muted, #626B6D);margin:0}.workspace>header>span{background:var(--canvas, #F7F3ED);color:var(--brand, #A3422B);border-radius:12px;padding:.35rem .65rem;font-size:.875rem;font-weight:800}.rows article{display:grid;grid-template-columns:42px minmax(170px,1fr) 90px 80px 90px auto;gap:.65rem;align-items:center;padding:.65rem 1rem;border-bottom:1px solid var(--canvas, #F7F3ED)}.rows article.deleted{opacity:.58;background:var(--canvas, #F7F3ED)}.token{width:40px;height:40px;display:grid;place-items:center;border:1px solid;border-radius:10px}.identity{display:grid}.identity strong{font-size:.875rem}.identity code{font-size:.875rem;color:var(--muted, #626B6D)}.order,.state,.system{font-size:.875rem}.state{border-radius:12px;padding:.25rem .5rem;text-align:center;background:#fff4df;color:#a16708}.state[data-state=active]{background:var(--canvas, #F7F3ED);color:var(--ink, #25343A)}.state[data-state=deleted]{background:var(--canvas, #F7F3ED);color:var(--muted, #626B6D)}.system{color:var(--muted, #626B6D)}.actions{display:flex;justify-content:flex-end;gap:.3rem}.actions button{border:1px solid var(--brand-soft, #F3E3D8);background:var(--surface, #FFFDFA);border-radius:8px;padding:.38rem .55rem;color:var(--muted, #626B6D);font-size:.875rem}.actions button.danger{color:#b83a3a}.actions button:disabled{opacity:.55;cursor:wait}.lookup-dialog{width:min(640px,calc(100% - 1rem));padding:0;border:0;border-radius:12px;box-shadow:none}.lookup-dialog::backdrop{background:#17251f80;backdrop-filter:none}.lookup-dialog form>header{display:grid;grid-template-columns:44px 1fr auto;align-items:center;gap:.6rem;padding:1rem;border-bottom:1px solid var(--canvas, #F7F3ED)}.lookup-dialog header>span{display:grid;place-items:center;width:42px;height:42px;border-radius:11px;background:var(--canvas, #F7F3ED);color:var(--brand, #A3422B)}.lookup-dialog h2{font-size:.95rem;margin:.05rem 0}.lookup-dialog header small{font-size:.875rem;color:var(--muted, #626B6D)}.lookup-dialog header button{border:0;background:var(--canvas, #F7F3ED);border-radius:9px;width:36px;height:36px}.hint{margin:.8rem 1rem 0;padding:.65rem;border-radius:10px;background:#edf5ff;color:#356285;font-size:.875rem}.form-grid{display:grid;grid-template-columns:2fr 1fr;gap:.7rem;padding:1rem}.form-grid label{display:grid;gap:.28rem}.form-grid label>span{font-size:.875rem;font-weight:750}.form-grid em{color:#b63737;font-size:.875rem;min-height:.65rem;font-style:normal}.wide{grid-column:auto}.color{display:flex;align-items:center;gap:.5rem;border:1px solid var(--brand-soft, #F3E3D8);border-radius:9px;padding:.28rem}.color input{width:42px;height:30px;border:0;background:none}.color bdi{font-size:.875rem;color:var(--muted, #626B6D)}.switch{display:flex!important;align-items:center;gap:.5rem;padding:.45rem;border:1px solid var(--brand-soft, #F3E3D8);border-radius:10px}.switch input{width:18px;height:18px}.switch span{display:grid}.switch small{color:var(--muted, #626B6D);font-size:.875rem}.preview{display:flex;align-items:center;gap:.6rem;margin:0 1rem;padding:.7rem;background:#f6f9f7;border-radius:10px}.preview small{font-size:.875rem;color:#798a80}.preview span{display:inline-flex;align-items:center;gap:.35rem;border:1px solid;border-radius:12px;padding:.3rem .6rem;font-size:.875rem;font-weight:800}.lookup-dialog footer{display:flex;justify-content:flex-end;gap:.5rem;padding:1rem;border-top:1px solid var(--canvas, #F7F3ED)}.spin{display:inline-block;animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(1turn)}}@media(max-width:700px){.rows article{grid-template-columns:38px 1fr auto}.order,.system{display:none}.state{grid-column:2}.actions{grid-column:3;grid-row:1/3}.form-grid{grid-template-columns:1fr}.lookup-dialog{margin:auto}}

/* Dar Al Sarw management layout */
.groups { padding: 7px; gap: 8px; margin-block: 20px; border-radius: 10px; } .groups button { min-height: 46px; border-radius: 8px; } .workspace { border-radius: 12px; } .workspace>header { padding: 20px; border-bottom: 1px solid var(--line); background: var(--canvas); }
</style>
