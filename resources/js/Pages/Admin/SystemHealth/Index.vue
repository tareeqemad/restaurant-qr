<script setup>
import ManagementPage from '../../../Components/Inventory/ManagementPage.vue';
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    report: { type: Object, required: true },
    deployment: { type: Object, required: true },
});

const tone = {
    good: { label: 'جاهز', icon: 'bi-check-circle-fill' },
    warning: { label: 'تنبيه', icon: 'bi-exclamation-circle-fill' },
    danger: { label: 'يتطلب إجراء', icon: 'bi-x-octagon-fill' },
};

const overall = computed(() => {
    if (props.report.summary.danger) return { status: 'danger', title: 'يوجد ما يمنع الجاهزية الكاملة', detail: `${props.report.summary.danger} فحوص تحتاج معالجة قبل الاعتماد على النشر.` };
    if (props.report.summary.warning) return { status: 'warning', title: 'النظام يعمل مع تنبيهات تشغيلية', detail: `${props.report.summary.warning} تنبيهات تستحق المتابعة.` };
    return { status: 'good', title: 'النظام جاهز للإنتاج', detail: 'كل الفحوص الأساسية اجتازت التحقق.' };
});
</script>

<template>
    <ManagementPage mode="workspace">
    <Head title="حالة النظام" />

    <main class="health-page">
        <header class="health-hero" :data-status="overall.status">
            <div class="hero-copy">
                <span class="eyebrow"><i class="bi bi-shield-check"></i> مركز الجاهزية الإنتاجية</span>
                <h1>{{ overall.title }}</h1>
                <p>{{ overall.detail }}</p>
                <small>آخر فحص: {{ new Date(report.generatedAt).toLocaleString('ar') }}</small>
            </div>
            <div class="score-grid">
                <article data-status="good"><strong>{{ report.summary.good }}</strong><span>جاهز</span></article>
                <article data-status="warning"><strong>{{ report.summary.warning }}</strong><span>تنبيه</span></article>
                <article data-status="danger"><strong>{{ report.summary.danger }}</strong><span>إجراء</span></article>
            </div>
        </header>

        <section class="checks-grid" aria-label="فحوص النظام">
            <article v-for="check in report.checks" :key="check.key" class="check-card" :data-status="check.status">
                <span class="check-icon"><i class="bi" :class="tone[check.status].icon"></i></span>
                <div class="check-copy">
                    <header><h2>{{ check.label }}</h2><b>{{ tone[check.status].label }}</b></header>
                    <p>{{ check.summary }}</p>
                    <small v-if="check.detail">{{ check.detail }}</small>
                    <code v-if="check.command" dir="ltr">{{ check.command }}</code>
                </div>
            </article>
        </section>

        <section class="runbook">
            <header><div><span>دليل المشغّل</span><h2>أوامر واضحة بدون تخمين</h2></div><i class="bi bi-terminal-fill"></i></header>
            <div class="runbook-grid">
                <article><span>فحص فقط</span><code dir="ltr">{{ deployment.healthCommand }}</code><p>يعرض نفس الفحوص من Terminal بدون تغيير البيانات.</p></article>
                <article><span>نشر آمن</span><code dir="ltr">{{ deployment.deployCommand }}</code><p>Backup ثم migrations والتخزين والكاش والتحقق النهائي.</p></article>
                <article><span>Cron كل دقيقة</span><code dir="ltr">{{ deployment.schedulerCommand }}</code><p>يشغّل التذكيرات والإغلاق الآمن والنسخ والنبضة.</p></article>
                <article><span>Queue على الاستضافة</span><code dir="ltr">{{ deployment.queueCommand }}</code><p>مناسب للتشغيل المتكرر من Cron عندما لا يتوفر Worker دائم.</p></article>
            </div>
        </section>
    </main>
    </ManagementPage>
</template>

