<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import CommandPalette from '@/Components/Ui/CommandPalette.vue';
import ConfirmDialog from '@/Components/Ui/ConfirmDialog.vue';
import HelpMenu from '@/Components/Ui/HelpMenu.vue';
import Icon from '@/Components/Ui/Icon.vue';
import NotificationBell from '@/Components/Ui/NotificationBell.vue';
import SessionExpired from '@/Components/Ui/SessionExpired.vue';
import Toasts from '@/Components/Ui/Toasts.vue';
import { canAny } from '@/plugins/permissions';
import { navigation, visibleSections, withSetupLists } from '@/navigation';

/**
 * The desk layout: dense, keyboard-driven, built for people who live in it all day
 * (08-architecture §4). The shop floor gets FloorLayout instead, which shares nothing with
 * this on purpose.
 */
/**
 * An optional name for the page below the list — the document's own reference, usually.
 *
 * A crumb reading "Detail" tells the reader nothing they did not already know from having
 * clicked; worse, an edit form carried the same word, so the trail said "Detail" for a screen
 * that was not one. Pages that have a name pass it; the rest fall back to what the URL says
 * they are, which is at least accurate.
 */
const props = defineProps({
    crumb: { type: String, default: null },
});

const page = usePage();

const user = computed(() => page.props.auth?.user);
const organisation = computed(() => page.props.app ?? {});
const currentUrl = computed(() => page.url);

/** A section the user can open nothing inside is not shown at all. */
/*
 * The Setup group is filled from the registry the server shares: Setup › Factory › Factory
 * units. The tree itself stays static and testable; only that one group is composed here.
 */
const sections = computed(() => visibleSections(withSetupLists(navigation, page.props.setupMenu ?? []), canAny));

/** Every row that is a destination: a parent row stands in for its children. */
function leaves(section) {
    return section.items.flatMap((item) => (item.children?.length ? item.children : [item]));
}

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
        for (const item of leaves(section)) {
            if (matches(item.href) && (!best || item.href.length > best.href.length)) {
                best = item;
            }
        }
    }

    return best;
});

function isActive(item) {
    if (item.children?.length) {
        return item.children.some((child) => isActive(child));
    }

    return activeItem.value?.href === item.href;
}

/** The parent row of a nested screen (Factory, for Factory units), or null. */
function parentOf(item) {
    for (const section of sections.value) {
        const parent = section.items.find((candidate) => candidate.children?.some((child) => child.href === item.href));

        if (parent) return parent;
    }

    return null;
}

/**
 * Breadcrumbs are derived from the navigation tree rather than declared per page: the section
 * and list a screen belongs to are already known, and a crumb that drifts out of date is
 * worse than none.
 */
/** What the URL says this screen is, when the page has not named itself. */
function depthLabel(path) {
    if (path.endsWith('/create')) return 'New';
    if (path.endsWith('/edit')) return 'Edit';
    if (path.endsWith('/print')) return 'Print';

    return 'Detail';
}

const crumbs = computed(() => {
    const item = activeItem.value;

    if (!item) {
        return [];
    }

    const section = sections.value.find((candidate) =>
        leaves(candidate).some((entry) => entry.href === item.href),
    );
    const parent = parentOf(item);

    const trail = !section || section.heading === false || section.label === item.label
        ? [{ label: item.label, href: item.href }]
        : [{ label: section.label }, ...(parent ? [{ label: parent.label }] : []), { label: item.label, href: item.href }];

    // Anything below the list itself — a detail page, a form — is the current page.
    const path = currentUrl.value.replace(/\?.*$/, '');

    if (path !== item.href) {
        trail.push({ label: props.crumb ?? depthLabel(path) });
    }

    return trail;
});

// --- Sidebar state -----------------------------------------------------------------------
/*
 * The rail persists. The open group does not, and there is only ever one.
 *
 * Groups used to expand independently and remember it across reloads, so a week's use left
 * every heading open and the sidebar a single scrolling list of forty rows — the grouping
 * stopped doing the one thing it is for. It is an accordion now: the group holding the current
 * page is open, opening another closes it, and navigating puts you back on the group you are
 * actually in.
 */
const railed = ref(localStorage.getItem('octa.sidebar.railed') === '1');
const mobileOpen = ref(false);

/** A heading the user opened by hand; cleared on navigation, when the page decides again. */
const openSection = ref(null);

watch(railed, (value) => localStorage.setItem('octa.sidebar.railed', value ? '1' : '0'));
watch(currentUrl, () => {
    mobileOpen.value = false;
    openSection.value = null;
    openItem.value = null;
});

// The old multi-open state is no longer read; clearing it keeps a stale key from confusing
// anyone reading localStorage in six months.
localStorage.removeItem('octa.sidebar.open');

function isSectionActive(section) {
    return section.items.some((item) => isActive(item));
}

/*
 * A parent row (Setup › Factory) opens like a heading does: one at a time, the one holding
 * the current page by default, cleared when the page changes.
 */
const openItem = ref(null);

function toggleItem(item) {
    openItem.value = openItem.value === item.label ? null : item.label;
}

