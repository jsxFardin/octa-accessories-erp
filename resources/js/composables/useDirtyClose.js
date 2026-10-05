import { ref } from 'vue';
import { useConfirm } from '@/composables/useConfirm';

/**
 * The close rules shared by every overlay that can hold a form.
 *
 * A modal or slide-over used to shut on any backdrop click or Escape, whatever had been typed
 * into it — a half-written user, a role's permission matrix, an override reason, gone on a
 * stray click. Now an overlay that has been typed into:
 *
 *  - ignores a click on the backdrop altogether (the commonest accident), and
 *  - asks before closing on Escape or the close button.
 *
 * "Typed into" is the page's `dirty` prop when it passes one, and otherwise any `input` or
 * `change` event from inside the panel since it opened — so the fifty dialogs that never
 * passed a prop are covered too. Options picked from a `SelectInput` are rendered outside the
 * panel and are not seen by that listener; a dialog made mostly of selects should pass `dirty`.
 *
 * A page that closes the overlay itself (`open = false` after a save, or its own Cancel button)
 * is not second-guessed: that is a decision, not an accident.
 *
 * @param {() => boolean} isOpen
 * @param {() => boolean} isDirtyProp  the explicit `dirty` prop
 * @param {() => void} close
 */
export function useDirtyClose(isOpen, isDirtyProp, close) {
    const { confirm } = useConfirm();
    const touched = ref(false);

    function markTouched() {
        touched.value = true;
    }

    function reset() {
        touched.value = false;
    }

    function isDirty() {
        return Boolean(isDirtyProp()) || touched.value;
    }

    /** Escape and the close button: ask first when there is something to lose. */
    async function requestClose() {
        if (!isOpen()) return;

        if (!isDirty()) {
            close();

            return;
        }

        const discard = await confirm({
            title: 'Discard what you entered?',
            message: 'This form has changes that have not been saved.',
            confirmLabel: 'Discard',
            cancelLabel: 'Keep editing',
            tone: 'danger',
        });

        if (discard) close();
    }

    /** A backdrop click closes an untouched overlay and is ignored on a touched one. */
    function onBackdrop() {
        if (!isDirty()) close();
    }

    return { markTouched, reset, requestClose, onBackdrop };
}