<style scoped>
.health-page{display:grid;gap:16px;padding-block:14px 28px;color:var(--ink, #25343A)}.health-hero{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:22px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;background:var(--surface, #FFFDFA);box-shadow:none}.health-hero[data-status="danger"]{background:#fff1f2}.health-hero[data-status="warning"]{background:#fff8eb}.hero-copy{max-width:720px}.eyebrow{display:inline-flex;align-items:center;gap:7px;color:var(--brand, #A3422B);font-size:.875rem;font-weight:850}.health-hero h1{margin:6px 0 3px;font-size:1.45rem;font-weight:950}.health-hero p{margin:0;color:var(--muted, #626B6D);font-size:.875rem}.health-hero small{display:block;margin-top:9px;color:var(--muted, #626B6D);font-size:.875rem}.score-grid{display:grid;grid-template-columns:repeat(3,78px);gap:8px}.score-grid article{display:grid;min-height:74px;place-content:center;text-align:center;border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;background:var(--surface, #FFFDFA)}.score-grid strong{font-size:1.25rem}.score-grid span{color:var(--muted, #626B6D);font-size:.875rem;font-weight:800}.score-grid [data-status="good"] strong{color:#187148}.score-grid [data-status="warning"] strong{color:#a66608}.score-grid [data-status="danger"] strong{color:#b3333c}.checks-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.check-card{display:flex;gap:11px;min-height:120px;padding:14px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;background:var(--surface, #FFFDFA)}.check-icon{display:grid;width:42px;height:42px;flex:0 0 42px;place-items:center;border-radius:12px;background:var(--canvas, #F7F3ED);color:var(--brand, #A3422B);font-size:1.05rem}.check-card[data-status="warning"] .check-icon{background:#fff3db;color:#9a6208}.check-card[data-status="danger"] .check-icon{background:#fff0f0;color:#b12e39}.check-copy{display:grid;min-width:0;align-content:start;gap:5px;flex:1}.check-copy header{display:flex;align-items:center;justify-content:space-between;gap:8px}.check-copy h2{margin:0;font-size:.875rem;font-weight:900}.check-copy b{padding:3px 7px;border-radius:12px;background:var(--canvas, #F7F3ED);color:var(--brand, #A3422B);font-size:.875rem;white-space:nowrap}.check-card[data-status="warning"] b{background:#fff2d7;color:#986109}.check-card[data-status="danger"] b{background:#ffeded;color:#ad303a}.check-copy p{margin:0;color:var(--muted, #626B6D);font-size:.875rem;font-weight:750}.check-copy small{color:var(--muted, #626B6D);font-size:.875rem;line-height:1.55}.check-copy code,.runbook code{display:block;overflow:auto;padding:7px 9px;border-radius:8px;background:var(--ink, #25343A);color:var(--brand-soft, #F3E3D8);font:600 .62rem/1.5 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:nowrap}.runbook{padding:18px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;background:var(--canvas, #F7F3ED)}.runbook>header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.runbook>header span{color:var(--muted, #626B6D);font-size:.875rem;font-weight:850}.runbook h2{margin:2px 0 0;font-size:1rem}.runbook>header>i{color:var(--brand, #A3422B);font-size:1.5rem}.runbook-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.runbook article{padding:12px;border:1px solid var(--brand-soft, #F3E3D8);border-radius:12px;background:var(--surface, #FFFDFA)}.runbook article>span{display:block;margin-bottom:6px;font-size:.875rem;font-weight:900}.runbook p{margin:7px 0 0;color:var(--muted, #626B6D);font-size:.875rem;line-height:1.6}@media(max-width:900px){.health-hero{align-items:flex-start;flex-direction:column}.checks-grid{grid-template-columns:1fr}.score-grid{width:100%;grid-template-columns:repeat(3,1fr)}}@media(max-width:600px){.health-page{padding-block-start:8px}.health-hero{padding:16px}.score-grid{grid-template-columns:1fr 1fr 1fr}.runbook-grid{grid-template-columns:1fr}.check-card{padding:12px}}

/* Dar Al Sarw management layout */
.health-page { padding: 0 0 24px; gap: 22px; } .health-hero { padding: 24px; border-radius: 12px; background: var(--surface); border-inline-start: 4px solid var(--brand); } .health-hero h1 { color: var(--ink); font-size: 1.5rem; } .health-hero[data-status="danger"] { background: #fff1f2; border-inline-start-color: #b91c1c; } .health-hero[data-status="warning"] { background: #fff8eb; border-inline-start-color: #b45309; } .checks-grid { gap: 18px; grid-template-columns: repeat(auto-fit,minmax(min(320px,100%),1fr)); } .check-card { border-radius: 12px; padding: 20px; } .runbook { padding: 22px; border-radius: 12px; background: var(--surface); }
</style>
