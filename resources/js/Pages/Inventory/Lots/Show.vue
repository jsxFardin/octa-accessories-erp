<script setup>
import { Head } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import { date, datetime, money, pcs, qty, titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({ lot: Object, ledger: Array, ledgerBalance: Number, genealogy: Object });

</script>

<template>
    <AppLayout>
        <Head :title="lot.lot_no" />

        <template #title>{{ lot.lot_no }}</template>
        <template #subtitle>{{ lot.item?.code }} — {{ lot.item?.name }} · {{ lot.warehouse?.code }}</template>

        <div class="grid gap-4 lg:grid-cols-3">
            <Card title="Lot" rule="I5">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between"><dt class="text-slate-500">Cached balance</dt><dd class="tnum">{{ qty(lot.balance_qty) }}</dd></div>
                    <div class="flex justify-between">
                        <dt class="text-slate-500">Ledger balance</dt>
                        <!-- I3: the ledger is the truth; a difference here is a posting bug -->
                        <dd class="tnum font-medium" :class="Math.abs(ledgerBalance - Number(lot.balance_qty)) > 0.000001 ? 'text-rose-600' : 'text-emerald-700'">
                            {{ qty(ledgerBalance) }}
                        </dd>
                    </div>
                    <div class="flex justify-between"><dt class="text-slate-500">Received</dt><dd class="tnum">{{ qty(lot.received_qty) }} on {{ date(lot.received_on) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Unit cost</dt><dd class="tnum">{{ money(lot.unit_cost) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Shade</dt><dd>{{ lot.shade_code ?? '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Roll length</dt><dd class="tnum">{{ lot.roll_length_m ? qty(lot.roll_length_m) + ' m' : '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Expiry</dt><dd>{{ lot.expiry_date ? date(lot.expiry_date) : '—' }}</dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Claim</dt><dd><Badge v-if="lot.cert_scheme" tone="success" :label="`${lot.cert_scheme} ${lot.cert_claim_pct}%`" /><span v-else>—</span></dd></div>
                    <div class="flex justify-between"><dt class="text-slate-500">Status</dt><dd><Badge :status="lot.status" /></dd></div>
                </dl>
            </Card>

            <Card title="Genealogy" rule="G6" subtitle="Any carton to its lots to its GRNs">
                <div class="space-y-3 text-sm">
                    <div v-if="genealogy.grn">
                        <p class="text-xs text-slate-500">Received on</p>
                        <p class="font-medium">{{ genealogy.grn.number }} · {{ date(genealogy.grn.received_date ?? genealogy.grn.received_on) }}</p>
                        <p v-if="genealogy.grn.cert_scheme" class="text-xs text-emerald-700">
                            Claim origin: {{ genealogy.grn.cert_scheme }} {{ genealogy.grn.cert_claim_pct }}%
                        </p>
                    </div>
                    <div v-if="genealogy.job_card">
                        <p class="text-xs text-slate-500">Produced by</p>
                        <p class="font-medium">{{ genealogy.job_card.number }}</p>
                    </div>
                    <div v-if="genealogy.parent">
                        <p class="text-xs text-slate-500">Parent lot</p>
                        <p class="font-mono text-xs">{{ genealogy.parent.lot_no }}</p>
                    </div>
                    <div v-if="genealogy.children?.length">
                        <p class="text-xs text-slate-500">Child lots</p>
                        <ul class="font-mono text-xs">
                            <li v-for="child in genealogy.children" :key="child.id">{{ child.lot_no }} · {{ qty(child.balance_qty) }}</li>
                        </ul>
                    </div>
                </div>
            </Card>

            <Card class="lg:col-span-1" title="Certification" rule="Gate 2 · I5">
                <p v-if="lot.cert_scheme" class="text-sm text-slate-700">
                    This lot carries a <strong>{{ lot.cert_scheme }}</strong> claim of
                    <strong>{{ lot.cert_claim_pct }}%</strong>, inherited from its GRN line. Output made from
                    it dilutes by consumption-weighted average (BR-40) and rounds down.
                </p>
                <p v-else class="text-sm text-slate-500">
                    No certification claim. Output made from this lot cannot carry one — nothing downstream
                    may invent a claim.
                </p>
            </Card>

            <Card class="lg:col-span-3" title="Stock ledger" rule="I1 · I3" subtitle="Append-only: corrections are reversing entries, never edits" :padded="false">
                <DataTable
                    :columns="[
                        { key: 'occurred_at', label: 'When' },
                        { key: 'movement_type', label: 'Movement' },
                        { key: 'qty', label: 'Qty', align: 'right' },
                        { key: 'unit_cost', label: 'Unit cost', align: 'right' },
                        { key: 'value', label: 'Value', align: 'right' },
                        { key: 'source_type', label: 'Source' },
                        { key: 'remarks', label: 'Remarks' },
                    ]"
                    :rows="ledger"
                    row-key="id"
                    empty="No movements."
                    dense
                >
                    <template #cell:occurred_at="{ value }">{{ datetime(value) }}</template>
                    <template #cell:movement_type="{ value }">{{ titleCase(value) }}</template>
                    <template #cell:qty="{ value }">
                        <span :class="Number(value) < 0 ? 'text-rose-600' : 'text-emerald-700'">{{ qty(value) }}</span>
                    </template>
                    <template #cell:unit_cost="{ value }">{{ money(value) }}</template>
                    <template #cell:value="{ value }">{{ money(value) }}</template>
                    <template #cell:source_type="{ row }">
                        <span class="text-xs text-slate-500">{{ row.source_type?.split('\\\\').pop() }} #{{ row.source_id }}</span>
                    </template>
                </DataTable>
            </Card>
        </div>
    </AppLayout>
</template>
