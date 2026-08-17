<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import Badge from '@/Components/Ui/Badge.vue';
import Button from '@/Components/Ui/Button.vue';
import Card from '@/Components/Ui/Card.vue';
import { date, money, titleCase } from '@/plugins/formatting';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    creditNote: { type: Object, required: true },
    invoice: { type: Object, default: null },
    availableTransitions: { type: Array, default: () => [] },
});

function transition(to) {
    router.post(`/credit-notes/${props.creditNote.id}/transition`, { to }, { preserveScroll: true });
}
</script>

<template>
    <AppLayout>
        <Head :title="creditNote.number ?? 'Credit note'" />

        <template #title>{{ creditNote.number ?? '(draft credit note)' }}</template>
        <template #subtitle>
            {{ creditNote.customer?.name }} · {{ date(creditNote.note_date) }} · {{ titleCase(creditNote.reason) }}
        </template>

        <template #actions>
            <Badge :status="creditNote.status" />
            <Button v-if="availableTransitions.includes('approved')" size="sm" variant="primary" @click="transition('approved')">
                Approve
            </Button>
            <Button v-if="availableTransitions.includes('applied')" size="sm" variant="primary" @click="transition('applied')">
                Apply to invoice
            </Button>
            <Button v-if="availableTransitions.includes('cancelled')" size="sm" variant="danger" @click="transition('cancelled')">
                Cancel
            </Button>
        </template>

        <div class="grid gap-4 lg:grid-cols-2">
            <Card title="Credit">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <div><dt class="text-xs text-ink-500">Amount</dt><dd class="font-medium tnum">{{ money(creditNote.amount) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Reason</dt><dd class="font-medium">{{ titleCase(creditNote.reason) }}</dd></div>
                </dl>
                <p v-if="creditNote.remarks" class="mt-3 whitespace-pre-line text-sm text-ink-600">{{ creditNote.remarks }}</p>
                <p class="mt-3 rounded bg-slate-50 px-2 py-1 text-xs text-ink-500">
                    Applying is the step that moves the invoice's arithmetic — checked against its live
                    outstanding balance under lock; over-crediting is refused there.
                </p>
            </Card>

            <Card v-if="invoice" title="Against invoice">
                <dl class="grid grid-cols-2 gap-2 text-sm">
                    <div><dt class="text-xs text-ink-500">Invoice</dt>
                        <dd><Link :href="`/invoices/${invoice.id}`" class="font-medium text-brand-700">{{ invoice.number }}</Link> <Badge :status="invoice.status" /></dd></div>
                    <div><dt class="text-xs text-ink-500">Total</dt><dd class="font-medium tnum">{{ money(invoice.total) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Received</dt><dd class="font-medium tnum text-emerald-700">{{ money(invoice.received_amount) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Credited</dt><dd class="font-medium tnum text-amber-700">{{ money(invoice.credited) }}</dd></div>
                    <div><dt class="text-xs text-ink-500">Outstanding</dt><dd class="font-medium tnum" :class="invoice.outstanding > 0 ? 'text-rose-600' : ''">{{ money(invoice.outstanding) }}</dd></div>
                </dl>
            </Card>
        </div>
    </AppLayout>
</template>
