<script setup>
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { Head, Link, router, useForm } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import PageHeader from '../../../Components/Ui/PageHeader.vue'
import AccountingNav from '../../../Components/Accounting/AccountingNav.vue'
import AccountingPanel from '../../../Components/Accounting/AccountingPanel.vue'
import AccountTreeNode from '../../../Components/Accounting/AccountTreeNode.vue'
import AccountForm from '../../../Components/Accounts/AccountForm.vue'
import { useConfirm } from '../../../Composables/useConfirm'

defineOptions({ layout: AdminLayout })

const props = defineProps({
    sections: { type: Array, default: () => [] },
    stats: { type: Object, required: true },
    can: { type: Object, required: true },
    existingCodes: { type: Array, default: () => [] },
    typeOptions: { type: Array, default: () => [] },
    balanceOptions: { type: Array, default: () => [] },
    balanceByType: { type: Object, default: () => ({}) },
    urls: { type: Object, required: true },
})

const { ask } = useConfirm()
const search = ref('')
const activeType = ref('all')
const showInactive = ref(false)
const selectedId = ref(null)
const collapsed = reactive({})

const nodeIndex = computed(() => {
    const index = new Map()
    const walk = (node) => {
        index.set(node.id, node)
        node.children.forEach(walk)
    }
    props.sections.forEach((section) => section.nodes.forEach(walk))
    return index
})

const selected = computed(() => (
    selectedId.value ? nodeIndex.value.get(selectedId.value) ?? null : null
))

watch(() => props.sections, (sections) => {
    Object.keys(collapsed).forEach((key) => delete collapsed[key])
    const closeParents = (node) => {
        if (node.hasChildren) collapsed[node.id] = true
        node.children.forEach(closeParents)
    }
    sections.forEach((section) => section.nodes.forEach(closeParents))
    if (selectedId.value && !nodeIndex.value.has(selectedId.value)) selectedId.value = null
}, { immediate: true })

function normalize(value) {
    return String(value ?? '')
        .toLowerCase()
        .replace(/[ً-ٰ]/g, '')
        .replace(/[إأآا]/g, 'ا')
        .replace(/ة/g, 'ه')
        .replace(/ى/g, 'ي')
        .trim()
}

const needle = computed(() => normalize(search.value))
const filtered = computed(() => {
    if (!needle.value) return { hiddenIds: null, hiddenSections: null, expandIds: [] }

    const hiddenIds = new Set()
    const hiddenSections = new Set()
    const expandIds = []

    const walk = (node) => {
        const selfMatches = normalize(node.searchText).includes(needle.value)
        const childMatches = node.children.some((child) => walk(child))
        const visible = selfMatches || childMatches
        if (!visible) hiddenIds.add(node.id)
        else if (node.hasChildren) expandIds.push(node.id)
        return visible
    }

    props.sections.forEach((section) => {
        const sectionMatches = normalize(section.searchText).includes(needle.value)
        const nodeMatches = section.nodes.some((node) => walk(node))
        if (!sectionMatches && !nodeMatches) hiddenSections.add(section.type)
    })

    return { hiddenIds, hiddenSections, expandIds }
})

watch(filtered, (result) => {
    result.expandIds.forEach((id) => delete collapsed[id])
    if (!result.hiddenSections) return
    props.sections.forEach((section) => {
        if (!result.hiddenSections.has(section.type)) delete collapsed[`section-${section.type}`]
    })
})

const visibleSections = computed(() => props.sections.filter((section) => {
    if (activeType.value !== 'all' && section.type !== activeType.value) return false
    return !(filtered.value.hiddenSections?.has(section.type))
}))

const activeSectionCount = computed(() => (
    activeType.value === 'all'
        ? props.stats.total
        : props.sections.find((section) => section.type === activeType.value)?.totalRootCount ?? 0
))

function toggleNode(id) {
    if (collapsed[id]) delete collapsed[id]
    else collapsed[id] = true
}

function expandAll() {
    Object.keys(collapsed).forEach((key) => delete collapsed[key])
}

