<script setup>
import { Head } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ suppliers: Array, warehouses: Array, items: Array, uoms: Array, purchaseOrders: Array, schemes: Array });
</script>

<template>
    <AppLayout>
        <Head title="New goods receipt" />

        <template #title>New goods receipt</template>
        <template #subtitle>Certification enters the system here</template>

        <Card >
            <p class="text-sm text-slate-600">GRN capture with landed cost (BR-36) and the certification fields that become the lot claim (I5).</p>
        </Card>
    </AppLayout>
</template>
