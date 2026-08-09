<script setup>
import { Head } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({ inquiry: Object, lines: Array, quotations: Array });
</script>

<template>
    <AppLayout>
        <Head title="Inquiry" />

        <template #title>Inquiry</template>
        <template #subtitle>Inquiry detail</template>

        <Card >
            <p class="text-sm text-slate-600">Inquiry lines and the quotations raised against them.</p>
        </Card>
    </AppLayout>
</template>
