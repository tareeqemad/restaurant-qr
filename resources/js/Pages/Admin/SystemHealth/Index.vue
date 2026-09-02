<script setup>
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
</template>

<style scoped>
.health-page{display:grid;gap:16px;padding-block:14px 28px;color:#203128}.health-hero{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:22px;border:1px solid #dce6df;border-radius:22px;background:linear-gradient(135deg,#fff,#f4f8f5);box-shadow:0 18px 45px -38px #173826}.health-hero[data-status="danger"]{background:linear-gradient(135deg,#fff,#fff5f4)}.health-hero[data-status="warning"]{background:linear-gradient(135deg,#fff,#fff9ee)}.hero-copy{max-width:720px}.eyebrow{display:inline-flex;align-items:center;gap:7px;color:#397050;font-size:.7rem;font-weight:850}.health-hero h1{margin:6px 0 3px;font-size:1.45rem;font-weight:950}.health-hero p{margin:0;color:#64736b;font-size:.82rem}.health-hero small{display:block;margin-top:9px;color:#8a958f;font-size:.64rem}.score-grid{display:grid;grid-template-columns:repeat(3,78px);gap:8px}.score-grid article{display:grid;min-height:74px;place-content:center;text-align:center;border:1px solid #e0e7e2;border-radius:15px;background:#fff}.score-grid strong{font-size:1.25rem}.score-grid span{color:#758079;font-size:.62rem;font-weight:800}.score-grid [data-status="good"] strong{color:#187148}.score-grid [data-status="warning"] strong{color:#a66608}.score-grid [data-status="danger"] strong{color:#b3333c}.checks-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}.check-card{display:flex;gap:11px;min-height:120px;padding:14px;border:1px solid #dfe7e2;border-radius:17px;background:#fff}.check-icon{display:grid;width:42px;height:42px;flex:0 0 42px;place-items:center;border-radius:12px;background:#e9f5ed;color:#21714b;font-size:1.05rem}.check-card[data-status="warning"] .check-icon{background:#fff3db;color:#9a6208}.check-card[data-status="danger"] .check-icon{background:#fff0f0;color:#b12e39}.check-copy{display:grid;min-width:0;align-content:start;gap:5px;flex:1}.check-copy header{display:flex;align-items:center;justify-content:space-between;gap:8px}.check-copy h2{margin:0;font-size:.82rem;font-weight:900}.check-copy b{padding:3px 7px;border-radius:999px;background:#eaf5ed;color:#25714c;font-size:.56rem;white-space:nowrap}.check-card[data-status="warning"] b{background:#fff2d7;color:#986109}.check-card[data-status="danger"] b{background:#ffeded;color:#ad303a}.check-copy p{margin:0;color:#55675d;font-size:.72rem;font-weight:750}.check-copy small{color:#849088;font-size:.62rem;line-height:1.55}.check-copy code,.runbook code{display:block;overflow:auto;padding:7px 9px;border-radius:8px;background:#15231b;color:#c8f1d6;font:600 .62rem/1.5 ui-monospace,SFMono-Regular,Consolas,monospace;white-space:nowrap}.runbook{padding:18px;border:1px solid #dce5df;border-radius:20px;background:#f8faf8}.runbook>header{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.runbook>header span{color:#5c7668;font-size:.63rem;font-weight:850}.runbook h2{margin:2px 0 0;font-size:1rem}.runbook>header>i{color:#397052;font-size:1.5rem}.runbook-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px}.runbook article{padding:12px;border:1px solid #e0e7e2;border-radius:13px;background:#fff}.runbook article>span{display:block;margin-bottom:6px;font-size:.68rem;font-weight:900}.runbook p{margin:7px 0 0;color:#748078;font-size:.62rem;line-height:1.6}@media(max-width:900px){.health-hero{align-items:flex-start;flex-direction:column}.checks-grid{grid-template-columns:1fr}.score-grid{width:100%;grid-template-columns:repeat(3,1fr)}}@media(max-width:600px){.health-page{padding-block-start:8px}.health-hero{padding:16px}.score-grid{grid-template-columns:1fr 1fr 1fr}.runbook-grid{grid-template-columns:1fr}.check-card{padding:12px}}
</style>
