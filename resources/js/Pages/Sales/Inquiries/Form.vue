<script setup>
import { Head } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ inquiry: Object, customers: Array, productTypes: Array });
</script>

<template>
    <AppLayout>
        <Head title="New inquiry" />

        <template #title>New inquiry</template>
        <template #subtitle>Log a customer inquiry</template>

        <Card >
            <p class="text-sm text-slate-600">Inquiry capture form.</p>
        </Card>
    </AppLayout>
</template>
