<script setup>
import { computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import Button from '@/Components/Ui/Button.vue';
import { useConfirm } from '@/composables/useConfirm';

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
    /**
     * Why the save button is disabled, in words.
     *
     * A control that refuses without saying why is a dead end, and for a screen-reader user it
     * is a button that simply is not there. It is also the reason a form's own validation
     * message used to disappear at the worst moment: `summary` was overwritten by "Unsaved
     * changes" the instant the user typed, which is precisely when the reason mattered.
     */
    disabledReason: { type: String, default: null },
});

const emit = defineEmits(['save']);

/**
 * Everything the server refused, said once, next to the button that was pressed.
 *
 * Field errors appear beside their fields, but the field may be three screens up, inside a
 * collapsed card, or — on a hand-built table — have no cell of its own at all. The footer is
 * where the user is looking when a save comes back refused, so the full list is repeated here.
 */
const errorMessages = computed(() => [...new Set(Object.values(props.form?.errors ?? {}).flat().filter(Boolean))]);

function save() {
    if (!props.disabled && !props.form?.processing) emit('save');
}

function onBeforeUnload(event) {
    if (!props.form?.isDirty || props.form?.processing) return;

    event.preventDefault();
    event.returnValue = '';
}

/** ⌘S / Ctrl-S: line-item entry is keyboard work, and committing it should be too. */
function onKeydown(event) {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();

        if (props.form?.isDirty) save();
    }
}

/**
 * The key the save shortcut is actually on. The hint read "⌘S" for everyone, which on the
 * Windows desks this runs on names a key the keyboard does not have.
 */
const isMac = typeof navigator !== 'undefined' && /Mac|iPhone|iPad/.test(navigator.platform ?? '');
const shortcutHint = isMac ? '⌘S' : 'Ctrl S';

const { confirm } = useConfirm();

let stopRouterGuard = null;
/** Set once the user has agreed to leave, so the visit re-issued below is let through. */
let leaving = false;

onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);
    window.addEventListener('keydown', onKeydown);

    stopRouterGuard = router.on('before', (event) => {
        if (!props.form?.isDirty || props.form?.processing) return;

        // The form's own submit is a visit too; guarding it would ask permission to save.
        if ((event.detail.visit.method ?? 'get').toLowerCase() !== 'get') return;

        if (leaving) return;

        // A visit that keeps this page's state is not leaving the form: it is the form asking
        // the server for something — the lines of the order just chosen, an invoice's
        // returnable balance. Asking "leave without saving?" there would be asking permission
        // to carry on filling the form in.
        if (event.detail.visit.preserveState === true) return;

        // Inertia decides here and now whether the visit goes ahead and cannot wait on a
        // promise. So the visit is stopped, the question is asked in the application's own
        // dialog, and on "leave" the same visit is issued again with the guard stood down.
        // This used to be `window.confirm`, the one native dialog left in the application.
        event.preventDefault();

        const visit = event.detail.visit;

        confirm({
            title: 'Leave without saving?',
            message: 'This form has changes that have not been saved. They will be lost.',
            confirmLabel: 'Leave',
            cancelLabel: 'Keep editing',
            tone: 'danger',
        }).then((leave) => {
            if (!leave) return;

            leaving = true;
            router.visit(visit.url, {
                method: visit.method,
                data: visit.data,
                replace: visit.replace,
                preserveScroll: visit.preserveScroll,
                preserveState: visit.preserveState,
                only: visit.only,
                onFinish: () => { leaving = false; },
            });
        });
    });
});

onUnmounted(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    window.removeEventListener('keydown', onKeydown);
    stopRouterGuard?.();
});
</script>

<template>
    <!-- `mt-auto` inside the form column's flex layout: docked to the foot of the window on a
         short form, and still sticky while a long one scrolls past. -->
    <div class="sticky bottom-0 z-20 -mx-4 mt-auto border-t border-slate-200 bg-white/95 px-4 pt-2.5 pb-2.5 backdrop-blur print:hidden">
        <div
            v-if="errorMessages.length"
            role="alert"
            class="mb-2 max-h-32 overflow-y-auto rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-800"
        >
            <p class="font-medium">
                {{ errorMessages.length === 1 ? 'Not saved — one thing to fix:' : `Not saved — ${errorMessages.length} things to fix:` }}
            </p>
            <ul class="mt-1 list-disc space-y-0.5 pl-4">
                <li v-for="message in errorMessages" :key="message">{{ message }}</li>
            </ul>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <!-- The blocking reason outranks both: it is the thing standing between the user
                 and the button they are trying to press. -->
            <span v-if="disabled && disabledReason" id="form-footer-reason" class="text-xs text-rose-700">{{ disabledReason }}</span>
            <span v-else-if="form?.isDirty" class="text-xs text-amber-700">Unsaved changes</span>
            <span v-else-if="summary" class="text-xs text-ink-500">{{ summary }}</span>

            <div class="ml-auto flex items-center gap-2">
                <Button v-if="cancelHref" :href="cancelHref">Cancel</Button>
                <Button
                    variant="primary"
                    :loading="form?.processing"
                    :disabled="disabled"
                    :title="disabled ? (disabledReason ?? 'Complete the form before saving.') : null"
                    :aria-describedby="disabled && disabledReason ? 'form-footer-reason' : null"
                    @click="save"
                >
                    {{ label }}
                    <kbd class="ml-1 rounded border border-white/30 px-1 font-sans text-[10px] opacity-80">{{ shortcutHint }}</kbd>
                </Button>
            </div>
        </div>
    </div>
</template>
