<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import AdminLayout from '../../../Layouts/AdminLayout.vue'
import PageHeader from '../../../Components/Ui/PageHeader.vue'
import { chapters, roleGuides, roleMatrix, setupChecklist } from './guideContent'

defineOptions({ layout: AdminLayout })

const props = defineProps({
    viewer: { type: Object, required: true },
    roles: { type: Array, default: () => [] },
    urls: { type: Object, required: true },
})

const query = ref('')
const selectedRole = ref(props.viewer.role)
const completed = ref([])
const printing = ref(false)
const activeChapterId = ref('start')
const showAllChapters = ref(false)
const searchInput = ref(null)
const progressKey = `restaurant-usage-guide:${props.viewer.id}:${props.viewer.branchId ?? 'all'}`
const page = usePage()
const brand = computed(() => page.props.shell?.brand ?? { name: 'نظام إدارة المطعم', logo: null })
const printDate = new Intl.DateTimeFormat('ar-PS', { dateStyle: 'long' }).format(new Date())
let printSnapshot = null
let chapterObserver = null

const roleReadingPaths = {
    super_admin: ['start', 'roles', 'inventory-foundation', 'recipes-menu', 'floor-setup', 'service-flow', 'rush-hour', 'cashier-table', 'purchasing', 'accounting', 'corrections', 'closing', 'troubleshooting', 'go-live'],
    partner: ['start', 'roles', 'rush-hour', 'accounting', 'corrections', 'closing', 'troubleshooting', 'go-live'],
    admin: ['start', 'roles', 'inventory-foundation', 'recipes-menu', 'floor-setup', 'service-flow', 'rush-hour', 'cashier-table', 'purchasing', 'accounting', 'corrections', 'closing', 'troubleshooting', 'go-live'],
    manager: ['start', 'roles', 'inventory-foundation', 'recipes-menu', 'floor-setup', 'service-flow', 'rush-hour', 'cashier-table', 'purchasing', 'corrections', 'closing', 'troubleshooting', 'go-live'],
    accountant: ['accounting', 'inventory-foundation', 'cashier-table', 'purchasing', 'corrections', 'closing', 'troubleshooting', 'go-live'],
    waiter: ['service-flow', 'floor-setup', 'rush-hour', 'cashier-table', 'troubleshooting', 'go-live'],
    chef: ['service-flow', 'recipes-menu', 'rush-hour', 'troubleshooting', 'go-live'],
    bartender: ['service-flow', 'recipes-menu', 'rush-hour', 'troubleshooting', 'go-live'],
    cashier: ['cashier-table', 'floor-setup', 'rush-hour', 'corrections', 'closing', 'troubleshooting', 'go-live'],
}
activeChapterId.value = roleReadingPaths[props.viewer.role]?.[0] ?? 'start'

const normalize = (value) => String(value ?? '')
    .normalize('NFKD')
    .replace(/[\u064B-\u065F\u0670\u0640]/g, '')
    .toLowerCase()

const filteredChapters = computed(() => {
    const needle = normalize(query.value.trim())
    if (! needle) return chapters

    return chapters.filter((chapter) => normalize(JSON.stringify(chapter)).includes(needle))
})

const selectedRoleMeta = computed(() => props.roles.find((role) => role.value === selectedRole.value))
const selectedRoleGuide = computed(() => roleGuides[selectedRole.value] ?? roleGuides[props.viewer.role])
const selectedRolePathIds = computed(() => roleReadingPaths[selectedRole.value] ?? roleReadingPaths[props.viewer.role] ?? chapters.map((chapter) => chapter.id))
const rolePathChapters = computed(() => selectedRolePathIds.value
    .map((id) => chapters.find((chapter) => chapter.id === id))
    .filter(Boolean))
const recommendedChapter = computed(() => rolePathChapters.value[0] ?? chapters[0])
const visibleChapters = computed(() => {
    if (query.value.trim()) return filteredChapters.value
    return showAllChapters.value ? chapters : rolePathChapters.value
})
const canSwitchRole = computed(() => ['super_admin', 'partner', 'admin', 'manager'].includes(props.viewer.role))
const canSeeSetupChecklist = computed(() => ['super_admin', 'partner', 'admin', 'manager', 'accountant'].includes(selectedRole.value))
const completedCount = computed(() => setupChecklist.filter((item) => completed.value.includes(item.id)).length)
const completionPercent = computed(() => Math.round((completedCount.value / setupChecklist.length) * 100))

function isRelevant(chapter) {
    return selectedRolePathIds.value.includes(chapter.id)
}

function audienceLabel(value) {
    if (value === 'all') return 'كل الأدوار'
    return props.roles.find((role) => role.value === value)?.label ?? value
}

function toggleChecklist(id) {
    completed.value = completed.value.includes(id)
        ? completed.value.filter((itemId) => itemId !== id)
        : [...completed.value, id]

    localStorage.setItem(progressKey, JSON.stringify(completed.value))
}

function selectRole(role) {
    selectedRole.value = role
    query.value = ''
    showAllChapters.value = false
    activeChapterId.value = roleReadingPaths[role]?.[0] ?? 'start'
}

function startReading() {
    query.value = ''
    showAllChapters.value = false
    nextTick(() => jumpTo(recommendedChapter.value.id))
}

function clearSearch() {
    query.value = ''
    nextTick(() => searchInput.value?.focus({ preventScroll: true }))
}

function jumpTo(id) {
    const target = document.getElementById(id)
    if (! target) return

    activeChapterId.value = id
    const details = target.closest('details')
    if (details) details.open = true
    window.history.replaceState(null, '', `#${id}`)
    nextTick(() => target.scrollIntoView({ behavior: 'smooth', block: 'start' }))
}

function setAllChapters(open) {
    document.querySelectorAll('.guide-chapter').forEach((chapter) => {
        chapter.open = open
    })
}

function observeChapters() {
    if (! chapterObserver) return
    chapterObserver.disconnect()
    document.querySelectorAll('.guide-chapter').forEach((chapter) => chapterObserver.observe(chapter))
}

async function preparePrint() {
    if (printing.value) return

    printSnapshot = {
        query: query.value,
        showAllChapters: showAllChapters.value,
        openIds: [...document.querySelectorAll('.guide-chapter[open]')].map((chapter) => chapter.id),
    }
    printing.value = true
    query.value = ''
    showAllChapters.value = true
    document.documentElement.classList.add('usage-guide-printing')

    await nextTick()
    setAllChapters(true)
}

function finishPrint() {
    if (! printSnapshot) return

    const snapshot = printSnapshot
    printSnapshot = null
    printing.value = false
    query.value = snapshot.query
    showAllChapters.value = snapshot.showAllChapters
    document.documentElement.classList.remove('usage-guide-printing')

    nextTick(() => {
        document.querySelectorAll('.guide-chapter').forEach((chapter) => {
            chapter.open = snapshot.openIds.includes(chapter.id)
        })
    })
}

async function printPage() {
    await preparePrint()
    requestAnimationFrame(() => window.print())
}

onMounted(() => {
    document.documentElement.classList.add('usage-guide-page')
    document.body.classList.add('usage-guide-page')
    window.addEventListener('beforeprint', preparePrint)
    window.addEventListener('afterprint', finishPrint)

    try {
        const stored = JSON.parse(localStorage.getItem(progressKey) ?? '[]')
        completed.value = Array.isArray(stored) ? stored : []
    } catch {
        completed.value = []
    }

    if (window.location.hash) {
        const requestedChapter = window.location.hash.slice(1)
        if (! selectedRolePathIds.value.includes(requestedChapter)) showAllChapters.value = true
        setTimeout(() => jumpTo(requestedChapter), 80)
    }

    chapterObserver = new IntersectionObserver((entries) => {
        const visible = entries
            .filter((entry) => entry.isIntersecting)
            .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)

        if (visible[0]?.target?.id) activeChapterId.value = visible[0].target.id
    }, { rootMargin: '-110px 0px -62% 0px', threshold: 0 })

    observeChapters()
})

