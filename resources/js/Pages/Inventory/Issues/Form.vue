<script setup>
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import { money, qty } from '@/plugins/formatting';

const ISSUE_JOB_STATUSES = ['released', 'in_production'];
const RETURN_JOB_STATUSES = ['released', 'in_production', 'qc_pending', 'completed'];

const props = defineProps({
    jobCards: { type: Array, default: () => [] },
    warehouses: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
});

const form = useForm({
    job_card_id: '',
    warehouse_id: props.warehouses[0]?.id ?? '',
    issue_type: 'issue',
    remarks: '',
    lines: [],
});

const isReturn = computed(() => form.issue_type === 'return');

const visibleJobs = computed(() => {
    const allowed = isReturn.value ? RETURN_JOB_STATUSES : ISSUE_JOB_STATUSES;

    return props.jobCards.filter((job) => allowed.includes(job.status));
});

// --- BR-37 lot suggestion -----------------------------------------------------------------
const requestItemId = ref('');
const requestQty = ref('');
const requestShade = ref('');
const requestClaim = ref('');
const suggestion = ref(null);
const busy = ref(false);

const selectedItem = computed(() =>
    props.items.find((item) => item.id === Number(requestItemId.value)) ?? null,
);

/**
 * The system suggests; the store keeper decides. Shade-first for shade-critical items with a
 * FIFO fallback — and every pick that breaks FIFO is flagged so a reason can be recorded.
 */
async function suggest() {
    if (!requestItemId.value || !requestQty.value) return;

    busy.value = true;

    try {
        const params = new URLSearchParams({
            item_id: String(requestItemId.value),
            qty: String(requestQty.value),
        });

        if (form.warehouse_id) params.set('warehouse_id', String(form.warehouse_id));
        if (requestShade.value) params.set('preferred_shade', requestShade.value);
        if (requestClaim.value) params.set('required_claim_pct', String(requestClaim.value));

        const response = await fetch(`/material-issues/suggest?${params}`, {
            headers: { Accept: 'application/json' },
        });

        suggestion.value = response.ok ? await response.json() : null;
    } finally {
        busy.value = false;
    }
}

function acceptSuggestion() {
    if (!suggestion.value) return;

    const item = selectedItem.value;

    for (const pick of suggestion.value.picks) {
        form.lines = [...form.lines, {
            item_id: item.id,
            item_code: item.code,
            lot_id: pick.id,
            lot_no: pick.lot_no,
            shade_code: pick.shade_code,
            uom_id: item.base_uom_id,
            qty: pick.qty,
            unit_cost: pick.unit_cost,
            breaks_fifo: pick.breaks_fifo,
            fifo_override_reason: '',
        }];
    }

    suggestion.value = null;
    requestItemId.value = '';
    requestQty.value = '';
    requestShade.value = '';
}

function removeLine(index) {
    form.lines = form.lines.filter((_, i) => i !== index);
}

/** BR-37 — breaking FIFO is allowed; breaking it silently is not. */
const missingReason = computed(() =>
    !isReturn.value && form.lines.some((line) => line.breaks_fifo && !line.fifo_override_reason),
);

const exceedsReturnable = computed(() =>
    isReturn.value && form.lines.some((line) => Number(line.qty) > Number(line.returnable_qty) + 0.000001),
);

const totalValue = computed(() =>
    form.lines.reduce((sum, line) => sum + (Number(line.qty) || 0) * (Number(line.unit_cost) || 0), 0),
);

// --- IN-3 returnable lots -----------------------------------------------------------------
const returnableLots = ref([]);
const pickLotId = ref('');

const unusedReturnableLots = computed(() =>
    returnableLots.value.filter((lot) => !form.lines.some((line) => Number(line.lot_id) === Number(lot.lot_id))),
);

async function loadReturnable() {
    returnableLots.value = [];

    if (!isReturn.value || !form.job_card_id) return;

    busy.value = true;

    try {
        const params = new URLSearchParams({ job_card_id: String(form.job_card_id) });

        if (form.warehouse_id) params.set('warehouse_id', String(form.warehouse_id));

        const response = await fetch(`/material-issues/returnable?${params}`, {
            headers: { Accept: 'application/json' },
        });

        if (!response.ok) {
            returnableLots.value = [];

            return;
        }

        const payload = await response.json();
        returnableLots.value = payload.lots ?? [];
    } finally {
        busy.value = false;
    }
}

