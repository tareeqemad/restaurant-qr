<script setup>
import { computed, ref, watch } from 'vue';

const props = defineProps({
    groups: { type: Array, default: () => [] },
    modelValue: { type: Array, default: () => [] },
    baseline: { type: Array, default: () => [] },
    disabled: { type: Boolean, default: false },
    search: { type: String, default: '' },
    mode: { type: String, default: 'role' },
    defaultFilter: { type: String, default: 'all' },
});

const emit = defineEmits(['update:modelValue']);
const statusFilter = ref(props.defaultFilter);
const activeGroupKey = ref(props.groups[0]?.key ?? null);
const selected = computed(() => new Set(props.modelValue.map(Number)));
const defaults = computed(() => new Set(props.baseline.map(Number)));
const needle = computed(() => props.search.trim().toLocaleLowerCase('ar'));
const isUserMode = computed(() => props.mode === 'user');

function stateOf(permission) {
    const id = Number(permission.id);
    const active = selected.value.has(id);
    const inherited = defaults.value.has(id);
    if (! isUserMode.value) {
        return { active, inherited: false, changed: false, tone: active ? 'enabled' : 'disabled' };
    }
    return {
        active,
        inherited,
        changed: active !== inherited,
        tone: active === inherited ? 'default' : active ? 'granted' : 'revoked',
    };
}

function matchesFilter(permission) {
    const state = stateOf(permission);
    if (statusFilter.value === 'enabled') return state.active;
    if (statusFilter.value === 'disabled') return ! state.active;
    if (statusFilter.value === 'changes') return state.changed;
    return true;
}

const visibleGroups = computed(() => props.groups
    .map((group) => ({
        ...group,
        permissions: group.permissions.filter((permission) => {
            const matchesSearch = ! needle.value || `${group.label} ${permission.label} ${permission.name}`
                .toLocaleLowerCase('ar')
                .includes(needle.value);
            return matchesSearch && matchesFilter(permission);
        }),
    }))
    .filter((group) => group.permissions.length));

const activeGroup = computed(() => visibleGroups.value.find((group) => group.key === activeGroupKey.value)
    ?? visibleGroups.value[0]
    ?? null);
const activeGroupIndex = computed(() => visibleGroups.value.findIndex((group) => group.key === activeGroup.value?.key));
const enabledCount = computed(() => selected.value.size);
const changeCount = computed(() => props.groups.reduce((total, group) => total + group.permissions
    .filter((permission) => stateOf(permission).changed).length, 0));

watch(visibleGroups, (groups) => {
    if (! groups.some((group) => group.key === activeGroupKey.value)) {
        activeGroupKey.value = groups[0]?.key ?? null;
    }
});

watch(() => props.mode, () => {
    statusFilter.value = props.defaultFilter;
});

function groupStats(group) {
    const source = props.groups.find((candidate) => candidate.key === group.key) ?? group;
    const count = source.permissions.filter((permission) => selected.value.has(Number(permission.id))).length;
    return { count, total: source.permissions.length };
}

function visibleGroupState(group) {
    const ids = group.permissions.map((permission) => Number(permission.id));
    const count = ids.filter((id) => selected.value.has(id)).length;
    return { count, all: count === ids.length, some: count > 0 && count < ids.length };
}

function togglePermission(id) {
    if (props.disabled) return;
    const next = new Set(selected.value);
    next.has(id) ? next.delete(id) : next.add(id);
    emit('update:modelValue', [...next]);
}

function toggleGroup(group) {
    if (props.disabled) return;
    const next = new Set(selected.value);
    const ids = group.permissions.map((permission) => Number(permission.id));
    const allSelected = ids.every((id) => next.has(id));
    ids.forEach((id) => allSelected ? next.delete(id) : next.add(id));
    emit('update:modelValue', [...next]);
}

function stateLabel(permission) {
    const state = stateOf(permission);
    if (! isUserMode.value) return state.active ? 'مفعّلة' : 'غير مفعّلة';
    if (state.tone === 'granted') return 'منح إضافي';
    if (state.tone === 'revoked') return 'مسحوبة من الدور';
    return state.inherited ? 'موروثة من الدور' : 'غير ممنوحة';
}

