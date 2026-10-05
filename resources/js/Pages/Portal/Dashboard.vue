<script setup>
import { Head } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';

defineOptions({ layout: null });
</script>

<template>
    <Head title="Customer portal" />

    <div class="min-h-screen bg-slate-50 p-6">
        <div class="mx-auto max-w-3xl">
            <h1 class="mb-4 text-xl font-semibold text-ink-900">Customer portal</h1>

            <Card title="Coming soon">
                <p class="text-sm text-ink-700">
                    Order status, documents, artwork approval and new inquiries will appear here.
                    You will only ever see your own company's records.
                </p>
            </Card>
        </div>
    </div>
</template>
