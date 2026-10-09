import { useConfirm } from '@/composables/useConfirm';

/**
 * One confirmation for document status changes.
 *
 * Every Show page posts `{ to }` at `/{resource}/{id}/transition`. The dialog in front of that
 * used to be generated from the status key alone — "Move to qc pending JC-0042?", a line about
 * the state machine, and a button that said "Continue" — which confirms that something will
 * happen without saying what. Each document now states its own consequence, in the words on
 * the button that opened the dialog.
 *
 *     const confirmTransition = useTransitionConfirm('physical_count');
 *     if (await confirmTransition('counting', count.number)) …
 *
 * A page can override any part for one call:
 *
 *     confirmTransition('posted', grn.number, { message: `Stock is added to ${warehouse}.` })
 *
 * `danger` is for what cannot be taken back: cancelling, rejecting, and anything that posts
 * stock or money.
 */
const CANCEL = { verb: 'Cancel', message: 'It is closed for good and cannot be reopened from this screen.', tone: 'danger', cancelLabel: 'Keep it' };

/** What applies when a document has no entry of its own for a status. */
const COMMON = {
    cancelled: CANCEL,
    rejected: { verb: 'Reject', message: 'It is closed and cannot be reopened from this screen.', tone: 'danger' },
    void: { verb: 'Void', message: 'This cannot be undone from this screen.', tone: 'danger' },
    voided: { verb: 'Void', message: 'This cannot be undone from this screen.', tone: 'danger' },
    confirmed: { verb: 'Confirm' },
    approved: { verb: 'Approve' },
    posted: { verb: 'Post', message: 'This cannot be undone from this screen.', tone: 'danger' },
    submitted: { verb: 'Submit' },
    closed: { verb: 'Close', message: 'Nothing more can be recorded against it once it is closed.' },
    released: { verb: 'Release' },
    issued: { verb: 'Issue' },
    dispatched: { verb: 'Dispatch' },
    received: { verb: 'Receive' },
    accepted: { verb: 'Accept' },
    completed: { verb: 'Complete' },
    draft: { verb: 'Return to draft', label: 'Return to draft', message: 'It can be edited again and will need to be submitted again.' },
};