function collapseAll() {
    const closeParents = (node) => {
        if (node.hasChildren) collapsed[node.id] = true
        node.children.forEach(closeParents)
    }
    props.sections.forEach((section) => section.nodes.forEach(closeParents))
}

function selectAccount(id) {
    selectedId.value = selectedId.value === id ? null : id
}

async function toggleActive() {
    if (!selected.value?.can.update) return
    router.patch(selected.value.urls.toggle, {}, { preserveScroll: true })
}

async function destroy() {
    if (!selected.value?.can.delete) return
    const confirmed = await ask({
        title: 'حذف الحساب نهائياً؟',
        message: `${selected.value.code} — ${selected.value.name}. لا يمكن حذف حساب نظامي أو مستخدم في قيد.`,
        confirmLabel: 'حذف الحساب',
        danger: true,
    })
    if (confirmed) router.delete(selected.value.urls.destroy, { preserveScroll: true })
}

const modalOpen = ref(false)
const modalMode = ref('create')
const modalNode = ref(null)
const modalParent = ref(null)
const codeLocked = ref(false)
const codeUnlocked = ref(false)
const codeLockParent = ref(null)

const form = useForm({
    code: '',
    name: '',
    description: '',
    type: 'asset',
    normal_balance: 'debit',
    display_order: 0,
    parent_account_id: '',
    is_active: true,
})

const modalTitle = computed(() => (
    modalMode.value === 'edit'
        ? `تعديل ${modalNode.value?.code} — ${modalNode.value?.name}`
        : modalParent.value
            ? `حساب فرعي جديد تحت ${modalParent.value.code}`
            : 'حساب رئيسي جديد'
))

const parentNotice = computed(() => {
    if (modalMode.value === 'edit') return modalNode.value?.parentLabel ?? null
    return modalParent.value ? `${modalParent.value.code} — ${modalParent.value.name}` : null
})

// The first child locks the suffix width for the branch, so later suggestions
// stay readable beside their siblings instead of growing a new code pattern.
function suggestChildCode(parent) {
    const parentCode = parent.code || ''
    if (!/^\d+$/.test(parentCode)) return ''

    const childCodes = (parent.children || []).map((child) => child.code)
    const suffixWidth = childCodes.length
        ? Math.max(1, childCodes[0].substring(parentCode.length).length)
        : 1
    const numericSuffixes = childCodes
        .map((code) => code.substring(parentCode.length))
        .filter((suffix) => /^\d+$/.test(suffix))
        .map(Number)
    const nextNumber = (numericSuffixes.length ? Math.max(...numericSuffixes) : 0) + 1
    const taken = new Set(props.existingCodes)

    for (let offset = 0; offset < 999; offset += 1) {
        const candidate = parentCode + String(nextNumber + offset).padStart(suffixWidth, '0')
        if (!taken.has(candidate)) return candidate
    }
    return ''
}

function resetModal() {
    form.clearErrors()
    form.defaults({
        code: '',
        name: '',
        description: '',
        type: 'asset',
        normal_balance: 'debit',
        display_order: 0,
        parent_account_id: '',
        is_active: true,
    })
    form.reset()
    modalNode.value = null
    modalParent.value = null
    codeLocked.value = false
    codeUnlocked.value = false
    codeLockParent.value = null
}

function focusAccountName() {
    setTimeout(() => document.getElementById('acc-name-field')?.focus(), 220)
}

function openCreate(parent = null) {
    resetModal()
    modalMode.value = 'create'
    if (parent) {
        modalParent.value = parent
        form.parent_account_id = parent.id
        form.type = parent.type
        form.normal_balance = props.balanceByType[parent.type] ?? parent.normalBalance
        const suggestion = suggestChildCode(parent)
        if (suggestion) {
            form.code = suggestion
            codeLocked.value = true
            codeLockParent.value = parent.code
        }
    }
    modalOpen.value = true
    nextTick(focusAccountName)
}

