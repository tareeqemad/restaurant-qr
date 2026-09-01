<script setup>
/**
 * Branch switcher pill — same workspace-picker design as the Blade
 * partial (the bsx-* styles moved here with it). Switching is a classic
 * form POST: the server flips the session branch and redirects back.
 */
import { useDropdown } from '../../Composables/useDropdown';
import { formPost } from '../../Support/formPost';

defineProps({
    branch: { type: Object, required: true },
});

const { open, root, toggle } = useDropdown();

const initial = (name) => (name ?? '').trim().charAt(0) || '؟';
const switchTo = (url) => formPost(url);
</script>

<template>
    <div class="header-element bsx" ref="root">
        <!-- Single-branch staff: static chip, nothing to switch. -->
        <span v-if="branch.single" class="bsx-trigger is-static">
            <span class="bsx-avatar" :style="{ '--hue': branch.activeHue }">{{ initial(branch.activeName) }}</span>
            <span class="bsx-trigger__name">{{ branch.activeName }}</span>
        </span>

        <template v-else>
            <a href="#" class="bsx-trigger" :class="{ 'bsx-trigger--all': branch.allMode }"
               role="button" :aria-expanded="open" @click.prevent="toggle">
                <span v-if="branch.allMode" class="bsx-avatar bsx-avatar--all">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.6"/><path d="M1.5 8h13M8 1.5c2 2 2 11 0 13M8 1.5c-2 2-2 11 0 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                </span>
                <span v-else class="bsx-avatar" :style="{ '--hue': branch.activeHue }">{{ initial(branch.activeName) }}</span>
                <span class="bsx-trigger__name">{{ branch.allMode ? 'كل الفروع' : branch.activeName }}</span>
                <svg class="bsx-trigger__chev" :class="{ 'is-open': open }" width="11" height="11" viewBox="0 0 12 12" fill="none"><path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </a>

            <div v-show="open" class="bsx-menu">
                <div class="bsx-menu__head">اختر الفرع</div>

                <div class="bsx-menu__list">
                    <button v-if="branch.canAll" type="button"
                            class="bsx-row bsx-row--all" :class="{ 'is-active': branch.allMode }"
                            :disabled="branch.allMode"
                            @click="switchTo(branch.allUrl)">
                        <span class="bsx-avatar bsx-avatar--all">
                            <svg width="14" height="14" viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.6"/><path d="M1.5 8h13M8 1.5c2 2 2 11 0 13M8 1.5c-2 2-2 11 0 13" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                        </span>
                        <span class="bsx-row__body">
                            <span class="bsx-row__name">كل الفروع</span>
                            <span class="bsx-row__meta">عرض بيانات كل الفروع معاً</span>
                        </span>
                        <svg v-if="branch.allMode" class="bsx-row__check" width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2.5 7L5.5 10L11.5 4" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>

                    <button v-for="b in branch.branches" :key="b.id" type="button"
                            class="bsx-row" :class="{ 'is-active': b.active }"
                            :disabled="b.active"
                            :style="{ '--hue': b.hue }"
                            @click="switchTo(b.switchUrl)">
                        <span class="bsx-avatar">{{ initial(b.name) }}</span>
                        <span class="bsx-row__body">
                            <span class="bsx-row__name">{{ b.name }}</span>
                            <span v-if="b.city" class="bsx-row__meta">{{ b.city }}</span>
                        </span>
                        <svg v-if="b.active" class="bsx-row__check" width="14" height="14" viewBox="0 0 14 14" fill="none"><path d="M2.5 7L5.5 10L11.5 4" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    </button>
                </div>

                <a v-if="branch.manageUrl" :href="branch.manageUrl" class="bsx-menu__manage">
                    <i class="bi bi-gear-fill"></i>
                    <span>إدارة الفروع</span>
                </a>
            </div>
        </template>
    </div>
</template>

<style scoped>
/* Same bsx design language as the Blade partial. Dashtic globally forces
   button { background: white; padding: .75rem }, hence the !important
   resets on .bsx-row. */
.bsx { position: relative; display: inline-flex; margin-inline-end: 8px; }

