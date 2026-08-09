# Module 06 — Procurement

**Purpose:** turn material shortages into received, inspected, certified stock. PR → RFQ → PO → GRN → Bill.

**Actors:** Store Keeper (PR), Purchase Officer, Purchase Manager (approval), QC (incoming), Accounts (bill).

**Tables:** `purchase_requisitions`, `purchase_requisition_lines`, `supplier_rfqs`, `supplier_rfq_lines`, `supplier_quotations`, `supplier_quotation_lines`, `purchase_orders`, `purchase_order_lines`, `grns`, `grn_lines`, `purchase_returns`, `purchase_return_lines`, `supplier_bills`, `supplier_bill_lines`.

**Rules:** BR-24, BR-25, BR-26, BR-36, BR-40 (claim origin).
**Workflows:** [05-workflows §7 Purchase Order, §8 GRN](../05-workflows.md).

---

## Import reality

Yarn comes from the UK, Turkey, China, Hong Kong and India. Ribbon from China and India. Ink and chemicals from the UK. That means:

- **Lead times are per supplier-item**, not global (`supplier_items.lead_time_days`).
- **Landed cost matters.** Freight, duty and clearing are captured on the GRN and apportioned by value before the weighted average updates (BR-36). An ex-works rate is not the cost.
- **LC and Bill of Entry references** are captured on the GRN for commercial and audit traceability.
- **Certification documents arrive with the goods.** GRS/FSC/OEKO scheme, claim percentage and document number are recorded per GRN line — the only legitimate origin of a certified claim in the whole system (BR-40).

---

## Screens

| Screen | Route | Notes |
|---|---|---|
| PR list / form | `/purchase/requisitions` | Origin badge: manual · MRP · reorder |
| Shortage → PR | `/planning/shortages` | Bulk-create PRs from an MRP run |
| RFQ | `/purchase/rfqs` | Issue to multiple suppliers |
| Quotation comparison | `/purchase/rfqs/{id}/compare` | Side-by-side rate, lead time, MOQ; select winner |
| PO list / form | `/purchase/orders` | Approval state, receipt progress |
| PO print | `/purchase/orders/{id}/pdf` | With incoterm and payment term |
| GRN | `/purchase/grns` | Lines, lot creation, certification, landed cost |
| Incoming QC | `/quality/incoming` | Accept / reject per line |
| Purchase return | `/purchase/returns` | Against a GRN |
| Supplier bill | `/purchase/bills` | 3-way match |
| Pending receipts | `/purchase/pending` | Open POs by expected date, ageing |

---

## Three-way match

Bill approval compares **PO ↔ GRN ↔ Bill**:

| Check | Tolerance | On breach |
|---|---|---|
| Quantity: billed ≤ received | 0% | Block |
| Rate: billed rate vs PO rate | 2% (setting) | Warn, require approval |
| Value: bill total vs computed | rounding only | Block |

Breaches beyond tolerance require `supplier_bill.approve_variance`.

---

## User stories

**PR-1 — Raise a purchase requisition**
- AC1: Origin is `manual`, `mrp` or `reorder_level`; MRP-generated PRs link back to `material_requirements`.
- AC2: Lines carry item, qty, UoM, required-by date, and optionally the job card that needs it.
- AC3: Submission routes to approval based on value thresholds in `settings`.
- AC4: An approved PR line tracks `ordered_qty` so partial ordering is visible.

**PR-2 — Compare supplier quotations**
- AC1: An RFQ may be issued to N suppliers from one screen.
- AC2: The comparison grid shows rate, lead time, MOQ, currency, and landed-cost estimate side by side.
- AC3: Selecting a winner marks `is_selected` and pre-fills the PO.
- AC4: For items above a value threshold, at least three quotations are required before PO approval (setting, enforced with an override reason).

**PR-3 — Create and approve a PO**
- AC1: Supplier must be `is_approved` (MD-5).
- AC2: Currency and payment term default from the supplier; exchange rate is snapshotted.
- AC3: Quantity is rounded per BR-25 (MOQ and order multiple).
- AC4: Approval is value-banded; the band comes from `settings`.
- AC5: An approved PO is read-only; changes create revision `n+1` with a reason.
- AC6: `cert_claim` on a line declares the certification the buyer expects — the GRN must match or the discrepancy is flagged.

**PR-4 — Receive goods (GRN)**
*As a Store Keeper I record receipt against a PO.*
- AC1: Lines default from open PO lines with outstanding quantity.
- AC2: Over-receipt beyond the PO tolerance (setting, default 5%) requires approval.
- AC3: Each accepted line **creates one or more stock lots** with lot number, barcode, supplier batch, shade code, expiry and certification claim.
- AC4: Ribbon and yarn receipts capture per-roll/per-cone quantities so `stock_lots.roll_length_m` is populated (BR-3).
- AC5: The GRN posts to `stock_ledger` with `movement_type = 'grn_receipt'` into a quarantine warehouse if incoming QC is required for the item.
- AC6: Freight, duty and clearing amounts are apportioned by line value into `landed_rate` (BR-36).
- AC7: Posting updates the item's weighted-average cost.

**PR-5 — Incoming QC**
- AC1: Items flagged for incoming inspection land in `quarantine` status and cannot be issued.
- AC2: QC records accepted/rejected quantities and defects; the accepted portion moves to `available`.
- AC3: A rejected quantity requires a disposition (return / concession / scrap) — BR-33.
- AC4: Rejection auto-drafts an NCR against the supplier and affects the supplier rating.

**PR-6 — Certification capture**
- AC1: If the PO line declares a certification claim, the GRN line requires `cert_scheme`, `cert_claim_pct` and `cert_document_no`.
- AC2: These copy onto every lot created from the line (I5).
- AC3: A `coc_transactions` row of direction `input` is written (BR-42).
- AC4: A missing or expired supplier certificate blocks acceptance of the claim (not of the goods) and raises a compliance task.

**PR-7 — Purchase return**
- AC1: Only from a posted GRN, only up to the received quantity.
- AC2: Posting writes a negative ledger movement and reduces the lot balance.
- AC3: A debit note reference is captured for Accounts.

**PR-8 — Supplier bill and payment**
- AC1: Three-way match as above.
- AC2: Approved bills appear in the AP ageing ([13-finance-ar-ap](13-finance-ar-ap.md)).
- AC3: Payments allocate against bills; partial allocation is supported.

---

## Reports

| Report | Content |
|---|---|
| Open PO / pending receipt | By supplier and expected date, with ageing |
| Purchase register | By item, supplier, period, with landed cost |
| Supplier performance | On-time %, quality rejection %, price variance, rating |
| Landed cost analysis | Ex-works vs landed by item and origin country |
| Price variance | PO rate vs last rate vs average |
| Certification receipts | Certified input by scheme and period (feeds BR-42) |
| GRN register | With LC, BoE, and QC outcome |
