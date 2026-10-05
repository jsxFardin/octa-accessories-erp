# Copy changes, Phase 3 workstream 1

What the screens said and what they say now. Covers findings M-03 to M-07, L-06, L-07, L-09 and M-54 of `docs/UX_AUDIT.md`, plus a sweep for the wording the UI kit bans (internal terms, rule codes outside the rule tooltip, raw status and permission keys).

## Changes made once, that apply everywhere

| Where | Before | Now |
|---|---|---|
| Every banner and every message under a field | Carried the rule number: `J3: output 5200 exceeds…`, `… (P1-1 · QC1)`, `BR-5: a web width is needed…` | The sentence without the number: “Output 5200 exceeds…”. The number stays in the rule tooltip, the logs and the floor API. |
| A status change that is not allowed | `SalesReturn cannot move from [posted] to [cancelled].` | “Sales return is posted, so it cannot be cancelled.” Each status has its own verb (“marked as sent”, “put on hold”); one without a verb reads “cannot be moved to Verified”. |
| A missing permission | `You do not have the [job_card.close] permission.` | “You do not have permission to close a job card. Ask an administrator to give your role that permission.” Lists read “see the list of invoices”. |
| 37 server messages that named a status | `moved to pending_approval`, `is in_transit`, `status [qc_pending]` | “is now pending approval”, “is in transit”, “QC pending” |
| Status badges, filters and menus | `Qc Pending`, `Po`, `Tt`, `Da`, `Dp` | `QC Pending`, `PO`, `TT`, `DA`, `DP` |
| Rule tooltips (the small “i” markers) | 31 markers opened on “Enforced by an internal rule.” | Each has a sentence. A test fails if a screen refers to a rule that has none. |

## Screen by screen