function addReturnLot(lotId) {
    const lot = returnableLots.value.find((row) => Number(row.lot_id) === Number(lotId));

    if (!lot || Number(lot.returnable_qty) <= 0) return;
    if (form.lines.some((line) => Number(line.lot_id) === Number(lot.lot_id))) return;

    form.lines = [...form.lines, {
        item_id: lot.item_id,
        item_code: lot.item_code,
        lot_id: lot.lot_id,
        lot_no: lot.lot_no,
        uom_id: lot.uom_id,
        qty: lot.returnable_qty,
        unit_cost: lot.unit_cost,
        issued_qty: lot.issued_qty,
        returned_qty: lot.returned_qty,
        returnable_qty: lot.returnable_qty,
        breaks_fifo: false,
        fifo_override_reason: '',
    }];
}

watch(pickLotId, (id) => {
    if (!id) return;
    addReturnLot(Number(id));
    pickLotId.value = '';
});

watch(
    () => [form.issue_type, form.job_card_id, form.warehouse_id],
    () => {
        form.lines = [];
        suggestion.value = null;
        pickLotId.value = '';

        if (form.job_card_id && !visibleJobs.value.some((job) => Number(job.id) === Number(form.job_card_id))) {
            form.job_card_id = '';
        }

        loadReturnable();
    },
);

function submit() {
    form.transform((data) => ({
        ...data,
        lines: data.lines.map((line) => ({
            item_id: line.item_id,
            lot_id: line.lot_id,
            uom_id: line.uom_id,
            qty: line.qty,
            fifo_override_reason: line.fifo_override_reason || null,
        })),
    })).post('/material-issues');
}
</script>

