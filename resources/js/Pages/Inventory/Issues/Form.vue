<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import FormField from '@/Components/Ui/FormField.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import UnsavedBar from '@/Components/Ui/UnsavedBar.vue';
import { date, money, qty } from '@/plugins/formatting';

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
    form.lines.some((line) => line.breaks_fifo && !line.fifo_override_reason),
);

const totalValue = computed(() =>
    form.lines.reduce((sum, line) => sum + (Number(line.qty) || 0) * (Number(line.unit_cost) || 0), 0),
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
        <Head title="Issue material" />

        <template #title>Issue material</template>
        <template #subtitle>Shade-first lot suggestion with a FIFO fallback (BR-37)</template>

        <template #actions>
            <Button href="/material-issues">Cancel</Button>
            <Button
                variant="primary"
                :loading="form.processing"
                :disabled="form.lines.length === 0 || missingReason"
                @click="submit"
            >
                Post issue
            </Button>
        </template>

        <div class="space-y-4">
            <Card title="Issue to">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Job card" :error="form.errors.job_card_id" required>
                        <SelectInput
                            v-model="form.job_card_id"
                            placeholder="— select —"
                            :options="jobCards"
                            value-key="id"
                            label-key="number"
                        />
                    </FormField>

                    <FormField label="From warehouse" :error="form.errors.warehouse_id" required>
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
                                { value: 'issue', label: 'Issue to job' },
                                { value: 'return', label: 'Return from job' },
                                { value: 'replacement', label: 'Replacement' },
                            ]"
                        />
                    </FormField>

                    <FormField label="Remarks" :error="form.errors.remarks">
                        <TextInput v-model="form.remarks" />
                    </FormField>
                </div>
            </Card>

            <Card title="Ask for material" rule="BR-37" subtitle="Enter what the job needs; the system picks the lots">
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

            <Card title="Lines to post" :padded="false">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-xs text-ink-700">
                        <tr>
                            <th class="px-3 py-2 text-left">Item</th>
                            <th class="px-3 py-2 text-left">Lot</th>
                            <th class="px-3 py-2 text-right">Quantity</th>
                            <th class="px-3 py-2 text-right">Value</th>
                            <th class="px-3 py-2 text-left">FIFO override reason</th>
                            <th class="w-10 px-3 py-2" />
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="form.lines.length === 0">
                            <td colspan="6" class="px-3 py-8 text-center text-sm text-ink-500">
                                Nothing added yet — ask for material above.
                            </td>
                        </tr>

                        <tr v-for="(line, index) in form.lines" :key="`${line.lot_id}-${index}`">
                            <td class="px-3 py-2 font-medium text-ink-800">{{ line.item_code }}</td>
                            <td class="px-3 py-2">
                                <span class="font-mono text-xs">{{ line.lot_no }}</span>
                                <span v-if="line.shade_code" class="ml-1 text-xs text-ink-500">{{ line.shade_code }}</span>
                            </td>
                            <td class="px-3 py-2 text-right tnum">{{ qty(line.qty) }}</td>
                            <td class="px-3 py-2 text-right tnum">{{ money(line.qty * line.unit_cost) }}</td>
                            <td class="px-3 py-2">
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
                            <td colspan="3" class="px-3 py-2 text-right text-xs text-ink-700">Issue value</td>
                            <td class="px-3 py-2 text-right text-sm font-semibold tnum text-ink-900">{{ money(totalValue) }}</td>
                            <td colspan="2" />
                        </tr>
                    </tfoot>
                </table>

                <p v-if="missingReason" class="border-t border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-900">
                    A line breaks FIFO without a reason. Record why — the override is logged against the issue.
                </p>
            </Card>
        </div>
        <UnsavedBar :form="form" @save="submit" />

    </AppLayout>
</template>
