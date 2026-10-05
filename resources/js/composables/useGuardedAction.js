import { ref } from 'vue';
import { useConfirm } from '@/composables/useConfirm';

/**
 * An action that cannot be taken back: asked about first, and not fired twice.
 *
 * Cancelling a delivery note, closing a letter of credit, removing a packed carton — these
 * fired straight from `@click`. One slip did the thing, and a double-click could do it twice
 * (two invoices from one delivery note). This is the two guards together, so no page has to
 * remember both:
 *
 *     const { busy, run } = useGuardedAction();
 *
 *     run('cancel', {
 *         title: `Cancel ${note.number}?`,
 *         message: 'Nothing can be delivered against it afterwards.',
 *         confirmLabel: 'Cancel delivery note',
 *         tone: 'danger',
 *     }, (done) => router.post(url, data, { preserveScroll: true, ...done }));
 *
 *     <Button :loading="busy === 'cancel'" :disabled="busy !== null" …>
 *
 * `done` carries the `onFinish` that releases the lock; spread it into the visit options.
 * Pass `null` for the confirmation to get the lock alone.
 */
export function useGuardedAction() {
    const { confirm } = useConfirm();
    /** The key of the action in flight, or null. */
    const busy = ref(null);

    async function run(key, confirmation, action) {
        if (busy.value !== null) return;

        if (confirmation) {
            const agreed = await confirm({ cancelLabel: 'Back', tone: 'default', ...confirmation });

            if (!agreed) return;
        }

        // Checked again: the dialog was open for as long as the user took to answer.
        if (busy.value !== null) return;

        busy.value = key;

        const release = () => {
            if (busy.value === key) busy.value = null;
        };

        try {
            action({ onFinish: release });
        } catch (error) {
            release();

            throw error;
        }
    }

    return { busy, run };
}
