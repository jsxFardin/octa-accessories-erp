import { reactive } from 'vue';

/**
 * One confirmation dialog for the whole application.
 *
 * `window.confirm` cannot be styled, cannot be translated, and on the Bangla locale renders in
 * whatever language the browser chrome happens to be — which for a store keeper deleting a lot
 * is the wrong moment to be guessing. Destructive actions go through this instead.
 *
 *     if (await confirm({ title: 'Delete this bin?', tone: 'danger' })) …
 */
const state = reactive({
    open: false,
    title: '',
    message: '',
    confirmLabel: 'Confirm',
    cancelLabel: 'Cancel',
    tone: 'danger',
    resolve: null,
});

export function useConfirm() {
    function confirm(options = {}) {
        // A dialog already waiting is answered "no" rather than left dangling with no resolver.
        state.resolve?.(false);

        Object.assign(state, {
            open: true,
            title: options.title ?? 'Are you sure?',
            message: options.message ?? '',
            confirmLabel: options.confirmLabel ?? 'Confirm',
            cancelLabel: options.cancelLabel ?? 'Cancel',
            tone: options.tone ?? 'danger',
        });

        return new Promise((resolve) => {
            state.resolve = resolve;
        });
    }

    function answer(value) {
        state.open = false;
        state.resolve?.(value);
        state.resolve = null;
    }

    return { state, confirm, answer };
}
