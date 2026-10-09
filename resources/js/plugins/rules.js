/**
 * Plain-language one-liners for the business-rule codes referenced by Card/FormField
 * `rule` props. The tooltip leads with the sentence; the code is the footnote.
 * Source of truth: docs/04-business-rules.md and the module docs.
 */
export const RULES = {
    'IM-1': 'An item is born draft and is activated only once its family, units, routing, bill of materials, wastage, warehouse and standard cost are in place.',
    'BR-1': 'Label prices are quoted per 1,000 pieces.',
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
    'BR-55': 'Every amount names the currency it is in — the document\u2019s own, or the base currency where the figure genuinely is one.',
    'BR-56': 'A letter of credit is drawn on only by purchase orders payable in the credit\u2019s own currency.',
    'BR-57': 'A receipt settles an invoice, and a payment a bill, only in the currency that document is raised in.',
    'BR-58': 'The exchange rate is booked by the server from the published reference rate, within tolerance \u2014 never defaulted to parity.',
    'BR-59': 'Stock is valued in the factory\u2019s currency: a receipt priced in the order\u2019s currency is converted at the order\u2019s snapshotted rate.',
    'S2': 'Any change after confirmation writes an amendment row with reason and user — no silent edits.',
    'S3': 'A line cannot be confirmed without a current spec and an approved artwork version.',
    'J1': 'Release needs approved artwork where the family uses it, a BOM, available tools, a machine on every operation, a QC plan, and material in stock or waived with a reason.',
    'CS-2': 'An order is costed again on the day it is confirmed and that sheet is locked as the benchmark; below the margin floor, only someone allowed to accept the margin may confirm. The closed job is costed from its actuals against that benchmark.',
    'J2': 'Operations run in routing sequence; the next one becomes ready only when its predecessor finishes.',
    'J3': 'Good plus waste quantity cannot exceed the quantity handed to the operation.',
    'J5': 'Produced quantity may not exceed planned x (1 + overrun tolerance) without supervisor approval.',
    'I1': 'Stock movements are never edited or deleted. A correction is a new, visible entry that reverses the wrong one.',
    'I3': 'A lot balance is the sum of its ledger rows; no stored balance is authoritative.',
    'I5': 'A lot carries the certification claim of the goods receipt it came from. Chain-of-custody checks rely on it.',
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
    '06-rbac §4': 'You see only the records of your own factory unit, your customers, or yourself, depending on your role.',
    '06-rbac §5': 'Who must approve depends on the value. The value bands are set under Configuration → Settings.',

    // Codes that screens referred to and this list did not have, so their marker opened on
    // "Enforced by an internal rule." (UX audit M-04). Kept in step by `tests/Js/rules.test.js`.
    'BR-2': 'Each kind of material is stocked in one base unit: yarn in kilograms, ribbon in metres, labels in pieces.',
    'BR-5': 'The number of labels across the web is worked out from the web width, the label width and the gaps.',
    'BR-6': 'Pitch, labels per metre and ends are worked out from the specification, not typed.',
    'BR-9': 'Only woven labels use yarn. Yarn weight is worked out from the fabric weight (GSM) and split between colours by their weights.',
    'BR-10': 'Ink use is worked out from the print coverage and the ink laid per square metre for each colour.',
    'BR-12': 'Packing materials (bundle bands, polybags, cartons) are worked out from the bundle size and the bundles per carton.',
    'BR-15': 'For a running programme, the cost of tooling is spread over the annual forecast quantity.',
    'BR-39': 'Stock is valued at weighted average cost including landed cost, and aged by how long it has been held.',
    'BR-48': 'Finished goods are costed from the material actually issued to the job.',
    'BR-49': 'A job card cannot plan more than its order line can still take, including the over-delivery allowance.',
    'BR-50': "A figure is shown in its own document's currency. Totals across currencies are converted at the rate each document recorded.",
    'BR-52': 'Finished goods cannot be received at zero cost unless the missing material issue is explained.',
    'D4': 'The delivery address comes from the order, so a delivery note cannot be pointed at a different customer.',
    'G4': 'Waste is booked with its cause, not only its quantity.',
    'I7': 'Every material the bill of materials calls for must have been issued, or the shortfall explained, before the job is completed.',
    'J6': 'A job card that already has production booked on it can only be cancelled with a recorded reason.',
    'P0-3': 'Finished goods enter stock through a receipt, and stay in quarantine until final QC accepts them.',
    'P0-4': 'Each fulfilment figure comes from its own document: ordered from the order, produced from job cards, delivered from delivery notes, invoiced from invoices.',
    'P1': 'A product belongs to one customer and stays with that customer.',
    'P1-3': 'A rejected lot raises a non-conformance report (NCR) and is held until a decision on it is recorded.',
    'PD-3': 'Only one bill of materials per product is active at a time.',
    'QC1': 'A step that needs a QC check holds the next step until its inspection is accepted.',
    '06-rbac §6': 'A floor operator signs in with a badge number and a PIN, for one shift.',
    'BR-4 … BR-13': 'The material plan is worked out from the specification: labels per metre, ends, yarn, ink, packing and tooling.',
    'BR-14 … BR-22': "A cost sheet adds material, conversion, tooling, overhead and margin in a fixed order, in the factory's own currency.",
    'PO ↔ GRN ↔ Bill': 'A supplier bill is checked against what was ordered and what was received before it can be approved.',
    'total = received + credited + outstanding': 'An invoice total always equals what has been received, plus what has been credited, plus what is still outstanding.',
    'billed = delivered': 'An invoice bills exactly the quantity that was delivered.',
    'computed, never typed': 'These figures are worked out by the system and cannot be typed.',
    'BR-17': 'Labour cost is the hours each step takes, times the people on it, times the labour rate.',
    'BR-19': 'Factory overhead is a percentage of material, tooling, machine, labour and energy cost; selling overhead is added on top.',
    'local-catalogue': 'A fixed quantity typed on the bill of materials, not worked out from the specification.',
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