const BY_DOCUMENT = {
    inquiry: {
        open: { verb: 'Submit', message: 'The inquiry is given its number and can then be quoted.' },
        cancelled: { ...CANCEL, message: 'Use this for an inquiry raised by mistake. It can no longer be quoted. If the customer simply went elsewhere, use "Mark lost" instead.' },
    },
    quotation: {
        sent: { verb: 'Mark as sent', title: (name) => `Mark ${name} as sent?`, label: 'Mark as sent', message: 'The quotation is given its number and its prices and exchange rate are fixed. It can no longer be edited — a change after this is a new revision. Nothing is emailed from here.' },
        accepted: { verb: 'Record acceptance', title: (name) => `Record that the customer accepted ${name}?`, label: 'Record acceptance', message: 'An accepted quotation can be converted to a sales order.' },
        revised: { verb: 'Revise', message: 'A new revision is opened for editing. This revision is kept as history and can no longer be sent or converted.' },
        cancelled: { ...CANCEL, message: 'The quotation can no longer be sent or converted to an order.' },
    },
    sales_order: {
        confirmed: { verb: 'Confirm', message: 'The order is checked for a current specification and approved artwork on every line, and against the customer\'s credit limit. Once confirmed, changing a quantity or date needs a reason.' },
        closed: { verb: 'Close', message: 'No more job cards, packing or delivery can be raised against the order.' },
        cancelled: { ...CANCEL, message: 'The order is withdrawn. Nothing more can be produced or delivered against it.' },
    },
    sales_return: {
        approved: { verb: 'Approve', message: 'The return is given its number. The goods are not back in stock until it is posted.' },
        posted: { verb: 'Post', label: 'Post return', message: 'The returned goods are put back into stock and a draft credit note is raised for their value. This cannot be undone.', tone: 'danger' },
        cancelled: { ...CANCEL, message: 'No goods are taken back and no credit is raised.' },
    },
    purchase_requisition: {
        submitted: { verb: 'Submit', label: 'Submit for approval', message: 'It goes to an approver and cannot be edited while it waits.' },
        approved: { verb: 'Approve', message: 'A purchase order or a request for quotation can then be raised from it.' },
        cancelled: { ...CANCEL, message: 'Nothing can be ordered against it.' },
    },
    rfq: {
        issued: { verb: 'Issue', label: 'Issue to suppliers', message: 'The request is given its number and its items are fixed. Supplier quotations can then be recorded.' },
        closed: { verb: 'Close', message: 'No more supplier quotations can be recorded against it.' },
        cancelled: { ...CANCEL, message: 'No purchase order can be raised from it.' },
    },
    purchase_order: {
        pending_approval: { verb: 'Submit', title: (name) => `Submit ${name} for approval?`, label: 'Submit for approval', message: 'It goes to the approver for its value and cannot be edited while it waits.' },
        approved: { verb: 'Approve', message: 'The order can then be sent to the supplier.' },
        sent: { verb: 'Mark as sent', title: (name) => `Mark ${name} as sent to the supplier?`, label: 'Mark as sent', message: 'The order is treated as placed: goods can be received against it and it can no longer be edited. Nothing is emailed from here.' },
        closed: { verb: 'Close', message: 'No more goods can be received against the order.' },
        cancelled: { ...CANCEL, message: 'Nothing more can be received or billed against the order.' },
    },
    supplier_bill: {
        approved: { verb: 'Approve', message: 'The bill becomes payable and counts toward what is owed to the supplier.' },
        cancelled: { ...CANCEL, message: 'The bill will not be paid.' },
    },
    stock_transfer: {
        in_transit: { verb: 'Send', title: (name) => `Send ${name}?`, label: 'Send transfer', message: 'The stock leaves the source warehouse now and is shown as in transit until it is received. This cannot be undone.', tone: 'danger' },
        received: { verb: 'Receive', label: 'Receive in full', message: 'The whole quantity is added to the destination warehouse. This cannot be undone.', tone: 'danger' },
        cancelled: { ...CANCEL, message: 'No stock moves.' },
    },
    stock_adjustment: {
        pending_approval: { verb: 'Submit', title: (name) => `Submit ${name} for approval?`, label: 'Submit for approval', message: 'It goes to an approver. Stock does not change until it is posted.' },
        posted: { verb: 'Post', label: 'Post adjustment', message: 'Stock changes by the quantities on this adjustment. This cannot be undone — a mistake is corrected with another adjustment.', tone: 'danger' },
        cancelled: { ...CANCEL, message: 'Stock does not change.' },
    },
    physical_count: {
        counting: { verb: 'Start counting', title: (name) => `Start counting ${name}?`, label: 'Start counting', message: 'Every available lot in this warehouse is frozen from now until the count is posted or cancelled. Nothing can be issued, transferred or adjusted from it in the meantime.', tone: 'danger', cancelLabel: 'Not yet' },
        reconciled: { verb: 'Reconcile', message: 'The counted quantities are compared with system stock and the differences are shown. Nothing is posted yet — if a figure turns out to be wrong, Recount takes you back to correct it.' },
        posted: { verb: 'Post', label: 'Post variances', message: 'Stock is changed to the counted quantities and the lots are released. This cannot be undone.', tone: 'danger' },
        cancelled: { ...CANCEL, message: 'The count is abandoned: the lots are released and no stock changes.' },
    },
    job_card: {
        planned: { verb: 'Mark as planned', title: (name) => `Mark ${name} as planned?`, label: 'Mark as planned', message: 'The card moves from draft to planned and can then be released to the floor.' },
        in_production: { verb: 'Resume', message: 'The card goes back to the floor and operators can book output against it again.' },
        qc_pending: { verb: 'Send to QC', title: (name) => `Send ${name} to quality control?`, label: 'Send to QC', message: 'Production stops on this card until an inspection is recorded.' },
        completed: { verb: 'Complete', message: 'Production is finished. No more output can be booked; the card still needs closing once finished goods are received.' },
        closed: { verb: 'Close', message: 'No more finished goods can be received against the card.' },
        cancelled: { ...CANCEL, message: 'Nothing more can be produced against the card.' },
    },
    packing_list: {
        packed: { verb: 'Confirm packed', title: (name) => `Confirm ${name} is packed?`, label: 'Confirm packed', message: 'The cartons and their contents are fixed, and a delivery note can be created from the list.' },
        cancelled: { ...CANCEL, message: 'The packed goods are released and no delivery note can be raised from it.' },
    },
    sales_invoice: {
        issued: { verb: 'Issue', label: 'Issue invoice', message: 'The invoice is given its number and counts toward what the customer owes. It can no longer be edited.' },
        cancelled: { ...CANCEL, message: 'The invoice no longer counts toward what the customer owes.' },
    },
    credit_note: {
        approved: { verb: 'Approve', message: 'The credit can then be applied to an invoice or refunded.' },
        applied: { verb: 'Apply', title: (name) => `Apply ${name} to its invoice?`, label: 'Apply to invoice', message: 'The whole credit is set against the invoice it was raised for, reducing what the customer owes on it. This cannot be undone.', tone: 'danger' },
        cancelled: { ...CANCEL, message: 'The credit is withdrawn and cannot be applied or refunded.' },
    },
    item: {
        active: { verb: 'Activate', message: 'It can then be bought, put on a bill of materials, quoted and ordered. A draft cannot.' },
        on_hold: { verb: 'Put on hold', title: (name) => `Put ${name} on hold?`, label: 'Put on hold', message: 'It stays readable on the documents that name it, but no new order, purchase or bill of materials may use it until it is made active again.' },
        discontinued: { verb: 'Discontinue', message: 'It is retired for good and cannot be brought back. Documents that already name it keep working.', tone: 'danger', cancelLabel: 'Keep it' },
    },
    test_report: {
        issued: { verb: 'Issue', label: 'Issue test report', message: 'The report is given its number and its results can no longer be changed.' },
        cancelled: { ...CANCEL, message: 'The report is withdrawn and cannot be issued.' },
    },
};

