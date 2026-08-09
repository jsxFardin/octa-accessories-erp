<script setup>
import { computed, ref } from 'vue';
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
const sidebarOpen = ref(false);

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

function logout() {
    router.post('/logout');
}
</script>

<template>
    <div class="min-h-full">
        <Toasts />

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 flex w-60 flex-col bg-slate-900 transition-transform lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="flex h-14 shrink-0 items-center gap-2 border-b border-white/10 px-4">
                <div class="flex size-7 items-center justify-center rounded bg-brand-500 text-sm font-bold text-white">
                    O
                </div>
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold text-white">Octa ERP</p>
                    <p class="truncate text-[10px] text-slate-400">Maheen Label</p>
                </div>
            </div>

            <nav class="flex-1 overflow-y-auto px-2 py-3">
                <div v-for="section in sections" :key="section.label" class="mb-4">
                    <p class="px-2 pb-1 text-[10px] font-semibold tracking-wider text-slate-500 uppercase">
                        {{ section.label }}
                    </p>
                    <ul class="space-y-0.5">
                        <li v-for="item in section.items" :key="item.href">
                            <Link
                                :href="item.href"
                                class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm transition"
                                :class="isActive(item)
                                    ? 'bg-brand-600/90 font-medium text-white'
                                    : 'text-slate-300 hover:bg-white/5 hover:text-white'"
                            >
                                <span class="w-4 shrink-0 text-center text-xs opacity-70">{{ item.icon }}</span>
                                <span class="truncate">{{ item.label }}</span>
                            </Link>
                        </li>
                    </ul>
                </div>
            </nav>

            <div class="shrink-0 border-t border-white/10 p-3">
                <div class="mb-2 min-w-0">
                    <p class="truncate text-xs font-medium text-white">{{ user?.name }}</p>
                    <p class="truncate text-[10px] text-slate-400">
                        {{ user?.roles?.map((r) => r.replace(/_/g, ' ')).join(', ') }}
                    </p>
                </div>
                <div class="flex gap-1.5">
                    <Link
                        href="/profile"
                        class="flex-1 rounded-md bg-white/5 px-2 py-1.5 text-center text-xs text-slate-300 transition hover:bg-white/10 hover:text-white"
                    >
                        My account
                    </Link>
                    <button
                        class="flex-1 rounded-md bg-white/5 px-2 py-1.5 text-xs text-slate-300 transition hover:bg-white/10 hover:text-white"
                        @click="logout"
                    >
                        Sign out
                    </button>
                </div>
            </div>
        </aside>

        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden"
            @click="sidebarOpen = false"
        />

        <!-- Content -->
        <div class="lg:pl-60">
            <header
                class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-slate-200 bg-white/90 px-4 backdrop-blur"
            >
                <button class="text-slate-500 lg:hidden" aria-label="Open navigation" @click="sidebarOpen = true">
                    ☰
                </button>

                <div class="min-w-0 flex-1">
                    <h1 class="truncate text-base font-semibold text-slate-900">
                        <slot name="title" />
                    </h1>
                    <p v-if="$slots.subtitle" class="truncate text-xs text-slate-500">
                        <slot name="subtitle" />
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <slot name="actions" />
                </div>
            </header>

            <main class="p-4">
                <slot />
            </main>
        </div>
    </div>
</template>
