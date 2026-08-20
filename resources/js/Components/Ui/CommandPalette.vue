<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import Icon from '@/Components/Ui/Icon.vue';
import { canAny } from '@/plugins/permissions';
import { adminNavigation, navigation } from '@/navigation';

/**
 * ⌘K / Ctrl-K: go anywhere, find anything.
 *
 * Two halves. Screens are matched locally against the navigation tree — instant, no request.
 * Records go to `/search`, which looks up document numbers and codes across the sources this
 * user may see. Typing "SO-2026" should not require knowing that sales orders live under
 * Sales.
 */
const open = ref(false);
const query = ref('');
const activeIndex = ref(0);
const results = ref([]);
const loading = ref(false);
const input = ref(null);

const screens = computed(() => {
    const flatten = (tree) => tree.flatMap((section) =>
        section.items
            .filter((item) => canAny(...item.permissions))
            .flatMap((item) => {
                const row = {
                    ...item,
                    section: section.label,
                    search: [item.label, section.label, ...(item.aliases ?? [])].join(' '),
                };

                if (!item.children?.length) {
                    return [row];
                }

                return item.children
                    .filter((child) => canAny(...child.permissions))
                    .map((child) => ({
                        ...child,
                        icon: child.icon ?? item.icon,
                        section: section.label,
                        search: [child.label, section.label, ...(child.aliases ?? [])].join(' '),
                    }));
            }),
    );

    return [...flatten(navigation), ...flatten(adminNavigation)];
});

const matchedScreens = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (!needle) return screens.value.slice(0, 7);

    return screens.value
        .filter((item) => (item.search ?? `${item.label} ${item.section}`).toLowerCase().includes(needle))
        .slice(0, 6);
});

/** One flat list, because the keyboard moves through it as one. */
const flatItems = computed(() => [
    ...matchedScreens.value.map((item) => ({
        kind: 'screen',
        icon: item.icon,
        title: item.label,
        subtitle: item.section,
        href: item.href,
    })),
    ...results.value.flatMap((group) =>
        group.items.map((item) => ({
            kind: 'record',
            group: group.label,
            icon: 'search',
            title: item.title,
            subtitle: item.subtitle,
            href: item.href,
        })),
    ),
]);

const fetchRecords = useDebounceFn(async (term) => {
    if (term.trim().length < 2) {
        results.value = [];

        return;
    }

    loading.value = true;

    try {
        const response = await fetch(`/search?q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json' },
        });

        results.value = response.ok ? (await response.json()).groups : [];
    } catch {
        // A failed lookup leaves the screen matches in place rather than emptying the palette.
        results.value = [];
    } finally {
        loading.value = false;
    }
}, 200);

watch(query, (value) => {
    activeIndex.value = 0;
    fetchRecords(value);
});

async function show() {
    open.value = true;
    query.value = '';
    results.value = [];
    activeIndex.value = 0;

    await nextTick();
    input.value?.focus();
}

function close() {
    open.value = false;
}

function go(item) {
    close();
    router.visit(item.href);
}

function onKeydown(event) {
    const isPaletteKey = (event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k';

    if (isPaletteKey) {
        event.preventDefault();
        open.value ? close() : show();

        return;
    }

    // "/" is the other muscle memory, but not while someone is typing into a field.
    const typing = ['INPUT', 'TEXTAREA', 'SELECT'].includes(event.target?.tagName)
        || event.target?.isContentEditable;

    if (event.key === '/' && !open.value && !typing) {
        event.preventDefault();
        show();

        return;
    }

    if (!open.value) return;

    if (event.key === 'Escape') {
        close();
    } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        activeIndex.value = (activeIndex.value + 1) % Math.max(1, flatItems.value.length);
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        activeIndex.value = (activeIndex.value - 1 + flatItems.value.length) % Math.max(1, flatItems.value.length);
    } else if (event.key === 'Enter') {
        event.preventDefault();
        const item = flatItems.value[activeIndex.value];

        if (item) go(item);
    }
}

onMounted(() => document.addEventListener('keydown', onKeydown));
onUnmounted(() => document.removeEventListener('keydown', onKeydown));

defineExpose({ show });
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-100"
            enter-from-class="opacity-0"
            leave-active-class="transition duration-75"
            leave-to-class="opacity-0"
        >
            <div v-if="open" class="fixed inset-0 z-[70]">
                <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-[2px]" @click="close" />

                <div class="relative mx-auto mt-[12vh] w-full max-w-xl px-4">
                    <div class="overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-slate-900/10">
                        <div class="flex items-center gap-2 border-b border-slate-100 px-3.5">
                            <Icon name="search" class="text-ink-400" />
                            <input
                                ref="input"
                                v-model="query"
                                type="text"
                                class="w-full bg-transparent py-3.5 text-sm text-ink-900 placeholder:text-ink-400 focus:outline-none"
                                placeholder="Search orders, items, customers — or jump to a screen"
                            >
                            <span v-if="loading" class="text-[11px] text-ink-400">searching…</span>
                            <kbd class="rounded border border-slate-200 px-1.5 py-0.5 text-[10px] text-ink-400">esc</kbd>
                        </div>

                        <div class="max-h-[22rem] overflow-y-auto py-1.5">
                            <template v-for="(item, index) in flatItems" :key="`${item.kind}-${item.href}`">
                                <p
                                    v-if="index === 0 || flatItems[index - 1].group !== item.group"
                                    class="px-3.5 pt-2 pb-1 text-[10px] font-semibold tracking-wider text-ink-400 uppercase"
                                >
                                    {{ item.group ?? 'Go to' }}
                                </p>

                                <button
                                    class="flex w-full items-center gap-2.5 px-3.5 py-2 text-left"
                                    :class="index === activeIndex ? 'bg-brand-50' : 'hover:bg-slate-50'"
                                    @click="go(item)"
                                    @mousemove="activeIndex = index"
                                >
                                    <Icon
                                        :name="item.icon"
                                        :class="index === activeIndex ? 'text-brand-600' : 'text-ink-400'"
                                    />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-sm text-ink-900">{{ item.title }}</span>
                                        <span v-if="item.subtitle" class="block truncate text-[11px] text-ink-500">
                                            {{ item.subtitle }}
                                        </span>
                                    </span>
                                    <Icon
                                        v-if="index === activeIndex"
                                        name="right"
                                        size="size-3.5"
                                        class="text-brand-500"
                                    />
                                </button>
                            </template>

                            <p v-if="flatItems.length === 0" class="px-3.5 py-8 text-center text-sm text-ink-500">
                                {{ query.trim().length < 2 ? 'Type at least two characters.' : `Nothing matches “${query}”.` }}
                            </p>
                        </div>

                        <div class="flex items-center gap-3 border-t border-slate-100 bg-slate-50 px-3.5 py-2 text-[10px] text-ink-500">
                            <span><kbd class="font-sans">↑↓</kbd> move</span>
                            <span><kbd class="font-sans">↵</kbd> open</span>
                            <span class="ml-auto">Documents you may not see are never searched.</span>
                        </div>
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
