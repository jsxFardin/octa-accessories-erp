<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/Ui/Icon.vue';

/**
 * Header bell: the authenticated user's own notifications. Mark-as-read is scoped to the
 * caller server-side; this component never asks for another user's id.
 */
const page = usePage();

const open = ref(false);
const inbox = ref({ unread: 0, notifications: [] });

watch(
    () => page.props.notifications,
    (value) => {
        inbox.value = value ?? { unread: 0, notifications: [] };
    },
    { immediate: true, deep: true },
);

const unread = computed(() => inbox.value.unread ?? 0);
const items = computed(() => inbox.value.notifications ?? []);
const unreadLabel = computed(() => (unread.value > 9 ? '9+' : String(unread.value)));

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function postJson(url) {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            'X-Requested-With': 'XMLHttpRequest',
        },
    });

    if (!response.ok) {
        return;
    }

    inbox.value = await response.json();
}

async function toggle() {
    open.value = !open.value;

    if (!open.value) {
        return;
    }

    const response = await fetch('/notifications', { headers: { Accept: 'application/json' } });

    if (response.ok) {
        inbox.value = await response.json();
    }
}

async function openItem(item) {
    if (!item.read_at) {
        await postJson(`/notifications/${item.id}/read`);
    }

    open.value = false;

    if (item.href) {
        router.visit(item.href);
    }
}

function markAllRead() {
    postJson('/notifications/read-all');
}

function onDocumentClick(event) {
    if (!event.target.closest('[data-notification-menu]')) {
        open.value = false;
    }
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        open.value = false;
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
</script>

<template>
    <div class="relative" data-notification-menu>
        <button
            class="relative rounded-md p-1.5 text-ink-400 transition hover:bg-slate-100 hover:text-ink-700"
            :aria-expanded="open"
            aria-haspopup="menu"
            aria-label="Notifications"
            title="Notifications"
            @click="toggle"
        >
            <Icon name="bell" />
            <span
                v-if="unread > 0"
                class="absolute top-0.5 right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-rose-600 px-1 text-[9px] font-semibold text-white"
            >
                {{ unreadLabel }}
            </span>
        </button>

        <Transition
            enter-active-class="transition duration-100"
            enter-from-class="opacity-0 scale-95"
            leave-active-class="transition duration-75"
            leave-to-class="opacity-0 scale-95"
        >
            <div
                v-if="open"
                class="absolute right-0 z-50 mt-2 w-80 origin-top-right rounded-lg border border-slate-200 bg-white py-1 shadow-lg"
                role="menu"
            >
                <div class="flex items-center justify-between border-b border-slate-100 px-3 py-2">
                    <p class="text-sm font-semibold text-ink-900">Notifications</p>
                    <button
                        v-if="unread > 0"
                        class="text-[11px] font-medium text-brand-700 hover:underline"
                        type="button"
                        @click="markAllRead"
                    >
                        Mark all read
                    </button>
                </div>

                <p v-if="!items.length" class="px-3 py-6 text-center text-xs text-ink-400">
                    Nothing waiting.
                </p>

                <ul v-else class="max-h-80 overflow-y-auto py-1">
                    <li v-for="item in items" :key="item.id">
                        <button
                            class="flex w-full flex-col gap-0.5 px-3 py-2 text-left transition hover:bg-slate-50"
                            :class="item.read_at ? 'text-ink-500' : 'text-ink-900'"
                            type="button"
                            role="menuitem"
                            @click="openItem(item)"
                        >
                            <span class="text-sm leading-snug font-medium">{{ item.title }}</span>
                            <span v-if="item.document_number" class="text-[11px] text-ink-400">
                                {{ item.document_number }}
                            </span>
                        </button>
                    </li>
                </ul>
            </div>
        </Transition>
    </div>
</template>
