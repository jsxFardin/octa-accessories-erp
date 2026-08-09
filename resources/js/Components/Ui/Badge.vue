<script setup>
import { computed } from 'vue';
import { titleCase } from '@/plugins/formatting';

const props = defineProps({
    status: { type: String, default: null },
    tone: { type: String, default: null },
    label: { type: String, default: null },
});

/**
 * Status colour is derived from the status vocabulary, not passed in per screen, so
 * `released` is the same green on the planning board and the job card list.
 */
const TONES = {
    neutral: 'bg-slate-100 text-ink-700 ring-slate-500/20',
    info: 'bg-sky-50 text-sky-800 ring-sky-600/20',
    progress: 'bg-indigo-50 text-indigo-800 ring-indigo-600/20',
    success: 'bg-emerald-50 text-emerald-800 ring-emerald-600/20',
    warning: 'bg-amber-50 text-amber-900 ring-amber-600/30',
    danger: 'bg-rose-50 text-rose-800 ring-rose-600/20',
};

const STATUS_TONES = {
    draft: 'neutral',
    open: 'info',
    submitted: 'info',
    sent: 'info',
    planned: 'info',
    pending: 'neutral',
    pending_approval: 'warning',
    ready: 'info',
    quoted: 'info',
    in_development: 'progress',
    in_progress: 'progress',
    in_production: 'progress',
    in_transit: 'progress',
    partially_received: 'progress',
    partially_delivered: 'progress',
    partially_paid: 'progress',
    released: 'success',
    approved: 'success',
    accepted: 'success',
    accepted_with_concession: 'warning',
    active: 'success',
    current: 'success',
    confirmed: 'success',
    completed: 'success',
    delivered: 'success',
    received: 'success',
    posted: 'success',
    paid: 'success',
    issued: 'success',
    packed: 'success',
    won: 'success',
    available: 'success',
    closed: 'neutral',
    superseded: 'neutral',
    consumed: 'neutral',
    material_pending: 'warning',
    credit_hold: 'warning',
    on_hold: 'warning',
    qc_pending: 'warning',
    quarantine: 'warning',
    pending_qc: 'warning',
    overdue: 'danger',
    expired: 'danger',
    rejected: 'danger',
    cancelled: 'danger',
    lost: 'danger',
    blocked: 'danger',
    breakdown: 'danger',
    scrapped: 'danger',
};

const toneClass = computed(() => {
    const tone = props.tone ?? STATUS_TONES[props.status] ?? 'neutral';

    return TONES[tone] ?? TONES.neutral;
});

const text = computed(() => props.label ?? titleCase(props.status));
</script>

<template>
    <span
        class="inline-flex items-center rounded-md px-1.5 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset"
        :class="toneClass"
    >
        <slot>{{ text }}</slot>
    </span>
</template>
