/**
 * What a document offers as its next step, decided once instead of in a chain of `v-if`s.
 *
 * These are the questions the audit found the screens answering differently from the server:
 * an accepted quotation kept offering "Convert to order" after it had already become one, and
 * a delivery note listed its customer as "—" while the row underneath had a customer id on it.
 *
 * Nothing here is a permission check on its own and nothing here is a rule. The server owns
 * both — `QuotationConversionService` decides whether a quotation converts and refuses the
 * POST regardless of what this returns. This is what the screen *shows*, kept in a module so
 * it can be tested and so it cannot drift into a different answer from the one the server
 * sent down in `conversion`.
 */

/**
 * The primary action for a quotation's conversion.
 *
 * @param {{status?: string}} quotation
 * @param {{convertible?: boolean, refusal?: string|null, live_orders?: Array<{id: number, number: string|null, status: string}>}} conversion
 * @param {(permission: string) => boolean} can
 * @returns {{kind: 'convert'|'view-order'|'already-converted'|'none', href?: string, label?: string, title?: string|null, order?: object}}
 */
export function conversionAction(quotation, conversion, can) {
    const state = conversion ?? {};
    const orders = state.live_orders ?? [];
    const mayCreate = can('sales_order.create');

    if (mayCreate && state.convertible) {
        return { kind: 'convert', label: 'Convert to order' };
    }

    // Already converted: send them to the order rather than to a dead button. This is offered
    // whatever their permissions, because reading the order they already have is not the same
    // right as raising a new one.
    if (orders.length > 0) {
        return {
            kind: 'view-order',
            label: orders.length > 1 ? 'View sales orders' : 'View sales order',
            href: `/sales-orders/${orders[0].id}`,
            order: orders[0],
        };
    }

    // Accepted, no order, still not convertible — the server has a reason and it belongs on
    // the disabled control rather than being discovered by pressing it.
    if (mayCreate && quotation?.status === 'accepted') {
        return { kind: 'already-converted', label: 'Cannot convert', title: state.refusal ?? null };
    }

    return { kind: 'none' };
}

/**
 * How a delivery note names its destination on a list row.
 *
 * `—` was every row's answer: the page was handed raw models and the customer relation was
 * never loaded. A missing destination is now said out loud rather than rendered as an
 * em dash, because a delivery note without one cannot be issued (D4).
 *
 * @param {{customer?: {name?: string}|null, destination?: string|null}} challan
 * @returns {{customer: string, destination: string, complete: boolean}}
 */
export function consigneeSummary(challan) {
    const customer = challan?.customer?.name ?? null;
    const destination = challan?.destination ?? null;

    return {
        customer: customer ?? 'No customer',
        destination: destination ?? 'No address',
        complete: Boolean(customer && destination),
    };
}

/**
 * A job-card operation quantity with the unit it was counted in.
 *
 * Weaving books metres and packing books pieces. Printed as a bare column of numbers they
 * invite the reader to add them up, which is how "60,457 good against 30,000 planned" reached
 * a screen for a job that made exactly 30,000 labels.
 *
 * @param {number|string|null} value
 * @param {string} unit
 * @param {(value: unknown) => string} format
 */
export function operationQuantity(value, unit, format) {
    return `${format(value)} ${unit ?? ''}`.trim();
}
