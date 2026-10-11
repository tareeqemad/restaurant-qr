<script setup>
import { computed, ref, watch } from 'vue'
import { Link, router } from '@inertiajs/vue3'

const props = defineProps({
    urls: { type: Object, required: true },
    active: { type: String, default: 'home' },
})

const groups = computed(() => [
    {
        id: 'home',
        step: 1,
        label: 'الرئيسية',
        description: 'الحالة ودليل سير العمل',
        icon: 'bi-grid-1x2-fill',
        items: [
            ['home', 'مركز المحاسبة', props.urls.home],
            ['guide', 'دليل التسجيل التلقائي', props.urls.guide],
        ],
    },
    {
        id: 'setup',
        step: 2,
        label: 'التأسيس',
        description: 'الفترة والشجرة والافتتاح',
        icon: 'bi-diagram-3-fill',
        items: [
            ['fiscalYears', 'السنوات المالية', props.urls.fiscalYears],
            ['periods', 'الشهور المالية', props.urls.periods],
            ['accounts', 'شجرة الحسابات', props.urls.accounts],
            ['openingBalances', 'الأرصدة الافتتاحية', props.urls.openingBalances],
            ['mappings', 'ربط العمليات بالحسابات', props.urls.mappings],
        ],
    },
    {
        id: 'books',
        step: 3,
        label: 'التسجيل',
        description: 'اليومية والأستاذ والحركات المستقلة',
        icon: 'bi-journals',
        items: [
            ['journal', 'القيود اليومية', props.urls.journal],
            ['ledger', 'دفتر الأستاذ', props.urls.ledger],
            ['manualEntry', 'قيد يدوي', props.urls.manualEntry],
            ['fixedAssets', 'الأصول الثابتة', props.urls.fixedAssets],
        ],
    },
    {
        id: 'review',
        step: 4,
        label: 'المراجعة',
        description: 'الميزان والمطابقة والذمم',
        icon: 'bi-check2-square',
        items: [
            ['trialBalance', 'ميزان المراجعة', props.urls.trialBalance],
            ['reconciliations', 'مطابقة الصندوق والبنك', props.urls.reconciliations],
            ['settlements', 'المحافظ والتسويات', props.urls.settlements],
            ['aging', 'أعمار الذمم', props.urls.aging],
        ],
    },
    {
        id: 'reports',
        step: 5,
        label: 'القوائم',
        description: 'النتيجة والمركز المالي والضريبة',
        icon: 'bi-bar-chart-line-fill',
        items: [
            ['profitLoss', 'الأرباح والخسائر', props.urls.profitLoss],
            ['balanceSheet', 'المركز المالي', props.urls.balanceSheet],
            ['taxReport', 'تقرير الضريبة', props.urls.taxReport],
        ],
    },
].map((group) => ({
    ...group,
    items: group.items.filter((item) => item[2]),
})).filter((group) => group.items.length))

const activeGroupId = computed(() => (
    groups.value.find((group) => group.items.some((item) => item[0] === props.active))?.id ?? 'home'
))
const openGroupId = ref(activeGroupId.value)
const openGroup = computed(() => (
    groups.value.find((group) => group.id === openGroupId.value) ?? groups.value[0]
))
const quickTarget = ref('')

function quickNavigate() {
    if (!quickTarget.value) return
    router.visit(quickTarget.value)
    quickTarget.value = ''
}

watch(activeGroupId, (value) => {
    openGroupId.value = value
})
</script>

<template>
<nav class="accounting-nav" aria-label="أدوات المحاسبة">
    <div class="accounting-nav__bar">
        <label class="accounting-nav__section">
            <i class="bi" :class="openGroup?.icon"></i>
            <span class="visually-hidden">قسم المحاسبة</span>
            <select v-model="openGroupId" aria-label="قسم المحاسبة">
                <option v-for="group in groups" :key="group.id" :value="group.id">{{ group.label }}</option>
            </select>
        </label>
        <div v-if="openGroup" class="accounting-nav__links">
            <Link v-for="item in openGroup.items" :key="item[0]" :href="item[2]" :class="{active:active===item[0]}" :aria-current="active===item[0]?'page':undefined" preserve-scroll>{{item[1]}}</Link>
        </div>
        <label class="accounting-nav__all">
            <i class="bi bi-grid"></i>
            <select v-model="quickTarget" aria-label="كل شاشات المحاسبة" @change="quickNavigate">
                <option value="" disabled>كل الأدوات</option>
                <optgroup v-for="group in groups" :key="group.id" :label="group.label">
                    <option v-for="item in group.items" :key="item[0]" :value="item[2]">{{item[1]}}</option>
                </optgroup>
            </select>
        </label>
    </div>
</nav>
</template>
<style scoped>
.accounting-nav { min-width:0; color:#25343A; }
.accounting-nav__bar { display:flex; align-items:center; gap:16px; min-width:0; padding:0 0 14px; border-bottom:1px solid #DFD7CB; }
.accounting-nav__section,.accounting-nav__all { display:flex; flex:0 0 auto; align-items:center; gap:8px; margin:0; padding:0 10px; border:1px solid #DFD7CB; border-radius:9px; background:#FFFDFA; color:#A3422B; }
.accounting-nav select { min-height:42px; max-width:220px; border:0; background:transparent; color:#25343A; font:700 14px Cairo,sans-serif; cursor:pointer; }
.accounting-nav__links { display:flex; flex:1; align-items:center; gap:4px; min-width:0; overflow-x:auto; scrollbar-width:thin; }
.accounting-nav__links a { flex:0 0 auto; padding:11px 13px; border-radius:8px; color:#626B6D; font-size:14px; font-weight:700; white-space:nowrap; text-decoration:none; }
.accounting-nav__links a:hover { background:#EFE9E0; color:#25343A; }
.accounting-nav__links a.active { color:#FFFDFA; background:#25343A; }
.accounting-nav__all { margin-inline-start:auto; }
@media(max-width:900px){.accounting-nav__bar{flex-wrap:wrap;gap:10px}.accounting-nav__links{order:3;flex-basis:100%}.accounting-nav__all select{max-width:160px}}
</style>
