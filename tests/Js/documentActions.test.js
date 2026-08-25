import { describe, expect, it } from 'vitest';
import { consigneeSummary, conversionAction, operationQuantity } from '../../resources/js/plugins/documentActions.js';

const all = () => true;
const none = () => false;

describe('quotation conversion action', () => {
    it('offers the conversion while the server says it is still available', () => {
        const action = conversionAction(
            { status: 'accepted' },
            { convertible: true, refusal: null, live_orders: [] },
            all,
        );

        expect(action.kind).toBe('convert');
        expect(action.label).toBe('Convert to order');
    });

    it('offers the order instead once the quotation has been converted', () => {
        // The regression this exists for: QTN-26-00049 displayed "Converted to SO-26-00027"
        // and, beside it, an actionable "Convert to order" that created a second draft order.
        const action = conversionAction(
            { status: 'accepted' },
            {
                convertible: false,
                refusal: 'This quotation has already been converted to SO-26-00027.',
                live_orders: [{ id: 27, number: 'SO-26-00027', status: 'confirmed' }],
            },
            all,
        );

        expect(action.kind).toBe('view-order');
        expect(action.label).toBe('View sales order');
        expect(action.href).toBe('/sales-orders/27');
    });

    it('never offers a convert action to someone who may not raise orders', () => {
        const action = conversionAction(
            { status: 'accepted' },
            { convertible: true, refusal: null, live_orders: [] },
            none,
        );

        expect(action.kind).toBe('none');
    });

    it('still shows a read-only route to the order to someone who may not raise one', () => {
        // Reading the order that exists is not the same right as creating another.
        const action = conversionAction(
            { status: 'accepted' },
            { convertible: false, live_orders: [{ id: 27, number: 'SO-26-00027', status: 'confirmed' }] },
            none,
        );

        expect(action.kind).toBe('view-order');
    });

    it('carries the server refusal onto a disabled control rather than hiding it', () => {
        const action = conversionAction(
            { status: 'accepted' },
            { convertible: false, refusal: 'Q5: something else is wrong.', live_orders: [] },
            all,
        );

        expect(action.kind).toBe('already-converted');
        expect(action.title).toBe('Q5: something else is wrong.');
    });

    it('offers nothing on a quotation that has not been accepted', () => {
        for (const status of ['draft', 'sent', 'rejected', 'expired', 'revised', 'cancelled']) {
            expect(conversionAction({ status }, { convertible: false, live_orders: [] }, all).kind)
                .toBe('none');
        }
    });

    it('pluralises when the domain legitimately allows more than one order', () => {
        const action = conversionAction(
            { status: 'accepted' },
            {
                convertible: false,
                live_orders: [
                    { id: 41, number: 'SO-26-00041', status: 'confirmed' },
                    { id: 27, number: 'SO-26-00027', status: 'cancelled' },
                ],
            },
            all,
        );

        expect(action.label).toBe('View sales orders');
        expect(action.href).toBe('/sales-orders/41');
    });

    it('survives a page that was sent no conversion state at all', () => {
        expect(conversionAction({ status: 'accepted' }, undefined, all).kind).toBe('already-converted');
    });
});

describe('delivery note consignee', () => {
    it('names the customer and the destination', () => {
        const summary = consigneeSummary({
            customer: { id: 12, name: 'Nordic Apparel Ltd' },
            destination: 'Head office · Dhaka',
        });

        expect(summary).toEqual({
            customer: 'Nordic Apparel Ltd',
            destination: 'Head office · Dhaka',
            complete: true,
        });
    });

    it('says a missing customer out loud rather than printing an em dash', () => {
        // All seven delivery notes read "Customer —", which looked like data loss and was in
        // fact an unloaded relation. Either way the row has to say what is wrong.
        const summary = consigneeSummary({ customer: null, destination: null });

        expect(summary.customer).toBe('No customer');
        expect(summary.destination).toBe('No address');
        expect(summary.complete).toBe(false);
    });

    it('is incomplete when the customer is known but the address is not', () => {
        const summary = consigneeSummary({ customer: { name: 'Nordic Apparel Ltd' }, destination: null });

        expect(summary.complete).toBe(false);
    });
});

describe('operation quantities', () => {
    const format = (value) => Number(value).toLocaleString('en-US');

    it('states the unit beside every operation figure', () => {
        // 407 and 30,000 are not two points on the same scale: one is metres of woven web,
        // the other is finished labels. Adding them gave 60,457 against a plan of 30,000.
        expect(operationQuantity(407, 'm', format)).toBe('407 m');
        expect(operationQuantity(30000, 'pcs', format)).toBe('30,000 pcs');
    });

    it('does not print a stray space when the unit is unknown', () => {
        expect(operationQuantity(12, null, format)).toBe('12');
    });
});
