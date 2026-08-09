<script setup>
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import DataTable from '@/Components/Ui/DataTable.vue';
import FormField from '@/Components/Ui/FormField.vue';
import Modal from '@/Components/Ui/Modal.vue';
import { date, money, pcs, ratePerM, titleCase } from '@/plugins/formatting';
import { can } from '@/plugins/permissions';

const props = defineProps({
    inquiry: { type: Object, required: true },
    lines: { type: Array, default: () => [] },
    quotations: { type: Array, default: () => [] },
});

const lostOpen = ref(false);
const lostForm = useForm({ status: 'lost', lost_reason: '' });

function transition(status) {
    router.post(`/inquiries/${props.inquiry.id}/transition`, { status }, { preserveScroll: true });
}

const lineColumns = [
    { key: 'line_no', label: '#', align: 'center', width: '3rem' },
    { key: 'description', label: 'Description' },
    { key: 'product', label: 'Product' },
    { key: 'product_type', label: 'Type' },
    { key: 'qty', label: 'Quantity', align: 'right' },
    { key: 'target_rate_per_m', label: 'Target /M', align: 'right' },
];

const quotationColumns = [
    { key: 'number', label: 'Number' },
    { key: 'quotation_date', label: 'Date' },
    { key: 'total', label: 'Value', align: 'right' },
    { key: 'status', label: 'Status' },
];
</script>

<template>
    <AppLayout>
        <Head :title="inquiry.number ?? 'Inquiry'" />

        <template #title>{{ inquiry.number ?? '(unnumbered)' }}</template>
        <template #subtitle>
            <Link v-if="inquiry.customer" :href="`/customers/${inquiry.customer.id}`" class="hover:underline">
                {{ inquiry.customer.name }}
            </Link>
            · received {{ date(inquiry.inquiry_date) }}
            <span v-if="inquiry.required_by"> · required by {{ date(inquiry.required_by) }}</span>
        </template>

        <template #actions>
            <Badge :status="inquiry.status" />

            <Button
                v-if="['draft', 'open'].includes(inquiry.status) && can('inquiry.update')"
                size="sm"
                :href="`/inquiries/${inquiry.id}/edit`"
            >
                Edit
            </Button>

            <!-- A draft with no lines cannot be submitted; the number is assigned here (BR-34). -->
            <Button
                v-if="inquiry.status === 'draft' && can('inquiry.submit')"
                variant="primary"
                size="sm"
                @click="transition('open')"
            >
                Submit
            </Button>

            <Button
                v-if="['open', 'quoted'].includes(inquiry.status) && can('quotation.create')"
                variant="primary"
                size="sm"
                :href="`/quotations/create?inquiry=${inquiry.id}`"
            >
                Quote it
            </Button>

            <Button
                v-if="['open', 'quoted'].includes(inquiry.status) && can('inquiry.close')"
                size="sm"
                variant="danger"
                @click="lostOpen = true"
            >
                Mark lost
            </Button>
        </template>

        <div class="space-y-4">
            <div
                v-if="inquiry.lost_reason"
                class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-900"
            >
                <span class="font-medium">Lost:</span> {{ inquiry.lost_reason }}
            </div>

            <Card title="Lines" :padded="false">
                <DataTable :columns="lineColumns" :rows="lines" row-key="id" empty="No lines." dense>
                    <template #cell:product="{ row }">
                        <Link v-if="row.product" :href="`/products/${row.product.id}`" class="font-medium text-brand-700">
                            {{ row.product.code }}
                        </Link>
                        <span v-else class="text-ink-400">not yet a product</span>
                    </template>
                    <template #cell:product_type="{ value }">{{ value ? titleCase(value) : '—' }}</template>
                    <template #cell:qty="{ value }">{{ pcs(value) }}</template>
                    <template #cell:target_rate_per_m="{ value }">
                        {{ value ? ratePerM(value) : '—' }}
                    </template>
                </DataTable>
            </Card>

            <Card title="Quotations raised" :padded="false">
                <DataTable
                    :columns="quotationColumns"
                    :rows="quotations"
                    row-key="id"
                    :row-href="(row) => `/quotations/${row.id}`"
                    empty="Nothing quoted yet."
                    dense
                >
                    <template #cell:number="{ row }">
                        {{ row.number ?? '(unnumbered)' }}<span v-if="row.revision_no" class="text-ink-400">/R{{ row.revision_no }}</span>
                    </template>
                    <template #cell:quotation_date="{ value }">{{ date(value) }}</template>
                    <template #cell:total="{ value }">{{ money(value) }}</template>
                    <template #cell:status="{ value }"><Badge :status="value" /></template>
                </DataTable>
            </Card>

            <Card v-if="inquiry.notes" title="Notes">
                <p class="text-sm whitespace-pre-line text-ink-700">{{ inquiry.notes }}</p>
            </Card>
        </div>

        <Modal v-model:open="lostOpen" title="Mark this inquiry lost" subtitle="The reason feeds win/loss analysis.">
            <FormField label="Reason" :error="lostForm.errors.lost_reason" required>
                <textarea v-model="lostForm.lost_reason" rows="3" class="form-textarea" />
            </FormField>

            <template #footer="{ close }">
                <Button @click="close">Cancel</Button>
                <Button
                    variant="danger"
                    :disabled="!lostForm.lost_reason"
                    :loading="lostForm.processing"
                    @click="lostForm.post(`/inquiries/${inquiry.id}/transition`, { onSuccess: () => (lostOpen = false) })"
                >
                    Mark lost
                </Button>
            </template>
        </Modal>
    </AppLayout>
</template>
