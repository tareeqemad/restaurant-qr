<script setup>
/**
 * Phase-0 gate page (MIGRATION-PILOT.md §5). Proves the whole pipeline —
 * route → Inertia middleware → root Blade → Vite entry → Vue mount — before
 * sol writes a single component. Nothing here is production UI.
 */
defineProps({
    now: String,
    userName: String,
});
</script>

<template>
    <main class="ping">
        <div class="ping-card">
            <div class="ping-mark"><i class="bi bi-check2-circle"></i></div>
            <h1>Inertia + Vue شغّال ✓</h1>
            <p>
                أهلاً {{ userName }} — الصفحة هذه انبنت بـ Vue 3 ووصلتها البيانات من Laravel
                عبر Inertia بلا أي API منفصل.
            </p>
            <dl>
                <dt>وقت السيرفر</dt>
                <dd>{{ now }}</dd>
            </dl>
            <a href="/admin" class="ping-back"><i class="bi bi-arrow-right"></i> رجوع للوحة التحكم</a>
        </div>
    </main>
</template>

<style scoped>
.ping {
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
    background:
        linear-gradient(180deg, rgba(var(--primary-rgb, 22 101 52), .06) 0%, transparent 240px),
        #f7f8f7;
}
.ping-card {
    width: 100%;
    max-width: 420px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-top: 4px solid rgb(var(--primary-rgb, 22 101 52));
    border-radius: 16px;
    padding: 2rem 1.75rem;
    text-align: center;
    box-shadow: 0 16px 40px -18px rgba(31, 41, 55, .16);
}
.ping-mark {
    font-size: 2.6rem;
    color: rgb(var(--primary-rgb, 22 101 52));
    margin-bottom: .5rem;
}
h1 { margin: 0 0 .5rem; font-size: 1.3rem; font-weight: 800; color: #1f2937; }
p { margin: 0 0 1rem; color: #6b7280; font-size: .9rem; line-height: 1.7; }
dl { margin: 0 0 1.25rem; font-size: .85rem; color: #374151; }
dt { font-weight: 700; color: #9ca3af; }
dd { margin: 0; font-variant-numeric: tabular-nums; }
.ping-back {
    display: inline-flex;
    align-items: center;
    gap: .35rem;
    min-height: 44px;
    padding: .5rem 1rem;
    border-radius: 10px;
    background: rgb(var(--primary-rgb, 22 101 52));
    color: #fff;
    font-weight: 800;
    font-size: .9rem;
    text-decoration: none;
}
</style>
