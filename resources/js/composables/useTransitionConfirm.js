import { useConfirm } from '@/composables/useConfirm';

/**
 * One confirmation for document state transitions.
 *
 * Every Show page posts `{ to }` at `/{resource}/{id}/transition`, and until this existed all
 * fifteen of them fired straight from `@click` — cancelling an approved purchase order was one
 * misclick with no dialog, while archiving a customer (recoverable) asked first. Destructive
 * targets get the danger treatment; everything else gets a plain confirm so a slip on
 * "Approve" is still catchable.
 */
const DESTRUCTIVE = new Set(['cancelled', 'rejected', 'void', 'voided']);

const VERBS = {
    cancelled: 'Cancel',
    rejected: 'Reject',
    void: 'Void',
    voided: 'Void',
    confirmed: 'Confirm',
    approved: 'Approve',
    posted: 'Post',
    submitted: 'Submit',
    closed: 'Close',
    released: 'Release',
    issued: 'Issue',
    dispatched: 'Dispatch',
    received: 'Receive',
    accepted: 'Accept',
    completed: 'Complete',
};

export function useTransitionConfirm() {
    const { confirm } = useConfirm();

    /**
     * @param {string} to        target status, e.g. `cancelled`
     * @param {string|null} name document number for the title, e.g. `PO-0042`
     * @returns {Promise<boolean>}
     */
    return function confirmTransition(to, name = null) {
        const destructive = DESTRUCTIVE.has(to);
        const verb = VERBS[to] ?? `Move to ${String(to).replaceAll('_', ' ')}`;

        return confirm({
            title: `${verb} ${name ?? 'this document'}?`,
            message: destructive
                ? 'This cannot be undone from this screen.'
                : 'The state machine will still apply its own checks.',
            confirmLabel: verb.startsWith('Move to') ? 'Continue' : verb,
            tone: destructive ? 'danger' : 'default',
        });
    };
}
