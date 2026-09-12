<script setup>
/**
 * F-01/F-02 — a document's history, for the person looking at the document.
 *
 * The audit rows were always being written; no detail page asked for them, so the only way to
 * find out when a quotation was sent was the global admin log, filtered by hand, by someone
 * holding `audit_log.view_any`.
 *
 * This is the operator's view: what happened, when, who did it, and what it became — in
 * sentences. The field-level before/after is folded away and only arrives at all for a viewer
 * the server decided may see it, so this component never has to make that judgement.
 *
 * It opens from a button rather than sitting at the foot of the page: the history is what
 * happened, not what the document *is*, and a dozen edit entries pushed the order itself off
 * the screen for everyone who never needed to read them.
 */
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import EmptyState from '@/Components/Ui/EmptyState.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { datetime, relative } from '@/plugins/formatting';

defineProps({
    /** Oldest first, as the server returns it. */
    entries: { type: Array, default: () => [] },
    title: { type: String, default: 'Activity' },
});

const open = ref(false);

/** Which entries have their field-level detail open. Keyed by index — the list is static. */
const expanded = ref(new Set());

function toggle(index) {
    const next = new Set(expanded.value);

    next.has(index) ? next.delete(index) : next.add(index);
    expanded.value = next;
}

/*
 * Shape, not just colour: an icon glyph carries the meaning for a reader who cannot separate
 * the greens from the ambers, and the label says it in words regardless.
 */
const MARKS = {
    created: { glyph: '+', tone: 'bg-slate-100 text-ink-600' },
    status_changed: { glyph: '→', tone: 'bg-brand-100 text-brand-800' },
    converted: { glyph: '⇥', tone: 'bg-emerald-100 text-emerald-800' },
    updated: { glyph: '±', tone: 'bg-amber-100 text-amber-800' },
    printed: { glyph: '⎙', tone: 'bg-slate-100 text-ink-600' },
    deleted: { glyph: '×', tone: 'bg-rose-100 text-rose-800' },
};

function mark(event) {
    return MARKS[event] ?? { glyph: '•', tone: 'bg-slate-100 text-ink-600' };
}
</script>

<template>
    <Button size="sm" :aria-expanded="open" @click="open = true">
        {{ title }}
        <span v-if="entries.length" class="text-ink-400 tnum">{{ entries.length }}</span>
    </Button>

    <!--
        Tall histories scroll inside the dialog rather than the page behind it: a long trail
        used to leave the reader scrolled somewhere else entirely once they closed it.
    -->
    <Modal v-model:open="open" :title="title" width="max-w-2xl">
        <ol v-if="entries.length" class="-my-1 max-h-[65vh] divide-y divide-slate-100 overflow-y-auto">
            <li v-for="(entry, index) in entries" :key="`${entry.event}-${entry.at}-${index}`" class="py-2.5">
                <div class="flex items-start gap-3">
                    <span
                        class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                        :class="mark(entry.event).tone"
                        aria-hidden="true"
                    >{{ mark(entry.event).glyph }}</span>

                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-ink-900">
                            <span class="font-medium">{{ entry.label }}</span>

                            <!-- The document this one became, reachable rather than merely named. -->
                            <Link v-if="entry.href" :href="entry.href" class="doc-link ml-1">
                                {{ entry.reference }}
                            </Link>
                        </p>

                        <p v-if="entry.description" class="mt-0.5 text-xs text-ink-600">
                            {{ entry.description }}
                        </p>

                        <p class="mt-0.5 text-xs text-ink-500">
                            <span :title="String(entry.at)">{{ datetime(entry.at) }}</span>
                            <span class="text-ink-400"> · {{ relative(entry.at) }}</span>
                            <template v-if="entry.actor"> · by {{ entry.actor }}</template>
                            <template v-else> · by the system</template>
                        </p>

                        <!--
                            Only present for a viewer the server decided may see it — the same
                            `audit_log.view_any` permission that gates the global log.
                        -->
                        <div v-if="entry.detail" class="mt-1">
                            <button
                                type="button"
                                class="rounded-sm text-xs text-brand-700 underline decoration-brand-700/40 underline-offset-2 transition hover:decoration-brand-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-brand-500"
                                :aria-expanded="expanded.has(index)"
                                @click="toggle(index)"
                            >
                                {{ expanded.has(index) ? 'Hide' : 'Show' }} recorded values
                            </button>

                            <dl v-if="expanded.has(index)" class="mt-1.5 space-y-1 rounded bg-slate-50 p-2 text-xs">
                                <div v-if="entry.detail.old" class="flex gap-2">
                                    <dt class="w-14 shrink-0 text-ink-500">Before</dt>
                                    <dd class="min-w-0 flex-1 font-mono break-all text-ink-700">{{ entry.detail.old }}</dd>
                                </div>
                                <div v-if="entry.detail.new" class="flex gap-2">
                                    <dt class="w-14 shrink-0 text-ink-500">After</dt>
                                    <dd class="min-w-0 flex-1 font-mono break-all text-ink-700">{{ entry.detail.new }}</dd>
                                </div>
                                <div v-if="entry.detail.ip_address" class="flex gap-2">
                                    <dt class="w-14 shrink-0 text-ink-500">From</dt>
                                    <dd class="min-w-0 flex-1 font-mono text-ink-700">{{ entry.detail.ip_address }}</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    <!--
                        Said out loud rather than passed off as a recorded event: this document
                        predates its own auditing and the entry was read off its own columns.
                    -->
                    <span
                        v-if="entry.derived"
                        class="shrink-0 rounded bg-slate-100 px-1.5 py-0.5 text-[10px] text-ink-500"
                        title="Read from the document's own record, not from the audit trail."
                    >from record</span>
                </div>
            </li>
        </ol>

        <EmptyState
            v-else
            icon="audit"
            title="Nothing recorded yet"
            description="Sending, converting, editing and printing this document are all recorded here as they happen."
        />

        <template #footer="{ close }">
            <Button @click="close">Close</Button>
        </template>
    </Modal>
</template>
