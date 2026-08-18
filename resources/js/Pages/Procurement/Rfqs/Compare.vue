<script setup>
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import { money, qty } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';

const props = defineProps({
    rfq: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    quotations: { type: Array, default: () => [] },
    quoteThreshold: { type: [Number, String], default: 50000 },
});

function rateFor(quotation, itemId) {
    return quotation.lines.find((line) => Number(line.item_id) === Number(itemId)) ?? null;
}

function selectWinner(quotation) {
    router.post(`/rfqs/${props.rfq.id}/select`, { quotation_id: quotation.id }, { preserveScroll: true });
}

function raisePo() {
    router.post(`/rfqs/${props.rfq.id}/purchase-order`);
}
</script>

<template>
    <AppLayout>
        <Head :title="`Compare ${rfq.number ?? 'RFQ'}`" />

        <template #title>Compare {{ rfq.number ?? 'RFQ' }}</template>
        <template #subtitle>
            Rate, lead time and MOQ side by side. Selecting a winner pre-fills the purchase order.
        </template>

        <template #actions>
            <Badge :status="rfq.status" />
            <Button size="sm" :href="`/rfqs/${rfq.id}`">Back to RFQ</Button>
            <Button
                v-if="quotations.some((q) => q.is_selected) && can('purchase_order.create') && rfq.status === 'issued'"
                size="sm"
                variant="success"
                @click="raisePo"
            >
                Raise PO
            </Button>
        </template>

        <Card :padded="false">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-200 bg-slate-50">
                            <th class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-ink-500">Item</th>
                            <th v-for="quote in quotations" :key="quote.id" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide text-ink-500">
                                {{ quote.supplier?.name }}
                                <Badge v-if="quote.is_selected" class="ml-1" tone="success" label="Winner" />
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in lines" :key="line.id" class="border-b border-slate-100">
                            <td class="px-3 py-2">
                                <p class="font-medium">{{ line.item_code }}</p>
                                <p class="text-xs text-ink-500">{{ qty(line.qty) }} {{ line.uom }}</p>
                            </td>
                            <td v-for="quote in quotations" :key="`${quote.id}-${line.item_id}`" class="px-3 py-2 text-right tnum">
                                <template v-if="rateFor(quote, line.item_id)">
                                    <p>{{ money(rateFor(quote, line.item_id).rate) }}</p>
                                    <p class="text-xs text-ink-500">
                                        {{ money(rateFor(quote, line.item_id).amount) }}
                                        <span v-if="rateFor(quote, line.item_id).moq"> · MOQ {{ qty(rateFor(quote, line.item_id).moq) }}</span>
                                    </p>
                                </template>
                                <span v-else class="text-ink-400">—</span>
                            </td>
                        </tr>
                        <tr class="bg-slate-50 font-medium">
                            <td class="px-3 py-2">Quoted total / lead time</td>
                            <td v-for="quote in quotations" :key="`tot-${quote.id}`" class="px-3 py-2 text-right tnum">
                                <p>{{ money(quote.total) }} {{ quote.currency }}</p>
                                <p class="text-xs font-normal text-ink-500">{{ quote.lead_time_days ?? '—' }} days</p>
                                <Button
                                    v-if="rfq.status === 'issued' && !quote.is_selected && can('rfq.update')"
                                    class="mt-2"
                                    size="sm"
                                    @click="selectWinner(quote)"
                                >
                                    Select winner
                                </Button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </AppLayout>
</template>