function resetView() {
    statusFilter.value = 'all';
}

function moveGroup(direction) {
    if (visibleGroups.value.length < 2) return;
    const current = Math.max(activeGroupIndex.value, 0);
    const next = (current + direction + visibleGroups.value.length) % visibleGroups.value.length;
    activeGroupKey.value = visibleGroups.value[next].key;
}
</script>

<template>
    <div class="permission-browser">
        <div class="browser-toolbar">
            <div class="status-filters" role="tablist" aria-label="تصفية الصلاحيات">
                <button type="button" :class="{ active: statusFilter === 'all' }" @click="statusFilter = 'all'">الكل</button>
                <button v-if="isUserMode" type="button" :class="{ active: statusFilter === 'changes' }" @click="statusFilter = 'changes'">
                    الاستثناءات <b>{{ changeCount }}</b>
                </button>
                <button type="button" :class="{ active: statusFilter === 'enabled' }" @click="statusFilter = 'enabled'">المفعّلة</button>
                <button type="button" :class="{ active: statusFilter === 'disabled' }" @click="statusFilter = 'disabled'">غير المفعّلة</button>
            </div>
            <div class="browser-summary">
                <span><strong>{{ enabledCount }}</strong> مفعّلة</span>
                <span v-if="isUserMode" :class="{ changed: changeCount }"><strong>{{ changeCount }}</strong> استثناء</span>
            </div>
        </div>

        <div v-if="visibleGroups.length" class="browser-body">
            <nav class="group-picker" aria-label="أقسام الصلاحيات">
                <div class="group-picker-copy">
                    <strong>اختر مساحة العمل</strong>
                    <small>{{ activeGroupIndex + 1 }} من {{ visibleGroups.length }} أقسام مطابقة</small>
                </div>
                <button type="button" aria-label="القسم السابق" @click="moveGroup(-1)"><i class="bi bi-chevron-right"></i></button>
                <label>
                    <i class="bi" :class="activeGroup?.icon"></i>
                    <select v-model="activeGroupKey" aria-label="اختر قسم الصلاحيات">
                        <option v-for="group in visibleGroups" :key="group.key" :value="group.key">
                            {{ group.label }} — {{ groupStats(group).count }} من {{ groupStats(group).total }} مفعّلة
                        </option>
                    </select>
                    <i class="bi bi-chevron-down"></i>
                </label>
                <button type="button" aria-label="القسم التالي" @click="moveGroup(1)"><i class="bi bi-chevron-left"></i></button>
            </nav>

            <section v-if="activeGroup" class="group-editor">
                <header class="group-header">
                    <span class="group-icon is-large"><i class="bi" :class="activeGroup.icon"></i></span>
                    <div><small>قسم الصلاحيات</small><h3>{{ activeGroup.label }}</h3><p>{{ activeGroup.permissions.length }} إجراء ظاهر الآن</p></div>
                    <button type="button" :disabled="disabled"
                            :class="{ active: visibleGroupState(activeGroup).all, partial: visibleGroupState(activeGroup).some }"
                            @click="toggleGroup(activeGroup)">
                        <i class="bi" :class="visibleGroupState(activeGroup).all ? 'bi-dash-circle' : 'bi-check-all'"></i>
                        {{ visibleGroupState(activeGroup).all ? 'سحب كل الظاهر' : 'منح كل الظاهر' }}
                    </button>
                </header>

                <div class="permission-list">
                    <label v-for="permission in activeGroup.permissions" :key="permission.id"
                           :class="[`is-${stateOf(permission).tone}`, `impact-${permission.impactTone}`, { disabled }]">
                        <input type="checkbox" :checked="stateOf(permission).active" :disabled="disabled"
                               @change="togglePermission(Number(permission.id))" />
                        <span class="check"><i class="bi bi-check2"></i></span>
                        <span class="permission-copy">
                            <strong>{{ permission.label }}</strong>
                            <small class="permission-impact"><i class="bi" :class="permission.impactIcon"></i>{{ permission.impactLabel }}</small>
                        </span>
                        <span class="state-pill">{{ stateLabel(permission) }}</span>
                    </label>
                </div>
            </section>
        </div>

        <div v-else class="tree-empty">
            <i class="bi" :class="statusFilter === 'changes' ? 'bi-shield-check' : 'bi-search'"></i>
            <strong>{{ statusFilter === 'changes' ? 'لا توجد استثناءات لهذا الموظف' : 'لا توجد صلاحيات مطابقة' }}</strong>
            <span>{{ statusFilter === 'changes' ? 'يعمل بصلاحيات دوره تماماً. افتح كل الصلاحيات لإضافة استثناء.' : 'جرّب كلمة أقصر أو غيّر الفلتر.' }}</span>
            <button type="button" @click="resetView">عرض كل الصلاحيات</button>
        </div>
    </div>