function openEdit() {
    if (!selected.value?.can.update) return
    const account = selected.value
    resetModal()
    modalMode.value = 'edit'
    modalNode.value = account
    form.code = account.code
    form.name = account.name
    form.description = account.description ?? ''
    form.type = account.type
    form.normal_balance = account.normalBalance
    form.display_order = account.displayOrder
    form.parent_account_id = account.parentId ?? ''
    form.is_active = account.isActive
    modalOpen.value = true
    nextTick(focusAccountName)
}

function unlockCode() {
    codeLocked.value = false
    codeUnlocked.value = true
    nextTick(() => document.querySelector('.account-modal input[maxlength="32"]')?.focus())
}

function closeModal() {
    if (form.processing) return
    modalOpen.value = false
}

function submitModal() {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            modalOpen.value = false
            selectedId.value = null
        },
    }
    if (modalMode.value === 'edit') form.patch(modalNode.value.urls.update, options)
    else form.post(props.urls.store, options)
}
</script>

<template>
    <Head title="شجرة الحسابات" />

    <PageHeader
        title="شجرة الحسابات"
        icon="bi-diagram-3-fill"
        subtitle="هيكل بسيط للمطعم؛ اختر الحساب لتظهر إجراءاته وتاريخه"
        :crumbs="[{ label: 'المحاسبة', url: urls.workspace.home }]"
    >
        <template #actions>
            <button v-if="can.create" type="button" class="btn btn-primary" @click="openCreate()">
                <i class="bi bi-plus-lg"></i> حساب رئيسي
            </button>
        </template>
    </PageHeader>

    <AccountingNav :urls="urls.workspace" active="accounts" />

    <section class="chart-overview">
        <div class="chart-overview__copy">
            <span><i class="bi bi-shield-check"></i></span>
            <div>
                <strong>الشجرة جاهزة للتشغيل اليومي</strong>
                <small>لا تضف حساباً لكل تفصيل صغير؛ أضف حساباً عندما تحتاج رصيده مستقلاً في التقارير.</small>
            </div>
        </div>
        <div class="chart-overview__stats">
            <span><small>نشط</small><strong>{{ stats.active }}</strong></span>
            <span><small>نظامي</small><strong>{{ stats.system }}</strong></span>
            <span v-if="stats.inactive"><small>معطّل</small><strong>{{ stats.inactive }}</strong></span>
        </div>
        <Link v-if="urls.mappings" :href="urls.mappings" preserve-scroll>
            <i class="bi bi-link-45deg"></i> ربط العمليات
        </Link>
    </section>

    <section class="chart-tools">
        <label class="chart-search">
            <i class="bi bi-search"></i>
            <input v-model="search" type="search" placeholder="ابحث بكود الحساب أو اسمه…">
            <button v-if="search" type="button" aria-label="مسح البحث" @click="search = ''"><i class="bi bi-x-lg"></i></button>
        </label>

        <div class="type-chips" aria-label="أنواع الحسابات">
            <button type="button" :class="{ active: activeType === 'all' }" @click="activeType = 'all'">
                الكل <small>{{ stats.total }}</small>
            </button>
            <button
                v-for="section in sections"
                :key="section.type"
                type="button"
                :class="{ active: activeType === section.type }"
                @click="activeType = section.type"
            >
                {{ section.label }} <small>{{ section.totalRootCount }}</small>
            </button>
        </div>

        <div class="chart-tools__actions">
            <label v-if="stats.inactive" class="inactive-switch">
                <input v-model="showInactive" type="checkbox">
                <span>إظهار المعطّلة</span>
            </label>
            <button type="button" title="توسيع الشجرة" @click="expandAll"><i class="bi bi-arrows-expand"></i></button>
            <button type="button" title="طي الشجرة" @click="collapseAll"><i class="bi bi-arrows-collapse"></i></button>
        </div>
    </section>

    <div class="chart-layout">
        <AccountingPanel
            title="الحسابات"
            :description="`${activeSectionCount} حساباً أو مجموعة ضمن الاختيار`"
            icon="bi-list-nested"
            compact
        >
            <div v-if="visibleSections.length" class="chart-sections">
                <section v-for="section in visibleSections" :key="section.type" class="chart-section">
                    <button type="button" class="chart-section__head" @click="toggleNode(`section-${section.type}`)">
                        <span><i class="bi" :class="section.icon"></i></span>
                        <div>
                            <strong>{{ section.label }}</strong>
                            <small>{{ showInactive ? section.totalRootCount : section.activeRootCount }} حساباً رئيسياً</small>
                        </div>
                        <i class="bi" :class="collapsed[`section-${section.type}`] ? 'bi-chevron-left' : 'bi-chevron-down'"></i>
                    </button>
                    <ul v-if="!collapsed[`section-${section.type}`]" class="chart-tree">
                        <AccountTreeNode
                            v-for="node in section.nodes"
                            :key="node.id"
                            :node="node"
                            :selected-id="selectedId"
                            :collapsed="collapsed"
                            :hidden-ids="filtered.hiddenIds"
                            :show-inactive="showInactive"
                            :can-create="can.create"
                            @select="selectAccount"
                            @toggle="toggleNode"
                            @add-child="openCreate"
                        />
                    </ul>
                </section>
            </div>
            <div v-else class="chart-empty">
                <i class="bi bi-search"></i>
                <strong>لا توجد حسابات مطابقة</strong>
                <small>جرّب كلمة أقصر أو اختر «الكل».</small>
                <button type="button" @click="search = ''; activeType = 'all'">مسح التصفية</button>
            </div>
        </AccountingPanel>

        <aside class="account-inspector">
            <template v-if="selected">
                <header>
                    <span><i class="bi" :class="selected.isSystem ? 'bi-shield-lock-fill' : 'bi-journal-bookmark'"></i></span>
                    <div><bdi>{{ selected.code }}</bdi><strong>{{ selected.name }}</strong></div>
                    <button type="button" aria-label="إغلاق التفاصيل" @click="selectedId = null"><i class="bi bi-x-lg"></i></button>
                </header>
                <p v-if="selected.description">{{ selected.description }}</p>
                <dl>
                    <div><dt>النوع</dt><dd>{{ selected.typeLabel }}</dd></div>
                    <div><dt>الطبيعة</dt><dd>{{ selected.normalBalanceLabel }}</dd></div>
                    <div><dt>الحالة</dt><dd :class="selected.isActive ? 'good' : 'warn'">{{ selected.isActive ? 'نشط' : 'معطّل' }}</dd></div>
                    <div><dt>الاستخدام</dt><dd>{{ selected.hasJournalLines ? 'عليه قيود' : 'بلا قيود' }}</dd></div>
                </dl>
                <div v-if="selected.parentLabel" class="account-inspector__parent">
                    <small>تابع للحساب</small><strong>{{ selected.parentLabel }}</strong>
                </div>
                <div class="account-inspector__actions">
                    <button v-if="can.create" type="button" class="primary" @click="openCreate(selected)"><i class="bi bi-plus-lg"></i> حساب فرعي</button>
                    <button v-if="selected.can.update" type="button" @click="openEdit"><i class="bi bi-pencil"></i> تعديل</button>
                    <Link :href="selected.urls.ledger" preserve-scroll><i class="bi bi-journal-bookmark"></i> دفتر الحساب</Link>
                    <button v-if="selected.can.update && !selected.isSystem" type="button" @click="toggleActive"><i class="bi bi-power"></i> {{ selected.isActive ? 'تعطيل' : 'تشغيل' }}</button>
                    <button v-if="selected.can.delete" type="button" class="danger" @click="destroy"><i class="bi bi-trash3"></i> حذف</button>
                </div>
                <small v-if="!selected.can.delete" class="account-inspector__protection">
                    <i class="bi bi-shield-check"></i>
                    لا يحذف الحساب النظامي أو المستخدم أو الذي تحته حسابات؛ يمكن تعطيل الحساب العادي بدلاً من ذلك.
                </small>
            </template>
            <template v-else>
                <div class="account-inspector__empty">
                    <span><i class="bi bi-hand-index-thumb"></i></span>
                    <strong>اختر حساباً</strong>
                    <small>ستظهر هنا بياناته وإجراءات التعديل ودفتره، دون ازدحام الشجرة بالأزرار.</small>
                    <Link :href="urls.guide" preserve-scroll>متى أضيف حساباً؟</Link>
                </div>
            </template>
        </aside>
    </div>

    <Teleport to="body">
        <Transition name="account-modal">
            <div v-if="modalOpen" class="account-modal__backdrop" @click.self="closeModal">
                <form class="account-modal" role="dialog" aria-modal="true" @submit.prevent="submitModal">
                    <header>
                        <span><i class="bi" :class="modalMode === 'edit' ? 'bi-pencil-square' : 'bi-plus-lg'"></i></span>
                        <div><small>{{ modalMode === 'edit' ? 'تعديل الحساب' : 'إضافة إلى الشجرة' }}</small><strong>{{ modalTitle }}</strong></div>
                        <button type="button" aria-label="إغلاق" @click="closeModal"><i class="bi bi-x-lg"></i></button>
                    </header>
                    <main>
                        <AccountForm
                            :form="form"
                            variant="modal"
                            :type-options="typeOptions"
                            :balance-options="balanceOptions"
                            :balance-by-type="balanceByType"
                            :is-system="modalMode === 'edit' && Boolean(modalNode?.isSystem)"
                            :has-journal-lines="modalMode === 'edit' && Boolean(modalNode?.hasJournalLines)"
                            :parent-notice="parentNotice"
                            :code-locked="codeLocked"
                            :code-lock-parent="codeLockParent"
                            :code-unlocked="codeUnlocked"
                            @unlock-code="unlockCode"
                        />
                    </main>
                    <footer>
                        <button type="button" class="btn btn-light" @click="closeModal">إلغاء</button>
                        <button type="submit" class="btn btn-primary" :disabled="form.processing">
                            <i class="bi bi-check2"></i> {{ modalMode === 'edit' ? 'حفظ التعديل' : 'إضافة الحساب' }}
                        </button>
                    </footer>
                </form>
            </div>
        </Transition>
    </Teleport>
