/**
 * Plain-language one-liners for the business-rule codes referenced by Card/FormField
 * `rule` props. The tooltip leads with the sentence; the code is the footnote.
 * Source of truth: docs/04-business-rules.md and the module docs.
 */
export const RULES = {
    'BR-1': 'Label prices are quoted per 1,000 pieces (/M).',
    'BR-4': 'Labels per metre follow from label height plus the cut gap for the cut type.',
    'BR-8': 'Wastage adds up across every routing operation that consumes the web.',
    'BR-13': 'A plate, screen or die is needed per colour; a tool with enough remaining life is reused at zero cost.',
    'BR-14': 'A cost sheet builds from typed lines: materials, tooling, machine, labour, energy, packing, overhead, margin.',
    'BR-16': 'Machine cost is machine hours — output at the standard rate, plus setup — times the hourly rate.',
    'BR-18': 'Energy cost is machine hours times the machine kW rating times the tariff per kWh.',
    'BR-20': 'The selling rate uses margin-on-price: unit cost x 1000 divided by (1 - margin %).',
    'BR-21': 'Orders below the customer minimum add a minimum-charge line and flag the quotation.',
    'BR-22': 'Costs are computed in BDT and converted at the rate effective on the quotation date, then snapshotted.',
    'BR-24': 'Net requirement is gross demand minus free stock and open POs; a positive result raises a shortage.',
    'BR-25': 'Suggested PO quantity respects the item minimum order quantity, rounded up to the order multiple.',
    'BR-26': 'Material must arrive safety days before the operation; the PO places by need date minus supplier lead time.',
    'BR-27': 'Machine load is checked against available minutes; scheduling past 100% needs a planner override with reason.',
    'BR-28': 'An order line splits into several job cards by lot size, delivery schedule, or colourway.',
    'BR-29': 'Promised date is the last operation finish plus QC, packing and transit days.',
    'BR-30': 'Final inspection samples per ISO 2859-1 AQL; defects at or above the reject number reject the lot.',
    'BR-31': 'DHU is defects found per 100 units inspected.',
    'BR-32': 'Lab results are judged against ISO test thresholds; stricter customer limits override the house defaults.',
    'BR-33': 'A rejected lot gets exactly one disposition — rework, concession, downgrade or scrap — before leaving QC.',
    'BR-36': 'Stock is valued at weighted average per item per warehouse, recomputed on every receipt.',
    'BR-37': 'Issues suggest lots FIFO by receipt date; same shade batch first for shade-critical items, override logged.',
    'BR-43': 'A shipment cannot claim a scheme whose certificate is expired on the shipment date.',
    'BR-44': 'Delivered quantity must stay within the tolerance band (default 5%); outside needs an override with reason.',
    'BR-46': 'If outstanding plus order value exceeds the credit limit, the order is held; Accounts or the MD release it.',
    'S2': 'Any change after confirmation writes an amendment row with reason and user — no silent edits.',
    'S3': 'A line cannot be confirmed without a current spec and an approved artwork version.',
    'J1': 'Release needs approved artwork, a BOM, available tools, and material in stock or waived with a reason.',
    'J2': 'Operations run in routing sequence; the next one becomes ready only when its predecessor finishes.',
    'J3': 'Good plus waste quantity cannot exceed the quantity handed to the operation.',
    'J5': 'Produced quantity may not exceed planned x (1 + overrun tolerance) without supervisor approval.',
    'I1': 'The stock ledger is append-only — corrections are visible reversing entries, never edits or deletes.',
    'I3': 'A lot balance is the sum of its ledger rows; no stored balance is authoritative.',
    'I5': 'A lot carries the certification claim inherited from its GRN line — the basis of CoC reconciliation.',
    'QC2': 'A rejected inspection blocks the FG receipt until a documented disposition is recorded.',
    'QC3': 'An issued test report is immutable; a correction is a new report referencing the original.',
    'QL-5': 'Each test verdict is computed against its threshold; any mandatory failure fails the overall result.',
    'QL-7': 'An NCR closes only with a root-caused CAPA and a separate effectiveness review.',
    'A2': 'At most one artwork version is approved at a time; approving a new one supersedes the previous.',
    'C1': 'Every certified output claim traces to certified input through an unbroken transaction chain.',
    'C3': 'CoC transactions are never edited after the reporting period closes.',
    'D1': "Every carton's contents must come from lots that passed final QC.",
    'D3': 'A challan cannot be issued for a packing list whose cartons total zero pieces.',
    'G6': 'Any carton traces to its lots and their GRNs in three clicks or fewer.',
    'P2': 'Exactly one product spec is current at a time; superseded specs are retained, never deleted.',
    'P3': 'A spec is immutable once a quotation, order or job card references it; changes create a new version.',
    'FN-1': 'Invoices are raised from delivered challans; issuing stamps the due date and makes the invoice immutable.',
    'DF-5': 'Delivery is confirmed with receiver name and signature; a failed stop needs a reason and requeues the challan.',
    'IN-4': 'Transfers move in two steps through a transit warehouse; a short receipt raises a variance to explain.',
    'AC4': 'Packing list totals — cartons, quantity, weights — are computed from the cartons, never typed.',
    'Gate 1': 'No job card releases to production without an approved artwork version.',
    'Gate 2': 'Output claims GRS/FSC certification only when the consumed lots carry the claim.',
    '06-rbac §4': 'What you see is scoped to your factory unit, customer or own records by a global query scope.',
    '06-rbac §5': 'Approvals route by value band; the thresholds live in settings and change without a deploy.',
};

/**
 * Resolve a rule reference like "BR-1 · BR-44" or "Gate 2 · I5" to its sentences.
 * Unknown codes resolve to nothing rather than guessing.
 * @returns {string[]} one sentence per known code, in reference order
 */
export function ruleSentences(reference) {
    return String(reference ?? '')
        .split('·')
        .map((part) => part.trim())
        .map((code) => RULES[code])
        .filter(Boolean);
}