function isItemOpen(item) {
    if (openItem.value !== null) {
        return openItem.value === item.label;
    }

    return isActive(item);
}

function toggleSection(section) {
    if (section.heading === false || section.open === true) {
        return;
    }

    // One at a time. Opening a heading closes whichever was open, including the active group —
    // that is what makes it possible to look somewhere else without losing the sidebar to a
    // wall of rows.
    openSection.value = openSection.value === section.label ? null : section.label;
}

/**
 * Hubs with no heading are always visible.
 *
 * Otherwise exactly one group is open: whichever the user last clicked, or — once they
 * navigate, which clears that — the one holding the page they are on. So the sidebar always
 * shows where you are and nothing else.
 */
function isOpen(section) {
    if (section.heading === false) {
        return true;
    }

    if (openSection.value !== null) {
        return openSection.value === section.label;
    }

    return isSectionActive(section);
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
        <SessionExpired />

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 flex flex-col border-r border-slate-200 bg-white transition-all duration-200 lg:translate-x-0 print:hidden"
            :class="[
                railed ? 'w-16' : 'w-60',
                mobileOpen ? 'translate-x-0 shadow-2xl lg:shadow-none' : '-translate-x-full',
            ]"
        >
            <!--
                Railed, the header is one 64px column: the mark alone, and it is the way back
                out. A logo and a collapse button side by side do not fit in a rail, and the
                pair of them squeezed in was the first thing that looked wrong.
            -->
            <div v-if="railed" class="flex h-14 shrink-0 items-center justify-center border-b border-slate-200">
                <button
                    class="group relative flex size-9 items-center justify-center rounded-lg transition hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                    title="Expand sidebar"
                    aria-label="Expand sidebar"
                    @click="railed = false"
                >
                    <span
                        class="flex size-8 items-center justify-center overflow-hidden rounded-lg transition group-hover:opacity-0"
                        :class="organisation.icon_url ? 'bg-white ring-1 ring-slate-200' : ''"
                    >
                        <!-- The uploaded square mark, or the shipped Trimflow mark — the same file the favicon uses. -->
                        <img :src="organisation.icon_url || '/favicon.svg'" alt="" class="size-full object-contain">
                    </span>

                    <Icon
                        name="right"
                        class="absolute text-ink-500 opacity-0 transition group-hover:opacity-100"
                    />
                </button>
            </div>

            <div class="flex h-14 shrink-0 items-center gap-2.5 border-b border-slate-200 px-3">
                <!-- The square mark from the organisation profile, falling back to the Trimflow mark. -->
                <Link
                    href="/dashboard"
                    class="-mx-1 flex min-w-0 flex-1 items-center gap-2.5 rounded-md px-1 py-1 transition hover:bg-slate-50"
                >
                    <span
                        class="flex size-8 shrink-0 items-center justify-center overflow-hidden rounded-lg"
                        :class="organisation.icon_url ? 'bg-white ring-1 ring-slate-200' : ''"
                    >
                        <!-- The uploaded square mark, or the shipped Trimflow mark — the same file the favicon uses. -->
                        <img :src="organisation.icon_url || '/favicon.svg'" alt="" class="size-full object-contain">
                    </span>

                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-semibold text-ink-900">{{ organisation.short_name ?? 'Trimflow' }}</span>
                        <span class="block truncate text-xs text-ink-500">{{ organisation.name }}</span>
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
                            class="min-w-0 flex-1 truncate text-xs font-semibold tracking-[0.08em] uppercase"
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
                        <li v-for="item in section.items" :key="item.label + item.href">
                            <!--
                                A row with children (Setup › Factory) is a toggle in the full
                                sidebar and its children sit indented under it. In the rail
                                there is no room for a second level, so the row is a link to its
                                first child, like a heading in the rail.
                            -->
                            <template v-if="item.children?.length && !railed">
                                <button
                                    type="button"
                                    class="group flex w-full items-center gap-2.5 rounded-md px-2 py-1.5 text-left text-sm transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                    :class="isActive(item) ? 'font-medium text-ink-900' : 'text-ink-800 hover:bg-slate-100 hover:text-ink-900'"
                                    :aria-expanded="isItemOpen(item)"
                                    @click="toggleItem(item)"
                                >
                                    <Icon
                                        :name="item.icon"
                                        class="shrink-0 transition-colors"
                                        :class="isActive(item) ? 'text-brand-600' : 'text-ink-500 group-hover:text-ink-700'"
                                    />
                                    <span class="min-w-0 flex-1 truncate">{{ item.label }}</span>
                                    <Icon
                                        name="down"
                                        size="size-3"
                                        class="shrink-0 text-ink-500 transition"
                                        :class="isItemOpen(item) ? '' : '-rotate-90'"
                                    />
                                </button>

                                <ul v-show="isItemOpen(item)" class="mt-px ml-4 space-y-px border-l border-slate-200 pl-2">
                                    <li v-for="child in item.children" :key="child.href">
                                        <Link
                                            :href="child.href"
                                            :aria-current="isActive(child) ? 'page' : undefined"
                                            class="flex items-center rounded-md px-2 py-1.5 text-sm transition focus-visible:ring-2 focus-visible:ring-brand-500/40 focus-visible:outline-none"
                                            :class="isActive(child)
                                                ? 'bg-brand-50 font-medium text-brand-700 shadow-[inset_2px_0_0_0_var(--color-brand-600)]'
                                                : 'text-ink-700 hover:bg-slate-100 hover:text-ink-900'"
                                        >
                                            <span class="min-w-0 flex-1 truncate">{{ child.label }}</span>
                                        </Link>
                                    </li>
                                </ul>
                            </template>

                            <!--
                                An external entry leaves this application (the floor terminal
                                runs its own shell), so it is a plain anchor in a new tab
                                rather than an Inertia visit that would replace the desk.
                            -->
                            <component
                                v-else
                                :is="item.external ? 'a' : Link"
                                :href="item.href"
                                v-bind="item.external ? { target: '_blank', rel: 'noopener' } : {}"
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
                                <Icon
                                    v-if="item.external && !railed"
                                    name="right"
                                    size="size-3"
                                    class="shrink-0 -rotate-45 text-ink-400"
                                />
                            </component>
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
                        <kbd class="ml-auto rounded border border-slate-200 bg-white px-1 py-0.5 font-sans text-xs text-ink-400">
                            {{ paletteHint }}
                        </kbd>
                    </template>
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
                <!--
                    Wraps rather than overflows. Detail pages now carry the next action in the
                    header alongside the status and the transitions, which on a phone is more
                    buttons than fit on one line; unwrapped they pushed the page sideways.
                -->
                <div class="flex min-h-14 flex-wrap items-center gap-x-3 gap-y-2 px-4 py-2">
                    <button
                        class="rounded-md p-1 text-ink-500 transition hover:bg-slate-100 lg:hidden"
                        aria-label="Open navigation"
                        @click="mobileOpen = true"
                    >
                        <Icon name="menu" size="size-5" />
                    </button>

                    <div class="min-w-0 flex-1 basis-48">
                        <nav v-if="crumbs.length" class="hidden items-center gap-1 text-xs text-ink-500 sm:flex">
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

                        <!-- Two lines on a phone before it is cut: a record's code and name did not fit in one. -->
                        <h1 class="line-clamp-2 text-xl leading-tight font-semibold tracking-tight break-words text-ink-900 sm:line-clamp-none sm:truncate">
                            <slot name="title" />
                        </h1>
                        <!--
                            Wraps on a narrow screen, truncates from `sm` up. `truncate` alone
                            is `nowrap` + `overflow:hidden`, so on a 390px phone the tail of
                            this line was not merely shortened — the backlink to the source
                            document lived there and was clipped away entirely, taking the only
                            route back to the inquiry or order with it.
                        -->
                        <p v-if="$slots.subtitle" class="text-xs text-ink-500 sm:truncate">
                            <slot name="subtitle" />
                        </p>
                    </div>

                    <!--
                        `ml-auto` keeps this strip against the right edge on the line it lands
                        on. Without it a wrapped header put the strip at the start of the second
                        line, and the notification panel — anchored `right-0` to the bell — then
                        opened off the left of the screen.
                    -->
                    <!--
                        On a phone the strip is one row that scrolls sideways rather than three
                        wrapped rows: a sales order's six actions took half the first screen.
                    -->
                    <div class="quiet-scroll ml-auto flex items-center gap-2 max-sm:w-full max-sm:flex-nowrap max-sm:overflow-x-auto max-sm:pb-1 max-sm:[&>*]:shrink-0 max-sm:[&>*]:whitespace-nowrap sm:flex-wrap sm:justify-end">
                        <slot name="actions" />

                        <NotificationBell />

                        <HelpMenu />

                        <!--
                            Shown at every width. Hidden below `sm` there was no way at all to
                            reach the command palette on a phone — the keyboard shortcut that
                            replaces it is not available on one.
                        -->
                        <button
                            class="rounded-md p-1.5 text-ink-400 transition hover:bg-slate-100 hover:text-ink-700"
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
                                        <p v-if="roleLabels" class="mt-1 truncate text-xs text-ink-400">{{ roleLabels }}</p>
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
            <!--
                Every seeded account starts on one shared password. Said on every screen until it
                is changed: it used to be mentioned only on the profile page, which nobody opens
                unprompted, so a factory could run for months on a password everyone knew.
            -->
            <!-- <div
                v-if="user?.using_seed_password && path !== '/profile'"
                role="status"
                class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-amber-200 bg-amber-50 px-4 py-2 text-sm text-amber-900 print:hidden"
            >
                <Icon name="warning" size="size-4" class="shrink-0 text-amber-700" />
                <span class="min-w-0 flex-1 basis-64">
                    You are still using the starter password, which every new account shares.
                </span>
                <Link href="/profile" class="rounded font-medium underline hover:text-amber-950 focus-visible:ring-2 focus-visible:ring-amber-600/40 focus-visible:outline-none">
                    Change your password
                </Link>
            </div> -->

            <main class="mx-auto w-full max-w-[90rem] p-4">
                <slot />
            </main>
        </div>
    </div>
</template>
