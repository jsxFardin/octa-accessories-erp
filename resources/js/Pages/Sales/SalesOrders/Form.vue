<script setup>
import { Head } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ order: Object, customers: Array, currencies: Array, products: Array, defaults: Object });
</script>

<template>
    <AppLayout>
        <Head title="Sales order" />

        <template #title>Sales order</template>
        <template #subtitle>Capture or amend an order</template>

        <Card >
            <p class="text-sm text-slate-600">Order capture. After confirmation every quantity or date change writes an amendment row (S2).</p>
        </Card>
    </AppLayout>
</template>
