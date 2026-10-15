<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DateInput from '@/Components/Ui/DateInput.vue';
import FormField from '@/Components/Ui/FormField.vue';
import FormFooter from '@/Components/Ui/FormFooter.vue';
import FormLayout from '@/Components/Ui/FormLayout.vue';
import SelectInput from '@/Components/Ui/SelectInput.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import { date, money, qty as formatQty, todayIso } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    grns: { type: Array, default: () => [] },
    grn: { type: Object, default: null },
    lots: { type: Array, default: () => [] },
    preselectGrnId: { type: Number, default: null },
});

const form = useForm({
    grn_id: props.grn?.id ?? props.preselectGrnId ?? '',
    returned_on: todayIso(),
    reason: '',
    debit_note_no: '',
    lines: [],
});

/** Choosing a receipt asks the server for its lots: the balance is a ledger figure, not a guess. */
function chooseGrn(id) {
    if (!id) return;
    router.get(`/purchase-returns/grn/${id}`, {}, { preserveState: true, preserveScroll: true, replace: true });
}

watch(() => form.grn_id, (id) => { if (id && id !== props.grn?.id) chooseGrn(id); });
onMounted(() => { if (form.grn_id && form.grn_id !== props.grn?.id) chooseGrn(form.grn_id); });

const toRow = (lot) => ({ ...lot, balance: Number(lot.balance_qty), qty: '' });
const rows = ref(props.lots.map(toRow));
watch(() => props.lots, (lots) => { rows.value = lots.map(toRow); });

const chosen = computed(() => rows.value.filter((r) => Number(r.qty) > 0));
const over = computed(() => rows.value.filter((r) => Number(r.qty) > r.balance));
const value = computed(() => chosen.value.reduce((sum, r) => sum + Number(r.qty) * Number(r.rate), 0));

const blockedBy = computed(() => {
    if (!form.grn_id) return 'Pick the receipt the goods arrived on.';
    if (!chosen.value.length) return 'Enter the quantity going back on at least one lot.';
    if (over.value.length) return `Lot ${over.value.map((r) => r.lot_no).join(', ')} does not hold that much.`;
    if (!form.reason.trim()) return 'Give the reason for the return.';

    return null;
});

function submit() {
    form.transform((data) => ({ ...data, lines: chosen.value.map((r) => ({ lot_id: r.id, qty: Number(r.qty) })) })).post('/purchase-returns');
}
</script>

<template>
    <AppLayout crumb="New supplier return">
        <Head title="New supplier return" />

        <template #title>New supplier return</template>
        <template #subtitle>Goods going back to the supplier, out of the lots they were received into.</template>

        <FormLayout @submit="submit">
            <Card title="What is going back">
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <FormField label="Goods receipt" :error="form.errors.grn_id" required class="lg:col-span-2">
                        <SelectInput
                            v-model="form.grn_id"
                            :options="grns.map((g) => ({ value: g.id, label: `${g.number} · ${g.supplier_name}`, hint: date(g.received_on) }))"
                            hint-key="hint"
                            placeholder="Pick the receipt the goods arrived on…"
                        />
                    </FormField>
                    <FormField label="Returned on" :error="form.errors.returned_on" required><DateInput v-model="form.returned_on" /></FormField>
                    <FormField label="Debit note" :error="form.errors.debit_note_no" hint="Leave blank if Accounts have not raised it yet; it can be added later."><TextInput v-model="form.debit_note_no" /></FormField>
                </div>
                <p v-if="grn" class="mt-3 rounded-md bg-slate-50 px-3 py-2 text-xs text-ink-600">
                    Receipt <span class="font-medium text-ink-900">{{ grn.number }}</span> from {{ grn.supplier }} is <Badge :status="grn.status" />. Only what its lots still hold can go back.
                </p>
                <FormField label="Reason" :error="form.errors.reason" required class="mt-3">
                    <textarea v-model="form.reason" rows="2" class="form-textarea" placeholder="Two rolls off-shade against the approved swatch…" />
                </FormField>
            </Card>

            <Card v-if="rows.length" title="Lots" subtitle="What each lot from this receipt still holds">
                <table class="w-full text-sm">
                    <thead class="border-b border-slate-200 text-xs text-ink-500">
                        <tr>
                            <th class="px-2 py-2 text-left">Lot</th>
                            <th class="px-2 py-2 text-left">Item</th>
                            <th class="px-2 py-2 text-right">Received</th>
                            <th class="px-2 py-2 text-right">Already back</th>
                            <th class="px-2 py-2 text-right">In stock</th>
                            <th class="px-2 py-2 text-right">Rate</th>
                            <th class="px-2 py-2 text-right">Qty back</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in rows" :key="row.id" class="border-b border-slate-100">
                            <td class="px-2 py-2"><span class="font-mono text-xs">{{ row.lot_no }}</span> <Badge :status="row.status" /></td>
                            <td class="px-2 py-2"><span class="font-medium text-ink-900">{{ row.item_code }}</span> <span class="text-ink-500">{{ row.item_name }}</span></td>
                            <td class="px-2 py-2 text-right tnum">{{ formatQty(row.received_qty) }} {{ row.uom }}</td>
                            <td class="px-2 py-2 text-right tnum text-ink-500">{{ formatQty(row.returned_qty) }}</td>
                            <td class="px-2 py-2 text-right tnum font-medium">{{ formatQty(row.balance) }}</td>
                            <td class="px-2 py-2 text-right tnum">{{ money(row.rate) }}</td>
                            <td class="px-2 py-2 text-right">
                                <TextInput v-model="row.qty" cell type="number" min="0" step="any" numeric :max="row.balance" :disabled="row.balance <= 0" :placeholder="row.balance > 0 ? `≤ ${formatQty(row.balance)}` : 'nothing left'" />
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="over.length" class="mt-2 text-xs text-rose-700">Lot {{ over.map((r) => r.lot_no).join(', ') }} does not hold that much.</p>
            </Card>

            <template #rail>
                <Card title="Return">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-ink-500">Lots</dt><dd class="font-medium tnum">{{ chosen.length }}</dd></div>
                        <div class="flex justify-between"><dt class="text-ink-500">Value at receipt rate</dt><dd class="font-medium tnum">{{ money(value) }}</dd></div>
                    </dl>
                    <p class="mt-3 text-xs leading-relaxed text-ink-500">Valued at what the supplier charged on the receipt, which is what the debit note claims back. Stock moves when the return is posted.</p>
                </Card>
            </template>

            <template #footer>
                <FormFooter :form="form" cancel-href="/purchase-returns" label="Save draft" :summary="`${chosen.length} ${chosen.length === 1 ? 'lot' : 'lots'}`" :disabled="blockedBy !== null" :disabled-reason="blockedBy" @save="submit" />
            </template>
        </FormLayout>
    </AppLayout>
</template>