.bsx-trigger {
    display: inline-flex;
    align-items: center;
    gap: 9px;
    height: 40px;
    padding: 0 12px 0 8px;
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 999px;
    color: #111827;
    font-size: .86rem;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    max-width: 240px;
    transition: background-color .15s, border-color .15s, box-shadow .15s;
    box-shadow: 0 1px 2px rgba(15, 23, 42, .04);
}
.bsx-trigger:hover:not(.is-static) {
    background: #f9fafb;
    border-color: #d1d5db;
    box-shadow: 0 4px 12px -3px rgba(15, 23, 42, .08);
    color: #111827;
}
.bsx-trigger.is-static { cursor: default; }
.bsx-trigger__name {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1;
}
.bsx-trigger__chev { flex-shrink: 0; color: #9ca3af; transition: transform .2s ease; }
.bsx-trigger__chev.is-open { transform: rotate(180deg); }
.bsx-trigger--all {
    background: linear-gradient(135deg, rgba(var(--primary-rgb), .07), rgba(var(--accent-rgb), .07));
    border-color: rgba(var(--primary-rgb), .35);
}
.bsx-trigger--all .bsx-trigger__name { color: rgb(var(--primary-rgb)); }

.bsx-avatar {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    flex-shrink: 0;
    display: inline-flex !important;
    align-items: center;
    justify-content: center;
    background: linear-gradient(135deg, hsl(var(--hue, 150) 50% 52%) 0%, hsl(var(--hue, 150) 55% 38%) 100%);
    color: #fff;
    font-size: .82rem;
    font-weight: 800;
    line-height: 1;
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, .25);
}
.bsx-avatar--all {
    background: linear-gradient(135deg, rgb(var(--primary-rgb)) 0%, rgb(var(--accent-rgb)) 100%);
}

.bsx-menu {
    position: absolute;
    top: calc(100% + 8px);
    inset-inline-end: 0;
    min-width: 320px;
    max-width: 360px;
    background: #fff;
    border: 1px solid rgba(15, 23, 42, .08);
    border-radius: 14px;
    box-shadow: 0 4px 6px -2px rgba(15, 23, 42, .06), 0 18px 36px -8px rgba(15, 23, 42, .16);
    overflow: hidden;
    z-index: 1040;
}
.bsx-menu__head {
    padding: 10px 14px 6px;
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .04em;
    color: #9ca3af;
}
.bsx-menu__list {
    padding: 0 6px 6px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    max-height: 360px;
    overflow-y: auto;
}
.bsx-menu__list::-webkit-scrollbar { width: 6px; }
.bsx-menu__list::-webkit-scrollbar-thumb { background: #e5e7eb; border-radius: 3px; }

.bsx-menu .bsx-row,
button.bsx-row {
    display: flex !important;
    align-items: center !important;
    gap: 11px !important;
    width: 100% !important;
    padding: 8px 10px !important;
    background: transparent !important;
    border: 0 !important;
    border-radius: 9px !important;
    color: #1f2937 !important;
    text-align: start !important;
    cursor: pointer !important;
    font: inherit !important;
    font-size: .88rem !important;
    font-weight: 600 !important;
    line-height: 1.2 !important;
    box-shadow: none !important;
    transition: background-color .12s !important;
}
.bsx-menu .bsx-row:hover:not(:disabled) { background: #f3f4f6 !important; }
.bsx-menu .bsx-row:disabled { cursor: default !important; opacity: 1 !important; }
.bsx-menu .bsx-row.is-active { background: #f3f4f6 !important; }

.bsx-row__body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 2px; }
.bsx-row__name {
    font-size: .88rem;
    font-weight: 700;
    color: #111827;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.25;
}
.bsx-row.is-active .bsx-row__name { font-weight: 800; }
.bsx-row__meta {
    font-size: .72rem;
    font-weight: 500;
    color: #6b7280;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.2;
}
.bsx-row__check { flex-shrink: 0; color: rgb(var(--accent-rgb)); }

.bsx-menu__manage {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 10px 14px;
    border-top: 1px solid #f3f4f6;
    background: #fafafa;
    font-size: .82rem;
    font-weight: 600;
    color: #4b5563;
    text-decoration: none;
    transition: background-color .12s, color .12s;
}
.bsx-menu__manage:hover { background: #f3f4f6; color: rgb(var(--primary-rgb)); }
.bsx-menu__manage > i { color: #9ca3af; font-size: .9rem; }
.bsx-menu__manage:hover > i { color: rgb(var(--primary-rgb)); }

@media (max-width: 768px) {
    .bsx-trigger { padding: 0 8px; max-width: 44px; justify-content: center; }
    .bsx-trigger__name, .bsx-trigger__chev { display: none; }
    .bsx-menu { min-width: 280px; max-width: calc(100vw - 24px); }
}
</style>
