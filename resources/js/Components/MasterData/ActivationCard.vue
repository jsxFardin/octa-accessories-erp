<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { can } from '@/plugins/permissions';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';

/**
 * IM-1 — what an item still needs before it is active, and the buttons that move it.
 *
 * Shown on the item page and on the product page, because a product is an item. The steps
 * arrive judged by the server; this only decides what to say and which button is primary.
 *
 * It reads as a status, not a to-do list: a done step is one line, steps the factory has not
 * asked for yet fold into one sentence at the foot, and only what is still to do gets a
 * second line. A page that has its own steps to show first — the product page's engineering
 * setup — puts them in the `before` slot and names the keys it already covers in `omit`.
 */
const props = defineProps({
    /** `[{ key, label, state: done|todo|attention|skipped, detail, required }]` */
    steps: { type: Array, default: () => [] },
    /** Statuses the reader may move to from here. */
    transitions: { type: Array, default: () => [] },
    status: { type: String, required: true },
    code: { type: String, required: true },
    /** Where `{ to }` is posted. */
    action: { type: String, required: true },
    /** Step keys the page already shows elsewhere. */
    omit: { type: Array, default: () => [] },
    title: { type: String, default: null },
    /** Anything else still to do outside these steps, counted into the subtitle. */
    extraOutstanding: { type: Number, default: 0 },
    nextLabel: { type: String, default: null },
});

const shown = computed(() => props.steps.filter((step) => !props.omit.includes(step.key)));
const listed = computed(() => shown.value.filter((step) => step.state !== 'skipped' && step.key !== 'approver'));
const skipped = computed(() => shown.value.filter((step) => step.state === 'skipped'));
const approver = computed(() => props.steps.find((step) => step.key === 'approver') ?? null);

/* Blockers count every required step, including the ones a page shows in its own list. */
const blockers = computed(() => props.steps.filter((step) => step.required && step.state !== 'done' && step.state !== 'skipped'));
const ready = computed(() => blockers.value.length === 0);
const outstanding = computed(() => blockers.value.length + props.extraOutstanding);

const canActivate = computed(() => props.transitions.includes('active') && can('item.activate'));
const canHold = computed(() => props.transitions.includes('on_hold') && can('item.update'));
const canDiscontinue = computed(() => props.transitions.includes('discontinued') && can('item.update'));

const heading = computed(() => props.title ?? (props.status === 'draft' ? 'Readiness' : 'Lifecycle'));

const subtitle = computed(() => {
    if (props.status !== 'draft') return null;
    if (ready.value && outstanding.value === 0) return 'Everything is in place.';

    const next = props.nextLabel ?? blockers.value[0]?.label ?? null;

    return `${outstanding.value} ${outstanding.value === 1 ? 'step' : 'steps'} to go${next ? ` · next: ${next.toLowerCase()}` : ''}.`;
});

const confirmTransition = useTransitionConfirm('item');

async function move(to) {
    if (!(await confirmTransition(to, props.code))) return;

    router.post(props.action, { to }, { preserveScroll: true });
}

/* Discontinuing needs a reason the documents that still name the item will show. */
const discontinueOpen = ref(false);
const discontinueForm = useForm({ to: 'discontinued', reason: '' });

function discontinue() {
    discontinueForm.post(props.action, { preserveScroll: true, onSuccess: () => (discontinueOpen.value = false) });
}

/** "v1 is current." under its own heading; "v1 is current" in a line of them. */
function brief(detail) {
    return String(detail ?? '').replace(/\.$/, '');
}

const ICONS = { done: 'check', attention: 'warning' };
</script>

<template>
    <Card :title="heading" :subtitle="subtitle" rule="IM-1" :padded="false">
        <template #actions>
            <slot name="actions" />
            <Button v-if="canActivate" size="sm" variant="primary" :disabled="!ready" :disabled-reason="ready ? null : `Still needed: ${blockers[0]?.label.toLowerCase()}`" data-activate-item @click="move('active')">
                Activate
            </Button>
            <Button v-if="canHold" size="sm" variant="secondary" @click="move('on_hold')">Put on hold</Button>
            <Button v-if="canDiscontinue" size="sm" variant="ghost" tone="danger" @click="discontinueOpen = true">Discontinue</Button>
        </template>

        <slot name="before" />

        <ol class="divide-y divide-slate-100">
            <li v-for="step in listed" :key="step.key" class="flex items-start gap-2.5 px-4 py-2 text-sm">
                <span
                    class="mt-0.5 grid size-5 shrink-0 place-items-center rounded-full text-xs"
                    :class="{
                        'bg-emerald-600 text-white': step.state === 'done',
                        'bg-amber-100 text-amber-900 ring-1 ring-inset ring-amber-600/40': step.state === 'attention',
                        'text-ink-500 ring-1 ring-inset ring-slate-300': step.state === 'todo',
                    }"
                    aria-hidden="true"
                >
                    <Icon v-if="ICONS[step.state]" :name="ICONS[step.state]" class="size-3" />
                </span>
                <div class="min-w-0 flex-1" :class="step.state === 'done' ? 'flex flex-wrap items-baseline gap-x-2' : ''">
                    <span class="font-medium text-ink-900">
                        {{ step.label }}
                        <span v-if="!step.required" class="ml-1 text-xs font-normal text-ink-400">optional</span>
                        <span class="sr-only">— {{ { done: 'done', attention: 'needs attention', todo: 'not done yet' }[step.state] }}</span>
                    </span>
                    <span class="text-xs" :class="step.state === 'attention' ? 'text-amber-900' : 'text-ink-600'">
                        {{ step.state === 'done' ? brief(step.detail) : step.detail }}
                    </span>
                </div>
            </li>
        </ol>

        <!-- What the factory has not asked for yet, in one line rather than three greyed rows. -->
        <p v-if="skipped.length" class="border-t border-slate-100 px-4 py-2 text-xs leading-5 text-ink-500">
            Not asked for yet: {{ skipped.map((step) => step.label).join(', ') }}.
            {{ skipped.some((step) => step.key === 'stock_levels') ? 'Made to order, so no stock levels. ' : '' }}{{ skipped.some((step) => ['qc_plan', 'accounts'].includes(step.key)) ? 'Switch the rest on under Settings → Inventory.' : '' }}
        </p>

        <p v-if="approver && status === 'draft'" class="border-t border-slate-100 px-4 py-2 text-xs text-ink-500">
            {{ approver.detail }}
        </p>

        <Modal v-model:open="discontinueOpen" title="Discontinue this item?" :dirty="discontinueForm.isDirty">
            <form class="space-y-3" @submit.prevent="discontinue">
                <p class="text-sm text-ink-700">{{ code }} is retired for good and cannot be brought back. Documents that already name it keep working.</p>
                <FormField label="Reason" required :error="discontinueForm.errors.reason">
                    <textarea v-model="discontinueForm.reason" rows="3" class="form-textarea" required />
                </FormField>
                <div class="flex justify-end gap-2">
                    <Button variant="ghost" type="button" @click="discontinueOpen = false">Keep it</Button>
                    <Button variant="danger" type="submit" :loading="discontinueForm.processing">Discontinue</Button>
                </div>
            </form>
        </Modal>
    </Card>
</template>