| Screen | Before | Now |
|---|---|---|
| Audit log | Written by a model observer, not a trigger — a trigger cannot see the authenticated user | Who changed what, and when. Every change records the person who made it. |
| Audit log | placeholder="Search model or event…" | placeholder="Search by record type or action…" |
| Certified materials | Gate 2 — certified output must trace to certified input, unbroken | Certified output must trace back to certified input, with no gaps |
| Certified period | subtitle="C3 — the transactions in it are locked, and no further certified movement may be booked into it." | subtitle="Its movements are locked, and no further certified movement can be booked into it." |
| Stock count | empty="No lines yet — start counting to snapshot lots." | empty="No lines yet. Start counting to list the lots in this warehouse as they stand now." |
| Job cards list | A job card binds an approved artwork version and snapshots the consumption plan it will be costed against. | A job card ties one production run to an approved artwork version and fixes the material plan it will be costed against. |
| Job card | subtitle="Snapshotted at planning. A later spec revision does not change what the floor produces to." | subtitle="Fixed when the card was planned. A later change to the specification does not alter what the floor makes." |
| Job card: release | subtitle="J1: approved artwork, active BOM, tools available, material in stock or waived." | subtitle="Needs approved artwork, an active bill of materials, tools available, and material in stock or waived." |
| Job card: complete | subtitle="I7: nothing was issued against part of this job's BOM." | subtitle="Nothing was issued for part of this job's bill of materials." |
| Job card: reopen | subtitle="P0-3: back to completed, so finished goods can be received from it." | subtitle="Puts the card back to completed, so finished goods can be received from it." |
| Job card: cancel | subtitle="J6: something has already been booked, so the cancellation is signed for." | subtitle="Production has been booked on this card, so cancelling it needs a reason. No second approval is asked for: your permission to cancel job cards is enough, and the reason is kept on the card's history." |
| Job card: manual booking | The same J3 and J5 limits apply, and this booking is marked as keyed at a desk. | The same input and over-run limits apply, and this booking is marked as entered at a desk. |
| Customer | title: 'Commercial guard rails' | title: 'Commercial limits' |
| Customer | hint: 'What this customer is quoted and invoiced in (BR-22).' | hint: 'The currency this customer is quoted and invoiced in.' |
| Customer | title="Commercial guard rails" | title="Commercial limits" |
| Goods receipt | `Each line becomes a barcoded lot with a grn_receipt ledger row · valued in ${baseCurrency()}` | Draft: “None yet. Posting this receipt will turn each line into a lot in stock.” Posted: “Each line became a lot in stock, valued in BDT. Print lot labels to put a barcode on each.” |
| Artworks list | Gate 1 — production may only run against an approved version | Production can only run against an approved version |
| Artworks list | Production cannot be released without an approved artwork version — this is Gate 1. | Production cannot be released without an approved artwork version. |
| Artwork | subtitle="At most one version may be approved at a time — the database enforces it, not the process" | subtitle="Only one version can be approved at a time. Approving another replaces it." |
| Artwork: approve | subtitle="Approving supersedes the current approved version in the same transaction." | subtitle="Approving this version replaces the one approved now." |
| Artwork: edit | subtitle="Versions are immutable; only the record that carries them is edited here." | subtitle="Uploaded versions cannot be changed. Only the code, title and designer are edited here." |
| Product: specifications | subtitle="Immutable once referenced; a change is a new version" | subtitle="A specification in use cannot be changed; a change is a new version" |
| Lab reports | subtitle="Immutable once issued — reprinting reproduces the original values" | subtitle="An issued certificate cannot be changed; a reprint shows the original values" |
| NCR: verify | subtitle="QL-7: verification is a separate step from recording the action." | subtitle="Checking that the action worked is a separate step from recording the action." |
| Inquiries list | The front of the funnel — numbered on submit, never on form open | What customers have asked for. An inquiry gets its number when it is submitted. |
| Quotations list | A sent quotation is immutable; its cost sheet is snapshotted and locked (Q1) | Once a quotation is sent, it and its costing can no longer be changed |
| Quotations list | A quotation prices an inquiry from a cost sheet, and snapshots that cost when it is sent. | A quotation prices an inquiry from a cost sheet, and keeps that costing as it was when the quotation was sent. |
| Quotation | This sheet was snapshotted with the quantity in a different unit from the one shown, and a sent quotation is never rewritten. | This costing was saved with the quantity in a different unit from the one shown, and a sent quotation is never rewritten. |
| Quotation | This sheet was snapshotted before the blended rate was stored, and a sent quotation is never rewritten. | This costing was saved before the blended rate was stored, and a sent quotation is never rewritten. |
| Sales order | label: 'Gate 1' | label: 'Artwork approved' |
| Letter of credit | hint: 'Snapshot at opening; the shipment carries its own.' | hint: 'The rate on the day the LC was opened. Each shipment records its own.' |
| Stock | differ from the ledger. This is a bug in a posting path, not a rounding artefact. | differ from the stock movements recorded. This is a system fault, not rounding: tell your administrator. |
| Stock | checked against the live ledger | checked against the stock movements recorded |
| Inspection | Rejected with no disposition — the database refuses this row, so it cannot exist. | Rejected, with no decision recorded on what happens to the lot. Tell your administrator: this should not be possible. |
| Quotation | @click="transition('sent')">Send</Button> | @click="transition('sent')">Mark as sent</Button> |
| Quotation | @click="rejectOpen = true">Rejected</Button> | @click="rejectOpen = true">Record rejection</Button> |
| Quotation | @click="transition('cancelled')">Cancel</Button> | @click="transition('cancelled')">Cancel quotation</Button> |
| Quotation | @click="transition('accepted')">Customer accepted</Button> | @click="transition('accepted')">Record acceptance</Button> |
| Import guidelines | Column order does not matter, and columns nobody asked for are ignored. | Column order does not matter, and extra columns are ignored. |
| Every status badge and filter | Qc Pending · Po · Tt · Da · Dp | QC Pending · PO · TT · DA · DP |
| Dispatch › Challans › Index | placeholder="Search challan number…" | placeholder="Search delivery note number…" |
| Dispatch › Challans › Index | empty="No challans issued." | empty="No delivery notes yet." |
| Dispatch › Challans › Index | title="No challans yet" | title="No delivery notes yet" |
| Dispatch › Challans › Index | A delivery challan is what physically leaves the gate, and what an invoice is raised from. | A delivery note is what goes out of the gate with the goods, and what an invoice is raised from. |
| Dispatch › Challans › Show | challan.number ?? 'Draft challan' | challan.number ?? 'Draft delivery note' |
| Dispatch › Challans › Show | challan.number ?? 'Delivery challan' | challan.number ?? 'Delivery note' |
| Dispatch › Challans › Show | challan.number ?? '(draft challan)' | challan.number ?? '(draft delivery note)' |
| Dispatch › Challans › Show | title="Issue this challan" | title="Issue this delivery note" |
| Dispatch › Challans › Show | The reason is stored on the challan and read at invoicing. | The reason is kept on the delivery note and shown when it is invoiced. |
| Dispatch › PackingLists › Index | Scan-to-pack builds the carton contents that a challan and an invoice are drawn from. | Packing records what is in each carton. The delivery note and the invoice are made from it. |
| Dispatch › PackingLists › Show | challan.number ?? '(draft challan)' | challan.number ?? '(draft delivery note)' |
| Dispatch › PackingLists › Show | title="Challans" | title="Delivery notes" |
| Finance › CreditNotes › Index | A challan return against an issued invoice drafts one automatically | Goods returned on an invoiced delivery note draft one automatically |
| Finance › Invoices › Index | Invoices are raised from a delivery challan. | Invoices are raised from a delivery note. |
| Finance › Invoices › Index | Invoices are raised from a delivery challan — the quantities have to be the ones that left the gate. | Invoices are raised from a delivery note, so the quantities are the ones that left the gate. |
| Procurement › Grns › Form | label="Supplier challan" | label="Supplier delivery note (challan)" |
| Procurement › Grns › Form | label="Supplier invoice" | label="Supplier bill (invoice) number" |
| Procurement › Grns › Index | placeholder="Search GRN, invoice or challan number…" | placeholder="Search goods receipt, bill or delivery note number…" |
| Procurement › Grns › Index | >New GRN< | >New goods receipt< |
| Procurement › Grns › Index | 'New GRN' | 'New goods receipt' |
| Sales › SalesOrders › Show | challan.number ?? '(draft challan)' | challan.number ?? '(draft delivery note)' |
| Compliance › Reconciliation | Certified input enters the system on a GRN line. | Certified input is recorded when its goods receipt is posted. |
| Compliance › Reconciliation | No chain-of-custody transactions recorded yet. | No chain-of-custody movements recorded yet. |
| Dispatch › PackingLists › Show | subtitle="Every content row names its lot — traceable back to a GRN in one query" | subtitle="Every carton line names its lot, so it can be traced back to the goods receipt it came from" |
| Inventory › Lots › Show | subtitle="Any carton to its lots to its GRNs" | subtitle="From a carton to its lots to the goods receipts they came from" |
| Procurement › Bills › Index | Three-way matched against PO and GRN before approval | Checked against the purchase order and the goods receipt before approval |
| Procurement › Bills › Index | Enter supplier invoices here. Approval runs a three-way match against PO and GRN. | Enter supplier bills here. Before approval each bill is checked against its purchase order and goods receipt. |
| Procurement › Bills › Show | >GRN< | >Goods receipt< |
| Procurement › Bills › Show | >GRN qty< | >Received qty< |
| Procurement › PurchaseOrders › Show | label: 'GRN' | label: 'Goods receipt' |
| Trade › Shipments › Show | label: 'GRN' | label: 'Goods receipt' |
| Trade › Shipments › Form | label: 'Supplier invoice no' | label: 'Supplier bill (invoice) number' |
| MasterData › Items › Index | label="Items" | label="Materials" |
| MasterData › Items › Index | title="No items yet" | title="No materials yet" |
| MasterData › Items › Index | Items are what stock is held in and what a bill of materials consumes — yarn, ink, ribbon, cartons. | Materials are what the store holds and what a bill of materials uses: yarn, ink, ribbon, cartons. |
| Procurement › Requisitions › Form | title="Items needed" | title="Materials needed" |
| Procurement › Requisitions › Form | >Items< | >Materials< |
| Inventory › Stock › Index | label: 'MRP-visible only' | label: 'Only stock the material plan can use' |
| MasterData › Items › Show | Non-nettable warehouses hold stock that MRP may not plan against | Some warehouses hold stock the material plan does not count, such as quarantine |
| Planning › Mrp | empty="Run MRP to see requirements." | empty="Run the material plan (MRP) to see what is needed." |
| Procurement › Requisitions › Index | Shortages raised by an MRP run arrive here | Shortages found by the material plan (MRP) arrive here |
| Procurement › Requisitions › Index | MRP shortages land here too. | Shortages found by the material plan arrive here too. |
| Finance › Invoices › Show | label: 'Rate /M' | label: 'Rate per 1,000 pcs' |
| Sales › Inquiries › Form | label: 'Target rate /M' | label: 'Target rate per 1,000 pcs' |
| Sales › PriceLists › Show | label: 'Rate / 1,000' | label: 'Rate per 1,000 pcs' |
| Sales › Quotations › Form | >Quoted rate / M< | >Quoted rate per 1,000 pcs< |
| Sales › Quotations › Show | >Cost rate / M< | >Cost per 1,000 pcs< |
| Sales › Quotations › Show | >Quoted rate / M< | >Quoted rate per 1,000 pcs< |
| Sales › SalesOrders › Form | label: 'Rate /M' | label: 'Rate per 1,000 pcs' |
| Sales › SalesOrders › Show | label: 'Rate /M' | label: 'Rate per 1,000 pcs' |
| Sales › SalesReturns › Show | label: 'Rate / 1,000' | label: 'Rate per 1,000 pcs' |
| Inventory › Lots › Index | label: 'WH' | label: 'Warehouse' |
| Inventory › Stock › Index | label: 'WH' | label: 'Warehouse' |
| MasterData › Items › Form | label: 'Base UoM' | label: 'Stock unit' |
| MasterData › Items › Form | label: 'Purchase UoM' | label: 'Buying unit' |
| MasterData › Items › Index | label: 'UoM' | label: 'Unit' |
| MasterData › Items › Show | base UoM {{ item.base_uom?.code }} | stock unit {{ item.base_uom?.code }} |
| Product › Products › Show | label: 'UoM' | label: 'Unit' |
| MasterData › Suppliers › Show | label: 'MOQ' | label: 'Minimum order' |
| Procurement › Rfqs › Compare | · MOQ {{ | · minimum order {{ |
| Compliance › Index | title="Recent CoC transactions" | title="Recent chain-of-custody movements" |
| Inventory › Issues › Form | label="Breaks FIFO" | label="Not the oldest lot" |
| Inventory › Issues › Index | Shade-first suggestions with a FIFO fallback; overrides are logged | Suggests lots of the same shade first, then the oldest. Choosing another lot is recorded. |
| Inventory › Issues › Index | Material is issued against a job card, shade-first with a FIFO fallback. | Material is issued to a job card. Lots of the same shade are suggested first, then the oldest. |
| Inventory › Issues › Show | title="FIFO overrides" | title="Lots issued out of oldest-first order" |
| Quality › Inspections › Index | label: 'DHU' | label: 'Defects per 100 (DHU)' |
| Quality › Inspections › Index | The verdict is computed from the AQL plan, never typed | Accept or reject is worked out from the sampling plan (AQL), not typed |
| Quality › Inspections › Index | The AQL plan decides the sample size and the verdict; no lot leaves QC without a disposition. | The sampling plan (AQL, acceptable quality limit) sets the sample size and the result. A rejected lot needs a decision before it leaves QC. |
| Quality › Inspections › Form | subtitle="ISO 2859-1, Level II, AQL 2.5" | subtitle="ISO 2859-1, Level II, acceptable quality limit (AQL) 2.5" |
| Quality › Inspections › Show | subtitle="ISO 2859-1, General Inspection Level II, AQL 2.5" | subtitle="ISO 2859-1, General Inspection Level II, acceptable quality limit (AQL) 2.5" |
| Sales › SalesOrders › Form | <Card title="Header"> | <Card title="Order details"> |
| Inventory › Transfers › Form | <Card title="Header"> | <Card title="Transfer details"> |
| Inventory › Adjustments › Form | <Card title="Header"> | <Card title="Adjustment details"> |
| Trade › LettersOfCredit › Form | hint: 'TT, DA and DP are here too — not every import goes through a credit.' | hint: 'Bank transfer (TT) and documents against acceptance or payment (DA, DP) are here too. Not every import uses a letter of credit.' |
| Column headings on 21 screens | Item | Material |
| Letter of credit (opened) | All fields editable; edits to most are discarded silently | Only Bank LC number and Remarks are editable; the rest are greyed out and the card says why |
| Letter of credit: kind | Sight · Usance · Tt · Da · Dp | LC at sight · LC usance (deferred payment) · Bank transfer (TT) · Documents against acceptance (DA) · Documents against payment (DP) |
| Expenses: row actions | Approved · Paid · Rejected · Cancelled | Approve · Mark as paid · Reject · Cancel expense |
| Artworks list: filter | Gate | Approval |
| Artwork: each version | artwork/12/Xy7…q.pdf + sha256 9f2c… printed in full | NFJ-ART-01-v3.pdf, with storage path and fingerprint under “File details” |
| Artwork: versions card | Numbered contiguously from 1, never renumbered | Numbered from 1 in the order they were uploaded |

## Follow-up after review (6 Oct 2026)

- 23 server sentences and 20 multi-line screen sentences that the first sweep missed were rewritten (“challan” and “GRN” on their own, “ledger”, “snapshotted”, rule codes in running text).
- The customer return form's line heading is “Product”, not “Material”: its lines are invoiced products.
- The job card cancel dialog no longer says issued material is returned. Cancelling only frees reserved stock.
- Two guard tests now fail the build if banned wording comes back: `tests/Js/bannedWording.test.js` for screens and `tests/Unit/Text/BannedWordingTest.php` for server messages.

## Terms now used

| Use | Not |
|---|---|
| Delivery note (challan) on first use, then Delivery note | Challan alone |
| Goods receipt | GRN alone, “Receive goods” as a noun |
| Supplier bill | Supplier invoice |
| Materials | Items |
| Material plan (MRP) | MRP alone |
| Rate per 1,000 pcs | Rate /M, Rate / 1,000 |
| Warehouse, Unit, Minimum order | WH, UoM, MOQ |

## Left as they are, on purpose

- The printed delivery note is still titled “Delivery challan”: that is the word on the customer's gate copy.
- “Supplier delivery note (challan)” on the goods receipt form: it is the supplier's own document, and that is the word printed on it.
- `FIFO` inside the lot picker on the material issue form, where it labels the suggested lot; the surrounding text now says “oldest first”.
- English refusal sentences in the floor API. The terminal does not show them; it shows its own Bangla and English sentence for each refusal.
- Internal names in code comments and in `docs/`. They are for developers.