watch(
    () => visibleChapters.value.map((chapter) => chapter.id).join('|'),
    () => nextTick(observeChapters),
)

onBeforeUnmount(() => {
    window.removeEventListener('beforeprint', preparePrint)
    window.removeEventListener('afterprint', finishPrint)
    chapterObserver?.disconnect()
    document.documentElement.classList.remove('usage-guide-page')
    document.body.classList.remove('usage-guide-page')
    document.documentElement.classList.remove('usage-guide-printing')
})
</script>

<template>
    <Head title="دليل الاستخدام" />

    <div class="print-running-header" aria-hidden="true">
        <span><img v-if="brand.logo" :src="brand.logo" alt=""> {{ brand.name }}</span>
        <strong>دليل الاستخدام والتشغيل</strong>
    </div>
    <div class="print-running-footer" aria-hidden="true">
        <span>{{ viewer.branchName || 'جميع الفروع' }}</span>
        <span>{{ printDate }}</span>
    </div>

    <section class="print-cover" aria-hidden="true">
        <div class="print-cover__mark">
            <img v-if="brand.logo" :src="brand.logo" alt="">
            <span v-else><i class="bi bi-journal-bookmark-fill"></i></span>
        </div>
        <div class="print-cover__body">
            <span class="print-cover__eyebrow">الدليل التشغيلي المعتمد</span>
            <h1>دليل استخدام<br>{{ brand.name }}</h1>
            <p>مرجع تأسيس المطعم وتشغيل الصالة والمطبخ والبار والكاشير والمخزون والمحاسبة من البداية إلى الإقفال.</p>
        </div>
        <dl class="print-cover__meta">
            <div><dt>الفرع</dt><dd>{{ viewer.branchName || 'جميع الفروع' }}</dd></div>
            <div><dt>أُعدّ للمستخدم</dt><dd>{{ viewer.name }} · {{ viewer.roleLabel }}</dd></div>
            <div><dt>مسار التدريب</dt><dd>{{ selectedRoleMeta?.label }}</dd></div>
            <div><dt>تاريخ الإصدار</dt><dd>{{ printDate }}</dd></div>
        </dl>
        <footer><span>{{ chapters.length }} فصلاً عملياً</span><strong>{{ brand.name }}</strong></footer>
    </section>

    <section class="print-toc" aria-hidden="true">
        <header>
            <span>محتويات الدليل</span>
            <h2>فهرس الفصول</h2>
            <p>اتبع الترتيب في التأسيس، ثم استخدم كل فصل كإجراء عمل مرجعي للدور المسؤول.</p>
        </header>
        <ol>
            <li v-for="chapter in chapters" :key="`print-${chapter.id}`">
                <span>{{ chapter.number }}</span>
                <div><strong>{{ chapter.title }}</strong><small>{{ chapter.summary }}</small></div>
            </li>
        </ol>
    </section>

    <PageHeader
        title="دليل الاستخدام"
        icon="bi-journal-bookmark-fill"
        subtitle="من تأسيس المطعم والمخزون والوصفات إلى التشغيل والمحاسبة والإقفال"
    >
        <template #actions>
            <div class="guide-header-actions">
                <button type="button" class="guide-print-button" :disabled="printing" @click="printPage">
                    <i class="bi bi-printer"></i>
                    {{ printing ? 'جاري تجهيز الطباعة...' : 'طباعة / حفظ PDF' }}
                </button>
            </div>
        </template>
    </PageHeader>

    <section class="reading-start" aria-labelledby="guide-welcome-title">
        <div class="reading-start__main">
            <span class="reading-start__eyebrow"><i class="bi bi-signpost-split-fill"></i> ابدأ القراءة من هنا</span>
            <h2 id="guide-welcome-title">أهلاً {{ viewer.name }}، جهّزنا لك مسار <strong>{{ selectedRoleMeta?.label || viewer.roleLabel }}</strong></h2>
            <p>لا تقرأ كل الدليل. ابدأ بالفصل المقترح، ثم انتقل بالترتيب بين فصول مسارك فقط.</p>

            <ol class="reading-start__steps" aria-label="خطوات بدء القراءة">
                <li class="done"><span><i class="bi bi-check-lg"></i></span><div><small>الخطوة 1</small><strong>حددنا دورك: {{ selectedRoleMeta?.label || viewer.roleLabel }}</strong></div></li>
                <li class="active"><span>2</span><div><small>الخطوة 2 · الآن</small><strong>اقرأ: {{ recommendedChapter.title }}</strong></div></li>
                <li><span>3</span><div><small>الخطوة 3</small><strong>أكمل فصول مسارك بالترتيب</strong></div></li>
            </ol>

            <div class="reading-start__actions">
                <button type="button" class="start-reading-button" @click="startReading">
                    ابدأ بالفصل {{ recommendedChapter.number }}
                    <i class="bi bi-arrow-down"></i>
                </button>
                <Link
                    v-if="selectedRoleGuide.action && urls[selectedRoleGuide.action]"
                    :href="urls[selectedRoleGuide.action]"
                    class="start-work-button"
                >
                    افتح شاشة عملي <i class="bi bi-box-arrow-up-left"></i>
                </Link>
            </div>
        </div>

        <aside class="reading-start__help">
            <span><i class="bi bi-life-preserver"></i> إذا جئت لحل مشكلة</span>
            <label for="guide-search">اكتب العملية أو المشكلة مباشرة</label>
            <div class="guide-search-box">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input
                    id="guide-search"
                    ref="searchInput"
                    v-model="query"
                    type="search"
                    placeholder="مثال: طاولة عليها 4 شيكل، الرصيد الافتتاحي، الاسترداد..."
                    autocomplete="off"
                >
                <button v-if="query" type="button" aria-label="مسح البحث" @click="clearSearch">
                    <i class="bi bi-x-lg"></i>
                </button>
            </div>
            <small v-if="query">{{ filteredChapters.length }} فصل مطابق للبحث</small>
            <small v-else>البحث يفتش كل الفصول، وليس مسارك فقط.</small>
            <div class="reading-scope" aria-label="نطاق الفصول">
                <button type="button" :class="{ active: !showAllChapters && !query }" @click="query = ''; showAllChapters = false">مساري فقط <b>{{ rolePathChapters.length }}</b></button>
                <button type="button" :class="{ active: showAllChapters && !query }" @click="query = ''; showAllChapters = true">كل الدليل <b>{{ chapters.length }}</b></button>
            </div>
        </aside>
    </section>

    <section v-if="!query" class="role-workspace" aria-labelledby="role-path-title">
        <div v-if="canSwitchRole" class="role-workspace__picker">
            <span>هل تدرّب موظفاً آخر؟ غيّر المسار:</span>
            <div class="role-pills" role="list" aria-label="اختيار الدور">
                <button
                    v-for="role in roles"
                    :key="role.value"
                    type="button"
                    :class="{ active: selectedRole === role.value }"
                    :aria-pressed="selectedRole === role.value"
                    @click="selectRole(role.value)"
                >
                    {{ role.label }}
                    <small v-if="role.value === viewer.role">أنت</small>
                </button>
            </div>
        </div>

        <article class="role-focus">
            <div class="role-focus__icon"><i class="bi" :class="selectedRoleGuide.icon"></i></div>
            <div class="role-focus__main">
                <span>مسار {{ selectedRoleMeta?.label }}</span>
                <h2 id="role-path-title">{{ selectedRoleGuide.summary }}</h2>
                <p>{{ selectedRoleGuide.start }}</p>
            </div>
            <div class="role-focus__lists">
                <div>
                    <strong><i class="bi bi-check2-circle"></i> مسؤولياته</strong>
                    <ul><li v-for="duty in selectedRoleGuide.duties" :key="duty">{{ duty }}</li></ul>
                </div>
                <div>
                    <strong><i class="bi bi-slash-circle"></i> حدوده</strong>
                    <ul><li v-for="boundary in selectedRoleGuide.boundaries" :key="boundary">{{ boundary }}</li></ul>
                </div>
            </div>
            <Link
                v-if="selectedRoleGuide.action && urls[selectedRoleGuide.action]"
                :href="urls[selectedRoleGuide.action]"
                class="role-focus__action"
            >
                {{ canSwitchRole ? 'افتح شاشة عمله' : 'افتح شاشة عملي' }} <i class="bi bi-arrow-left"></i>
            </Link>
        </article>
    </section>

    <section v-if="canSeeSetupChecklist && !query" class="launch-card" aria-labelledby="launch-title">
        <header class="launch-card__header">
            <div>
                <span class="guide-eyebrow"><i class="bi bi-list-check"></i> للإدارة · عند تأسيس المطعم فقط</span>
                <h2 id="launch-title">قائمة التأسيس قبل أول يوم تشغيل</h2>
                <p>تجاهلها إذا كان المطعم يعمل فعلاً. تُحفظ العلامات لهذا المستخدم والفرع.</p>
            </div>
            <div class="launch-progress" :aria-label="`اكتمل ${completionPercent}%`">
                <strong>{{ completedCount }} / {{ setupChecklist.length }}</strong>
                <span>مكتمل</span>
                <div><i :style="{ width: `${completionPercent}%` }"></i></div>
            </div>
        </header>

        <div class="launch-grid">
            <article
                v-for="(item, index) in setupChecklist"
                :key="item.id"
                class="launch-item"
                :class="{ completed: completed.includes(item.id) }"
            >
                <button
                    type="button"
                    class="launch-item__check"
                    :aria-label="completed.includes(item.id) ? `إلغاء اكتمال ${item.title}` : `تحديد ${item.title} كمكتمل`"
                    :aria-pressed="completed.includes(item.id)"
                    @click="toggleChecklist(item.id)"
                >
                    <i class="bi" :class="completed.includes(item.id) ? 'bi-check-lg' : 'bi-circle'"></i>
                </button>
                <span class="launch-item__number">{{ String(index + 1).padStart(2, '0') }}</span>
                <div>
                    <strong>{{ item.title }}</strong>
                    <p>{{ item.detail }}</p>
                    <small><i class="bi bi-person-check"></i> {{ item.owner }}</small>
                </div>
                <Link v-if="item.action && urls[item.action]" :href="urls[item.action]" title="فتح الشاشة">
                    <i class="bi bi-box-arrow-up-left"></i>
                </Link>
            </article>
        </div>
    </section>

    <div class="guide-layout">
        <aside class="guide-toc" aria-label="فهرس دليل الاستخدام">
            <div class="guide-toc__sticky">
                <header>
                    <span>{{ query ? 'نتائج البحث' : (showAllChapters ? 'كل الدليل' : `مسار ${selectedRoleMeta?.label}`) }}</span>
                    <strong>{{ visibleChapters.length }} فصل</strong>
                </header>
                <nav>
                    <button
                        v-for="chapter in visibleChapters"
                        :key="chapter.id"
                        type="button"
                        :class="{ relevant: isRelevant(chapter), active: activeChapterId === chapter.id }"
                        @click="jumpTo(chapter.id)"
                    >
                        <span>{{ chapter.number }}</span>
                        <i class="bi" :class="chapter.icon"></i>
                        <b>{{ chapter.title }}</b>
                        <em v-if="isRelevant(chapter)">لك</em>
                    </button>
                </nav>

                <div class="toc-help">
                    <i class="bi bi-lightbulb-fill"></i>
                    <p v-if="!showAllChapters && !query">هذه فصولك بالترتيب. اقرأ الأول، ثم انتقل للذي يليه.</p>
                    <p v-else>العلامة «لك» تعني أن الفصل ضمن مسار دورك.</p>
                </div>
            </div>
        </aside>

        <main class="guide-content">
            <div v-if="visibleChapters.length" class="chapter-list">
                <details
                    v-for="(chapter, chapterIndex) in visibleChapters"
                    :id="chapter.id"
                    :key="chapter.id"
                    class="guide-chapter"
                    :class="{ 'guide-chapter--relevant': isRelevant(chapter) }"
                    :open="Boolean(query) || chapter.id === recommendedChapter.id"
                >
                    <summary>
                        <span class="chapter-number">{{ chapter.number }}</span>
                        <span class="chapter-icon"><i class="bi" :class="chapter.icon"></i></span>
                        <span class="chapter-copy">
                            <span><em v-if="isRelevant(chapter)">مهم لمسار {{ selectedRoleMeta?.label }}</em></span>
                            <strong>{{ chapter.title }}</strong>
                            <small>{{ chapter.summary }}</small>
                        </span>
                        <span class="chapter-toggle"><i class="bi bi-chevron-down"></i></span>
                    </summary>

                    <div class="chapter-body">
                        <div v-if="chapter.flow" class="flow-track" aria-label="تسلسل العمل">
                            <template v-for="(step, index) in chapter.flow" :key="step">
                                <span>{{ step }}</span>
                                <i v-if="index < chapter.flow.length - 1" class="bi bi-arrow-left"></i>
                            </template>
                        </div>

                        <section v-for="(block, blockIndex) in chapter.blocks" :key="`${chapter.id}-${blockIndex}`" class="chapter-block">
                            <h3>{{ block.title }}</h3>
                            <p v-if="block.intro" class="chapter-intro">{{ block.intro }}</p>

                            <ol v-if="block.steps" class="numbered-steps">
                                <li v-for="(step, stepIndex) in block.steps" :key="step[0]">
                                    <span>{{ stepIndex + 1 }}</span>
                                    <div><strong>{{ step[0] }}</strong><p>{{ step[1] }}</p></div>
                                </li>
                            </ol>

                            <ul v-if="block.bullets" class="chapter-bullets">
                                <li v-for="bullet in block.bullets" :key="bullet"><i class="bi bi-check2"></i><span>{{ bullet }}</span></li>
                            </ul>

                            <div v-if="block.table" class="guide-table-wrap guide-table-wrap--inner">
                                <table>
                                    <thead><tr><th v-for="header in block.table.headers" :key="header">{{ header }}</th></tr></thead>
                                    <tbody><tr v-for="row in block.table.rows" :key="row.join('-')"><td v-for="cell in row" :key="cell">{{ cell }}</td></tr></tbody>
                                </table>
                            </div>
                            <p v-if="block.after" class="chapter-after"><i class="bi bi-info-circle-fill"></i>{{ block.after }}</p>
                        </section>

                        <aside v-if="chapter.callout" class="chapter-callout" :data-tone="chapter.callout.tone">
                            <i class="bi" :class="{
                                'bi-exclamation-octagon-fill': chapter.callout.tone === 'danger',
                                'bi-exclamation-triangle-fill': chapter.callout.tone === 'warning',
                                'bi-check-circle-fill': chapter.callout.tone === 'success',
                                'bi-info-circle-fill': chapter.callout.tone === 'info',
                            }"></i>
                            <div><strong>{{ chapter.callout.title }}</strong><p>{{ chapter.callout.text }}</p></div>
                        </aside>

                        <footer class="chapter-footer">
                            <div class="chapter-audience">
                                <span>مفيد لـ</span>
                                <b v-for="role in chapter.audience" :key="role">{{ audienceLabel(role) }}</b>
                            </div>
                            <div class="chapter-footer__actions">
                                <Link v-if="chapter.action && urls[chapter.action]" :href="urls[chapter.action]" class="chapter-action chapter-action--screen">
                                    {{ chapter.actionLabel }} <i class="bi bi-box-arrow-up-left"></i>
                                </Link>
                                <button
                                    v-if="visibleChapters[chapterIndex + 1]"
                                    type="button"
                                    class="chapter-action chapter-action--next"
                                    @click="jumpTo(visibleChapters[chapterIndex + 1].id)"
                                >
                                    الفصل التالي: {{ visibleChapters[chapterIndex + 1].title }}
                                    <i class="bi bi-arrow-down"></i>
                                </button>
                                <span v-else class="reading-complete"><i class="bi bi-check2-circle"></i> أكملت آخر فصل في هذا المسار</span>
                            </div>
                        </footer>
                    </div>
                </details>
            </div>

            <section v-else class="no-results">
                <i class="bi bi-search"></i>
                <h2>لم نجد فصلاً مطابقاً</h2>
                <p>جرّب كلمة أقصر مثل «دين»، «طاولة»، «مخزون»، «مطبخ» أو «رصيد».</p>
                <button type="button" @click="query = ''; showAllChapters = true">عرض الدليل كاملاً</button>
            </section>

            <details class="permissions-card permissions-card--reference">
                <summary>
                    <span><i class="bi bi-people-fill"></i></span>
                    <div>
                        <small>مرجع اختياري · ليس من بداية القراءة</small>
                        <h2>جدول الأدوار وحدود الصلاحيات</h2>
                    </div>
                    <i class="bi bi-chevron-down"></i>
                </summary>
                <div class="guide-table-wrap">
                    <table>
                        <thead><tr><th>الدور</th><th>نطاقه</th><th>عمله الأساسي</th><th>ليس مساره الطبيعي</th></tr></thead>
                        <tbody>
                            <tr v-for="row in roleMatrix" :key="row[0]" :class="{ current: row[0] === viewer.roleLabel }">
                                <td><strong>{{ row[0] }}</strong><small v-if="row[0] === viewer.roleLabel">دورك</small></td>
                                <td>{{ row[1] }}</td><td>{{ row[2] }}</td><td>{{ row[3] }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p><i class="bi bi-info-circle"></i> الصلاحيات الفردية قد توسّع أو تقيّد الدور؛ الشاشة والزر الفعليان هما المرجع النهائي.</p>
            </details>

            <section class="final-rule">
                <span><i class="bi bi-shield-check"></i></span>
                <div>
                    <small>قاعدة تحفظ النظام</small>
                    <h2>صحّح العملية من مصدرها، ولا تمسح أثرها</h2>
                    <p>المستند التشغيلي هو مصدر الحقيقة. استخدم الإلغاء أو العكس أو الإشعار الدائن أو الشطب المصرح، مع سبب ومرجع واضحين.</p>
                </div>
                <Link v-if="urls.accountingGuide" :href="urls.accountingGuide">دليل القيود التفصيلي <i class="bi bi-arrow-left"></i></Link>
            </section>
        </main>
    </div>
</template>

<style scoped>
.print-cover,
.print-toc,
.print-running-header,
.print-running-footer { display: none; }

:global(html.usage-guide-page) { overflow-x: hidden !important; overflow-y: auto !important; }
:global(body.usage-guide-page) { overflow: visible !important; }

.guide-header-actions {
    display: flex;
    align-items: center;
    gap: .5rem;
}

.guide-print-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: .55rem;
    min-height: 42px;
    padding: .65rem 1rem;
    border-radius: 12px;
    font-size: .78rem;
    font-weight: 800;
    transition: transform .18s ease, border-color .18s ease, background .18s ease, box-shadow .18s ease;
}

