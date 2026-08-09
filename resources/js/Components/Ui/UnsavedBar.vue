<script setup>
import { onMounted, onUnmounted, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import Icon from '@/Components/Ui/Icon.vue';

/**
 * Two jobs, because they are the same job: tell the user the form is dirty, and stop the form
 * being lost.
 *
 * A twelve-line quotation represents ten minutes of typing. Until now a stray click on the
 * sidebar threw it away silently — Inertia navigations do not trigger `beforeunload`, so the
 * router hook is the one that actually matters here; the browser handler only covers reloads
 * and closing the tab.
 */
const props = defineProps({
    /** An Inertia `useForm` instance. */
    form: { type: Object, required: true },
    label: { type: String, default: 'Save changes' },
    /** Shown on the right of the bar — a document total, a line count, anything. */
    summary: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['save']);

function onBeforeUnload(event) {
    if (!props.form.isDirty || props.form.processing) return;

    event.preventDefault();
    // Browsers ignore custom text now, but a non-empty return is still what triggers the prompt.
    event.returnValue = '';
}

let stopRouterGuard = null;

onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);

    stopRouterGuard = router.on('before', (event) => {
        if (!props.form.isDirty || props.form.processing) return;

        // The form's own submit is a visit too; letting the guard catch it would make saving
        // ask permission to save.
        const method = (event.detail.visit.method ?? 'get').toLowerCase();

        if (method !== 'get') return;

        if (!window.confirm('This form has unsaved changes. Leave and discard them?')) {
            event.preventDefault();
        }
    });
});

onUnmounted(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    stopRouterGuard?.();
});

// A successful save clears the dirty flag; nothing else should.
watch(() => props.form.recentlySuccessful, (value) => {
    if (value) window.removeEventListener('beforeunload', onBeforeUnload);
});
</script>

<template>
    <Transition
        enter-active-class="transition duration-150"
        enter-from-class="translate-y-full opacity-0"
        leave-active-class="transition duration-100"
        leave-to-class="translate-y-full opacity-0"
    >
        <div
            v-if="form.isDirty"
            class="fixed inset-x-0 bottom-0 z-30 border-t border-slate-200 bg-white/95 backdrop-blur print:hidden"
        >
            <div class="mx-auto flex max-w-[1600px] items-center gap-3 px-4 py-2.5">
                <span class="flex items-center gap-1.5 text-xs text-amber-700">
                    <Icon name="bell" size="size-3.5" />
                    Unsaved changes
                </span>

                <span v-if="summary" class="hidden text-xs text-ink-500 sm:inline">{{ summary }}</span>

                <div class="ml-auto flex items-center gap-2">
                    <Button size="sm" :disabled="form.processing" @click="form.reset()">Discard</Button>
                    <Button
                        size="sm"
                        variant="primary"
                        :loading="form.processing"
                        :disabled="disabled"
                        @click="emit('save')"
                    >
                        {{ label }}
                    </Button>
                </div>
            </div>
        </div>
    </Transition>
</template>
