<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import DocumentActions from '@/Components/Ui/DocumentActions.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import TextInput from '@/Components/Ui/TextInput.vue';
import StageComparison from '@/Components/Costing/StageComparison.vue';
import { date, datetime, money, moneyPerThousand, number, pcs, pct, qty, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useTransitionConfirm } from '@/composables/useTransitionConfirm';

const props = defineProps({
    sheet: { type: Object, required: true },
    product: { type: Object, default: null },
    buyer: { type: Object, default: null },
    document: { type: Object, default: null },
    summary: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    revisions: { type: Array, default: () => [] },
    history: { type: Array, default: () => [] },
    comparison: { type: Object, default: null },
    transitions: { type: Array, default: () => [] },
    marginFloorPct: { type: Number, default: 12 },
});

const cur = computed(() => props.sheet.currency ?? undefined);
const name = computed(() => props.sheet.number ?? `cost sheet #${props.sheet.id}`);
const prof = computed(() => props.summary.profitability);
const belowFloor = computed(() => prof.value.margin_pct !== null && prof.value.margin_pct < props.marginFloorPct);
const verdict = computed(() => props.summary.verdict);
const verdictTone = {
    good: 'border-emerald-200 bg-emerald-50 text-emerald-900',
    warn: 'border-amber-200 bg-amber-50 text-amber-900',
    bad: 'border-rose-200 bg-rose-50 text-rose-900',
    none: 'border-slate-200 bg-slate-50 text-ink-800',
};

const stageLabel = { marketing: 'Marketing', pre_production: 'Pre-production', post_production: 'Post-production' };
const stageHint = {
    marketing: 'The estimate behind the quotation, at standard rates.',
    pre_production: 'The order at the rates of the day it was confirmed. Locked: the benchmark.',
    post_production: 'What the job actually cost: material issued, minutes run, waste by cause.',
};
const statusTone = { draft: 'neutral', submitted: 'warning', approved: 'success', rejected: 'danger' };

/* --- the workflow: submit and approve confirm; reject and reopen ask for a reason ---------- */
const confirmTransition = useTransitionConfirm('cost_sheet');
const reasonFor = ref(null); // 'rejected' | 'draft'
const reasonForm = useForm({ to: '', reason: '' });

async function move(to) {
    if (['rejected', 'draft'].includes(to)) {
        reasonForm.reset();
        reasonForm.to = to;
        reasonFor.value = to;

        return;
    }

    if (!(await confirmTransition(to, name.value))) return;

    router.post(`/cost-sheets/${props.sheet.id}/transition`, { to }, { preserveScroll: true });
}

function sendReason() {
    reasonForm.post(`/cost-sheets/${props.sheet.id}/transition`, { preserveScroll: true, onSuccess: () => { reasonFor.value = null; } });
}

const reasonTitle = computed(() => (reasonFor.value === 'rejected' ? `Reject ${name.value}?` : `Reopen ${name.value} as a draft?`));

/* --- the variance reason on a post-production sheet ---------------------------------------- */
const explainForm = useForm({ variance_reason: props.sheet.variance_reason ?? '' });

function explain() {
    explainForm.post(`/cost-sheets/${props.sheet.id}/reason`, { preserveScroll: true });
}

/* --- line detail, grouped: material, conversion, other, then the percentage rows ------------ */
const groups = computed(() => {
    const order = [
        { key: 'material', label: 'Material', test: (l) => l.is_material },
        { key: 'conversion', label: 'Conversion', test: (l) => ['machine', 'labour', 'energy'].includes(l.cost_type) },
        { key: 'waste', label: 'Waste', test: (l) => l.is_waste },
        { key: 'other', label: 'Other direct costs', test: (l) => l.basis !== 'percentage' },
        { key: 'percentage', label: 'Overhead and margin', test: () => true },
    ];
    const taken = new Set();

    return order
        .map((group) => ({ ...group, rows: props.lines.filter((l) => { if (taken.has(l.sequence_no) || !group.test(l)) return false; taken.add(l.sequence_no); return true; }) }))
        .filter((group) => group.rows.length);
});

