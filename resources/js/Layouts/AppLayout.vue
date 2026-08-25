<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import CommandPalette from '@/Components/Ui/CommandPalette.vue';
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue';
import Icon from '@/Components/Ui/Icon.vue';
import NotificationBell from '@/Components/Ui/NotificationBell.vue';
import Toasts from '@/Components/Ui/Toasts.vue';
import { canAny } from '@/plugins/permissions';
import { ADMIN_PREFIXES, adminNavigation, navigation, visibleSections } from '@/navigation';

/**
 * The desk layout: dense, keyboard-driven, built for people who live in it all day
 * (08-architecture §4). The shop floor gets FloorLayout instead, which shares nothing with
 * this on purpose.
 */
const page = usePage();

const user = computed(() => page.props.auth?.user);
const organisation = computed(() => page.props.app ?? {});
const currentUrl = computed(() => page.url);

/**
 * Configuration is a mode, not a menu. Inside it the sidebar swaps wholesale and offers an
 * explicit way out, so the working application never shows admin rows.
 */
const inAdminShell = computed(() =>
    ADMIN_PREFIXES.some((prefix) => currentUrl.value.startsWith(prefix)),
);

/** A section the user can open nothing inside is not shown at all. */
const sections = computed(() =>
    visibleSections(inAdminShell.value ? adminNavigation : navigation, canAny),
);

const path = computed(() => currentUrl.value.split('?')[0]);

function matches(href) {
    return path.value === href || path.value.startsWith(`${href}/`);
}

/**
 * Longest href wins, so `/reports/fulfilment` does not also light up All reports (`/reports`).
 */
const activeItem = computed(() => {
    let best = null;

    for (const section of sections.value) {
        for (const item of section.items) {
            if (matches(item.href) && (!best || item.href.length > best.href.length)) {
                best = item;
            }
        }
    }

    return best;
});

function isActive(item) {
    return activeItem.value?.href === item.href;
}

/**
 * Breadcrumbs are derived from the navigation tree rather than declared per page: the section
 * and list a screen belongs to are already known, and a crumb that drifts out of date is
 * worse than none.
 */
const crumbs = computed(() => {
    const item = activeItem.value;

    if (!item) {
        return [];
    }

    const section = sections.value.find((candidate) =>
        candidate.items.some((entry) => entry.href === item.href),
    );

    const trail = !section || section.heading === false || section.label === item.label
        ? [{ label: item.label, href: item.href }]
        : [{ label: section.label }, { label: item.label, href: item.href }];

    // Anything below the list itself — a detail page, a form — is the current page,
    // whose name is already the <h1>; the crumb just marks the depth.
    if (currentUrl.value.replace(/\?.*$/, '') !== item.href) {
        trail.push({ label: currentUrl.value.endsWith('/create') ? 'New' : 'Detail' });
    }

    return trail;
});

// --- Sidebar state -----------------------------------------------------------------------
// The rail persists, and so does which groups are expanded: a click on a heading only ever
// toggles it, never navigates, so open/closed state survives across pages and reloads the
// same way the rail does. The group containing the active page always shows regardless.
const railed = ref(localStorage.getItem('octa.sidebar.railed') === '1');
const mobileOpen = ref(false);

function loadOpenSections() {
    try {
        return new Set(JSON.parse(localStorage.getItem('octa.sidebar.open') ?? '[]'));
    } catch {
        return new Set();
    }
}

const openSections = ref(loadOpenSections());

watch(railed, (value) => localStorage.setItem('octa.sidebar.railed', value ? '1' : '0'));
watch(currentUrl, () => {
    mobileOpen.value = false;
});

function isSectionActive(section) {
    return section.items.some((item) => isActive(item));
}

function toggleSection(section) {
    if (section.heading === false || section.open === true) {
        return;
    }

    const next = new Set(openSections.value);

    next.has(section.label) ? next.delete(section.label) : next.add(section.label);
    openSections.value = next;
    localStorage.setItem('octa.sidebar.open', JSON.stringify([...next]));
}

/**
 * Hubs with no heading are always visible. The active group is always visible, so you never
 * lose sight of where you are. Sections marked `open: true` (the small admin shell, where
 * collapsing three groups would hide three of six rows) never close. Others follow whatever
 * the user last chose, remembered across navigation and reload.
 */
