<script setup>
import { Head } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ jobCards: Array, defects: Array, plans: Array });
</script>

<template>
    <AppLayout>
        <Head title="New inspection" />

        <template #title>New inspection</template>
        <template #subtitle>The verdict is computed, never typed (BR-30)</template>

        <Card >
            <p class="text-sm text-slate-600">Enter the lot size and the defects found; the AQL plan decides accept or reject. A rejection needs a disposition (BR-33).</p>
        </Card>
    </AppLayout>
</template>
