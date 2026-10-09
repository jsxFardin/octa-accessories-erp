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
import { date, datetime, money, number, pcs, pct, titleCase } from '@/plugins/formatting';
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
        { key: 'other', label: 'Tooling, packing, subcontract, development, logistics, finance, commission', test: (l) => l.basis !== 'percentage' },
        { key: 'percentage', label: 'Overhead and margin', test: () => true },
    ];
    const taken = new Set();

    return order
        .map((group) => ({ ...group, rows: props.lines.filter((l) => { if (taken.has(l.sequence_no) || !group.test(l)) return false; taken.add(l.sequence_no); return true; }) }))
        .filter((group) => group.rows.length);
});

const lineColumns = [
    { key: 'description', label: 'Line', wrap: true },
    { key: 'qty', label: 'Qty', align: 'right' },
    { key: 'basis_uom', label: 'Unit' },
    { key: 'rate', label: 'Rate', align: 'right' },
    { key: 'amount', label: 'Amount', align: 'right' },
    { key: 'per_unit', label: 'Per unit', align: 'right' },
    { key: 'pct_of_price', label: '% of price', align: 'right' },
];

const revisionColumns = [
    { key: 'number', label: 'Sheet' },
    { key: 'stage', label: 'Stage' },
    { key: 'basis_qty', label: 'Qty', align: 'right' },
    { key: 'material_cost', label: 'Material', align: 'right' },
    { key: 'conversion_cost', label: 'Conversion', align: 'right' },
    { key: 'other_cost', label: 'Other', align: 'right' },
    { key: 'overhead_amount', label: 'Overhead', align: 'right' },
    { key: 'unit_cost', label: 'Per unit', align: 'right' },
    { key: 'revenue_per_unit', label: 'Price', align: 'right' },
    { key: 'margin_pct', label: 'Margin', align: 'right' },
    { key: 'created_at', label: 'Costed' },
];

const varianceTone = (value) => (value === null ? '' : value > 0 ? 'text-rose-700' : value < 0 ? 'text-emerald-700' : '');
</script>

