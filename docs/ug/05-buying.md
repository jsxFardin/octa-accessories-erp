# Buying

Requisition → RFQ → purchase order → goods receipt. Supplier bills are under **Money**, not here.

Sidebar: **Buying** — Requisitions, RFQs, Purchase orders, Goods receipts, Suppliers, Import.

## Requisitions

**Buying → Requisitions.**

A purchase requisition (PR) is the internal ask. MRP and planners raise them when a job will starve; purchase officers can raise them by hand.

1. Draft with item lines (qty, needed-by).
2. Submit / approve per your role.
3. From an approved PR, **Raise RFQ** or go straight to a PO when the process allows.

## RFQs

**Buying → RFQs.**

1. New RFQ (optionally from a PR). Lines and respond-by date.
2. **Issue** to suppliers — assigns the RFQ number.
3. Record each **supplier quotation** on the RFQ (rates, lead time).
4. **Compare** — side by side per line.
5. **Select** a winner. Above the three-quote threshold (`rfq_three_quote_value_threshold` in Settings), the system expects three quotes before you pick.
6. **Raise PO** from the winning quote — lines and rates pre-fill.

Close or cancel an RFQ you will not place.

## Purchase orders

**Buying → Purchase orders.**

1. Draft against a supplier (from RFQ or blank).
2. Lines: item, qty, rate, dates.
3. Submit for approval when your role cannot self-approve.
4. Approval band: a purchase manager signs up to `po_approval_band_manager`; above that, the **MD**. The dashboard queue only shows orders **you** can sign.
5. Send / confirm as your process requires.
6. Receipts against the PO appear as GRNs.

You cannot receive more than the PO allows without the matching rules catching it on the bill later. Short-close leftover qty with a reason.

## Goods receipts (GRN)

**Buying → Goods receipts.**

This is how stock **enters**. Do not type opening lots on the lot screen.

1. New GRN: supplier, warehouse, optional PO.
2. Lines: received qty, accepted / rejected, rate, shade, **certification** (scheme, claim %, document no) for GRS/FSC yarn.
3. Landed cost: freight, duty, clearing on the header — spread onto `landed_rate`.
4. **Receive & post** — creates lots, posts the ledger, inherits certification onto the lot. Number assigned here.

Rejected qty does not become available stock. Certified claim on the lot is what Gate 2 will later reconcile against output.

## Suppliers

**Buying → Suppliers.** Master data: lead time, payment terms, item catalogue. Inactive suppliers cannot be placed on new POs.

## Import

**Buying → Import** (tabs: Shipments, Letters of credit).

Follow one consignment: LC → shipment → costs that land on the GRN. Use this for overseas yarn and ribbon, not for a local stationery PO.

## What you should not do

- Post a GRN against the wrong warehouse. Lots live where you received them; transfers are a later document.
- Skip certification on a GRS yarn receipt and hope compliance can reconstruct it.
- Approve a PO you cannot see on your dashboard queue — it is in someone else’s band.