function isOpen(section) {
    if (section.heading === false || section.open === true || isSectionActive(section)) {
        return true;
    }

    return openSections.value.has(section.label);
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

    // ⌘B / Ctrl-B collapses the sidebar to its icon rail, as both sibling products do.
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'b') {
        event.preventDefault();
        railed.value = !railed.value;
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
        <ConfirmDialog />

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 flex flex-col border-r border-slate-200 bg-white transition-all duration-200 lg:translate-x-0 print:hidden"
            :class="[
                railed ? 'w-16' : 'w-60',
                mobileOpen ? 'translate-x-0 shadow-2xl lg:shadow-none' : '-translate-x-full',
            ]"
        >
            <!-- Inside configuration the header becomes the way out, as `socialx` does. -->
            <div v-if="inAdminShell" class="flex h-14 shrink-0 items-center gap-2 border-b border-slate-200 px-3">
                <Link
                    href="/dashboard"
                    class="flex min-w-0 flex-1 items-center gap-2 rounded-md px-1.5 py-1.5 text-sm text-ink-700 transition hover:bg-slate-100"
                    :class="railed && 'justify-center px-0'"
                    :title="railed ? 'Exit configuration' : undefined"
                >
                    <Icon name="close" size="size-4" class="shrink-0 text-ink-400" />
                    <span v-if="!railed" class="truncate font-medium">Exit configuration</span>
                </Link>
            </div>

            <!--
                Railed, the header is one 64px column: the mark alone, and it is the way back
                out. A logo and a collapse button side by side do not fit in a rail, and the
                pair of them squeezed in was the first thing that looked wrong.
            -->
            <div v-else-if="railed" class="flex h-14 shrink-0 items-center justify-center border-b border-slate-200">
                <button
                    class="group relative flex size-9 items-center justify-center rounded-lg transition hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                    title="Expand sidebar"
                    aria-label="Expand sidebar"
                    @click="railed = false"
                >
                    <span
                        class="flex size-8 items-center justify-center overflow-hidden rounded-lg transition group-hover:opacity-0"
                        :class="organisation.icon_url ? 'bg-white ring-1 ring-slate-200' : 'bg-brand-600'"
                    >
                        <img v-if="organisation.icon_url" :src="organisation.icon_url" alt="" class="size-full object-contain">
                        <span v-else class="text-sm font-bold text-white">
                            {{ (organisation.name ?? 'O').charAt(0).toUpperCase() }}
                        </span>
                    </span>

                    <Icon
                        name="right"
                        class="absolute text-ink-500 opacity-0 transition group-hover:opacity-100"
                    />
                </button>
            </div>

            <div v-else class="flex h-14 shrink-0 items-center gap-2.5 border-b border-slate-200 px-3">
                <!-- The square mark from the organisation profile, falling back to an initial. -->
                <Link
                    href="/dashboard"
                    class="-mx-1 flex min-w-0 flex-1 items-center gap-2.5 rounded-md px-1 py-1 transition hover:bg-slate-50"
                >
                    <span
                        class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg"
                        :class="organisation.icon_url ? 'bg-white ring-1 ring-slate-200' : 'bg-brand-600'"
                    >
                        <img v-if="organisation.icon_url" :src="organisation.icon_url" alt="" class="size-full object-contain">
                        <span v-else class="text-sm font-bold text-white">
                            {{ (organisation.name ?? 'O').charAt(0).toUpperCase() }}
                        </span>
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-ink-900">{{ organisation.short_name ?? 'Octa ERP' }}</span>
                        <span class="block truncate text-[11px] text-ink-500">{{ organisation.name }}</span>
                    </span>
                </Link>

                <button
                    class="hidden shrink-0 rounded-md p-1 text-ink-400 transition hover:bg-slate-100 hover:text-ink-700 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none lg:block"
                    :title="`Collapse sidebar (${paletteHint.replace('K', 'B')})`"
                    aria-label="Collapse sidebar"
                    @click="railed = true"
                >
                    <Icon name="left" />
                </button>
            </div>

            <nav class="quiet-scroll flex-1 overflow-y-auto overscroll-contain px-2 py-2">
                <div
                    v-for="(section, index) in sections"
                    :key="section.label"
                    :class="index > 0 && (railed ? 'mt-2 border-t border-slate-100 pt-2' : 'mt-3')"
                >
                    <!--
                        Section headers collapse; the rail hides them entirely and separates
                        sections with a hairline instead, since a rail of unlabelled icons with
                        no grouping is twenty identical rows.
                    -->
                    <!--
                        The heading is a toggle, not a destination: click anywhere on the row
                        opens or closes the group without leaving the page you are on. Pick a
                        child row to actually navigate.
                    -->
                    <button
                        v-if="!railed && section.heading !== false"
                        type="button"
                        class="flex w-full items-center gap-0.5 rounded px-2 py-1 text-left transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                        :class="section.open === true ? 'cursor-default' : 'hover:bg-slate-50'"
                        :aria-expanded="isOpen(section)"
                        :disabled="section.open === true"
                        @click="toggleSection(section)"
                    >
                        <span
                            class="min-w-0 flex-1 truncate text-[11px] font-semibold tracking-[0.08em] uppercase"
                            :class="isSectionActive(section) ? 'text-ink-800' : 'text-ink-600'"
                        >
                            {{ section.label }}
                        </span>
                        <Icon
                            v-if="section.open !== true"
                            name="down"
                            size="size-3"
                            class="shrink-0 text-ink-500 transition"
                            :class="isOpen(section) ? '' : '-rotate-90'"
                        />
                    </button>

                    <ul v-show="railed || isOpen(section)" class="mt-0.5 space-y-px">
                        <li v-for="item in section.items" :key="item.href">
                            <Link
                                :href="item.href"
                                :title="railed ? item.label : undefined"
                                :aria-current="isActive(item) ? 'page' : undefined"
                                class="group flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                :class="[
                                    // The active row carries a 2px brand edge drawn as an inset
                                    // shadow rather than an absolutely-placed bar: it follows the
                                    // rounded corner instead of poking out of it. In the rail
                                    // there is no row to edge, so the tint carries it alone.
                                    isActive(item)
                                        ? railed
                                            ? 'bg-brand-50 text-brand-700'
                                            : 'bg-brand-50 font-medium text-brand-700 shadow-[inset_2px_0_0_0_var(--color-brand-600)]'
                                        : 'text-ink-800 hover:bg-slate-100 hover:text-ink-900',
                                    railed && 'justify-center px-0',
                                ]"
                            >
                                <Icon
                                    :name="item.icon"
                                    class="shrink-0 transition-colors"
                                    :class="isActive(item) ? 'text-brand-600' : 'text-ink-500 group-hover:text-ink-700'"
                                />
                                <span v-if="!railed" class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="shrink-0 space-y-px border-t border-slate-200 bg-slate-50/60 px-2 py-2">
                <button
                    class="group flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-sm text-ink-800 transition hover:bg-white hover:text-ink-900 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                    :class="railed && 'justify-center px-0'"
                    :title="railed ? `Search (${paletteHint})` : undefined"
                    @click="palette?.show()"
                >
                    <Icon name="search" class="shrink-0 text-ink-500 transition-colors group-hover:text-ink-700" />
                    <template v-if="!railed">
                        <span>Search</span>
                        <kbd class="ml-auto rounded border border-slate-200 bg-white px-1 py-0.5 font-sans text-[10px] text-ink-400">
                            {{ paletteHint }}
                        </kbd>
                    </template>
                </button>

                <!--
                    The terminal is a different application on a different device, so it opens
                    in its own tab rather than replacing this one. Without a link here the only
                    way to reach it was to know the URL.
                -->
                <a
                    v-if="!inAdminShell && canAny('job_card.view_any')"
                    href="/floor"
                    target="_blank"
                    rel="noopener"
                    class="group flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-sm text-ink-800 transition hover:bg-white hover:text-ink-900 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                    :class="railed && 'justify-center px-0'"
                    :title="railed ? 'Shop-floor terminal' : undefined"
                >
                    <Icon name="machine" class="shrink-0 text-ink-500 transition-colors group-hover:text-ink-700" />
                    <span v-if="!railed">Shop floor</span>
                </a>

                <!--
                    Configuration is entered deliberately and left deliberately. Six admin rows
                    used to sit in the main tree competing with the shop floor for attention.
                -->
                <Link
                    v-if="!inAdminShell && canAny('reference_data.view_any', 'setting.view_any', 'user.view_any', 'role.view_any')"
                    href="/setup"
                    class="group flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-sm text-ink-800 transition hover:bg-white hover:text-ink-900 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                    :class="railed && 'justify-center px-0'"
                    :title="railed ? 'Configuration' : undefined"
                >
                    <Icon name="settings" class="shrink-0 text-ink-500 transition-colors group-hover:text-ink-700" />
                    <span v-if="!railed">Configuration</span>
                </Link>
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
                        <nav v-if="crumbs.length" class="hidden items-center gap-1 text-[11px] text-ink-500 sm:flex">
                            <template v-for="(crumb, index) in crumbs" :key="crumb.label">
                                <Icon v-if="index > 0" name="right" size="size-3" class="text-ink-400" />
                                <Link
                                    v-if="crumb.href"
                                    :href="crumb.href"
                                    class="truncate transition hover:text-ink-800"
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

                        <NotificationBell />

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