const lineColumns = [
    { key: 'description', label: 'Item', wrap: true },
    { key: 'qty', label: 'Qty', align: 'right' },
    { key: 'basis_uom', label: 'Unit' },
    { key: 'rate', label: 'Rate', align: 'right' },
    { key: 'amount', label: 'Amount', align: 'right' },
    { key: 'per_unit', label: 'Per 1,000', align: 'right' },
];

const revisionColumns = [
    { key: 'number', label: 'Sheet' },
    { key: 'stage', label: 'Stage' },
    { key: 'basis_qty', label: 'Qty', align: 'right' },
    { key: 'material_cost', label: 'Material', align: 'right' },
    { key: 'conversion_cost', label: 'Conversion', align: 'right' },
    { key: 'other_cost', label: 'Other', align: 'right' },
    { key: 'overhead_amount', label: 'Overhead', align: 'right' },
    { key: 'unit_cost', label: 'Cost / 1,000', align: 'right' },
    { key: 'revenue_per_unit', label: 'Price / 1,000', align: 'right' },
    { key: 'margin_pct', label: 'Margin', align: 'right' },
    { key: 'created_at', label: 'Costed' },
];

</script>

<template>
    <AppLayout crumb="Cost sheet">
        <Head :title="name" />

        <template #title>{{ sheet.number ?? 'Cost sheet' }} <span v-if="sheet.revision_no" class="text-base font-normal text-ink-500">rev {{ sheet.revision_no }}</span></template>
        <template #subtitle>
            {{ stageLabel[sheet.stage] }} · {{ product?.code }} {{ product?.name }}<template v-if="buyer"> · {{ buyer.name }}</template> · {{ pcs(sheet.basis_qty) }} pcs<template v-if="document"> · <Link :href="document.href" class="doc-link-quiet">{{ document.label }}</Link></template>
        </template>

        <template #actions>
            <Badge :tone="statusTone[sheet.status] ?? 'neutral'" :label="titleCase(sheet.status)" />
            <Badge v-if="sheet.is_locked" tone="neutral" label="Locked" />
            <Button v-if="document" size="sm" variant="ghost" :href="document.href">Open {{ document.kind === 'job_card' ? 'job card' : document.kind === 'sales_order' ? 'order' : 'quotation' }}</Button>
            <DocumentActions document="cost-sheets" :id="sheet.id" :status="sheet.status" />
            <Button v-if="transitions.includes('submitted') && can('cost_sheet.submit')" size="sm" variant="primary" @click="move('submitted')">Submit for approval</Button>
            <Button v-if="transitions.includes('approved') && can('cost_sheet.approve')" size="sm" variant="primary" :disabled="belowFloor && !can('cost_sheet.override_margin')" :disabled-reason="belowFloor && !can('cost_sheet.override_margin') ? `Below the ${marginFloorPct}% floor: needs someone who may accept that margin.` : null" @click="move('approved')">Approve</Button>
            <Button v-if="transitions.includes('rejected') && can('cost_sheet.approve')" size="sm" variant="secondary" @click="move('rejected')">Reject</Button>
            <Button v-if="transitions.includes('draft') && sheet.status !== 'draft'" size="sm" variant="ghost" @click="move('draft')">{{ sheet.status === 'approved' ? 'Reopen' : 'Withdraw' }}</Button>
        </template>

        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="min-w-0 space-y-4">
                <!-- The answer first, in words: what the sheet makes or loses, and why. -->
                <section class="rounded-lg border px-4 py-3" :class="verdictTone[verdict.tone]" data-testid="verdict">
                    <p class="text-base font-semibold">{{ verdict.headline }}</p>
                    <p v-if="verdict.detail" class="mt-0.5 text-sm">{{ verdict.detail }}</p>
                    <p v-if="belowFloor && transitions.includes('approved') && !can('cost_sheet.override_margin')" class="mt-1 text-xs opacity-80">Approving a sheet under the floor needs someone who may accept that margin.</p>
                </section>

                <Card title="Profitability" rule="CS-2" :padded="false">
                    <dl class="grid grid-cols-3 divide-x divide-slate-100 text-sm lg:grid-cols-6">
                        <div class="px-3 py-2"><dt class="text-xs text-ink-500">Margin</dt><dd class="tnum text-lg font-semibold" :class="belowFloor ? 'text-rose-700' : 'text-ink-900'">{{ prof.margin_pct === null ? '—' : pct(prof.margin_pct, 1) }}</dd></div>
                        <div class="px-3 py-2"><dt class="text-xs text-ink-500">{{ prof.margin_amount === null || prof.margin_amount >= 0 ? 'Margin on the order' : 'Loss on the order' }}</dt><dd class="tnum font-medium" :class="belowFloor ? 'text-rose-700' : 'text-ink-900'">{{ prof.margin_amount === null ? '—' : money(Math.abs(prof.margin_amount), cur) }}</dd></div>
                        <div class="px-3 py-2"><dt class="text-xs text-ink-500">Cost / 1,000</dt><dd class="tnum font-medium text-ink-900">{{ moneyPerThousand(prof.unit_cost, cur) }}</dd></div>
                        <div class="px-3 py-2 max-lg:border-t max-lg:border-slate-100"><dt class="text-xs text-ink-500">Price / 1,000</dt><dd class="tnum font-medium text-ink-900">{{ prof.revenue_per_unit === null ? '—' : moneyPerThousand(prof.revenue_per_unit, cur) }}</dd></div>
                        <div class="px-3 py-2 max-lg:border-t max-lg:border-slate-100"><dt class="text-xs text-ink-500">Total cost</dt><dd class="tnum font-medium text-ink-900">{{ money(prof.total_cost, cur) }}</dd></div>
                        <div class="px-3 py-2 max-lg:border-t max-lg:border-slate-100"><dt class="text-xs text-ink-500">Value at price</dt><dd class="tnum font-medium text-ink-900">{{ prof.value === null ? '—' : money(prof.value, cur) }}</dd></div>
                    </dl>
                </Card>

                <!-- On a post-production sheet the clerk's own words are the first thing to write, not the last. -->
                <Card v-if="sheet.stage === 'post_production'" title="Why it differed" subtitle="In the clerk's words: price, usage, yield, efficiency, overhead, subcontract, exchange.">
                    <form v-if="can('cost_sheet.explain')" class="flex flex-col gap-2 sm:flex-row sm:items-end" @submit.prevent="explain">
                        <FormField class="min-w-0 flex-1" label="Reason" :error="explainForm.errors.variance_reason" hint="For example: yarn price up 3%; 1.5 kg extra at setup.">
                            <TextInput v-model="explainForm.variance_reason" maxlength="255" />
                        </FormField>
                        <Button type="submit" size="sm" variant="primary" :loading="explainForm.processing">Save reason</Button>
                    </form>
                    <p v-else class="text-sm" :class="sheet.variance_reason ? 'text-ink-800' : 'text-ink-500'">{{ sheet.variance_reason ?? 'Not yet explained.' }}</p>
                </Card>

                <!-- Where the cost goes: six buckets that add up to the total, as a bar and as rows. -->
                <Card title="Where the cost goes" :padded="false">
                    <div class="px-3 pt-3">
                        <div class="flex h-3 w-full overflow-hidden rounded-sm bg-slate-100" role="img" :aria-label="summary.breakdown.map((r) => `${r.label} ${pct(r.pct_of_cost, 1)}`).join(', ')">
                            <div v-for="row in summary.breakdown" :key="row.key" :class="row.colour" :style="{ width: `${Math.max(0, row.pct_of_cost ?? 0)}%` }" :title="`${row.label} · ${pct(row.pct_of_cost, 1)}`"></div>
                        </div>
                    </div>
                    <table class="mt-2 min-w-full text-sm">
                        <thead class="text-xs text-ink-500">
                            <tr><th class="px-3 py-1 text-left font-medium">Cost</th><th class="px-3 py-1 text-right font-medium">Amount</th><th class="px-3 py-1 text-right font-medium">Of cost</th><th class="px-3 py-1 text-right font-medium">Of price</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in summary.breakdown" :key="row.key" class="border-t border-slate-100">
                                <td class="px-3 py-1.5"><span class="mr-2 inline-block size-2.5 rounded-sm align-middle" :class="row.colour"></span>{{ row.label }}</td>
                                <td class="px-3 py-1.5 text-right tnum">{{ money(row.amount, cur) }}</td>
                                <td class="px-3 py-1.5 text-right tnum">{{ row.pct_of_cost === null ? '—' : pct(row.pct_of_cost, 1) }}</td>
                                <td class="px-3 py-1.5 text-right tnum text-ink-500">{{ row.pct_of_price === null ? '—' : pct(row.pct_of_price, 1) }}</td>
                            </tr>
                            <tr class="border-t border-slate-200 bg-slate-50/60 font-semibold">
                                <td class="px-3 py-1.5">Total cost</td>
                                <td class="px-3 py-1.5 text-right tnum">{{ money(prof.total_cost, cur) }}</td>
                                <td class="px-3 py-1.5 text-right tnum">100%</td>
                                <td class="px-3 py-1.5 text-right tnum text-ink-500">{{ prof.value ? pct(prof.total_cost / prof.value * 100, 1) : '—' }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-if="summary.materials.length > 1" class="border-t border-slate-100">
                        <p class="px-3 pt-2 text-xs font-semibold text-ink-700">Material by type</p>
                        <table class="min-w-full text-sm">
                            <tbody>
                                <tr v-for="row in summary.materials" :key="row.key">
                                    <td class="px-3 py-1">{{ row.label }}</td>
                                    <td class="px-3 py-1 text-right tnum">{{ money(row.amount, cur) }}</td>
                                    <td class="px-3 py-1 text-right tnum text-ink-500">{{ prof.material ? pct(row.amount / prof.material * 100, 1) : '—' }}</td>
                                    <td class="px-3 py-1 text-right tnum text-ink-500">{{ row.pct_of_price === null ? '—' : pct(row.pct_of_price, 1) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </Card>

                <Card title="Line detail" :subtitle="stageHint[sheet.stage]" :padded="false">
                    <div v-for="group in groups" :key="group.key">
                        <p class="border-b border-slate-100 bg-slate-50/60 px-3 py-1.5 text-xs font-semibold text-ink-700">{{ group.label }}</p>
                        <DataTable :columns="lineColumns" :rows="group.rows" row-key="sequence_no" dense>
                            <template #cell:description="{ row, value }">
                                {{ value }}
                                <!-- The rule reference is for support conversations, not the clerk: a tooltip, and a footnote on the print. -->
                                <span class="block text-xs text-ink-500" :title="row.formula_ref ? `Rule ${row.formula_ref}` : null">{{ titleCase(row.cost_type) }}</span>
                            </template>
                            <template #cell:qty="{ row, value }">{{ row.basis === 'percentage' ? pct(value, 1) : row.basis_uom === 'pcs' ? pcs(value) : qty(value, 2) }}</template>
                            <template #cell:basis_uom="{ row, value }">{{ row.basis === 'percentage' ? `of ${money(row.percentage_of, cur)}` : (value ?? '—') }}</template>
                            <template #cell:rate="{ row, value }"><template v-if="row.basis !== 'percentage'">{{ number(value, 2) }}<span v-if="row.rate_is_derived" class="text-ink-400" title="Recovered from amount ÷ qty">*</span></template><span v-else>—</span></template>
                            <template #cell:amount="{ value }">{{ money(value, cur) }}</template>
                            <template #cell:per_unit="{ row, value }">{{ row.basis === 'percentage' ? '—' : moneyPerThousand(value, false) }}</template>
                        </DataTable>
                    </div>
                </Card>

                <Card v-if="revisions.length > 1" title="Deviation summary" subtitle="Every costing of this line, newest first: what moved between one and the next." :padded="false">
                    <DataTable :columns="revisionColumns" :rows="revisions" row-key="id" :row-href="(row) => row.is_current ? null : `/costing/sheets/${row.id}`" dense>
                        <template #cell:number="{ row, value }"><span :class="row.is_current ? 'font-semibold text-ink-900' : ''">{{ value ?? `#${row.id}` }}<template v-if="row.revision_no"> R{{ row.revision_no }}</template></span></template>
                        <template #cell:stage="{ value }">{{ stageLabel[value] ?? titleCase(value) }}</template>
                        <template #cell:basis_qty="{ value }">{{ pcs(value) }}</template>
                        <template #cell:material_cost="{ value }">{{ money(value, cur) }}</template>
                        <template #cell:conversion_cost="{ value }">{{ money(value, cur) }}</template>
                        <template #cell:other_cost="{ value }">{{ money(value, cur) }}</template>
                        <template #cell:overhead_amount="{ value }">{{ money(value, cur) }}</template>
                        <template #cell:unit_cost="{ value }">{{ moneyPerThousand(value, false) }}</template>
                        <template #cell:revenue_per_unit="{ value }">{{ value === null ? '—' : moneyPerThousand(value, false) }}</template>
                        <template #cell:margin_pct="{ value }"><span :class="value !== null && value < marginFloorPct ? 'text-rose-700' : ''">{{ value === null ? '—' : pct(value, 1) }}</span></template>
                        <template #cell:created_at="{ value }">{{ date(value) }}</template>
                    </DataTable>
                </Card>

                <Card v-if="comparison" title="Against the benchmark" rule="CS-2" subtitle="What the order was confirmed at, what the job actually cost, and the difference: by element per unit, then material by material and operation by operation." :padded="false">
                    <StageComparison :comparison="comparison" :currency="cur" />
                    <p v-if="!comparison.pre" class="px-3 py-3 text-xs text-ink-600">No benchmark: this job was made for stock, not for an order confirmed with costing.</p>
                </Card>
            </div>

            <aside class="space-y-4 xl:sticky xl:top-20">
                <Card title="Sheet">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Product</dt><dd class="text-right"><Link v-if="product" :href="`/products/${product.id}`" class="doc-link-quiet">{{ product.code }}</Link></dd></div>
                        <div v-if="product?.family" class="flex justify-between gap-3"><dt class="text-ink-500">Family</dt><dd class="text-right">{{ product.family }}</dd></div>
                        <div v-if="product?.style_ref" class="flex justify-between gap-3"><dt class="text-ink-500">Style</dt><dd class="text-right">{{ product.style_ref }}</dd></div>
                        <div v-if="product?.spec_version" class="flex justify-between gap-3"><dt class="text-ink-500">Specification</dt><dd class="text-right">v{{ product.spec_version }}</dd></div>
                        <div v-if="buyer" class="flex justify-between gap-3"><dt class="text-ink-500">Buyer</dt><dd class="text-right"><Link :href="`/customers/${buyer.id}`" class="doc-link-quiet">{{ buyer.name }}</Link></dd></div>
                        <div v-if="document" class="flex justify-between gap-3"><dt class="text-ink-500">Document</dt><dd class="text-right"><Link :href="document.href" class="doc-link-quiet">{{ document.label }}</Link><span v-if="document.line_no" class="text-ink-500"> line {{ document.line_no }}</span></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Quantity</dt><dd class="tnum">{{ pcs(sheet.basis_qty) }} pcs</dd></div>
                        <div v-if="Number(sheet.costing_per) !== 1" class="flex justify-between gap-3"><dt class="text-ink-500">Priced per</dt><dd class="tnum">{{ pcs(sheet.costing_per) }} pcs</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Currency</dt><dd class="tnum">{{ sheet.currency ?? '—' }}</dd></div>
                        <div v-if="Number(sheet.exchange_rate) !== 1" class="flex justify-between gap-3"><dt class="text-ink-500">Exchange rate</dt><dd class="tnum">{{ number(sheet.exchange_rate, 2) }}</dd></div>
                        <div v-if="sheet.incoterm" class="flex justify-between gap-3"><dt class="text-ink-500">Terms</dt><dd>{{ sheet.incoterm }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Overhead</dt><dd class="tnum">{{ pct(sheet.overhead_pct, 0) }} factory + {{ pct(sheet.admin_pct, 0) }} admin</dd></div>
                        <div v-if="sheet.gross_metres" class="flex justify-between gap-3"><dt class="text-ink-500">Gross metres</dt><dd class="tnum">{{ number(sheet.gross_metres, 2) }} m · {{ pct(sheet.total_wastage_pct, 1) }} wastage</dd></div>
                        <div class="flex justify-between gap-3 border-t border-slate-100 pt-2"><dt class="text-ink-500">Costed</dt><dd class="text-right">{{ date(sheet.created_at) }}<span v-if="sheet.created_by" class="block text-xs text-ink-500">{{ sheet.created_by }}</span></dd></div>
                        <div v-if="sheet.submitted_at" class="flex justify-between gap-3"><dt class="text-ink-500">Submitted</dt><dd class="text-right">{{ date(sheet.submitted_at) }}<span class="block text-xs text-ink-500">{{ sheet.submitted_by }}</span></dd></div>
                        <div v-if="sheet.approved_at" class="flex justify-between gap-3"><dt class="text-ink-500">Approved</dt><dd class="text-right">{{ date(sheet.approved_at) }}<span class="block text-xs text-ink-500">{{ sheet.approved_by ?? 'System' }}</span></dd></div>
                    </dl>
                </Card>

                <Card title="Approval history" rule="CS-3" :padded="false">
                    <ol v-if="history.length" class="divide-y divide-slate-100 text-sm">
                        <li v-for="event in history" :key="event.id" class="px-4 py-2">
                            <p class="font-medium text-ink-900">{{ event.to ? titleCase(event.to) : titleCase(event.event) }}<span class="font-normal text-ink-500"> · {{ event.user ?? 'System' }}</span></p>
                            <p class="text-xs text-ink-500">{{ datetime(event.at) }}</p>
                            <p v-if="event.reason" class="mt-0.5 text-xs text-ink-700">{{ event.reason }}</p>
                        </li>
                    </ol>
                    <p v-else class="px-4 py-3 text-sm text-ink-500">Nothing yet.</p>
                </Card>
            </aside>
        </div>

        <Modal :open="reasonFor !== null" :title="reasonTitle" :subtitle="reasonFor === 'rejected' ? 'The sheet goes back to the clerk with your reason.' : 'The approval is withdrawn and your reason joins the approval history.'" @update:open="reasonFor = null">
            <form class="space-y-3" @submit.prevent="sendReason">
                <FormField label="Reason" required :error="reasonForm.errors.reason">
                    <TextInput v-model="reasonForm.reason" maxlength="255" />
                </FormField>
            </form>
            <template #footer>
                <Button @click="reasonFor = null">Back</Button>
                <Button variant="danger" :loading="reasonForm.processing" :disabled="!reasonForm.reason.trim()" @click="sendReason">{{ reasonFor === 'rejected' ? 'Reject' : 'Reopen' }}</Button>
            </template>
        </Modal>
    </AppLayout>
</template>
