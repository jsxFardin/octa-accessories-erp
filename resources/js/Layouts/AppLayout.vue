<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import CommandPalette from '@/Components/Ui/CommandPalette.vue';
import Icon from '@/Components/Ui/Icon.vue';
import Toasts from '@/Components/Ui/Toasts.vue';
import { canAny } from '@/plugins/permissions';
import { navigation } from '@/navigation';

/**
 * The desk layout: dense, keyboard-driven, built for people who live in it all day
 * (08-architecture §4). The shop floor gets FloorLayout instead, which shares nothing with
 * this on purpose.
 */
const page = usePage();

const user = computed(() => page.props.auth?.user);
const organisation = computed(() => page.props.app ?? {});
const currentUrl = computed(() => page.url);

/** A section the user can open nothing inside is not shown at all. */
const sections = computed(() =>
    navigation
        .map((section) => ({
            ...section,
            items: section.items.filter((item) => canAny(...item.permissions)),
        }))
        .filter((section) => section.items.length > 0),
);

function isActive(item) {
    return currentUrl.value.startsWith(item.href);
}

/**
 * Breadcrumbs are derived from the navigation tree rather than declared per page: the section
 * and list a screen belongs to are already known, and a crumb that drifts out of date is
 * worse than none.
 */
const crumbs = computed(() => {
    for (const section of sections.value) {
        const item = section.items.find((candidate) => isActive(candidate));

        if (item) {
            const trail = [{ label: section.label }, { label: item.label, href: item.href }];

            // Anything below the list itself — a detail page, a form — is the current page,
            // whose name is already the <h1>; the crumb just marks the depth.
            if (currentUrl.value.replace(/\?.*$/, '') !== item.href) {
                trail.push({ label: currentUrl.value.endsWith('/create') ? 'New' : 'Detail' });
            }

            return trail;
        }
    }

    return [];
});

// --- Sidebar state -----------------------------------------------------------------------
// Both the rail collapse and the per-section state persist: a planner who works out of two
// sections should not re-open them every morning.
const railed = ref(localStorage.getItem('octa.sidebar.railed') === '1');
const mobileOpen = ref(false);

const collapsed = ref(new Set(JSON.parse(localStorage.getItem('octa.sidebar.collapsed') ?? '[]')));

watch(railed, (value) => localStorage.setItem('octa.sidebar.railed', value ? '1' : '0'));
watch(currentUrl, () => (mobileOpen.value = false));

function toggleSection(label) {
    const next = new Set(collapsed.value);

    next.has(label) ? next.delete(label) : next.add(label);
    collapsed.value = next;

    localStorage.setItem('octa.sidebar.collapsed', JSON.stringify([...next]));
}

/** A collapsed section still opens when you are inside it — never hide where you are. */
function isOpen(section) {
    if (section.items.some((item) => isActive(item))) {
        return true;
    }

    return !collapsed.value.has(section.label);
}

// --- Account menu ------------------------------------------------------------------------
const accountOpen = ref(false);
const palette = ref(null);

const initials = computed(() =>
    (user.value?.name ?? '')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase(),
);

const roleLabels = computed(() =>
    (user.value?.roles ?? []).map((role) => role.replace(/_/g, ' ')).join(', '),
);

function onDocumentClick(event) {
    if (!event.target.closest('[data-account-menu]')) {
        accountOpen.value = false;
    }
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        accountOpen.value = false;
        mobileOpen.value = false;
    }
}

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
});

function logout() {
    router.post('/logout');
}

/** ⌘ on a Mac, Ctrl everywhere else — shown, because a hint nobody can read is decoration. */
const paletteHint = computed(() =>
    typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform ?? '') ? '⌘K' : 'Ctrl K',
);
</script>