function readable(status) {
    return String(status).replaceAll('_', ' ');
}

/**
 * @param {string|null} document  key into the copy above, e.g. `purchase_order`
 */
export function useTransitionConfirm(document = null) {
    const { confirm } = useConfirm();

    /**
     * @param {string} to        target status, e.g. `cancelled`
     * @param {string|null} name document number for the title, e.g. `PO-0042`
     * @param {{title?: string, message?: string, confirmLabel?: string, cancelLabel?: string, tone?: string}} overrides
     * @returns {Promise<boolean>}
     */
    return function confirmTransition(to, name = null, overrides = {}) {
        const copy = BY_DOCUMENT[document]?.[to] ?? COMMON[to] ?? {};
        const subject = name ?? 'this document';
        const verb = copy.verb ?? null;

        return confirm({
            title: overrides.title
                ?? copy.title?.(subject)
                ?? (verb ? `${verb} ${subject}?` : `Change ${subject} to ${readable(to)}?`),
            message: overrides.message ?? copy.message ?? '',
            confirmLabel: overrides.confirmLabel ?? copy.label ?? verb ?? 'Change status',
            // Cancelling a document put "Cancel" on both buttons — the destructive one and the
            // safe one — on every screen that can cancel anything.
            cancelLabel: overrides.cancelLabel ?? copy.cancelLabel ?? 'Back',
            tone: overrides.tone ?? copy.tone ?? 'default',
        });
    };
}
