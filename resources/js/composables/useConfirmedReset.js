import { watch } from 'vue';
import { useConfirm } from '@/composables/useConfirm';

/**
 * A header choice that the lines below depend on.
 *
 * On a material issue, a transfer or an adjustment, the lots on the lines belong to the store
 * named in the header, so changing the store has to drop them. It used to do that silently:
 * picked lots vanished the moment the dropdown changed. Now, when there is work to lose, the
 * change is asked about first, and declining puts the header back as it was.
 *
 * @param {() => any[]} source      the header values the lines depend on
 * @param {() => boolean} hasWork   whether anything would be lost
 * @param {(now: any[], before: any[]) => void} apply    what the change does
 * @param {(before: any[]) => void} restore              puts the header back
 * @param {object} copy             dialog wording
 */
export function useConfirmedReset(source, hasWork, apply, restore, copy) {
    const { confirm } = useConfirm();
    /** Set while the header is being put back, so that restoring is not itself asked about. */
    let restoring = false;

    watch(source, async (now, before) => {
        if (restoring) {
            restoring = false;

            return;
        }

        if (hasWork()) {
            const agreed = await confirm({
                confirmLabel: 'Change and clear the lines',
                cancelLabel: 'Keep the lines',
                tone: 'danger',
                ...copy,
            });

            if (!agreed) {
                restoring = true;
                restore(before);

                return;
            }
        }

        apply(now, before);
    });
}
