<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Card from '@/Components/Ui/Card.vue';
import Icon from '@/Components/Ui/Icon.vue';

/**
 * The Setup hub.
 *
 * These lists feed every dropdown in the application, and until now none of them had a screen
 * — a new department meant editing a seeder. Grouped by what they belong to rather than
 * alphabetically, because someone adding a warehouse is thinking about inventory, not about W.
 */
defineProps({ groups: { type: Array, default: () => [] } });
</script>

<template>
    <AppLayout>
        <Head title="Setup" />

        <template #title>Setup</template>
        <template #subtitle>The lists behind every dropdown in the system</template>

        <div class="space-y-6">
            <section v-for="group in groups" :key="group.key">
                <h2 class="mb-2 text-[11px] font-semibold tracking-wider text-ink-400 uppercase">
                    {{ group.label }}
                </h2>

                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    <Link
                        v-for="item in group.items"
                        :key="item.slug"
                        :href="`/setup/${item.slug}`"
                        class="group rounded-lg border border-slate-200 bg-white p-3.5 transition hover:border-brand-300 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                    >
                        <div class="flex items-start gap-3">
                            <span class="flex size-8 shrink-0 items-center justify-center rounded-md bg-slate-100 text-ink-500 transition group-hover:bg-brand-50 group-hover:text-brand-600">
                                <Icon :name="item.icon" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <p class="flex items-center gap-2 text-sm font-medium text-ink-900">
                                    {{ item.label }}
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[10px] tnum text-ink-500">
                                        {{ item.count }}
                                    </span>
                                </p>
                                <p class="mt-1 text-xs leading-relaxed text-ink-500">{{ item.description }}</p>
                            </div>
                        </div>
                    </Link>
                </div>
            </section>

            <Card v-if="groups.length === 0">
                <p class="text-sm text-ink-500">You do not have access to any setup list.</p>
            </Card>
        </div>
    </AppLayout>
</template>