</template>

<style scoped>
.chart-overview {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto auto;
    align-items: center;
    gap: 14px;
    margin-bottom: 10px;
    padding: 13px 15px;
    border: 1px solid #b7d7c2;
    border-radius: 15px;
    background: linear-gradient(130deg, #eff8f2, #fff 72%);
}

.chart-overview__copy {
    display: flex;
    align-items: center;
    gap: 10px;
}

.chart-overview__copy > span {
    display: grid;
    flex: 0 0 38px;
    width: 38px;
    height: 38px;
    place-items: center;
    border-radius: 11px;
    color: #fff;
    background: rgb(var(--primary-rgb, 31, 107, 80));
}

.chart-overview__copy > div {
    display: grid;
}

.chart-overview__copy strong {
    color: #264435;
    font-size: .78rem;
}

.chart-overview__copy small {
    color: #7a8980;
    font-size: .62rem;
    line-height: 1.55;
}

.chart-overview__stats {
    display: flex;
    gap: 7px;
}

.chart-overview__stats span {
    display: grid;
    min-width: 58px;
    justify-items: center;
    padding: 6px 8px;
    border-radius: 10px;
    background: rgba(255, 255, 255, .78);
}

.chart-overview__stats small {
    color: #839087;
    font-size: .54rem;
}

.chart-overview__stats strong {
    color: #284637;
    font-size: .76rem;
}

.chart-overview > a {
    display: flex;
    min-height: 40px;
    align-items: center;
    gap: 6px;
    padding: 8px 11px;
    border: 1px solid #a8ccb4;
    border-radius: 10px;
    color: rgb(var(--primary-rgb, 31, 107, 80));
    background: #fff;
    font-size: .65rem;
    font-weight: 850;
}

.chart-tools {
    display: grid;
    grid-template-columns: minmax(240px, .8fr) minmax(0, 1.6fr) auto;
    align-items: center;
    gap: 9px;
    margin-bottom: 10px;
    padding: 9px;
    border: 1px solid #dce6df;
    border-radius: 14px;
    background: #fff;
}

.chart-search {
    display: flex;
    min-height: 44px;
    align-items: center;
    gap: 8px;
    padding: 0 11px;
    border: 1px solid #dbe4de;
    border-radius: 10px;
    background: #fafcfb;
}

.chart-search > i {
    color: #849188;
}

.chart-search input {
    width: 100%;
    min-width: 0;
    border: 0;
    outline: 0;
    background: transparent;
    font-size: .7rem;
}

.chart-search button {
    display: grid;
    width: 30px;
    height: 30px;
    place-items: center;
    border: 0;
    border-radius: 8px;
    color: #68776e;
    background: #eef2ef;
}

.type-chips {
    display: flex;
    min-width: 0;
    gap: 5px;
    overflow-x: auto;
    scrollbar-width: thin;
}

.type-chips button {
    display: flex;
    flex: 0 0 auto;
    min-height: 40px;
    align-items: center;
    gap: 5px;
    padding: 7px 10px;
    border: 1px solid #e0e7e2;
    border-radius: 10px;
    color: #68766d;
    background: #fff;
    font-size: .62rem;
    font-weight: 850;
}

.type-chips button.active {
    border-color: rgb(var(--primary-rgb, 31, 107, 80));
    color: #fff;
    background: rgb(var(--primary-rgb, 31, 107, 80));
}

.type-chips small {
    display: grid;
    min-width: 19px;
    height: 19px;
    place-items: center;
    border-radius: 999px;
    color: inherit;
    background: rgba(128, 143, 134, .12);
    font-size: .54rem;
}

.type-chips button.active small {
    background: rgba(255, 255, 255, .18);
}

.chart-tools__actions {
    display: flex;
    align-items: center;
    gap: 5px;
}

.chart-tools__actions > button {
    display: grid;
    width: 42px;
    height: 42px;
    place-items: center;
    border: 1px solid #dce5df;
    border-radius: 10px;
    color: #617067;
    background: #fff;
}

.inactive-switch {
    display: flex;
    min-height: 42px;
    align-items: center;
    gap: 6px;
    padding: 6px 9px;
    border-radius: 10px;
    color: #6b796f;
    background: #f7f9f8;
    font-size: .6rem;
    font-weight: 800;
    white-space: nowrap;
}

.inactive-switch input {
    width: 17px;
    height: 17px;
    accent-color: rgb(var(--primary-rgb, 31, 107, 80));
}

.chart-layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(285px, 330px);
    align-items: start;
    gap: 11px;
}

