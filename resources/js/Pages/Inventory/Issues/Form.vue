<script setup>
import { Head } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ jobCards: Array, warehouses: Array });
</script>

<template>
    <AppLayout>
        <Head title="Issue material" />

        <template #title>Issue material</template>
        <template #subtitle>Shade-first lot suggestion with a FIFO fallback (BR-37)</template>

        <Card >
            <p class="text-sm text-slate-600">Pick a job card, then let the system suggest lots. Breaking FIFO is allowed; breaking it without a reason is not.</p>
        </Card>
    </AppLayout>
</template>