<template>
    <AppLayout crumb="Cost sheet">
        <Head :title="name" />

        <template #title>{{ sheet.number ?? 'Cost sheet' }} <span v-if="sheet.revision_no" class="text-base font-normal text-ink-500">rev {{ sheet.revision_no }}</span></template>
        <template #subtitle>
            {{ stageLabel[sheet.stage] }} · {{ product?.code }} {{ product?.name }}<template v-if="buyer"> · {{ buyer.name }}</template>
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

        <p v-if="belowFloor" class="mb-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800">
            This sheet leaves {{ pct(prof.margin_pct, 1) }} margin, below the {{ marginFloorPct }}% floor. Approving it needs someone who may accept a margin under the floor.
        </p>

        <div class="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
            <div class="min-w-0 space-y-4">
                <!-- The profitability strip first: the figures the sheet exists to produce. -->
                <Card title="Profitability" rule="CS-2" :padded="false">
                    <dl class="grid grid-cols-2 divide-y divide-slate-100 text-sm sm:grid-cols-4 sm:divide-y-0 sm:divide-x lg:grid-cols-7">
                        <div class="px-3 py-2"><dt class="text-xs text-ink-500">Value at price</dt><dd class="tnum font-medium text-ink-900">{{ prof.value === null ? '—' : money(prof.value, cur) }}</dd></div>
                        <div class="px-3 py-2"><dt class="text-xs text-ink-500">Total cost</dt><dd class="tnum font-medium text-ink-900">{{ money(prof.total_cost, cur) }}</dd></div>
                        <div class="px-3 py-2"><dt class="text-xs text-ink-500">Cost per unit</dt><dd class="tnum font-medium text-ink-900">{{ money(prof.unit_cost, cur) }}</dd></div>
                        <div class="px-3 py-2"><dt class="text-xs text-ink-500">Price per unit</dt><dd class="tnum font-medium text-ink-900">{{ prof.revenue_per_unit === null ? '—' : money(prof.revenue_per_unit, cur) }}</dd></div>
                        <div class="px-3 py-2"><dt class="text-xs text-ink-500">Margin per unit</dt><dd class="tnum font-medium" :class="belowFloor ? 'text-rose-700' : 'text-ink-900'">{{ prof.margin_per_unit === null ? '—' : money(prof.margin_per_unit, cur) }}</dd></div>
                        <div class="px-3 py-2"><dt class="text-xs text-ink-500">Margin</dt><dd class="tnum text-lg font-semibold" :class="belowFloor ? 'text-rose-700' : 'text-ink-900'">{{ prof.margin_pct === null ? '—' : pct(prof.margin_pct, 1) }}</dd></div>
                        <div class="px-3 py-2"><dt class="text-xs text-ink-500">Breakeven per unit</dt><dd class="tnum font-medium text-ink-900">{{ money(prof.breakeven_per_unit, cur) }}</dd></div>
                    </dl>
                </Card>

                <!-- The two summaries the printed sheet carries, side by side. -->
                <div class="grid gap-4 md:grid-cols-2">
                    <Card title="Materials" :padded="false">
                        <table class="min-w-full text-sm">
                            <tbody>
                                <tr v-for="row in summary.materials" :key="row.key" class="border-b border-slate-100">
                                    <td class="px-3 py-1.5">{{ row.label }}</td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ money(row.amount, cur) }}</td>
                                    <td class="px-3 py-1.5 text-right tnum text-ink-500">{{ row.pct_of_price === null ? '—' : pct(row.pct_of_price, 1) }}</td>
                                </tr>
                                <tr v-if="!summary.materials.length"><td class="px-3 py-3 text-ink-500" colspan="3">No material on this sheet.</td></tr>
                                <tr class="bg-slate-50/60 font-semibold">
                                    <td class="px-3 py-1.5">Total</td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ money(prof.material, cur) }}</td>
                                    <td class="px-3 py-1.5 text-right tnum text-ink-500">{{ prof.material_pct === null ? '—' : pct(prof.material_pct, 1) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </Card>

                    <Card title="Other costs" :padded="false">
                        <table class="min-w-full text-sm">
                            <tbody>
                                <tr v-for="row in summary.others" :key="row.key" class="border-b border-slate-100">
                                    <td class="px-3 py-1.5">{{ row.label }}</td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ money(row.amount, cur) }}</td>
                                    <td class="px-3 py-1.5 text-right tnum text-ink-500">{{ row.pct_of_price === null ? '—' : pct(row.pct_of_price, 1) }}</td>
                                </tr>
                                <tr class="bg-slate-50/60 font-semibold">
                                    <td class="px-3 py-1.5">Total</td>
                                    <td class="px-3 py-1.5 text-right tnum">{{ money(prof.other, cur) }}</td>
                                    <td class="px-3 py-1.5 text-right tnum text-ink-500">{{ prof.other_pct === null ? '—' : pct(prof.other_pct, 1) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </Card>

                </div>

                <Card title="Line detail" :subtitle="stageHint[sheet.stage]" :padded="false">
                    <div v-for="group in groups" :key="group.key">
                        <p class="border-b border-slate-100 bg-slate-50/60 px-3 py-1.5 text-xs font-semibold text-ink-700">{{ group.label }}</p>
                        <DataTable :columns="lineColumns" :rows="group.rows" row-key="sequence_no" dense>
                            <template #cell:description="{ row, value }">
                                {{ value }}
                                <span class="block text-xs text-ink-500">{{ titleCase(row.cost_type) }}<template v-if="row.formula_ref"> · {{ row.formula_ref }}</template></span>
                            </template>
                            <template #cell:qty="{ row, value }">{{ row.basis === 'percentage' ? pct(value, 2) : number(value, 4) }}</template>
                            <template #cell:basis_uom="{ row, value }">{{ row.basis === 'percentage' ? `of ${money(row.percentage_of, cur)}` : (value ?? '—') }}</template>
                            <template #cell:rate="{ row, value }"><template v-if="row.basis !== 'percentage'">{{ number(value, 4) }}<span v-if="row.rate_is_derived" class="text-ink-400" title="Recovered from amount ÷ qty">*</span></template><span v-else>—</span></template>
                            <template #cell:amount="{ value }">{{ money(value, cur) }}</template>
                            <template #cell:per_unit="{ value }">{{ number(value, 4) }}</template>
                            <template #cell:pct_of_price="{ value }">{{ value === null ? '—' : pct(value, 2) }}</template>
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
                        <template #cell:unit_cost="{ value }">{{ number(value, 4) }}</template>
                        <template #cell:revenue_per_unit="{ value }">{{ value === null ? '—' : number(value, 4) }}</template>
                        <template #cell:margin_pct="{ value }"><span :class="value !== null && value < marginFloorPct ? 'text-rose-700' : ''">{{ value === null ? '—' : pct(value, 1) }}</span></template>
                        <template #cell:created_at="{ value }">{{ date(value) }}</template>
                    </DataTable>
                </Card>

                <Card v-if="comparison" title="Against the benchmark" rule="CS-2" subtitle="Per unit: what the order was confirmed at, what the job actually cost, and the difference by element." :padded="false">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-xs text-ink-500">
                            <tr><th class="px-3 py-2 text-left">Element</th><th class="px-3 py-2 text-right">Benchmark</th><th class="px-3 py-2 text-right">Actual</th><th class="px-3 py-2 text-right">Variance / unit</th><th class="px-3 py-2 text-right">Variance, job</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in comparison.variance" :key="row.key" class="border-t border-slate-100" :class="row.key === 'total_cost' && 'font-semibold'">
                                <td class="px-3 py-1.5">{{ row.label }}</td>
                                <td class="px-3 py-1.5 text-right tnum">{{ row.pre === null ? '—' : money(row.pre, cur) }}</td>
                                <td class="px-3 py-1.5 text-right tnum">{{ row.post === null ? '—' : money(row.post, cur) }}</td>
                                <td class="px-3 py-1.5 text-right tnum" :class="varianceTone(row.per_unit)">{{ row.per_unit === null ? '—' : money(row.per_unit, cur) }}</td>
                                <td class="px-3 py-1.5 text-right tnum" :class="varianceTone(row.total)">{{ row.total === null ? '—' : money(row.total, cur) }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="!comparison.pre" class="px-3 py-3 text-xs text-ink-600">No benchmark: this job was made for stock, not for an order confirmed with costing.</p>
                </Card>
            </div>

            <aside class="order-first space-y-4 xl:order-none xl:sticky xl:top-20">
                <Card title="Sheet">
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Product</dt><dd class="text-right"><Link v-if="product" :href="`/products/${product.id}`" class="doc-link-quiet">{{ product.code }}</Link></dd></div>
                        <div v-if="product?.family" class="flex justify-between gap-3"><dt class="text-ink-500">Family</dt><dd class="text-right">{{ product.family }}</dd></div>
                        <div v-if="product?.style_ref" class="flex justify-between gap-3"><dt class="text-ink-500">Style</dt><dd class="text-right">{{ product.style_ref }}</dd></div>
                        <div v-if="product?.spec_version" class="flex justify-between gap-3"><dt class="text-ink-500">Specification</dt><dd class="text-right">v{{ product.spec_version }}</dd></div>
                        <div v-if="buyer" class="flex justify-between gap-3"><dt class="text-ink-500">Buyer</dt><dd class="text-right"><Link :href="`/customers/${buyer.id}`" class="doc-link-quiet">{{ buyer.name }}</Link></dd></div>
                        <div v-if="document" class="flex justify-between gap-3"><dt class="text-ink-500">Document</dt><dd class="text-right"><Link :href="document.href" class="doc-link-quiet">{{ document.label }}</Link><span v-if="document.line_no" class="text-ink-500"> line {{ document.line_no }}</span></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Quantity</dt><dd class="tnum">{{ pcs(sheet.basis_qty) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Costing per</dt><dd class="tnum">{{ pcs(sheet.costing_per) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Currency</dt><dd class="tnum">{{ sheet.currency ?? '—' }}<template v-if="Number(sheet.exchange_rate) !== 1"> · {{ number(sheet.exchange_rate, 4) }}</template></dd></div>
                        <div v-if="sheet.incoterm" class="flex justify-between gap-3"><dt class="text-ink-500">Terms</dt><dd>{{ sheet.incoterm }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-500">Overheads</dt><dd class="tnum">{{ pct(sheet.overhead_pct, 1) }} + {{ pct(sheet.admin_pct, 1) }}</dd></div>
                        <div v-if="sheet.gross_metres" class="flex justify-between gap-3"><dt class="text-ink-500">Gross metres</dt><dd class="tnum">{{ number(sheet.gross_metres, 2) }} m · {{ pct(sheet.total_wastage_pct, 2) }} wastage</dd></div>
                        <div class="flex justify-between gap-3 border-t border-slate-100 pt-2"><dt class="text-ink-500">Costed</dt><dd class="text-right">{{ date(sheet.created_at) }}<span v-if="sheet.created_by" class="block text-xs text-ink-500">{{ sheet.created_by }}</span></dd></div>
                        <div v-if="sheet.submitted_at" class="flex justify-between gap-3"><dt class="text-ink-500">Submitted</dt><dd class="text-right">{{ date(sheet.submitted_at) }}<span class="block text-xs text-ink-500">{{ sheet.submitted_by }}</span></dd></div>
                        <div v-if="sheet.approved_at" class="flex justify-between gap-3"><dt class="text-ink-500">Approved</dt><dd class="text-right">{{ date(sheet.approved_at) }}<span class="block text-xs text-ink-500">{{ sheet.approved_by ?? 'System' }}</span></dd></div>
                    </dl>
                </Card>

                <Card v-if="sheet.stage === 'post_production'" title="Why it differed" subtitle="In the clerk's words: price, usage, yield, efficiency, overhead, subcontract, exchange.">
                    <form v-if="can('cost_sheet.explain')" class="space-y-2" @submit.prevent="explain">
                        <FormField label="Reason" :error="explainForm.errors.variance_reason">
                            <TextInput v-model="explainForm.variance_reason" maxlength="255" placeholder="Yarn price up 3%; 1.5 kg extra at setup" />
                        </FormField>
                        <Button type="submit" size="sm" variant="primary" :loading="explainForm.processing">Save reason</Button>
                    </form>
                    <p v-else class="text-sm" :class="sheet.variance_reason ? 'text-ink-800' : 'text-ink-500'">{{ sheet.variance_reason ?? 'Not yet explained.' }}</p>
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