.chart-sections {
    display: grid;
}

.chart-section {
    border-bottom: 1px solid #edf1ee;
}

.chart-section:last-child {
    border-bottom: 0;
}

.chart-section__head {
    display: flex;
    width: 100%;
    min-height: 58px;
    align-items: center;
    gap: 9px;
    padding: 9px 12px;
    border: 0;
    color: #304238;
    background: #fafcfb;
    text-align: start;
}

.chart-section__head > span {
    display: grid;
    width: 36px;
    height: 36px;
    place-items: center;
    border-radius: 10px;
    color: rgb(var(--primary-rgb, 31, 107, 80));
    background: #eaf4ed;
}

.chart-section__head > div {
    display: grid;
    flex: 1;
}

.chart-section__head strong {
    font-size: .76rem;
}

.chart-section__head small {
    color: #86928a;
    font-size: .57rem;
}

.chart-section__head > i {
    color: #89958d;
    font-size: .7rem;
}

.chart-tree {
    margin: 0;
    padding: 5px 8px 8px;
}

.chart-empty {
    display: grid;
    min-height: 300px;
    place-content: center;
    justify-items: center;
    gap: 5px;
    padding: 30px;
    color: #849088;
    text-align: center;
}

.chart-empty > i {
    font-size: 1.6rem;
}

