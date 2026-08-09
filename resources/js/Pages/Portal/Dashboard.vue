<script setup>
import { Head } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';

defineOptions({ layout: null });
</script>

<template>
    <Head title="Customer portal" />

    <div class="min-h-screen bg-slate-50 p-6">
        <div class="mx-auto max-w-3xl">
            <h1 class="mb-4 text-xl font-semibold text-slate-900">Customer portal</h1>

            <Card title="Coming in Phase 4">
                <p class="text-sm text-slate-600">
                    Order status, documents, artwork approval and inquiry submission — scoped to your
                    company by a global query scope resolved once at the middleware, so a missing
                    <code class="rounded bg-slate-100 px-1 font-mono text-xs">where</code> in one
                    controller cannot leak another customer's data.
                </p>
            </Card>
        </div>
    </div>
</template>
