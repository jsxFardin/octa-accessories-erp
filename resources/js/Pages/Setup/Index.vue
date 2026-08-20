<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Ui/Icon.vue';

/**
 * Setup is a directory, not a workspace.
 *
 * Each lookup already has a full screen. Previewing rows here meant editing in two places
 * and hunting across eight tabs for a list whose name you already knew.
 */
const props = defineProps({
    groups: { type: Array, default: () => [] },
});

const query = ref('');

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (!needle) {
        return props.groups;
    }

    return props.groups
        .map((group) => ({
            ...group,
            lists: group.lists.filter((list) =>
                `${list.label} ${list.description}`.toLowerCase().includes(needle),
            ),
        }))
        .filter((group) => group.lists.length > 0);
});
</script>

<template>
    <AppLayout>
        <Head title="Lists" />

        <template #title>Lists</template>
        <template #subtitle>Dropdown values: departments, taxes, defect codes. Company name and logo are in Settings.</template>

        <div class="space-y-4">
            <div class="relative max-w-md">
                <Icon name="search" size="size-4" class="pointer-events-none absolute top-2.5 left-3 text-ink-500" />
                <input
                    v-model="query"
                    type="search"
                    class="form-input pl-9"
                    placeholder="Find a list — departments, taxes, defects…"
                >
            </div>

            <nav
                v-if="!query && groups.length > 1"
                class="flex flex-wrap gap-x-3 gap-y-1 text-sm"
                aria-label="Groups"
            >
                <a
                    v-for="group in groups"
                    :key="group.key"
                    :href="`#setup-${group.key}`"
                    class="text-ink-600 transition hover:text-ink-900 hover:underline"
                >
                    {{ group.label }}
                </a>
            </nav>

            <section
                v-for="group in filtered"
                :id="`setup-${group.key}`"
                :key="group.key"
                class="scroll-mt-20 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm"
            >
                <header class="border-b border-slate-200 bg-slate-50/70 px-4 py-2.5">
                    <h2 class="text-sm font-semibold text-ink-800">{{ group.label }}</h2>
                </header>

                <ul class="divide-y divide-slate-100">
                    <li v-for="list in group.lists" :key="list.slug">
                        <Link
                            :href="`/setup/${list.slug}`"
                            class="group flex items-start gap-3 px-4 py-3 transition hover:bg-slate-50 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                        >
                            <span class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-md bg-slate-100 text-ink-600 transition group-hover:bg-brand-50 group-hover:text-brand-700">
                                <Icon :name="list.icon" size="size-4" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-ink-900">{{ list.label }}</span>
                                <span class="mt-0.5 line-clamp-2 block text-xs leading-relaxed text-ink-500">{{ list.description }}</span>
                            </span>
                            <span class="mt-1 shrink-0 text-sm tnum text-ink-500">{{ list.total }}</span>
                            <Icon name="right" size="size-4" class="mt-1 shrink-0 text-ink-400 transition group-hover:text-ink-600" />
                        </Link>
                    </li>
                </ul>
            </section>

            <p
                v-if="groups.length === 0"
                class="rounded-lg border border-slate-200 bg-white px-4 py-10 text-center text-sm text-ink-500"
            >
                You do not have access to any list.
            </p>
            <p
                v-else-if="filtered.length === 0"
                class="rounded-lg border border-slate-200 bg-white px-4 py-10 text-center text-sm text-ink-500"
            >
                No list matches “{{ query }}”.
            </p>
        </div>
    </AppLayout>
</template>
