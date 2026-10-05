/**
 * Spreading one sum of money over several documents.
 *
 * A single cheque often settles several invoices, and a single transfer several supplier bills.
 * The receipt and payment dialogs took one document per entry, so one cheque for five invoices
 * was five entries. These are the sums behind the multi-document dialogs, kept apart from the
 * screens so they can be tested: money arithmetic that is only ever exercised by clicking is
 * money arithmetic nobody has checked.
 *
 * Everything is done in whole cents. Adding 0.1 and 0.2 in floating point does not give 0.3,
 * and a receipt that fails to allocate by a hundred-millionth of a taka is a support call.
 */
const cents = (value) => Math.round((Number(value) || 0) * 100);
const fromCents = (value) => (value / 100).toFixed(2);

/**
 * Oldest first: each document is settled in full, in the order given, until the money runs out.
 *
 * @param {number|string} amount  the money to spread
 * @param {Array<{id: number, outstanding: number|string}>} documents  oldest first
 * @returns {Record<number, string>} amount per document id, as "0.00" strings; documents the
 *   money did not reach are left out
 */
export function spreadOldestFirst(amount, documents) {
    let remaining = Math.max(0, cents(amount));
    const result = {};

    for (const document of documents) {
        if (remaining <= 0) break;

        const share = Math.min(remaining, Math.max(0, cents(document.outstanding)));

        if (share > 0) {
            result[document.id] = fromCents(share);
            remaining -= share;
        }
    }

    return result;
}

/** The total of what has been set against documents, as a number. */
export function allocatedTotal(amounts) {
    return Object.values(amounts).reduce((sum, value) => sum + cents(value), 0) / 100;
}

/**
 * What is wrong with a set of allocations, in words, or null when it can be posted.
 *
 * @param {number|string} amount
 * @param {Record<number, string|number>} amounts
 * @param {Array<{id: number, outstanding: number|string, label: string}>} documents
 */
export function allocationProblem(amount, amounts, documents) {
    const total = cents(amount);
    let allocated = 0;

    for (const document of documents) {
        const share = cents(amounts[document.id]);

        if (share < 0) return `${document.label}: the amount cannot be negative.`;
        if (share > cents(document.outstanding)) return `${document.label}: more than its outstanding balance.`;

        allocated += share;
    }

    if (allocated === 0) return 'Set the money against at least one document.';
    if (allocated > total) return `${fromCents(allocated - total)} more is set against documents than was received.`;

    return null;
}