.guide-print-button {
    border: 1px solid #176b45;
    background: linear-gradient(135deg, #176b45, #13805a);
    color: #fff;
    box-shadow: 0 8px 18px rgba(23, 107, 69, .16);
}

.guide-print-button:hover:not(:disabled) { color: #fff; transform: translateY(-1px); box-shadow: 0 11px 24px rgba(23, 107, 69, .22); }
.guide-print-button:disabled { cursor: wait; opacity: .72; }
.guide-print-button:focus-visible { outline: 3px solid rgba(23, 107, 69, .18); outline-offset: 2px; }

.reading-start {
    display: grid;
    grid-template-columns: minmax(0, 1.35fr) minmax(330px, .65fr);
    margin-bottom: 1.35rem;
    overflow: hidden;
    border: 1px solid #d8e6df;
    border-radius: 22px;
    background: #fff;
    box-shadow: 0 18px 45px rgba(20, 72, 54, .12);
}

.reading-start__main {
    position: relative;
    isolation: isolate;
    padding: clamp(1.35rem, 3vw, 2.35rem);
    background:
        radial-gradient(circle at 12% 8%, rgba(244, 178, 44, .2), transparent 34%),
        linear-gradient(125deg, #103c30, #176b45 64%, #13805a);
    color: #fff;
    overflow: hidden;
}

.reading-start__main::after {
    position: absolute;
    z-index: -1;
    inset: 0;
    content: '';
    background-image: radial-gradient(rgba(255,255,255,.13) 1px, transparent 1px);
    background-size: 20px 20px;
    mask-image: linear-gradient(90deg, #000, transparent 70%);
    opacity: .48;
}

.reading-start__eyebrow,
.guide-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: .45rem;
    color: #dff5e9;
    font-size: .78rem;
    font-weight: 900;
    letter-spacing: .02em;
}

.reading-start h2 {
    max-width: 760px;
    margin: .65rem 0 .45rem;
    color: #fff;
    font-size: clamp(1.35rem, 2.4vw, 2rem);
    line-height: 1.35;
}

.reading-start h2 strong { color: #f8d986; }
.reading-start__main > p { max-width: 730px; margin: 0; color: rgba(255,255,255,.8); font-size: .82rem; line-height: 1.85; }
.reading-start__steps { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .55rem; margin: 1.2rem 0; padding: 0; list-style: none; }
.reading-start__steps li { display: grid; grid-template-columns: 30px minmax(0, 1fr); gap: .5rem; align-items: center; min-height: 62px; padding: .65rem; border: 1px solid rgba(255,255,255,.15); border-radius: 12px; background: rgba(255,255,255,.07); }
.reading-start__steps li > span { display: grid; width: 29px; height: 29px; place-items: center; border-radius: 9px; background: rgba(255,255,255,.12); color: #d8eee3; font-size: .68rem; font-weight: 900; }
.reading-start__steps li > div { display: grid; min-width: 0; gap: .15rem; }
.reading-start__steps small { color: rgba(255,255,255,.62); font-size: .56rem; font-weight: 800; }
.reading-start__steps strong { color: #fff; font-size: .68rem; line-height: 1.55; }
.reading-start__steps li.done > span { background: #dff4e7; color: #176b45; }
.reading-start__steps li.active { border-color: rgba(248,217,134,.72); background: rgba(248,217,134,.13); box-shadow: inset 0 0 0 1px rgba(248,217,134,.12); }
.reading-start__steps li.active > span { background: #f8d986; color: #5d470e; }
.reading-start__steps li.active small { color: #f8d986; }
.reading-start__actions { display: flex; flex-wrap: wrap; gap: .55rem; }
.start-reading-button,
.start-work-button { display: inline-flex; min-height: 44px; align-items: center; justify-content: center; gap: .5rem; padding: .65rem .9rem; border-radius: 11px; font-size: .73rem; font-weight: 900; text-decoration: none; }
.start-reading-button { border: 1px solid #fff; background: #fff; color: #125f3d; box-shadow: 0 9px 20px rgba(4,35,26,.18); }
.start-work-button { border: 1px solid rgba(255,255,255,.24); background: rgba(255,255,255,.08); color: #fff !important; }
.start-reading-button:hover { transform: translateY(-1px); }
.start-work-button:hover { background: rgba(255,255,255,.14); }

.reading-start__help { display: grid; align-content: center; gap: .55rem; padding: clamp(1.1rem, 2.2vw, 1.65rem); background: linear-gradient(145deg, #fff, #f7faf8); }
.reading-start__help > span { display: inline-flex; align-items: center; gap: .4rem; color: #167049; font-size: .67rem; font-weight: 900; }
.reading-start__help > label { color: #2b4338; font-size: .82rem; font-weight: 900; line-height: 1.55; }
.reading-start__help > small { min-height: 18px; color: #7b8982; font-size: .61rem; }
.guide-search-box { display: flex; align-items: center; gap: .55rem; min-height: 52px; padding: 0 .85rem; border: 1px solid #cfddd5; border-radius: 13px; background: #fff; color: #50615d; transition: border-color .15s ease, box-shadow .15s ease; }
.guide-search-box:focus-within { border-color: #78ad8e; box-shadow: 0 0 0 3px rgba(23,107,69,.1); }
.guide-search-box > i { font-size: 1.1rem; }
.guide-search-box input { flex: 1; min-width: 0; border: 0; outline: 0; background: transparent; color: #1f332e; font-size: .9rem; }
.guide-search-box button { border: 0; background: transparent; color: #7b8986; }
.reading-scope { display: grid; grid-template-columns: 1fr 1fr; gap: .3rem; padding: .25rem; margin-top: .35rem; border-radius: 11px; background: #edf3ef; }
.reading-scope button { display: flex; min-height: 38px; align-items: center; justify-content: center; gap: .4rem; border: 0; border-radius: 8px; background: transparent; color: #6d7c74; font-size: .64rem; font-weight: 850; }
.reading-scope button b { display: grid; min-width: 20px; height: 20px; place-items: center; padding-inline: .25rem; border-radius: 99px; background: #dde7e1; font-size: .55rem; }
.reading-scope button.active { background: #fff; color: #176b45; box-shadow: 0 2px 8px rgba(24,53,37,.08); }
.reading-scope button.active b { background: #e3f3e9; }

.role-workspace,
.launch-card,
.permissions-card,
.guide-chapter,
.final-rule {
    border: 1px solid #e1e8e5;
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 8px 28px rgba(31, 56, 48, .055);
}

.role-workspace { padding: 1.1rem; margin-bottom: 1.35rem; }
.role-workspace__picker { display: flex; align-items: flex-start; gap: 1rem; padding: .15rem .15rem 1rem; }
.role-workspace__picker > span { flex: 0 0 auto; padding-top: .55rem; color: #6a7975; font-size: .78rem; font-weight: 900; }
.role-pills { display: flex; flex-wrap: wrap; gap: .45rem; }
.role-pills button { display: inline-flex; align-items: center; gap: .35rem; padding: .48rem .72rem; border: 1px solid #dfe7e4; border-radius: 10px; background: #f8faf9; color: #53635f; font-size: .77rem; font-weight: 800; }
.role-pills button:hover { border-color: #96bea9; }
.role-pills button.active { border-color: #176b45; background: #eaf6f0; color: #145c3c; box-shadow: 0 0 0 3px rgba(23,107,69,.08); }
.role-pills small { padding: .12rem .3rem; border-radius: 999px; background: #176b45; color: #fff; font-size: .58rem; }

.role-focus { position: relative; display: grid; grid-template-columns: auto minmax(230px, .85fr) minmax(380px, 1.15fr) auto; gap: 1.1rem; align-items: center; padding: 1.25rem; border-radius: 15px; background: linear-gradient(135deg, #f1f8f4, #fbfdfc); }
.role-focus__icon { display: grid; width: 58px; height: 58px; place-items: center; border-radius: 16px; background: #176b45; color: #fff; font-size: 1.45rem; box-shadow: 0 9px 20px rgba(23,107,69,.19); }
.role-focus__main > span { color: #17734b; font-size: .74rem; font-weight: 900; }
.role-focus__main h2 { margin: .25rem 0 .4rem; color: #223832; font-size: 1.1rem; line-height: 1.55; }
.role-focus__main p { margin: 0; color: #63716e; font-size: .82rem; line-height: 1.7; }
.role-focus__lists { display: grid; grid-template-columns: 1fr 1fr; gap: .75rem; }
.role-focus__lists > div { padding: .8rem; border: 1px solid #e1ebe7; border-radius: 12px; background: #fff; }
.role-focus__lists strong { display: block; margin-bottom: .42rem; color: #2d4940; font-size: .76rem; }
.role-focus__lists ul { margin: 0; padding: 0 1rem 0 0; color: #687773; font-size: .7rem; line-height: 1.7; }
.role-focus__action,
.chapter-action { display: inline-flex; align-items: center; justify-content: center; gap: .45rem; padding: .65rem .8rem; border-radius: 10px; background: #176b45; color: #fff !important; font-size: .75rem; font-weight: 900; white-space: nowrap; }

.launch-card { padding: 1.3rem; margin-bottom: 1.35rem; }
.launch-card__header { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1rem; }
.launch-card .guide-eyebrow { color: #17734b; }
.launch-card__header h2 { margin: .35rem 0 .2rem; color: #243b35; font-size: 1.25rem; }
.launch-card__header p { margin: 0; color: #798682; font-size: .78rem; }
.launch-progress { display: grid; grid-template-columns: auto auto; align-items: end; gap: 0 .35rem; min-width: 170px; }
.launch-progress strong { color: #176b45; font-size: 1.35rem; }
.launch-progress span { padding-bottom: .15rem; color: #74817e; font-size: .7rem; font-weight: 800; }
.launch-progress > div { grid-column: 1 / -1; height: 7px; margin-top: .35rem; border-radius: 999px; background: #e8eeeb; overflow: hidden; }
.launch-progress i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, #176b45, #28a16b); transition: width .25s ease; }
.launch-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: .65rem; }
.launch-item { display: grid; grid-template-columns: auto auto 1fr auto; gap: .7rem; align-items: start; padding: .9rem; border: 1px solid #e5ebe8; border-radius: 13px; background: #fbfcfc; transition: .2s ease; }
.launch-item:hover { border-color: #cbded5; background: #fff; box-shadow: 0 8px 18px rgba(31,56,48,.055); transform: translateY(-1px); }
.launch-item.completed { border-color: #b9dfca; background: #f1faf5; }
.launch-item__check { display: grid; width: 30px; height: 30px; place-items: center; border: 1px solid #cfd9d5; border-radius: 9px; background: #fff; color: #176b45; }
.launch-item__number { padding-top: .35rem; color: #9aa6a2; font: 800 .68rem/1 monospace; }
.launch-item strong { display: block; color: #2d423c; font-size: .82rem; }
.launch-item p { margin: .18rem 0 .35rem; color: #71807c; font-size: .72rem; line-height: 1.55; }
.launch-item small { color: #4d7765; font-size: .65rem; font-weight: 700; }
.launch-item > a { color: #176b45; }
.launch-item.completed strong { text-decoration: line-through; text-decoration-color: #8cb5a1; }

.guide-layout { display: grid; grid-template-columns: 280px minmax(0, 1fr); gap: 1.25rem; align-items: start; }
.guide-toc { position: sticky; top: 92px; }
.guide-toc__sticky { padding: 1rem; border: 1px solid #e0e8e4; border-radius: 17px; background: #fff; box-shadow: 0 8px 28px rgba(31,56,48,.05); }
.guide-toc header { display: flex; justify-content: space-between; align-items: center; padding: 0 .25rem .7rem; border-bottom: 1px solid #edf1ef; }
.guide-toc header span { color: #334d45; font-weight: 900; }
.guide-toc header strong { color: #8a9793; font-size: .68rem; }
.guide-toc nav { display: grid; gap: .25rem; max-height: min(64vh, 620px); padding: .65rem 0; overflow-y: auto; }
.guide-toc nav button { display: grid; grid-template-columns: 25px 24px 1fr auto; gap: .42rem; align-items: center; width: 100%; padding: .52rem .45rem; border: 0; border-radius: 9px; background: transparent; color: #6a7874; text-align: right; }
.guide-toc nav button:hover { background: #f2f7f4; color: #176b45; }
.guide-toc nav button.active { background: #e8f4ed; color: #176b45; box-shadow: inset -3px 0 #176b45; }
.guide-toc nav button.active > span,
.guide-toc nav button.active > i,
.guide-toc nav button.active b { color: #176b45; }
.guide-toc nav button > span { color: #a1aaa7; font: 700 .62rem monospace; }
.guide-toc nav button > i { text-align: center; }
.guide-toc nav button b { font-size: .7rem; font-weight: 800; }
.guide-toc nav button em { padding: .12rem .3rem; border-radius: 999px; background: #e1f4e9; color: #176b45; font-size: .55rem; font-style: normal; font-weight: 900; }
.guide-toc nav button.relevant b { color: #38584d; }
.toc-help { display: flex; gap: .55rem; padding: .75rem; border-radius: 11px; background: #fff8e8; color: #795d1c; }
.toc-help > i { color: #d59513; }
.toc-help p { margin: 0; font-size: .65rem; line-height: 1.6; }

.guide-content { min-width: 0; }
.permissions-card { margin-top: 1rem; overflow: hidden; }
.permissions-card > summary { display: grid; grid-template-columns: 38px minmax(0, 1fr) auto; align-items: center; gap: .7rem; padding: .85rem 1rem; cursor: pointer; list-style: none; }
.permissions-card > summary::-webkit-details-marker { display: none; }
.permissions-card > summary > span { display: grid; width: 38px; height: 38px; place-items: center; border-radius: 11px; background: #edf6f1; color: #176b45; }
.permissions-card > summary small { color: #87938d; font-size: .58rem; font-weight: 750; }
.permissions-card > summary > i { color: #839188; transition: transform .18s ease; }
.permissions-card[open] > summary { border-bottom: 1px solid #edf1ef; background: #fbfdfc; }
.permissions-card[open] > summary > i { transform: rotate(180deg); }
.permissions-card h2 { margin: .12rem 0 0; color: #2c443c; font-size: .88rem; }
.permissions-card > p { display: flex; gap: .4rem; margin: 0; padding: .7rem 1rem; background: #f6f9f7; color: #65736f; font-size: .68rem; }

.guide-table-wrap { overflow-x: auto; }
.guide-table-wrap table { width: 100%; min-width: 720px; border-collapse: collapse; }
.guide-table-wrap th { padding: .68rem .8rem; background: #f4f7f5; color: #587068; font-size: .68rem; text-align: right; white-space: nowrap; }
.guide-table-wrap td { padding: .72rem .8rem; border-top: 1px solid #edf1ef; color: #5e6d68; font-size: .7rem; line-height: 1.55; vertical-align: top; }
.guide-table-wrap tbody tr.current { background: #edfaf3; }
.guide-table-wrap td:first-child { color: #2e463f; white-space: nowrap; }
.guide-table-wrap td small { display: inline-block; margin-right: .35rem; padding: .12rem .32rem; border-radius: 999px; background: #176b45; color: #fff; font-size: .55rem; }
.guide-table-wrap--inner { margin-top: .65rem; border: 1px solid #e1e8e5; border-radius: 12px; }

.chapter-list { display: grid; gap: .85rem; }
.guide-chapter { scroll-margin-top: 95px; overflow: hidden; }
.guide-chapter[open] { border-color: #cbded5; box-shadow: 0 12px 34px rgba(31,56,48,.07); }
.guide-chapter > summary { display: grid; grid-template-columns: 46px 48px 1fr auto; gap: .8rem; align-items: center; padding: 1rem 1.1rem; cursor: pointer; list-style: none; }
.guide-chapter > summary::-webkit-details-marker { display: none; }
.guide-chapter > summary:hover { background: #fafcfb; }
.guide-chapter--relevant > summary { box-shadow: inset -4px 0 #259864; }
.chapter-number { color: #99a6a2; font: 800 .75rem monospace; }
.chapter-icon { display: grid; width: 44px; height: 44px; place-items: center; border-radius: 13px; background: #edf6f1; color: #176b45; font-size: 1.05rem; }
.chapter-copy { min-width: 0; }
.chapter-copy > span { display: block; min-height: 17px; }
.chapter-copy em { color: #168353; font-size: .58rem; font-style: normal; font-weight: 900; }
.chapter-copy strong { display: block; color: #293f38; font-size: .98rem; }
.chapter-copy small { display: block; margin-top: .18rem; color: #7b8783; font-size: .72rem; line-height: 1.55; }
.chapter-toggle { display: grid; width: 32px; height: 32px; place-items: center; color: #81908b; transition: transform .2s ease; }
.guide-chapter[open] .chapter-toggle { transform: rotate(180deg); }
.guide-chapter[open] > summary { border-bottom: 1px solid #edf1ef; background: #fbfdfc; }
.chapter-body { padding: 1.2rem; }
.flow-track { display: flex; align-items: center; gap: .5rem; padding: .85rem; margin-bottom: 1rem; border-radius: 13px; background: #153f33; overflow-x: auto; }
.flow-track span { flex: 0 0 auto; padding: .4rem .62rem; border-radius: 8px; background: rgba(255,255,255,.1); color: #fff; font-size: .66rem; font-weight: 800; white-space: nowrap; }
.flow-track > i { flex: 0 0 auto; color: #82d1ac; }
.chapter-block + .chapter-block { padding-top: 1.05rem; margin-top: 1.05rem; border-top: 1px dashed #dfe7e3; }
.chapter-block h3 { margin: 0 0 .65rem; color: #29453c; font-size: .92rem; }
.chapter-intro { margin: 0 0 .8rem; color: #667670; font-size: .78rem; line-height: 1.8; }
.numbered-steps { display: grid; gap: .55rem; margin: 0; padding: 0; list-style: none; counter-reset: guide-step; }
.numbered-steps li { display: grid; grid-template-columns: 30px 1fr; gap: .65rem; align-items: start; padding: .7rem; border: 1px solid #e8eeeb; border-radius: 11px; background: #fbfcfc; }
.numbered-steps li > span { display: grid; width: 28px; height: 28px; place-items: center; border-radius: 8px; background: #e5f4ec; color: #176b45; font: 900 .68rem monospace; }
.numbered-steps strong { display: block; margin-bottom: .15rem; color: #344c44; font-size: .78rem; }
.numbered-steps p { margin: 0; color: #6c7975; font-size: .72rem; line-height: 1.7; }
.chapter-bullets { display: grid; gap: .5rem; margin: 0; padding: 0; list-style: none; }
.chapter-bullets li { display: grid; grid-template-columns: 22px 1fr; gap: .45rem; align-items: start; color: #64736e; font-size: .74rem; line-height: 1.7; }
.chapter-bullets i { color: #1c8b59; font-size: 1rem; }
.chapter-after { display: flex; gap: .5rem; margin: .75rem 0 0; padding: .7rem; border-radius: 10px; background: #eef6f2; color: #4f6b60; font-size: .72rem; line-height: 1.65; }

.chapter-callout { display: flex; gap: .75rem; margin-top: 1.1rem; padding: .9rem; border: 1px solid; border-radius: 12px; }
.chapter-callout > i { padding-top: .1rem; font-size: 1.1rem; }
.chapter-callout strong { display: block; margin-bottom: .18rem; font-size: .76rem; }
.chapter-callout p { margin: 0; font-size: .7rem; line-height: 1.65; }
.chapter-callout[data-tone="success"] { border-color: #bce1cc; background: #eef9f3; color: #176b45; }
.chapter-callout[data-tone="info"] { border-color: #bdddea; background: #f0f8fb; color: #246077; }
.chapter-callout[data-tone="warning"] { border-color: #efd89f; background: #fff9e9; color: #775b18; }
.chapter-callout[data-tone="danger"] { border-color: #efc4c4; background: #fff3f3; color: #8b3838; }
.chapter-footer { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding-top: 1rem; margin-top: 1.1rem; border-top: 1px solid #edf1ef; }
.chapter-audience { display: flex; flex-wrap: wrap; align-items: center; gap: .35rem; }
.chapter-audience span { color: #899591; font-size: .65rem; }
.chapter-audience b { padding: .25rem .45rem; border-radius: 7px; background: #f1f5f3; color: #587068; font-size: .6rem; }
.chapter-footer__actions { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .45rem; }
.chapter-action { border: 0; }
.chapter-action--screen { border: 1px solid #cfe0d6; background: #fff; color: #176b45 !important; }
.chapter-action--next { max-width: 310px; text-align: start; white-space: normal; }
.reading-complete { display: inline-flex; align-items: center; gap: .4rem; padding: .55rem .7rem; border-radius: 9px; background: #eef8f2; color: #176b45; font-size: .65rem; font-weight: 850; }

.no-results { padding: 3rem 1rem; border: 1px dashed #cbd7d2; border-radius: 18px; background: #fff; text-align: center; }
.no-results > i { color: #91a39c; font-size: 2rem; }
.no-results h2 { margin: .6rem 0 .35rem; color: #344b44; font-size: 1.1rem; }
.no-results p { color: #74817d; }
.no-results button { padding: .6rem .85rem; border: 0; border-radius: 9px; background: #176b45; color: #fff; font-weight: 800; }
.final-rule { display: grid; grid-template-columns: auto 1fr auto; gap: 1rem; align-items: center; padding: 1.2rem; margin-top: 1rem; background: linear-gradient(135deg, #173e33, #176b45); color: #fff; }
.final-rule > span { display: grid; width: 50px; height: 50px; place-items: center; border-radius: 14px; background: rgba(255,255,255,.1); font-size: 1.2rem; }
.final-rule small { color: #aee0c7; font-weight: 800; }
.final-rule h2 { margin: .2rem 0; color: #fff; font-size: 1rem; }
.final-rule p { margin: 0; color: rgba(255,255,255,.73); font-size: .72rem; line-height: 1.65; }
.final-rule a { color: #fff; font-size: .72rem; font-weight: 900; white-space: nowrap; }

@media (max-width: 1199.98px) {
    .role-focus { grid-template-columns: auto 1fr auto; }
    .role-focus__lists { grid-column: 1 / -1; }
    .guide-layout { grid-template-columns: 235px minmax(0, 1fr); }
}

@media (max-width: 991.98px) {
    .guide-header-actions { flex-wrap: wrap; }
    .reading-start { grid-template-columns: 1fr; }
    .reading-start__steps { grid-template-columns: 1fr 1fr 1fr; }
    .role-workspace__picker { flex-direction: column; }
    .role-focus { grid-template-columns: auto 1fr; }
    .role-focus__action { grid-column: 1 / -1; }
    .launch-grid { grid-template-columns: 1fr; }
    .guide-layout { grid-template-columns: 1fr; }
    .guide-toc { position: static; }
    .guide-toc nav { grid-template-columns: repeat(2, minmax(0, 1fr)); max-height: none; }
}

@media (max-width: 575.98px) {
    .guide-header-actions,
    .guide-print-button { width: 100%; }
    .reading-start { border-radius: 16px; }
    .reading-start__main,
    .reading-start__help { padding: 1rem; }
    .reading-start__steps { grid-template-columns: 1fr; }
    .reading-start__actions { display: grid; grid-template-columns: 1fr; }
    .start-reading-button,
    .start-work-button { width: 100%; }
    .role-focus { grid-template-columns: 1fr; }
    .role-focus__icon { width: 48px; height: 48px; }
    .role-focus__lists { grid-template-columns: 1fr; }
    .launch-card__header { align-items: flex-start; flex-direction: column; }
    .launch-progress { width: 100%; }
    .launch-item { grid-template-columns: auto auto 1fr; }
    .launch-item > a { grid-column: 3; }
    .guide-toc nav { grid-template-columns: 1fr; }
    .permissions-card > summary { grid-template-columns: 34px minmax(0, 1fr) auto; padding: .75rem; }
    .guide-chapter > summary { grid-template-columns: 34px 42px 1fr auto; gap: .5rem; padding: .85rem .75rem; }
    .chapter-icon { width: 40px; height: 40px; }
    .chapter-body { padding: .9rem; }
    .chapter-footer { align-items: flex-start; flex-direction: column; }
    .chapter-footer__actions,
    .chapter-action { width: 100%; }
    .chapter-action--next { max-width: none; }
    .final-rule { grid-template-columns: auto 1fr; }
    .final-rule a { grid-column: 1 / -1; }
}

@page {
    size: A4 portrait;
    margin: 15mm 13mm 18mm;

    @bottom-center {
        content: "صفحة " counter(page) " من " counter(pages);
        color: #64736e;
        font-size: 8pt;
    }
}

@media print {
    :global(html),
    :global(body) {
        width: 210mm !important;
        min-width: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        color: #1d302a !important;
        font-size: 10pt !important;
        print-color-adjust: exact !important;
        -webkit-print-color-adjust: exact !important;
    }

    :global(.app-header),
    :global(.app-sidebar),
    :global(#responsive-overlay),
    :global(.footer),
    :global(.page-header),
    .reading-start,
    .guide-header-actions,
    .role-workspace__picker,
    .guide-toc,
    .role-focus__action,
    .launch-item > a,
    .chapter-toggle,
    .chapter-action,
    .reading-complete,
    .no-results { display: none !important; }

    :global(.page),
    :global(.main-content.app-content),
    :global(.main-content .container-fluid) {
        width: 100% !important;
        max-width: none !important;
        min-height: 0 !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }

    .print-running-header,
    .print-running-footer {
        position: fixed;
        z-index: 100;
        right: 0;
        left: 0;
        display: flex;
        align-items: center;
        justify-content: space-between;
        color: #6b7975;
        font-size: 7.5pt;
    }

    .print-running-header {
        top: -10mm;
        padding-bottom: 2.5mm;
        border-bottom: .25mm solid #dfe7e3;
    }

    .print-running-header span { display: inline-flex; align-items: center; gap: 2mm; font-weight: 800; }
    .print-running-header img { width: 7mm; height: 7mm; object-fit: contain; }
    .print-running-header strong { color: #28463d; font-size: 7.5pt; }
    .print-running-footer { bottom: -12mm; padding-top: 2.5mm; border-top: .25mm solid #dfe7e3; }

    .print-cover {
        position: relative;
        display: flex;
        min-height: 245mm;
        flex-direction: column;
        padding: 18mm 16mm 14mm;
        border: .5mm solid #d6e2dc;
        background:
            radial-gradient(circle at 12% 10%, rgba(210, 158, 39, .22), transparent 30%),
            linear-gradient(145deg, #0f3a2d, #176b45 58%, #11825a);
        color: #fff;
        break-after: page;
        page-break-after: always;
        overflow: hidden;
    }

    .print-cover::after {
        position: absolute;
        inset: 0;
        content: '';
        background-image: radial-gradient(rgba(255,255,255,.16) .35mm, transparent .35mm);
        background-size: 6mm 6mm;
        mask-image: linear-gradient(90deg, #000, transparent 72%);
        opacity: .45;
    }

    .print-cover > * { position: relative; z-index: 1; }
    .print-cover__mark { display: grid; width: 25mm; height: 25mm; place-items: center; border: .35mm solid rgba(255,255,255,.32); border-radius: 7mm; background: rgba(255,255,255,.13); }
    .print-cover__mark img { width: 18mm; height: 18mm; object-fit: contain; }
    .print-cover__mark span { font-size: 25pt; }
    .print-cover__body { max-width: 145mm; margin-top: 30mm; }
    .print-cover__eyebrow { display: inline-block; padding: 2mm 4mm; border: .3mm solid rgba(255,255,255,.3); border-radius: 99mm; color: #d7f2e4; font-size: 9pt; font-weight: 900; }
    .print-cover h1 { margin: 7mm 0 5mm; color: #fff !important; font-size: 34pt; line-height: 1.25; }
    .print-cover__body p { max-width: 140mm; margin: 0; color: rgba(255,255,255,.8); font-size: 12pt; line-height: 1.9; }
    .print-cover__meta { display: grid; grid-template-columns: 1fr 1fr; gap: 3mm; margin: auto 0 10mm; }
    .print-cover__meta > div { padding: 4mm; border: .3mm solid rgba(255,255,255,.2); border-radius: 3mm; background: rgba(255,255,255,.08); }
    .print-cover__meta dt { margin-bottom: 1mm; color: #bce4d0; font-size: 8pt; font-weight: 800; }
    .print-cover__meta dd { margin: 0; color: #fff; font-size: 10pt; font-weight: 900; }
    .print-cover footer { display: flex; justify-content: space-between; padding-top: 5mm; border-top: .3mm solid rgba(255,255,255,.23); color: rgba(255,255,255,.78); font-size: 9pt; }

    .print-toc {
        display: block;
        min-height: 245mm;
        padding-top: 8mm;
        break-after: page;
        page-break-after: always;
    }

    .print-toc header { padding-bottom: 7mm; margin-bottom: 7mm; border-bottom: .6mm solid #176b45; }
    .print-toc header > span { color: #176b45; font-size: 9pt; font-weight: 900; }
    .print-toc h2 { margin: 2mm 0; color: #1d382f; font-size: 24pt; }
    .print-toc header p { margin: 0; color: #657570; font-size: 10pt; }
    .print-toc ol { display: grid; grid-template-columns: 1fr 1fr; gap: 3mm 5mm; margin: 0; padding: 0; list-style: none; }
    .print-toc li { display: grid; grid-template-columns: 11mm 1fr; gap: 3mm; min-height: 22mm; align-items: start; padding: 3mm; border: .3mm solid #dfe8e4; border-radius: 3mm; break-inside: avoid; }
    .print-toc li > span { display: grid; width: 10mm; height: 10mm; place-items: center; border-radius: 2.5mm; background: #e8f4ed; color: #176b45; font-size: 8pt; font-weight: 900; }
    .print-toc li strong { display: block; color: #29453c; font-size: 9pt; line-height: 1.45; }
    .print-toc li small { display: block; margin-top: 1mm; color: #788681; font-size: 7.5pt; line-height: 1.45; }

    .role-workspace,
    .launch-card,
    .permissions-card,
    .guide-chapter,
    .final-rule {
        border: .3mm solid #dfe7e3 !important;
        border-radius: 3mm !important;
        box-shadow: none !important;
    }

    .role-workspace { padding: 4mm; margin: 0 0 6mm; break-inside: avoid; }
    .role-focus { grid-template-columns: 13mm 1fr !important; gap: 4mm; padding: 4mm; background: #f2f8f5 !important; }
    .role-focus__icon { width: 13mm; height: 13mm; border-radius: 3mm; box-shadow: none; }
    .role-focus__main h2 { font-size: 12pt; }
    .role-focus__main p { font-size: 8.5pt; }
    .role-focus__lists { grid-column: 1 / -1; grid-template-columns: 1fr 1fr; }
    .role-focus__lists > div { padding: 3mm; }
    .role-focus__lists strong { font-size: 8.5pt; }
    .role-focus__lists ul { font-size: 7.5pt; }

    .launch-card { padding: 5mm; margin: 0 0 7mm; break-after: page; page-break-after: always; }
    .launch-card__header { margin-bottom: 4mm; }
    .launch-card__header h2 { font-size: 15pt; }
    .launch-grid { grid-template-columns: 1fr 1fr; gap: 2.5mm; }
    .launch-item { grid-template-columns: 8mm 8mm 1fr; gap: 2mm; padding: 3mm; border-radius: 2.5mm; background: #fff !important; box-shadow: none !important; transform: none !important; break-inside: avoid; }
    .launch-item__check { display: grid !important; width: 7mm; height: 7mm; pointer-events: none; }
    .launch-item strong { font-size: 8.5pt; }
    .launch-item p { font-size: 7.2pt; }
    .launch-item small { font-size: 6.8pt; }

    .guide-layout { display: block; }
    .permissions-card { display: block !important; margin: 0; break-after: page; page-break-after: always; overflow: visible; }
    .permissions-card > summary { display: grid !important; padding: 4mm; }
    .permissions-card > .guide-table-wrap,
    .permissions-card > p { display: block !important; }
    .permissions-card h2 { font-size: 15pt; }
    .permissions-card > p { padding: 3mm 4mm; font-size: 7.5pt; }

    .guide-table-wrap { overflow: visible !important; }
    .guide-table-wrap table { min-width: 0 !important; table-layout: fixed; }
    .guide-table-wrap thead { display: table-header-group; }
    .guide-table-wrap tr { break-inside: avoid; page-break-inside: avoid; }
    .guide-table-wrap th { padding: 2.3mm; background: #edf5f1 !important; color: #29483e; font-size: 7.2pt; white-space: normal; }
    .guide-table-wrap td { padding: 2.3mm; font-size: 7.2pt; line-height: 1.5; }
    .guide-table-wrap td:first-child { white-space: normal; }

    .chapter-list { display: block; }
    .guide-chapter {
        display: block !important;
        margin: 0 !important;
        border: 0 !important;
        border-radius: 0 !important;
        overflow: visible !important;
        break-before: page;
        page-break-before: always;
        break-inside: auto;
    }

    .guide-chapter > summary {
        display: grid !important;
        grid-template-columns: 13mm 13mm 1fr !important;
        gap: 3mm;
        min-height: 24mm;
        padding: 5mm 0 !important;
        border-top: 1.2mm solid #176b45;
        border-bottom: .3mm solid #dce6e1 !important;
        background: #fff !important;
        break-after: avoid;
        page-break-after: avoid;
    }

    .chapter-number { font-size: 9pt; }
    .chapter-icon { width: 12mm; height: 12mm; border-radius: 3mm; background: #e8f4ed !important; font-size: 12pt; }
    .chapter-copy > span { min-height: 3mm; }
    .chapter-copy em { font-size: 7pt; }
    .chapter-copy strong { color: #1e3d33; font-size: 17pt; }
    .chapter-copy small { color: #61716c; font-size: 8.5pt; }
    .guide-chapter > .chapter-body,
    .guide-chapter:not([open]) > .chapter-body { display: block !important; padding: 5mm 0 0; }
    .chapter-block { break-inside: auto; }
    .chapter-block + .chapter-block { padding-top: 4mm; margin-top: 4mm; }
    .chapter-block h3 { margin: 0 0 3mm; color: #21483a; font-size: 12pt; break-after: avoid; page-break-after: avoid; }
    .chapter-intro { font-size: 8.5pt; }
    .numbered-steps { gap: 2mm; }
    .numbered-steps li { grid-template-columns: 8mm 1fr; gap: 2.5mm; padding: 3mm; border-radius: 2mm; background: #fafcfb !important; break-inside: avoid; page-break-inside: avoid; }
    .numbered-steps li > span { width: 7mm; height: 7mm; border-radius: 2mm; font-size: 7pt; }
    .numbered-steps strong { font-size: 8.5pt; }
    .numbered-steps p { font-size: 8pt; line-height: 1.6; }
    .chapter-bullets { gap: 1.5mm; }
    .chapter-bullets li { grid-template-columns: 6mm 1fr; font-size: 8pt; line-height: 1.65; break-inside: avoid; }
    .chapter-after { font-size: 7.8pt; }
    .flow-track { flex-wrap: wrap; gap: 2mm; padding: 3mm; background: #173f33 !important; overflow: visible; break-inside: avoid; }
    .flow-track span { padding: 2mm 3mm; font-size: 7pt; }
    .chapter-callout { padding: 3mm; break-inside: avoid; page-break-inside: avoid; }
    .chapter-callout strong { font-size: 8.5pt; }
    .chapter-callout p { font-size: 7.8pt; }
    .chapter-footer { padding-top: 3mm; margin-top: 4mm; }
    .chapter-audience span,
    .chapter-audience b { font-size: 6.8pt; }

    .final-rule { grid-template-columns: 13mm 1fr; gap: 4mm; padding: 5mm; margin-top: 7mm; background: #173f33 !important; break-inside: avoid; }
    .final-rule > span { width: 12mm; height: 12mm; }
    .final-rule h2 { font-size: 11pt; }
    .final-rule p { font-size: 8pt; }

    h1, h2, h3, strong { orphans: 3; widows: 3; }
    p, li, td { orphans: 3; widows: 3; }
}
</style>
