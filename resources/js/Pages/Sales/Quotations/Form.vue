<script setup>
import { Head } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ quotation: Object, inquiryId: Number, customers: Array, currencies: Array, products: Array, defaultMarginPct: Number, marginFloorPct: Number });
</script>

<template>
    <AppLayout>
        <Head title="Quotation" />

        <template #title>Quotation</template>
        <template #subtitle>Build a quotation with a cost sheet per line</template>

        <Card >
            <p class="text-sm text-slate-600">Quotation builder — each line gets a cost sheet computed by CostSheetCalculator (BR-14 … BR-22).</p>
        </Card>
    </AppLayout>
</template>
