<script setup>
import { Head } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ orderLines: Array, units: Array });
</script>

<template>
    <AppLayout>
        <Head title="New job card" />

        <template #title>New job card</template>
        <template #subtitle>From a confirmed sales order line</template>

        <Card >
            <p class="text-sm text-slate-600">Gate 1 is structural here: job_cards.artwork_version_id is NOT NULL, so a card cannot be created without an approved version.</p>
        </Card>
    </AppLayout>
</template>