.chart-empty strong {
    color: #4d5d53;
    font-size: .8rem;
}

.chart-empty small {
    font-size: .63rem;
}

.chart-empty button {
    min-height: 40px;
    margin-top: 5px;
    padding: 7px 12px;
    border: 1px solid #b9d5c2;
    border-radius: 9px;
    color: rgb(var(--primary-rgb, 31, 107, 80));
    background: #fff;
    font-size: .64rem;
    font-weight: 850;
}

.account-inspector {
    position: sticky;
    top: 188px;
    overflow: hidden;
    min-height: 300px;
    border: 1px solid #dce6df;
    border-radius: 16px;
    background: #fff;
    box-shadow: 0 10px 30px rgba(24, 52, 35, .055);
}

.account-inspector > header {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 13px;
    border-bottom: 1px solid #edf1ee;
    background: #f8fbf9;
}

.account-inspector > header > span {
    display: grid;
    width: 38px;
    height: 38px;
    place-items: center;
    border-radius: 11px;
    color: rgb(var(--primary-rgb, 31, 107, 80));
    background: #e8f3ec;
}

.account-inspector > header > div {
    display: grid;
    min-width: 0;
    flex: 1;
}

.account-inspector > header bdi {
    color: rgb(var(--primary-rgb, 31, 107, 80));
    font-family: Consolas, monospace;
    font-size: .64rem;
    font-weight: 900;
}

