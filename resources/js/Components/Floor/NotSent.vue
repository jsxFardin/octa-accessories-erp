<script setup>
import { number } from '@/plugins/formatting';
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { rejectedRecords, removeRejected } from '@/Composables/useOfflineQueue';
import { guide, label, refusal, unitLabel } from '@/floor/dictionary';

/**
 * The records this device could not send, one by one.
 *
 * "2 records not sent — call your supervisor" was the whole of it: a count, with nothing behind
 * it. The supervisor who came could not learn which job, how much, or why, so the output was
 * re-keyed from memory or not at all. Everything needed to book each record by hand at the desk
 * is shown here, and a record stays until someone who may book it says it has been dealt with.
 *
 * A panel inside the queue and operation screens rather than a page of its own, on purpose:
 * it is needed most when the link is down, and a screen that has to be fetched is the one
 * thing the terminal cannot promise then. All of it is read from this device.
 */
const props = defineProps({
    /** Supervisors only. An operator can read the list; they cannot make a record go away. */
    canRemove: { type: Boolean, default: false },
    /** Bumped by the queue whenever it changes. */
    revision: { type: Number, default: 0 },
});

const emit = defineEmits(['close']);

const records = computed(() => {
    props.revision;

    return rejectedRecords();
});

/** The record being removed, while the confirmation is on screen. */
const removing = ref(null);

function when(iso) {
    const date = new Date(iso);

    if (Number.isNaN(date.getTime())) return '—';

    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const time = date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    return `${day}/${month}/${date.getFullYear()} ${time}`;
}

function reason(record) {
    if (record.expired) return label('reason_expired');

    return record.code ? refusal(record) : label('reason_refused');
}

function figures(record) {
    const unit = record.unit ? ` ${unitLabel(record.unit)}` : '';
    const payload = record.payload;

    if (record.action === 'downtime') {
        return [`${Number(payload.minutes) || 0} ${label('minutes')}`];
    }

    if (record.action !== 'log') return [];

    return [
        ['input_received', payload.input_qty],
        ['good', payload.good_qty],
        ['waste', payload.waste_qty],
    ]
        .filter(([, qty]) => Number(qty) > 0)
        .map(([key, qty]) => `${label(key)}: ${number(qty)}${unit}`);
}

function remove() {
    removeRejected(removing.value.key, removing.value.rejectedAt);
    removing.value = null;

    if (rejectedRecords().length === 0) emit('close');
}
</script>

<template>
    <section class="space-y-4" aria-labelledby="not-sent-title">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="not-sent-title" class="text-2xl font-bold">{{ label('not_sent_title') }}</h2>
            <button class="min-h-11 rounded-full bg-white/10 px-5 py-2 text-lg font-bold hover:bg-white/20" @click="emit('close')">
                {{ label('close') }}
            </button>
        </div>

        <p v-if="records.length === 0" class="rounded-2xl bg-white/5 px-5 py-8 text-center text-2xl text-slate-300">
            {{ guide('not_sent_empty') }}
        </p>

        <template v-else>
            <p class="rounded-xl bg-white/5 px-5 py-4 text-lg leading-relaxed text-slate-200">
                {{ guide(canRemove ? 'not_sent_explain' : 'not_sent_operator') }}
            </p>

            <ul class="space-y-3">
                <li v-for="record in records" :key="`${record.key}-${record.rejectedAt}`" class="rounded-2xl bg-white/5 p-5" data-not-sent>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-2xl font-bold">
                                {{ record.job ?? label('unknown_job', { id: record.operationId ?? '?' }) }}
                                <span class="ml-2 rounded-full bg-white/10 px-3 py-1 align-middle text-base font-semibold">
                                    {{ label(`action_${record.action}`) }}
                                </span>
                            </p>
                            <p v-if="record.step" class="text-lg text-slate-300">{{ record.step }}</p>
                        </div>
                        <p class="text-right text-lg text-slate-300">
                            {{ label('booked_at') }}<br>
                            <span class="font-semibold text-white tnum">{{ when(record.occurredAt) }}</span>
                        </p>
                    </div>

                    <p v-if="figures(record).length" class="mt-3 text-xl font-semibold tnum">
                        <span v-for="(figure, index) in figures(record)" :key="index" class="mr-5 inline-block">{{ figure }}</span>
                    </p>
                    <p v-if="record.payload.no_output_reason" class="mt-2 text-lg text-slate-300">
                        {{ label('reason') }}: {{ record.payload.no_output_reason }}
                    </p>
                    <p v-if="record.operator" class="mt-2 text-lg text-slate-300">{{ label('booked_by') }}: {{ record.operator }}</p>

                    <p class="mt-3 rounded-xl bg-amber-500 px-4 py-3 text-lg font-semibold text-slate-900">{{ reason(record) }}</p>

                    <div
                        v-if="removing?.key === record.key && removing?.rejectedAt === record.rejectedAt"
                        class="mt-3 space-y-3"
                        role="alertdialog"
                        :aria-label="label('dismiss')"
                    >
                        <p class="text-lg font-semibold">{{ guide('dismiss_confirm') }}</p>
                        <div class="grid grid-cols-2 gap-3">
                            <button class="min-h-14 rounded-xl bg-slate-600 px-4 text-lg font-bold" @click="removing = null">{{ label('finish_no') }}</button>
                            <button class="min-h-14 rounded-xl bg-rose-600 px-4 text-lg font-bold" data-remove-confirm @click="remove">{{ label('yes_remove') }}</button>
                        </div>
                    </div>

                    <div v-else class="mt-3 flex flex-wrap gap-3">
                        <button
                            v-if="record.operationId"
                            class="min-h-12 rounded-xl bg-sky-600 px-5 text-lg font-bold"
                            @click="router.visit(`/floor/operations/${record.operationId}`)"
                        >
                            {{ label('open_job') }}
                        </button>
                        <button
                            v-if="canRemove"
                            class="min-h-12 rounded-xl bg-white/10 px-5 text-lg font-bold hover:bg-white/20"
                            data-remove
                            @click="removing = record"
                        >
                            {{ label('dismiss') }}
                        </button>
                    </div>
                </li>
            </ul>
        </template>
    </section>
</template>