<template>
    <AppLayout>
        <Head :title="isReturn ? 'Return unused material' : 'Issue material'" />

        <template #title>{{ isReturn ? 'Return from job' : 'Issue material' }}</template>
        <template #subtitle>
            {{ isReturn
                ? 'Unused material goes back onto the same lot it was issued from'
                : 'Shade-first lot suggestion with a FIFO fallback (BR-37)' }}
        </template>

        <FormLayout @submit="submit">

            <Card :title="isReturn ? 'Return from' : 'Issue to'">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Job card" :error="form.errors.job_card_id" required>
                        <SelectInput
                            v-model="form.job_card_id"
                            placeholder="— select —"
                            :options="visibleJobs"
                            value-key="id"
                            label-key="number"
                            hint-key="status"
                        />
                    </FormField>

                    <FormField :label="isReturn ? 'Warehouse' : 'From warehouse'" :error="form.errors.warehouse_id" required>
                        <SelectInput
                            v-model="form.warehouse_id"
                            :placeholder="null"
                            :options="warehouses"
                            value-key="id"
                            label-key="name"
                        />
                    </FormField>

                    <FormField label="Type" :error="form.errors.issue_type">
                        <SelectInput
                            v-model="form.issue_type"
                            :placeholder="null"
                            :options="[
                                { value: 'issue', label: 'Issue' },
                                { value: 'return', label: 'Return' },
                            ]"
                        />
                    </FormField>

                    <FormField label="Remarks" :error="form.errors.remarks">
                        <TextInput v-model="form.remarks" />
                    </FormField>
                </div>
            </Card>

            <Card
                v-if="!isReturn"
                title="Ask for material"
                rule="BR-37"
                subtitle="Enter what the job needs; the system picks the lots"
            >
                <div class="grid items-end gap-3 sm:grid-cols-2 lg:grid-cols-5">
                    <FormField label="Item">
                        <SelectInput
                            v-model="requestItemId"
                            placeholder="— item —"
                            :options="items"
                            value-key="id"
                            label-key="code"
                        />
                    </FormField>

                    <FormField label="Quantity">
                        <TextInput v-model="requestQty" type="number" step="0.000001" numeric />
                    </FormField>

                    <FormField
                        label="Preferred shade"
                        :hint="selectedItem?.is_shade_critical ? 'Shade-critical item' : null"
                    >
                        <TextInput v-model="requestShade" :disabled="!selectedItem?.is_shade_critical" />
                    </FormField>

                    <FormField label="Required claim %" hint="For certified production only.">
                        <TextInput v-model="requestClaim" type="number" step="0.01" numeric />
                    </FormField>

                    <Button :loading="busy" :disabled="!requestItemId || !requestQty" @click="suggest">
                        Suggest lots
                    </Button>
                </div>

                <div v-if="suggestion" class="mt-4 rounded-md border border-slate-200">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 bg-slate-50 px-3 py-2">
                        <span class="text-sm font-medium text-ink-800">
                            {{ suggestion.picks.length }} lot(s) suggested
                            <Badge v-if="suggestion.is_shade_critical" tone="warning" label="Shade critical" class="ml-1" />
                        </span>
                        <Button size="sm" variant="primary" :disabled="suggestion.picks.length === 0" @click="acceptSuggestion">
                            Add to issue
                        </Button>
                    </div>

                    <table class="min-w-full text-sm">
                        <thead class="text-xs text-ink-700">
                            <tr>
                                <th class="px-3 py-1.5 text-left">Lot</th>
                                <th class="px-3 py-1.5 text-left">Shade</th>
                                <th class="px-3 py-1.5 text-right">Take</th>
                                <th class="px-3 py-1.5 text-right">On hand</th>
                                <th class="px-3 py-1.5 text-left">Claim</th>
                                <th class="px-3 py-1.5 text-left">FIFO</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="pick in suggestion.picks" :key="pick.id">
                                <td class="px-3 py-1.5 font-mono text-xs">{{ pick.lot_no }}</td>
                                <td class="px-3 py-1.5">{{ pick.shade_code ?? '—' }}</td>
                                <td class="px-3 py-1.5 text-right tnum font-medium">{{ qty(pick.qty) }}</td>
                                <td class="px-3 py-1.5 text-right tnum text-ink-500">{{ qty(pick.balance_qty) }}</td>
                                <td class="px-3 py-1.5">
                                    <Badge v-if="pick.cert_scheme" tone="success" :label="`${pick.cert_scheme} ${pick.cert_claim_pct}%`" />
                                    <span v-else class="text-ink-400">—</span>
                                </td>
                                <td class="px-3 py-1.5">
                                    <Badge v-if="pick.breaks_fifo" tone="warning" label="Breaks FIFO" />
                                    <span v-else class="text-xs text-emerald-700">in order</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- BR-38 will refuse the posting anyway; saying it here saves a trip to the store. -->
                    <p v-if="suggestion.shortfall > 0" class="border-t border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                        {{ qty(suggestion.shortfall) }} short — there is not enough on hand to cover this request.
                    </p>
                </div>
            </Card>

            <Card
                v-else
                title="Lots issued to this job"
                subtitle="Only previously issued lots with remaining returnable quantity"
            >
                <div class="grid items-end gap-3 sm:grid-cols-2">
                    <FormField label="Returnable lot">
                        <SelectInput
                            v-model="pickLotId"
                            placeholder="— lot —"
                            :options="unusedReturnableLots"
                            value-key="lot_id"
                            label-key="lot_no"
                            hint-key="item_code"
                            :disabled="!form.job_card_id || unusedReturnableLots.length === 0"
                        />
                    </FormField>
                    <p class="pb-2 text-xs text-ink-500">
                        {{ unusedReturnableLots.length === 0
                            ? (form.job_card_id ? 'No unused material remains on this job in this warehouse.' : 'Select a job to see returnable lots.')
                            : 'Issued lots with zero returnable quantity are not offered.' }}
                    </p>
                </div>
            </Card>

            <Card title="Lines to post" :padded="false">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-ink-700">
                        <tr>
                            <th class="px-3 py-2 text-left">Item</th>
                            <th class="px-3 py-2 text-left">Lot</th>
                            <th v-if="isReturn" class="px-3 py-2 text-right">Issued</th>
                            <th v-if="isReturn" class="px-3 py-2 text-right">Already returned</th>
                            <th class="px-3 py-2 text-right">{{ isReturn ? 'Returnable qty' : 'Quantity' }}</th>
                            <th v-if="isReturn" class="px-3 py-2 text-right">Return qty</th>
                            <th class="px-3 py-2 text-right">Value</th>
                            <th v-if="!isReturn" class="px-3 py-2 text-left">FIFO override reason</th>
                            <th class="w-10 px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="form.lines.length === 0">
                            <td :colspan="isReturn ? 8 : 6" class="px-3 py-8 text-center text-sm text-ink-500">
                                {{ isReturn ? 'Nothing added yet — pick a returnable lot above.' : 'Nothing added yet — ask for material above.' }}
                            </td>
                        </tr>

                        <tr v-for="(line, index) in form.lines" :key="`${line.lot_id}-${index}`">
                            <td class="px-3 py-2 font-medium text-ink-800">{{ line.item_code }}</td>
                            <td class="px-3 py-2">
                                <span class="font-mono text-xs">{{ line.lot_no }}</span>
                                <span v-if="line.shade_code" class="ml-1 text-xs text-ink-500">{{ line.shade_code }}</span>
                            </td>
                            <td v-if="isReturn" class="px-3 py-2 text-right tnum text-ink-500">{{ qty(line.issued_qty) }}</td>
                            <td v-if="isReturn" class="px-3 py-2 text-right tnum text-ink-500">{{ qty(line.returned_qty) }}</td>
                            <td v-if="!isReturn" class="px-3 py-2 text-right tnum">{{ qty(line.qty) }}</td>
                            <td v-else class="px-3 py-2 text-right tnum text-ink-500">{{ qty(line.returnable_qty) }}</td>
                            <td v-if="isReturn" class="px-3 py-2 text-right">
                                <TextInput
                                    v-model="line.qty"
                                    type="number"
                                    step="0.000001"
                                    numeric
                                    :error="Number(line.qty) > Number(line.returnable_qty) + 0.000001 ? 'exceeds returnable' : null"
                                />
                            </td>
                            <td class="px-3 py-2 text-right tnum">{{ money(line.qty * line.unit_cost) }}</td>
                            <td v-if="!isReturn" class="px-3 py-2">
                                <TextInput
                                    v-if="line.breaks_fifo"
                                    v-model="line.fifo_override_reason"
                                    placeholder="Why this lot before an older one?"
                                    :error="!line.fifo_override_reason ? 'required' : null"
                                />
                                <span v-else class="text-xs text-ink-400">not needed</span>
                            </td>
                            <td class="px-3 py-2 text-right">
                                <button
                                    class="rounded p-1 text-ink-400 transition hover:bg-rose-50 hover:text-rose-600"
                                    aria-label="Remove line"
                                    @click="removeLine(index)"
                                >
                                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                                        <path d="M6 6l8 8M14 6l-8 8" stroke-linecap="round" />
                                    </svg>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot v-if="form.lines.length" class="border-t-2 border-slate-200 bg-slate-50">
                        <tr>
                            <td :colspan="isReturn ? 5 : 3" class="px-3 py-2 text-right text-xs text-ink-700">
                                {{ isReturn ? 'Return value' : 'Issue value' }}
                            </td>
                            <td class="px-3 py-2 text-right text-sm font-semibold tnum text-ink-900">{{ money(totalValue) }}</td>
                            <td :colspan="isReturn ? 2 : 2" />
                        </tr>
                    </tfoot>
                </table>

                <p v-if="missingReason" class="border-t border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    A line breaks FIFO without a reason. Record why — the override is logged against the issue.
                </p>
                <p v-if="exceedsReturnable" class="border-t border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800">
                    A line exceeds the remaining returnable quantity. Reduce it before posting.
                </p>
            </Card>

            <template #rail>
                <Card :title="isReturn ? 'Return from job' : 'Issue'" :rule="isReturn ? null : 'BR-37'">
                    <dl class="space-y-2.5 text-sm">
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs text-ink-500">Lines to post</dt>
                            <dd class="tnum text-ink-900">{{ form.lines.length }}</dd>
                        </div>

                        <div class="flex items-baseline justify-between gap-3 border-t border-slate-100 pt-2.5">
                            <dt class="text-xs text-ink-500">{{ isReturn ? 'Value returned' : 'Value issued' }}</dt>
                            <dd class="text-base font-semibold tnum text-ink-900">{{ money(totalValue) }}</dd>
                        </div>
                    </dl>

                    <p
                        v-if="missingReason"
                        class="mt-3 rounded bg-amber-50 px-2 py-1.5 text-[11px] leading-relaxed text-amber-900"
                    >
                        A line takes a lot out of FIFO order without a reason. Give one on the line
                        before this can be posted.
                    </p>
                    <p v-else-if="isReturn" class="mt-3 text-[11px] leading-relaxed text-ink-500">
                        Unused material returns to the same original lot. The remaining returnable
                        quantity is issued minus already returned, for this job only.
                    </p>
                    <p v-else class="mt-3 text-[11px] leading-relaxed text-ink-500">
                        Lots are picked oldest-first. A line that departs from that carries the
                        reason with it, onto the ledger.
                    </p>
                </Card>
            </template>

            <template #footer>
                <FormFooter
                    :form="form"
                    :disabled="form.lines.length === 0 || missingReason || exceedsReturnable"
                    cancel-href="/material-issues"
                    :label="isReturn ? 'Post return' : 'Post issue'"
                    @save="submit"
                />
            </template>
        </FormLayout>
    </AppLayout>
</template>