.account-inspector > header strong {
    overflow: hidden;
    font-size: .76rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.account-inspector > header button {
    display: grid;
    width: 36px;
    height: 36px;
    place-items: center;
    border: 0;
    border-radius: 9px;
    color: #6f7d74;
    background: #eef2ef;
}

.account-inspector > p {
    margin: 0;
    padding: 11px 13px;
    border-bottom: 1px solid #edf1ee;
    color: #748178;
    font-size: .63rem;
    line-height: 1.6;
}

.account-inspector dl {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 7px;
    margin: 0;
    padding: 12px;
}

.account-inspector dl div {
    display: grid;
    gap: 2px;
    padding: 8px;
    border-radius: 9px;
    background: #f7f9f8;
}

.account-inspector dt {
    color: #89958d;
    font-size: .55rem;
}

.account-inspector dd {
    margin: 0;
    color: #3f5146;
    font-size: .65rem;
    font-weight: 850;
}

.account-inspector dd.good {
    color: #187440;
}

.account-inspector dd.warn {
    color: #a05c06;
}

.account-inspector__parent {
    display: grid;
    gap: 2px;
    margin: 0 12px 11px;
    padding: 9px;
    border-radius: 9px;
    color: #52655a;
    background: #eef6f1;
}

.account-inspector__parent small {
    color: #829087;
    font-size: .54rem;
}

.account-inspector__parent strong {
    font-size: .62rem;
}

.account-inspector__actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 7px;
    padding: 0 12px 12px;
}

.account-inspector__actions button,
.account-inspector__actions a {
    display: flex;
    min-height: 42px;
    align-items: center;
    justify-content: center;
    gap: 6px;
    padding: 7px;
    border: 1px solid #dbe4de;
    border-radius: 10px;
    color: #53635a;
    background: #fff;
    font-size: .61rem;
    font-weight: 850;
}

.account-inspector__actions .primary {
    border-color: rgb(var(--primary-rgb, 31, 107, 80));
    color: #fff;
    background: rgb(var(--primary-rgb, 31, 107, 80));
}

.account-inspector__actions .danger {
    border-color: #ebc0c0;
    color: #b53232;
    background: #fff7f7;
}

.account-inspector__protection {
    display: flex;
    gap: 6px;
    margin: 0 12px 12px;
    padding: 8px;
    border-radius: 9px;
    color: #7d8982;
    background: #f6f8f7;
    font-size: .55rem;
    line-height: 1.5;
}

.account-inspector__empty {
    display: grid;
    min-height: 300px;
    align-content: center;
    justify-items: center;
    gap: 5px;
    padding: 25px;
    color: #87938b;
    text-align: center;
}

