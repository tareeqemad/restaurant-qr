<script setup>
/**
 * The ONE action a triage card offers — shared by the hot strip and the
 * queue cards so the two renders can never drift apart (v4's _feed-action
 * partial, reborn). Action kinds come from the server's triage.action:
 * serve / ack / clean / close are emitted up to the board; navigation stays
 * inside the Inertia application.
 */
import { Link } from '@inertiajs/vue3';

defineProps({
    row: { type: Object, required: true },
    busy: { type: Boolean, default: false },
});

defineEmits(['serve', 'ack', 'clean', 'close']);
</script>

<template>
    <template v-if="row.triage?.action">
        <button v-if="row.triage.action.kind === 'serve'" type="button" class="tb-feed-btn"
                :disabled="busy" @click="$emit('serve', row)">
            <i class="bi bi-check2-circle"></i> سلّم
        </button>

        <button v-else-if="row.triage.action.kind === 'ack'" type="button" class="tb-feed-btn"
                :disabled="busy" @click="$emit('ack', row)">
            <i class="bi bi-check2-circle"></i> رحت للطاولة
        </button>

        <button v-else-if="row.triage.action.kind === 'clean'" type="button" class="tb-feed-btn"
                :disabled="busy" @click="$emit('clean', row)">
            <i class="bi bi-check2-circle"></i> تم التنظيف
        </button>

        <button v-else-if="row.triage.action.kind === 'close'" type="button" class="tb-feed-btn"
                :disabled="busy" @click="$emit('close', row)">
            <i class="bi bi-x-circle"></i> أغلق الراكدة
        </button>

        <Link v-else-if="row.triage.action.kind === 'link'" :href="row.triage.action.url" class="tb-feed-btn">
            <i class="bi" :class="row.triage.action.icon"></i> {{ row.triage.action.label }}
        </Link>
    </template>
</template>
