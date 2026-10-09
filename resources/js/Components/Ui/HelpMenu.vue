<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '@/Components/Ui/Icon.vue';
import { can, canAny } from '@/plugins/permissions';

/**
 * The one place that says how the system is driven.
 *
 * Four keyboard shortcuts existed and none was listed anywhere; a hundred hints lived in
 * `title` attributes a finger never sees. This menu names the shortcuts, says where the
 * explanations are, and gives the person someone to ask. It opens from a "?" that sits at
 * every width, like the search and the bell beside it.
 */
const page = usePage();
const open = ref(false);
const organisation = computed(() => page.props.app ?? {});

const mac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform ?? '');
const mod = mac ? '⌘' : 'Ctrl';

const shortcuts = [
    { keys: [`${mod} K`, '/'], does: 'Search any screen, record or setting' },
    { keys: [`${mod} S`], does: 'Save the form you are on' },
    { keys: [`${mod} B`], does: 'Collapse the sidebar to icons' },
    { keys: [`${mod} click`], does: 'Open a row in a new tab' },
    { keys: ['Esc'], does: 'Close a dialog, menu or the search' },
];

const starts = computed(() => [
    { label: 'Getting the system ready', href: '/dashboard', show: canAny('report.dashboard', 'report.view_any') },
    { label: 'Lists the forms choose from', href: '/setup', show: can('reference_data.view_any') },
    { label: 'Company details and formats', href: '/admin/settings', show: can('setting.view_any') },
    { label: 'Who can do what', href: '/admin/roles', show: can('role.view_any') },
].filter((item) => item.show));

function onDocumentClick(event) {
    if (!event.target.closest('[data-help-menu]')) open.value = false;
}

function onKeydown(event) {
    if (event.key === 'Escape') open.value = false;
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
    <div class="relative" data-help-menu>
        <button
            class="rounded-md p-1.5 text-ink-400 transition hover:bg-slate-100 hover:text-ink-700"
            :aria-expanded="open"
            aria-haspopup="menu"
            aria-label="Help"
            title="Help and keyboard shortcuts"
            type="button"
            @click="open = !open"
        >
            <Icon name="help" />
        </button>

        <Transition
            enter-active-class="transition duration-100"
            enter-from-class="opacity-0 scale-95"
            leave-active-class="transition duration-75"
            leave-to-class="opacity-0 scale-95"
        >
            <!-- Sized like the notification panel, for the same reason: it must land on-screen on a phone. -->
            <div
                v-if="open"
                class="absolute right-0 z-50 mt-2 w-[calc(100vw_-_7.5rem)] origin-top-right rounded-lg border border-slate-200 bg-white shadow-lg sm:w-80"
                role="menu"
            >
                <div class="border-b border-slate-100 px-3 py-2">
                    <p class="text-sm font-semibold text-ink-900">Help</p>
                </div>

                <section class="px-3 py-2.5">
                    <h3 class="text-xs font-semibold text-ink-700">Keyboard</h3>
                    <dl class="mt-1.5 space-y-1.5">
                        <div v-for="shortcut in shortcuts" :key="shortcut.does" class="flex items-start justify-between gap-3 text-sm">
                            <dt class="text-ink-700">{{ shortcut.does }}</dt>
                            <dd class="flex shrink-0 gap-1">
                                <kbd
                                    v-for="key in shortcut.keys"
                                    :key="key"
                                    class="rounded border border-slate-200 bg-slate-50 px-1.5 py-0.5 text-xs text-ink-700"
                                >{{ key }}</kbd>
                            </dd>
                        </div>
                    </dl>
                </section>

                <section class="border-t border-slate-100 px-3 py-2.5">
                    <h3 class="text-xs font-semibold text-ink-700">Reading a screen</h3>
                    <p class="mt-1 text-sm text-ink-700">
                        The <Icon name="info" size="size-3.5" class="inline align-text-bottom text-ink-500" /> beside a title explains the rule
                        that screen follows. Tap or hover it. A greyed button says why it is off when you point at it.
                    </p>
                </section>

                <section v-if="starts.length" class="border-t border-slate-100 px-3 py-2.5">
                    <h3 class="text-xs font-semibold text-ink-700">Where things are set up</h3>
                    <ul class="mt-1 space-y-0.5">
                        <li v-for="item in starts" :key="item.href">
                            <Link :href="item.href" class="doc-link-quiet text-sm" role="menuitem" @click="open = false">{{ item.label }}</Link>
                        </li>
                    </ul>
                </section>

                <section class="border-t border-slate-100 px-3 py-2.5">
                    <h3 class="text-xs font-semibold text-ink-700">Stuck?</h3>
                    <p class="mt-1 text-sm text-ink-700">
                        Ask your administrator; they set up access and the lists.
                        <template v-if="organisation.email">
                            Or write to
                            <a :href="`mailto:${organisation.email}`" class="doc-link-quiet">{{ organisation.email }}</a>.
                        </template>
                    </p>
                </section>
            </div>
        </Transition>
    </div>
</template>
