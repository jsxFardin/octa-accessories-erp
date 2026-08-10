<script setup>
import { onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';

/**
 * The action bar for a form — always docked, never hunted for.
 *
 * The buttons used to sit in the page header on some screens and inline at the bottom of the
 * flow on others, so the same task had two homes. Both sibling products put them in one place;
 * this is that place, and it carries the three things a form owes its user at the moment they
 * commit: whether anything changed, what will happen, and a way out.
 *
 * It also owns the guards that belong to an unsaved form: ⌘S, the browser reload prompt, and
 * the Inertia navigation prompt.
 */
const props = defineProps({
    /** An Inertia `useForm` instance. */
    form: { type: Object, required: true },
    label: { type: String, default: 'Save' },
    cancelHref: { type: String, default: null },
    /** Shown on the left — a document total, a line count, a warning. */
    summary: { type: String, default: null },
    disabled: { type: Boolean, default: false },
});

const emit = defineEmits(['save']);

function save() {
    if (!props.disabled && !props.form.processing) emit('save');
}

function onBeforeUnload(event) {
    if (!props.form.isDirty || props.form.processing) return;

    event.preventDefault();
    event.returnValue = '';
}

/** ⌘S / Ctrl-S: line-item entry is keyboard work, and committing it should be too. */
function onKeydown(event) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();

        if (props.form.isDirty) save();
    }
}

let stopRouterGuard = null;

onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);
    window.addEventListener('keydown', onKeydown);

    stopRouterGuard = router.on('before', (event) => {
        if (!props.form.isDirty || props.form.processing) return;

        // The form's own submit is a visit too; guarding it would ask permission to save.
        if ((event.detail.visit.method ?? 'get').toLowerCase() !== 'get') return;

        // Synchronous by necessity: Inertia decides then and there whether the visit proceeds
        // and cannot wait on a promise, so this is the one place the native dialog stays.
        if (!window.confirm('This form has unsaved changes. Leave and discard them?')) {
            event.preventDefault();
        }
    });
});

onUnmounted(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    window.removeEventListener('keydown', onKeydown);
    stopRouterGuard?.();
});
</script>

<template>
    <div class="sticky bottom-0 z-20 -mx-4 mt-4 border-t border-slate-200 bg-white/95 px-4 py-2.5 backdrop-blur print:hidden">
        <div class="flex flex-wrap items-center gap-3">
            <span v-if="form.isDirty" class="text-xs text-amber-700">Unsaved changes</span>
            <span v-else-if="summary" class="text-xs text-ink-500">{{ summary }}</span>

            <div class="ml-auto flex items-center gap-2">
                <Button v-if="cancelHref" :href="cancelHref">Cancel</Button>
                <Button
                    variant="primary"
                    :loading="form.processing"
                    :disabled="disabled"
                    @click="save"
                >
                    {{ label }}
                    <kbd class="ml-1 rounded border border-white/30 px-1 font-sans text-[10px] opacity-80">⌘S</kbd>
                </Button>
            </div>
        </div>
    </div>
</template>
