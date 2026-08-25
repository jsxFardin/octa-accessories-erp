<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Icon from '@/Components/Ui/Icon.vue';

/**
 * Setup is a directory, not a workspace.
 *
 * Each lookup already has a full screen. Previewing rows here meant editing in two places
 * and hunting across eight tabs for a list whose name you already knew.
 *
 * Two additions to the plain directory:
 *
 *  - **Completeness.** The counts were already fetched; now they add up to something. An
 *    empty list is the reason a dropdown somewhere is empty, so the still-empty lists are
 *    named up top instead of hiding as grey zeros in the eighth section.
 *  - **A group bar that knows where you are.** The anchor links became sticky chips with a
 *    scroll-spy, so the bar is a map of the page rather than a one-shot table of contents.
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
                `${group.label} ${list.label} ${list.description}`.toLowerCase().includes(needle),
            ),
        }))
        .filter((group) => group.lists.length > 0);
});

// --- Completeness ------------------------------------------------------------------------
const allLists = computed(() => props.groups.flatMap((group) => group.lists));
const emptyLists = computed(() => allLists.value.filter((list) => list.total === 0));
const configured = computed(() => allLists.value.length - emptyLists.value.length);

// --- Scroll-spy --------------------------------------------------------------------------
const activeGroup = ref(null);
let observer = null;

onMounted(() => {
    observer = new IntersectionObserver(
        (entries) => {
            const visible = entries
                .filter((entry) => entry.isIntersecting)
                .sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top);

            if (visible.length > 0) {
                activeGroup.value = visible[0].target.id.replace('setup-', '');
            }
        },
        // Top band of the viewport: the section under the sticky chrome is the current one.
        { rootMargin: '-96px 0px -60% 0px' },
    );

    document.querySelectorAll('[id^="setup-"]').forEach((el) => observer.observe(el));
});

onUnmounted(() => observer?.disconnect());
</script>

<template>
    <AppLayout>
        <Head title="Lists" />

        <template #title>Lists</template>
        <template #subtitle>Dropdown values: departments, taxes, defect codes. Company name and logo are in Settings.</template>

        <div class="space-y-4">
            <!-- What is still unconfigured, before the directory: an empty list here is an
                 empty dropdown somewhere in the working app. -->
            <div
                v-if="emptyLists.length > 0 && !query"
                class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3"
            >
                <div class="flex items-baseline justify-between gap-3">
                    <p class="text-sm font-medium text-amber-900">
                        {{ configured }} of {{ allLists.length }} lists have entries
                    </p>
                    <span class="tnum text-xs text-amber-700">{{ emptyLists.length }} still empty</span>
                </div>
                <div class="mt-1.5 h-1.5 overflow-hidden rounded-full bg-amber-100">
                    <div
                        class="h-full rounded-full bg-amber-500 transition-all"
                        :style="{ width: `${Math.round((configured / Math.max(allLists.length, 1)) * 100)}%` }"
                    />
                </div>
                <div class="mt-2 flex flex-wrap gap-1.5">
                    <Link
                        v-for="list in emptyLists"
                        :key="list.slug"
                        :href="`/setup/${list.slug}`"
                        class="inline-flex items-center gap-1 rounded-full border border-amber-300 bg-white px-2.5 py-0.5 text-xs text-amber-900 transition hover:border-amber-400 hover:bg-amber-100"
                    >
                        {{ list.label }}
                        <Icon name="right" size="size-3" class="text-amber-500" />
                    </Link>
                </div>
            </div>

            <!-- Sticky under the app header, so the map of the page travels with you. -->
            <div class="sticky top-14 z-10 -mx-1 space-y-2 bg-slate-50/95 px-1 py-2 backdrop-blur">
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
                    class="flex flex-wrap gap-1.5"
                    aria-label="Groups"
                >
                    <a
                        v-for="group in groups"
                        :key="group.key"
                        :href="`#setup-${group.key}`"
                        class="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition"
                        :class="activeGroup === group.key
                            ? 'border-brand-300 bg-brand-50 text-brand-800'
                            : 'border-slate-200 bg-white text-ink-600 hover:border-slate-300 hover:text-ink-900'"
                        :aria-current="activeGroup === group.key ? 'true' : undefined"
                    >
                        {{ group.label }}
                        <span class="tnum text-[10px]" :class="activeGroup === group.key ? 'text-brand-500' : 'text-ink-400'">
                            {{ group.lists.length }}
                        </span>
                    </a>
                </nav>
            </div>

            <section
                v-for="group in filtered"
                :id="`setup-${group.key}`"
                :key="group.key"
                class="scroll-mt-36 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm"
            >
                <header class="flex items-baseline justify-between border-b border-slate-200 bg-slate-50/70 px-4 py-2.5">
                    <h2 class="text-sm font-semibold text-ink-800">{{ group.label }}</h2>
                    <span class="tnum text-xs text-ink-500">{{ group.lists.length }} {{ group.lists.length === 1 ? 'list' : 'lists' }}</span>
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
                            <!-- A zero is not a count, it is a to-do: name it. -->
                            <span
                                v-if="list.total === 0"
                                class="mt-1 shrink-0 rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-700 ring-1 ring-amber-200"
                            >
                                Empty — add first
                            </span>
                            <span v-else class="mt-1 shrink-0 text-sm tnum text-ink-500">{{ list.total }}</span>
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