</template>

<style scoped>
.permission-browser{overflow:hidden;border:1px solid #dfe7e2;border-radius:16px;background:#fff}.browser-toolbar{display:flex;min-height:56px;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px;border-bottom:1px solid #e9efeb;background:#f8faf9}.status-filters{display:flex;gap:4px;padding:3px;border-radius:10px;background:#edf2ee}.status-filters button{display:flex;min-height:34px;align-items:center;gap:5px;padding:0 10px;border:0;border-radius:8px;background:transparent;color:#69786f;font:inherit;font-size:.59rem;font-weight:800}.status-filters button.active{background:#fff;color:#176f3a;box-shadow:0 2px 8px rgba(27,63,39,.08)}.status-filters b{display:grid;min-width:18px;height:18px;place-items:center;border-radius:999px;background:#fff1d9;color:#955d08;font-size:.5rem}.browser-summary{display:flex;gap:7px}.browser-summary span{padding:5px 8px;border-radius:8px;background:#eef6f0;color:#386247;font-size:.56rem}.browser-summary span.changed{background:#fff2dc;color:#8d5909}.browser-summary strong{font-size:.65rem}.browser-body{min-height:320px}.group-picker{display:grid;grid-template-columns:minmax(150px,1fr) 38px minmax(280px,1.5fr) 38px;align-items:center;gap:7px;padding:9px 12px;border-bottom:1px solid #e8eeea;background:#fbfcfb}.group-picker-copy{display:grid;gap:1px}.group-picker-copy strong{color:#33463a;font-size:.65rem}.group-picker-copy small{color:#87938b;font-size:.53rem}.group-picker>button{display:grid;width:38px;height:38px;place-items:center;border:1px solid #dce6df;border-radius:9px;background:#fff;color:#587063}.group-picker>button:hover{border-color:#b9d2c0;background:#eef8f1;color:#176f3a}.group-picker label{position:relative;display:grid;min-width:0;grid-template-columns:28px minmax(0,1fr) 20px;align-items:center;gap:7px;height:42px;padding:0 9px;border:1px solid #cfe0d4;border-radius:10px;background:#fff;color:#176f3a}.group-picker label>i:first-child{display:grid;width:27px;height:27px;place-items:center;border-radius:7px;background:#e9f5ed}.group-picker select{width:100%;height:100%;border:0;outline:0;appearance:none;background:transparent;color:#2f4537;font:inherit;font-size:.64rem;font-weight:850;cursor:pointer}.group-picker label>i:last-child{font-size:.55rem}.group-icon{display:grid;width:31px;height:31px;place-items:center;border-radius:9px;background:#edf2ef;color:#5f7166}.group-editor{min-width:0;padding:12px}.group-header{display:grid;grid-template-columns:42px minmax(0,1fr) auto;align-items:center;gap:10px;margin-bottom:10px;padding:11px;border:1px solid #e4ebe6;border-radius:13px;background:#fafcfb}.group-icon.is-large{width:40px;height:40px;border-radius:11px;background:#e8f4ec;color:#176f3a;font-size:.95rem}.group-header>div{display:grid}.group-header small{color:#87938b;font-size:.53rem}.group-header h3{margin:0;color:#21372a;font-size:.8rem;font-weight:900}.group-header p{margin:1px 0 0;color:#829087;font-size:.54rem}.group-header>button{display:flex;min-height:38px;align-items:center;gap:6px;padding:0 11px;border:1px solid #cedfd3;border-radius:9px;background:#f0f8f2;color:#176f3a;font:inherit;font-size:.58rem;font-weight:850}.group-header>button.active{border-color:#edc3c7;background:#fff3f3;color:#a62f37}.group-header>button.partial{border-color:#e8d1a5;background:#fff9ed;color:#936009}.group-header>button:disabled{opacity:.5}.permission-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:6px}.permission-list label{display:grid;min-height:58px;grid-template-columns:27px minmax(0,1fr);align-items:center;gap:8px;padding:8px 9px;border:1px solid #e7ece8;border-radius:11px;background:#fff;cursor:pointer}.permission-list label:hover{border-color:#bcd0c2;background:#f9fbfa}.permission-list label.disabled{cursor:default}.permission-list input{position:absolute;inline-size:1px;block-size:1px;opacity:0}.check{display:grid;width:25px;height:25px;place-items:center;border:1px solid #cfd9d2;border-radius:7px;color:transparent;background:#fff}.permission-list input:checked+.check{border-color:#17713c;background:#17713c;color:#fff}.permission-list label.is-granted{border-color:#b7d8c0;background:#f5fbf7}.permission-list label.is-granted .check{border-color:#168448;background:#168448;color:#fff}.permission-list label.is-revoked{border-color:#edc7ca;background:#fff8f8}.permission-list label.is-revoked .check{border-color:#d79ba1;background:#fff;color:transparent}.permission-copy{display:grid;min-width:0}.permission-copy strong{color:#34463b;font-size:.65rem;line-height:1.45}.permission-copy small{margin-top:2px;color:#87938b;font-size:.52rem}.is-enabled .permission-copy small,.is-granted .permission-copy small{color:#167540}.is-revoked .permission-copy small{color:#a22d36}.tree-empty{display:grid;min-height:300px;place-content:center;justify-items:center;gap:6px;padding:20px;color:#829087;text-align:center}.tree-empty>i{font-size:1.5rem}.tree-empty strong{color:#3f5146;font-size:.8rem}.tree-empty span{max-width:390px;font-size:.61rem;line-height:1.7}.tree-empty button{min-height:38px;margin-top:4px;padding:0 12px;border:1px solid #cfe0d4;border-radius:9px;background:#f0f8f2;color:#176f3a;font:inherit;font-size:.59rem;font-weight:850}
@media(max-width:1000px){.permission-list{grid-template-columns:1fr}}
@media(max-width:700px){.browser-toolbar{align-items:stretch;flex-direction:column}.status-filters{overflow-x:auto}.status-filters button{flex:0 0 auto}.browser-summary{justify-content:flex-end}.group-picker{grid-template-columns:34px minmax(0,1fr) 34px;padding:8px}.group-picker-copy{grid-column:1/-1}.group-picker>button{width:34px;height:36px}.group-picker label{height:40px}.group-editor{padding:9px}.group-header{grid-template-columns:36px minmax(0,1fr)}.group-header>button{grid-column:1/-1;justify-content:center}.group-icon.is-large{width:35px;height:35px}.permission-list label{min-height:54px}}
.permission-list label{min-height:66px;grid-template-columns:27px minmax(0,1fr) auto;padding:9px}.permission-impact{display:flex;align-items:center;gap:4px;margin-top:3px!important;color:#87938b!important;font-size:.5rem!important}.impact-sensitive .permission-impact{color:#a04a23!important}.impact-access .permission-impact{color:#3e6c82!important}.impact-action .permission-impact{color:#65776c!important}.state-pill{padding:4px 7px;border-radius:8px;background:#f0f3f1;color:#708078;font-size:.49rem;font-weight:850;white-space:nowrap}.is-enabled .state-pill,.is-granted .state-pill{color:#167540;background:#e7f5eb}.is-revoked .state-pill{color:#a22d36;background:#ffeaec}@media(max-width:700px){.permission-list label{min-height:58px;grid-template-columns:27px minmax(0,1fr)}.state-pill{grid-column:2;justify-self:start}}
</style>
