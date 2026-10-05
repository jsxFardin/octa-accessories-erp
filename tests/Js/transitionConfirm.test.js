import { describe, expect, it } from 'vitest';
import { useConfirm } from '../../resources/js/composables/useConfirm.js';
import { useTransitionConfirm } from '../../resources/js/composables/useTransitionConfirm.js';

const { state, answer } = useConfirm();

/** Opens the dialog, reads what it would show, and dismisses it. */
function shown(document, to, name, overrides) {
    useTransitionConfirm(document)(to, name, overrides);

    const copy = { title: state.title, message: state.message, confirm: state.confirmLabel, cancel: state.cancelLabel, tone: state.tone };

    answer(false);

    return copy;
}

describe('useTransitionConfirm', () => {
    // UX audit H-28: "Start counting" froze a whole warehouse behind a dialog that did not say so.
    it('says that starting a count freezes the warehouse', () => {
        const copy = shown('physical_count', 'counting', 'PC-26-00001');

        expect(copy.title).toBe('Start counting PC-26-00001?');
        expect(copy.message).toMatch(/frozen/);
        expect(copy.confirm).toBe('Start counting');
    });

    it('marks stock postings as destructive', () => {
        expect(shown('stock_adjustment', 'posted', 'ADJ-1').tone).toBe('danger');
        expect(shown('stock_transfer', 'in_transit', 'STR-1').tone).toBe('danger');
        expect(shown('physical_count', 'posted', 'PC-1').tone).toBe('danger');
        expect(shown('sales_return', 'posted', 'SR-1').tone).toBe('danger');
    });

    it('never puts Cancel on both buttons', () => {
        const copy = shown('purchase_order', 'cancelled', 'PO-1');

        expect(copy.confirm).toBe('Cancel');
        expect(copy.cancel).toBe('Keep it');
    });

    it('falls back to readable words for a status it has no copy for', () => {
        const copy = shown('purchase_order', 'partially_received', 'PO-1');

        expect(copy.title).toBe('Change PO-1 to partially received?');
        expect(copy.confirm).toBe('Change status');
    });

    it('lets one call override any part', () => {
        const copy = shown('stock_adjustment', 'posted', 'ADJ-1', { message: 'Custom consequence.', confirmLabel: 'Do it' });

        expect(copy.message).toBe('Custom consequence.');
        expect(copy.confirm).toBe('Do it');
        expect(copy.tone).toBe('danger');
    });

    it('keeps implementation wording out of every dialog', () => {
        const documents = ['inquiry', 'quotation', 'sales_order', 'sales_return', 'purchase_requisition', 'rfq', 'purchase_order', 'supplier_bill', 'stock_transfer', 'stock_adjustment', 'physical_count', 'job_card', 'packing_list', 'sales_invoice', 'credit_note', 'test_report', null];
        const statuses = ['open', 'sent', 'accepted', 'revised', 'confirmed', 'closed', 'cancelled', 'approved', 'posted', 'submitted', 'issued', 'pending_approval', 'in_transit', 'received', 'counting', 'reconciled', 'planned', 'in_production', 'qc_pending', 'completed', 'packed', 'applied', 'draft', 'something_new'];

        for (const document of documents) {
            for (const status of statuses) {
                const copy = shown(document, status, 'DOC-1');

                expect(`${copy.title} ${copy.message} ${copy.confirm}`).not.toMatch(/state machine|_|Continue/);
            }
        }
    });
});
