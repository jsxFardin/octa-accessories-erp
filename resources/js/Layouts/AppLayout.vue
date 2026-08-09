<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
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

// --- Sidebar state -----------------------------------------------------------------------
// Both the rail collapse and the per-section state persist: a planner who works out of two
// sections should not re-open them every morning.
const railed = ref(localStorage.getItem('octa.sidebar.railed') === '1');
const mobileOpen = ref(false);

const collapsed = ref(new Set(JSON.parse(localStorage.getItem('octa.sidebar.collapsed') ?? '[]')));

watch(railed, (value) => localStorage.setItem('octa.sidebar.railed', value ? '1' : '0'));

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

// --- Language ----------------------------------------------------------------------------
// The floor runs in Bangla; a supervisor moving between the office and the floor should not
// need an admin to flip it.
const locale = computed(() => user.value?.locale ?? 'en');

function setLocale(value) {
    if (value === locale.value) {
        return;
    }

    router.put('/profile/locale', { locale: value }, { preserveScroll: true });
}
</script>

<template>
    <div class="min-h-full">
        <Toasts />

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 flex flex-col border-r border-slate-200 bg-white transition-all duration-200 lg:translate-x-0"
            :class="[
                railed ? 'w-16' : 'w-60',
                mobileOpen ? 'translate-x-0' : '-translate-x-full',
            ]"
        >
            <div class="flex h-14 shrink-0 items-center gap-2 border-b border-slate-200 px-3">
                <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-brand-600 text-sm font-bold text-white">
                    O
                </div>
                <div v-if="!railed" class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-ink-900">Octa ERP</p>
                    <p class="truncate text-[10px] text-ink-500">Maheen Label</p>
                </div>

                <button
                    class="hidden shrink-0 rounded p-1 text-ink-400 transition hover:bg-slate-100 hover:text-ink-700 lg:block"
                    :title="railed ? 'Expand sidebar' : 'Collapse sidebar'"
                    :aria-label="railed ? 'Expand sidebar' : 'Collapse sidebar'"
                    @click="railed = !railed"
                >
                    <svg class="size-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8">
                        <path :d="railed ? 'M7 5l5 5-5 5' : 'M13 5l-5 5 5 5'" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </button>
            </div>

            <nav class="flex-1 overflow-y-auto px-2 py-3">
                <div v-for="section in sections" :key="section.label" class="mb-1">
                    <!-- Section headers collapse; the rail hides them entirely. -->
                    <button
                        v-if="!railed"
                        class="flex w-full items-center justify-between rounded px-2 py-1.5 text-[10px] font-semibold tracking-wider text-ink-400 uppercase transition hover:text-ink-700"
                        @click="toggleSection(section.label)"
                    >
                        <span>{{ section.label }}</span>
                        <svg
                            class="size-3.5 transition-transform"
                            :class="isOpen(section) ? '' : '-rotate-90'"
                            viewBox="0 0 20 20"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2"
                        >
                            <path d="M5 8l5 5 5-5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>

                    <ul v-show="railed || isOpen(section)" class="space-y-0.5 pb-2">
                        <li v-for="item in section.items" :key="item.href">
                            <Link
                                :href="item.href"
                                :title="railed ? item.label : undefined"
                                class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm transition"
                                :class="[
                                    isActive(item)
                                        ? 'bg-brand-50 font-medium text-brand-700'
                                        : 'text-ink-700 hover:bg-slate-100 hover:text-ink-900',
                                    railed && 'justify-center px-0',
                                ]"
                            >
                                <span class="w-4 shrink-0 text-center text-xs" :class="isActive(item) ? 'text-brand-600' : 'text-ink-400'">
                                    {{ item.icon }}
                                </span>
                                <span v-if="!railed" class="truncate">{{ item.label }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <!-- <div class="shrink-0 border-t border-slate-200 p-2">
                <Link
                    href="/profile"
                    :title="railed ? 'Help & account' : undefined"
                    class="flex items-center gap-2.5 rounded-md px-2 py-1.5 text-sm text-ink-500 transition hover:bg-slate-100 hover:text-ink-900"
                    :class="railed && 'justify-center px-0'"
                >
                    <span class="w-4 shrink-0 text-center text-xs">?</span>
                    <span v-if="!railed">Help &amp; account</span>
                </Link>
            </div> -->
        </aside>

        <div
            v-if="mobileOpen"
            class="fixed inset-0 z-30 bg-slate-900/40 lg:hidden"
            @click="mobileOpen = false"
        />

        <!-- Content -->
        <div class="transition-all duration-200" :class="railed ? 'lg:pl-16' : 'lg:pl-60'">
            <header
                class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur"
            >
                <button
                    class="text-ink-500 lg:hidden"
                    aria-label="Open navigation"
                    @click="mobileOpen = true"
                >
                    ☰
                </button>

                <div class="min-w-0 flex-1">
                    <h1 class="truncate text-base font-semibold text-ink-900">
                        <slot name="title" />
                    </h1>
                    <p v-if="$slots.subtitle" class="truncate text-xs text-ink-500">
                        <slot name="subtitle" />
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <slot name="actions" />

                    <!-- Language -->
                    <!-- <div class="hidden overflow-hidden rounded-md border border-slate-200 sm:flex">
                        <button
                            v-for="option in [{ value: 'en', label: 'EN' }, { value: 'bn', label: 'BN' }]"
                            :key="option.value"
                            class="px-2 py-1 text-xs font-medium transition"
                            :class="locale === option.value
                                ? 'bg-brand-600 text-white'
                                : 'bg-white text-ink-500 hover:bg-slate-50'"
                            @click="setLocale(option.value)"
                        >
                            {{ option.label }}
                        </button>
                    </div> -->

                    <!-- Account: profile and sign-out live here, not buried in the sidebar. -->
                    <div class="relative ml-4" data-account-menu>
                        <button
                            class="flex size-8 items-center justify-center rounded-full bg-brand-600 text-xs font-semibold text-white transition hover:bg-brand-700"
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
                                    class="block px-3 py-2 text-sm text-ink-700 transition hover:bg-slate-50"
                                    role="menuitem"
                                    @click="accountOpen = false"
                                >
                                    Profile
                                </Link>

                                <button
                                    class="block w-full px-3 py-2 text-left text-sm text-rose-600 transition hover:bg-rose-50"
                                    role="menuitem"
                                    @click="logout"
                                >
                                    Log out
                                </button>
                            </div>
                        </Transition>
                    </div>
                </div>
            </header>

            <main class="p-4">
                <slot />
            </main>
        </div>
    </div>
</template>
