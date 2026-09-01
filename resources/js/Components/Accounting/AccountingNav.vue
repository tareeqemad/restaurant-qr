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
        label: 'ابدأ هنا',
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
    <nav class="accounting-nav" aria-label="مركز المحاسبة">
        <div class="accounting-nav__groups">
            <button
                v-for="group in groups"
                :key="group.id"
                type="button"
                :class="{
                    active: openGroup.id === group.id,
                    current: activeGroupId === group.id,
                }"
                :aria-pressed="openGroup.id === group.id"
                @click="openGroupId = group.id"
            >
                <span class="accounting-nav__group-icon"><i class="bi" :class="group.icon"></i></span>
                <span class="accounting-nav__group-copy">
                    <strong>{{ group.label }}</strong>
                    <small>{{ group.description }}</small>
                </span>
                <em aria-hidden="true">{{ group.step }}</em>
            </button>
        </div>

        <div class="accounting-nav__context">
            <span class="accounting-nav__context-title">
                <i class="bi" :class="openGroup.icon"></i>
                الخطوة {{ openGroup.step }} · {{ openGroup.label }}
            </span>
            <div class="accounting-nav__links">
                <Link
                    v-for="item in openGroup.items"
                    :key="item[0]"
                    :href="item[2]"
                    :class="{ active: active === item[0] }"
                    preserve-scroll
                >
                    {{ item[1] }}
                    <i v-if="active === item[0]" class="bi bi-check2"></i>
                </Link>
            </div>
            <label class="accounting-nav__quick">
                <i class="bi bi-search"></i>
                <span class="visually-hidden">انتقال سريع إلى شاشة محاسبية</span>
                <select v-model="quickTarget" aria-label="انتقال سريع إلى شاشة محاسبية" @change="quickNavigate">
                    <option value="" disabled>انتقال سريع…</option>
                    <optgroup v-for="group in groups" :key="group.id" :label="group.label">
                        <option v-for="item in group.items" :key="item[0]" :value="item[2]">{{ item[1] }}</option>
                    </optgroup>
                </select>
            </label>
        </div>
    </nav>
</template>

<style scoped>
.accounting-nav {
    position: sticky;
    z-index: 14;
    top: 62px;
    margin-bottom: 14px;
    overflow: hidden;
    border: 1px solid #dce6df;
    border-radius: 18px;
    background: rgba(255, 255, 255, .97);
    box-shadow: 0 12px 34px rgba(25, 55, 38, .07);
    backdrop-filter: blur(14px);
}

.accounting-nav__groups {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 6px;
    padding: 7px;
}

.accounting-nav__groups button {
    position: relative;
    display: flex;
    min-width: 0;
    min-height: 54px;
    align-items: center;
    gap: 9px;
    padding: 7px 9px;
    border: 0;
    border-radius: 12px;
    color: #66756c;
    background: transparent;
    text-align: start;
    transition: background .15s ease, color .15s ease, transform .15s ease;
}

.accounting-nav__groups button:hover {
    color: rgb(var(--primary-rgb, 31, 107, 80));
    background: #f3f8f5;
}

.accounting-nav__groups button.active {
    color: rgb(var(--primary-rgb, 31, 107, 80));
    background: #eaf4ee;
}

.accounting-nav__groups button.current::after {
    position: absolute;
    right: 12px;
    bottom: 0;
    left: 12px;
    height: 2px;
    border-radius: 999px;
    background: rgb(var(--primary-rgb, 31, 107, 80));
    content: '';
}

.accounting-nav__group-icon {
    display: grid;
    flex: 0 0 34px;
    width: 34px;
    height: 34px;
    place-items: center;
    border: 1px solid #dfe8e2;
    border-radius: 10px;
    background: #fff;
}

.accounting-nav__group-copy {
    display: grid;
    min-width: 0;
    line-height: 1.35;
}

.accounting-nav__group-copy strong {
    font-size: .78rem;
    font-weight: 900;
}

.accounting-nav__group-copy small {
    overflow: hidden;
    color: #8a968e;
    font-size: .62rem;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.accounting-nav__groups em {
    display: grid;
    min-width: 20px;
    height: 20px;
    margin-inline-start: auto;
    place-items: center;
    border-radius: 999px;
    color: #748279;
    background: #edf2ef;
    font-size: .58rem;
    font-style: normal;
    font-weight: 850;
}

.accounting-nav__context {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 10px;
    border-top: 1px solid #edf1ee;
    background: #fbfcfb;
}

.accounting-nav__context-title {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    gap: 6px;
    color: #6d7a72;
    font-size: .7rem;
    font-weight: 900;
}

.accounting-nav__links {
    display: flex;
    min-width: 0;
    gap: 6px;
    overflow-x: auto;
    scrollbar-width: thin;
}

.accounting-nav__links a {
    display: flex;
    flex: 0 0 auto;
    min-height: 36px;
    align-items: center;
    gap: 6px;
    padding: 7px 11px;
    border: 1px solid #e0e8e2;
    border-radius: 10px;
    color: #596860;
    background: #fff;
    font-size: .72rem;
    font-weight: 800;
}

.accounting-nav__quick {
    display: flex;
    flex: 0 0 185px;
    min-height: 38px;
    align-items: center;
    gap: 7px;
    padding: 0 10px;
    border: 1px solid #d8e4dc;
    border-radius: 10px;
    color: #1f6b50;
    background: #fff;
}

.accounting-nav__quick select {
    width: 100%;
    border: 0;
    outline: 0;
    color: #4f6056;
    background: transparent;
    font: inherit;
    font-size: .7rem;
    font-weight: 800;
}

.accounting-nav__quick:focus-within {
    border-color: #83b596;
    box-shadow: 0 0 0 3px rgba(31, 107, 80, .09);
}

@media (max-width: 1180px) {
    .accounting-nav__quick {
        display: none;
    }
}

.accounting-nav__links a.active {
    border-color: #8dbda0;
    color: rgb(var(--primary-rgb, 31, 107, 80));
    background: #edf7f0;
    box-shadow: inset 0 0 0 1px rgba(var(--primary-rgb, 31, 107, 80), .08);
}

@media (max-width: 980px) {
    .accounting-nav__group-copy small,
    .accounting-nav__groups em {
        display: none;
    }

    .accounting-nav__groups button {
        justify-content: center;
    }
}

@media (max-width: 720px) {
    .accounting-nav {
        top: 56px;
        border-radius: 14px;
    }

    .accounting-nav__groups {
        display: flex;
        overflow-x: auto;
        scrollbar-width: none;
    }

    .accounting-nav__groups button {
        flex: 0 0 86px;
        min-height: 52px;
        gap: 5px;
    }

    .accounting-nav__group-icon {
        flex-basis: 30px;
        width: 30px;
        height: 30px;
    }

    .accounting-nav__group-copy strong {
        font-size: .65rem;
    }

    .accounting-nav__context {
        align-items: flex-start;
        flex-direction: column;
        gap: 6px;
    }

    .accounting-nav__context-title {
        display: none;
    }

    .accounting-nav__links {
        width: 100%;
    }

    .accounting-nav__links a {
        min-height: 40px;
    }

    .accounting-nav__quick {
        display: none;
    }
}

@media (prefers-reduced-motion: reduce) {
    .accounting-nav__groups button {
        transition: none;
    }
}
</style>