.account-inspector__empty > span {
    display: grid;
    width: 48px;
    height: 48px;
    place-items: center;
    border-radius: 14px;
    color: rgb(var(--primary-rgb, 31, 107, 80));
    background: #eaf4ed;
    font-size: 1.2rem;
}

.account-inspector__empty strong {
    color: #536159;
    font-size: .78rem;
}

.account-inspector__empty small {
    font-size: .61rem;
    line-height: 1.55;
}

.account-inspector__empty a {
    min-height: 40px;
    margin-top: 5px;
    padding: 8px 11px;
    color: rgb(var(--primary-rgb, 31, 107, 80));
    font-size: .62rem;
    font-weight: 850;
}

.account-modal__backdrop {
    position: fixed;
    z-index: 18000;
    inset: 0;
    display: grid;
    overflow-y: auto;
    place-items: center;
    padding: 16px;
    background: rgba(15, 28, 20, .58);
    backdrop-filter: blur(4px);
}

.account-modal {
    display: flex;
    width: min(760px, 100%);
    max-height: 92vh;
    overflow: hidden;
    flex-direction: column;
    border-radius: 19px;
    background: #fff;
    box-shadow: 0 28px 80px rgba(8, 26, 15, .25);
}

.account-modal > header {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 13px 15px;
    border-bottom: 1px solid #e9efeb;
    background: #f8fbf9;
}

.account-modal > header > span,
.account-modal > header > button {
    display: grid;
    flex: 0 0 40px;
    width: 40px;
    height: 40px;
    place-items: center;
    border: 0;
    border-radius: 11px;
}

.account-modal > header > span {
    color: #fff;
    background: rgb(var(--primary-rgb, 31, 107, 80));
}

.account-modal > header > button {
    color: #68766d;
    background: #eaf0ec;
}

.account-modal > header > div {
    display: grid;
    min-width: 0;
    flex: 1;
}

.account-modal > header small {
    color: #849188;
    font-size: .57rem;
}

.account-modal > header strong {
    overflow: hidden;
    font-size: .82rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.account-modal > main {
    overflow-y: auto;
    padding: 15px;
}

.account-modal > footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 11px 15px;
    border-top: 1px solid #e9efeb;
    background: #fbfcfb;
}

.account-modal > footer .btn {
    min-height: 44px;
}

.account-modal-enter-active,
.account-modal-leave-active {
    transition: opacity .16s ease;
}

.account-modal-enter-from,
.account-modal-leave-to {
    opacity: 0;
}

@media (max-width: 1050px) {
    .chart-overview {
        grid-template-columns: 1fr auto;
    }

    .chart-overview > a {
        grid-column: 1 / -1;
        width: max-content;
    }

    .chart-tools {
        grid-template-columns: 1fr auto;
    }

    .type-chips {
        grid-column: 1 / -1;
        grid-row: 2;
    }
}

@media (max-width: 820px) {
    .chart-layout {
        grid-template-columns: 1fr;
    }

    .account-inspector {
        position: static;
        min-height: 0;
        order: -1;
    }

    .account-inspector__empty {
        display: none;
    }
}

@media (max-width: 620px) {
    .chart-overview {
        grid-template-columns: 1fr;
    }

    .chart-overview__stats {
        justify-content: stretch;
    }

    .chart-overview__stats span {
        flex: 1;
    }

    .chart-overview > a {
        width: 100%;
        justify-content: center;
    }

    .chart-tools {
        grid-template-columns: 1fr;
    }

    .type-chips {
        grid-column: auto;
        grid-row: auto;
    }

    .chart-tools__actions {
        justify-content: flex-end;
    }

    .inactive-switch {
        margin-inline-end: auto;
    }

    .account-modal__backdrop {
        align-items: end;
        padding: 0;
    }

    .account-modal {
        max-height: 94vh;
        border-radius: 20px 20px 0 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .account-modal-enter-active,
    .account-modal-leave-active {
        transition: none;
    }
}
</style>