<template>
    <div class="min-h-full">
        <Toasts />
        <CommandPalette ref="palette" />

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 flex flex-col border-r border-slate-200 bg-white transition-all duration-200 lg:translate-x-0 print:hidden"
            :class="[
                railed ? 'w-16' : 'w-60',
                mobileOpen ? 'translate-x-0' : '-translate-x-full',
            ]"
        >
            <div class="flex h-14 shrink-0 items-center gap-2.5 border-b border-slate-200 px-3">
                <!-- The square mark from the organisation profile, falling back to an initial. -->
                <div
                    class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg"
                    :class="organisation.icon_url ? 'bg-white ring-1 ring-slate-200' : 'bg-brand-600'"
                >
                    <img v-if="organisation.icon_url" :src="organisation.icon_url" alt="" class="size-full object-contain">
                    <span v-else class="text-sm font-bold text-white">
                        {{ (organisation.name ?? 'O').charAt(0).toUpperCase() }}
                    </span>
                </div>

                <div v-if="!railed" class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-ink-900">{{ organisation.short_name ?? 'Octa ERP' }}</p>
                    <p class="truncate text-[11px] text-ink-500">{{ organisation.name }}</p>
                </div>

                <button
                    class="hidden shrink-0 rounded-md p-1 text-ink-400 transition hover:bg-slate-100 hover:text-ink-700 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none lg:block"
                    :title="railed ? 'Expand sidebar' : 'Collapse sidebar'"
                    :aria-label="railed ? 'Expand sidebar' : 'Collapse sidebar'"
                    @click="railed = !railed"
                >
                    <Icon :name="railed ? 'right' : 'left'" />
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto px-2 py-3">
                <div v-for="section in sections" :key="section.label" class="mb-1">
                    <!-- Section headers collapse; the rail hides them entirely. -->
                    <button
                        v-if="!railed"
                        class="flex w-full items-center justify-between rounded px-2 py-1.5 text-[10px] font-semibold tracking-wider text-ink-400 uppercase transition hover:text-ink-700 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                        @click="toggleSection(section.label)"
                    >
                        <span>{{ section.label }}</span>
                        <Icon name="down" size="size-3.5" class="transition-transform" :class="isOpen(section) ? '' : '-rotate-90'" />
                    </button>

                    <ul v-show="railed || isOpen(section)" class="space-y-0.5 pb-2">
                        <li v-for="item in section.items" :key="item.href">
                            <Link
                                :href="item.href"
                                :title="railed ? item.label : undefined"
                                class="group relative flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                :class="[
                                    isActive(item)
                                        ? 'bg-brand-50 font-medium text-brand-700'
                                        : 'text-ink-700 hover:bg-slate-100 hover:text-ink-900',
                                    railed && 'justify-center px-0',
                                ]"
                            >
                                <!-- A rail bar on the active row, so the current screen reads at a glance. -->
                                <span
                                    v-if="isActive(item)"
                                    class="absolute inset-y-1 left-0 w-0.5 rounded-full bg-brand-600"
                                />
                                <Icon
                                    :name="item.icon"
                                    class="shrink-0"
                                    :class="isActive(item) ? 'text-brand-600' : 'text-ink-400 group-hover:text-ink-600'"
                                />
                                <span v-if="!railed" class="truncate">{{ item.label }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <div v-if="!railed" class="shrink-0 border-t border-slate-200 px-3 py-2">
                <button
                    class="flex w-full items-center gap-2 rounded-md px-2 py-1.5 text-xs text-ink-500 transition hover:bg-slate-100 hover:text-ink-800"
                    @click="palette?.show()"
                >
                    <Icon name="search" size="size-3.5" />
                    <span>Search</span>
                    <kbd class="ml-auto rounded border border-slate-200 px-1 py-0.5 font-sans text-[10px] text-ink-400">
                        {{ paletteHint }}
                    </kbd>
                </button>
            </div>
        </aside>

        <div
            v-if="mobileOpen"
            class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"
            @click="mobileOpen = false"
        />

        <!-- Content -->
        <div class="transition-all duration-200 print:pl-0" :class="railed ? 'lg:pl-16' : 'lg:pl-60'">
            <header
                class="sticky top-0 z-20 border-b border-slate-200 bg-white/85 backdrop-blur print:hidden"
            >
                <div class="flex min-h-14 items-center gap-3 px-4 py-2">
                    <button
                        class="rounded-md p-1 text-ink-500 transition hover:bg-slate-100 lg:hidden"
                        aria-label="Open navigation"
                        @click="mobileOpen = true"
                    >
                        <Icon name="menu" size="size-5" />
                    </button>

                    <div class="min-w-0 flex-1">
                        <nav v-if="crumbs.length" class="hidden items-center gap-1 text-[11px] text-ink-400 sm:flex">
                            <template v-for="(crumb, index) in crumbs" :key="crumb.label">
                                <Icon v-if="index > 0" name="right" size="size-3" class="text-ink-300" />
                                <Link
                                    v-if="crumb.href"
                                    :href="crumb.href"
                                    class="truncate transition hover:text-ink-700"
                                >
                                    {{ crumb.label }}
                                </Link>
                                <span v-else class="truncate">{{ crumb.label }}</span>
                            </template>
                        </nav>

                        <h1 class="truncate text-base leading-tight font-semibold text-ink-900">
                            <slot name="title" />
                        </h1>
                        <p v-if="$slots.subtitle" class="truncate text-xs text-ink-500">
                            <slot name="subtitle" />
                        </p>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <slot name="actions" />

                        <button
                            class="hidden rounded-md p-1.5 text-ink-400 transition hover:bg-slate-100 hover:text-ink-700 sm:block"
                            :title="`Search (${paletteHint})`"
                            aria-label="Search"
                            @click="palette?.show()"
                        >
                            <Icon name="search" />
                        </button>

                        <!-- Account: profile and sign-out live here, not buried in the sidebar. -->
                        <div class="relative ml-1 border-l border-slate-200 pl-3" data-account-menu>
                            <button
                                class="flex size-8 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white transition hover:bg-brand-700 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                :aria-expanded="accountOpen"
                                aria-haspopup="menu"
                                :title="user?.name"
                                @click="accountOpen = !accountOpen"
                            >
                                {{ initials }}
                            </button>

                            <Transition
                                enter-active-class="transition duration-100"
                                enter-from-class="opacity-0 scale-95"
                                leave-active-class="transition duration-75"
                                leave-to-class="opacity-0 scale-95"
                            >
                                <div
                                    v-if="accountOpen"
                                    class="absolute right-0 z-50 mt-2 w-64 origin-top-right rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
                                    role="menu"
                                >
                                    <div class="border-b border-slate-100 px-3 py-2.5">
                                        <p class="truncate text-sm font-semibold text-ink-900">{{ user?.name }}</p>
                                        <p class="truncate text-xs text-ink-500">{{ user?.email }}</p>
                                        <p v-if="roleLabels" class="mt-1 truncate text-[10px] text-ink-400">{{ roleLabels }}</p>
                                    </div>

                                    <Link
                                        href="/profile"
                                        class="flex items-center gap-2 px-3 py-2 text-sm text-ink-700 transition hover:bg-slate-50"
                                        role="menuitem"
                                        @click="accountOpen = false"
                                    >
                                        <Icon name="users" size="size-3.5" class="text-ink-400" />
                                        Profile
                                    </Link>

                                    <button
                                        class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-rose-600 transition hover:bg-rose-50"
                                        role="menuitem"
                                        @click="logout"
                                    >
                                        <Icon name="logout" size="size-3.5" />
                                        Log out
                                    </button>
                                </div>
                            </Transition>
                        </div>
                    </div>
                </div>
            </header>

            <!--
                Capped: on a 1920 monitor a full-bleed table stretches so wide the eye loses the
                row between the first column and the last.
            -->
            <main class="mx-auto w-full max-w-[1600px] p-4">
                <slot />
            </main>
        </div>
    </div>
</template>
