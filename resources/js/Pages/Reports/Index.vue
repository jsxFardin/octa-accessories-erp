<script setup>
import { Head, Link } from '@inertiajs/vue3';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    reports: { type: Array, required: true },
});
</script>

<template>
    <AppLayout>
        <Head title="Reports" />

        <template #title>Reports</template>
        <template #subtitle>
            Read-only views of the same figures the transactional screens already show
        </template>

        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="report in reports"
                :key="report.key"
                :href="`/reports/${report.key}`"
                class="block"
            >
                <Card class="h-full transition hover:border-brand-300 hover:shadow-md">
                    <div class="flex items-start gap-3">
                        <span class="mt-0.5 rounded-md bg-brand-50 p-2 text-brand-700">
                            <Icon name="reports" size="size-4" />
                        </span>
                        <div>
                            <h2 class="text-sm font-semibold text-ink-900">{{ report.title }}</h2>
                            <p class="mt-1 text-xs text-ink-500">{{ report.subtitle }}</p>
                        </div>
                    </div>
                </Card>
            </Link>
        </div>
    </AppLayout>
</template>
