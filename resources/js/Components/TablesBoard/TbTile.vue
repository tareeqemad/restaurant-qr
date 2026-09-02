<script setup>
/**
 * One floor-map tile. State → look comes from the server's tileState
 * (urgent/bill/attention/cleaning/stale/occupied/reserved/oos/available)
 * so the map can never disagree with the feed about the same table.
 */
import { Link } from '@inertiajs/vue3';
import TbActionsMenu from './TbActionsMenu.vue';

const TILE_META = {
    urgent: { label: 'عاجل', icon: 'bi-exclamation-triangle-fill' },
    bill: { label: 'فاتورة', icon: 'bi-receipt-cutoff' },
    attention: { label: 'تحتاج متابعة', icon: 'bi-clock-history' },
    cleaning: { label: 'تحتاج تنظيف', icon: 'bi-stars' },
    stale: { label: 'راكدة', icon: 'bi-hourglass-bottom' },
    occupied: { label: 'مشغولة', icon: 'bi-people-fill' },
    reserved: { label: 'محجوزة', icon: 'bi-bookmark-check-fill' },
    oos: { label: 'خارج الخدمة', icon: 'bi-tools' },
    available: { label: 'متاحة', icon: 'bi-check2-circle' },
};

const props = defineProps({
    row: { type: Object, required: true },
    transferTables: { type: Array, default: () => [] },
    menuOpen: { type: Boolean, default: false },
});

defineEmits(['menu-toggle', 'quick-edit', 'transfer', 'destroy', 'clean', 'serve', 'ack', 'close']);

const meta = () => TILE_META[props.row.tileState] ?? TILE_META.occupied;
const displayName = () => {
    const name = String(props.row.name ?? '').trim();
    if (name === '' || name === `طاولة ${props.row.number}` || name === `طاولة رقم ${props.row.number}`) {
        return '';
    }

    return name;
};
const primaryAction = () => {
    if (props.row.tileState === 'bill' && props.row.urls.cashier) {
        return { kind: 'link', href: props.row.urls.cashier, label: 'تحصيل الفاتورة', icon: 'bi-cash-stack' };
    }

    if (props.row.triage?.type === 'approval' && props.row.urls.review) {
        return { kind: 'link', href: props.row.urls.review, label: 'راجع الجولة', icon: 'bi-check2-square' };
    }

    if (props.row.triage?.type === 'food_ready') {
        return { kind: 'serve', label: 'تم التسليم', icon: 'bi-check2-circle' };
    }

    if (props.row.triage?.type === 'help') {
        return { kind: 'ack', label: 'أنا ذاهب للطاولة', icon: 'bi-person-walking' };
    }

    if (props.row.triage?.type === 'idle' && props.row.triage?.action?.kind === 'close') {
        return { kind: 'close', label: 'حرّر الطاولة', icon: 'bi-unlock' };
    }

    return {
        kind: 'link',
        href: props.row.urls.order,
        label: props.row.sessionId ? 'افتح مساحة الطاولة' : 'افتح طلباً',
        icon: props.row.sessionId ? 'bi-layout-text-window-reverse' : 'bi-plus-lg',
    };
};
</script>

<template>
    <div class="tb-tile"
         :class="[`tb-tile--${row.tileState}`, { 'is-menu-open': menuOpen }]"
         role="group"
         :aria-label="`طاولة ${row.number} · ${meta().label}${row.idleShort ? ' · ' + row.idleShort : ''}`">
        <Link v-if="row.tileState === 'available'" :href="row.urls.order" class="tb-tile-hit"
              :aria-label="`فتح طلب جديد على طاولة ${row.number}`"></Link>

        <div class="tb-tile-top">
            <span class="tb-tile-num" aria-hidden="true">{{ row.number }}</span>
            <TbActionsMenu :row="row" :transfer-tables="transferTables" :open="menuOpen"
                           @toggle="$emit('menu-toggle', $event)"
                           @quick-edit="$emit('quick-edit', $event)"
                           @transfer="$emit('transfer', $event)"
                           @destroy="$emit('destroy', $event)" />
        </div>

        <div class="tb-tile-body">
            <span class="tb-tile-state">
                <i class="bi" :class="meta().icon"></i>{{ meta().label }}
                <small v-if="row.idleShort">· {{ row.idleShort }}</small>
            </span>
            <span v-if="row.tileState === 'available'" class="tb-tile-open-cue" aria-hidden="true">
                <i class="bi bi-plus-lg"></i><span>افتح طلباً</span>
            </span>
            <span v-if="displayName()" class="tb-tile-name">{{ displayName() }}</span>
            <div class="tb-tile-meta">
                <span v-if="row.capacity"><i class="bi bi-people"></i>{{ row.capacity }}</span>
                <span v-if="row.waiterName"><i class="bi bi-person-badge"></i>{{ row.waiterName }}</span>
            </div>
            <div v-if="row.sessionId || row.counts.today > 0" class="tb-table-counts"
                 aria-label="عداد طلبات الطاولة">
                <span v-if="row.sessionId" class="is-session"
                      :title="`طلبات الجلسة الحالية: ${row.counts.session}`">
                    <i class="bi bi-layers"></i>الجلسة <b>{{ row.counts.session }}</b>
                </span>
                <span v-if="row.counts.today > 0" class="is-today"
                      :title="`طلبات الطاولة غير الملغاة اليوم: ${row.counts.today}`">
                    <i class="bi bi-receipt"></i>اليوم <b>{{ row.counts.today }}</b>
                </span>
            </div>
            <div v-if="row.counts.active > 0" class="tb-order-pulse" aria-label="حالة طلبات الطاولة">
                <span v-if="row.counts.pending > 0" class="is-pending">معلق {{ row.counts.pending }}</span>
                <span v-if="row.counts.kitchen > 0" class="is-kitchen">بالمطبخ {{ row.counts.kitchen }}</span>
                <span v-if="row.counts.ready > 0" class="is-ready">جاهز {{ row.counts.ready }}</span>
            </div>
        </div>

        <div v-if="row.tileState === 'cleaning'" class="tb-tile-actions">
            <button type="button" class="tb-tile-action is-wide" @click="$emit('clean', row)">
                <i class="bi bi-check2"></i> تم التنظيف
            </button>
        </div>
        <div v-else-if="row.tileState !== 'oos' && row.tileState !== 'available'" class="tb-tile-actions">
            <button v-if="primaryAction().kind === 'serve'" type="button"
                    class="tb-tile-action tb-tile-action--follow tb-tile-action--primary is-wide"
                    @click="$emit('serve', row)">
                <i class="bi" :class="primaryAction().icon"></i> {{ primaryAction().label }}
            </button>
            <button v-else-if="primaryAction().kind === 'ack'" type="button"
                    class="tb-tile-action tb-tile-action--follow tb-tile-action--primary is-wide"
                    @click="$emit('ack', row)">
                <i class="bi" :class="primaryAction().icon"></i> {{ primaryAction().label }}
            </button>
            <button v-else-if="primaryAction().kind === 'close'" type="button"
                    class="tb-tile-action tb-tile-action--follow tb-tile-action--primary is-wide"
                    @click="$emit('close', row)">
                <i class="bi" :class="primaryAction().icon"></i> {{ primaryAction().label }}
            </button>
            <Link v-else :href="primaryAction().href"
                  class="tb-tile-action tb-tile-action--follow tb-tile-action--primary is-wide">
                <i class="bi" :class="primaryAction().icon"></i> {{ primaryAction().label }}
            </Link>
        </div>
    </div>
</template>
