# Money

**Money** sits as its own sidebar group: Invoices, Receipts, Credit notes, Supplier bills, Payments, Expenses.

AR follows the challan. AP follows the GRN / PO.

## Invoices (AR)

**Money → Invoices** is a **register**. New invoices are not typed here.

From an **issued / in-transit / delivered** challan, **Create invoice**. Lines are the dispatched quantities at the **order line rates**. You cannot invoice more than left the gate, and you cannot invent a rate.

Then on the invoice:

1. Draft (may still be unnumbered).
2. **Issue** — number, Mushak fields as required, AR becomes real for credit control.
3. Receipts allocate against it; status becomes `partially_paid` / `paid`.
4. Overdue is a status the ageing job / notifications use — do not type it.
5. Cancel only while unpaid (and within the state machine’s rules).

Credit exposure (BR-46) reads **issued** invoices, not drafts.

## Receipts

**Money → Receipts.**

Record a customer payment, then **allocate** to open invoices. Allocation is what marks invoices paid — not a status dropdown.

You cannot allocate more than the receipt or more than the invoice outstanding.

## Credit notes

**Money → Credit notes.**

Against an invoice: return, quality claim, short delivery, rate difference, discount, other.

Draft → approve (accounts band / MD above `credit_note_approval_band_accounts`) → apply. Applied credit reduces outstanding the same way a receipt does (`total = received + credited + outstanding`).

## Supplier bills (AP)

**Money → Supplier bills** (also linked from Buying).

1. **New supplier bill** — supplier, optional PO and GRN (pre-fill from GRN if you opened it that way), supplier’s bill number and date, lines.
2. Save draft.
3. **Approve** — three-way match (PO ↔ GRN ↔ bill):
   - Quantity mismatch **blocks**.
   - Rate variance within `supplier_bill_rate_tolerance_pct` passes; above it needs **Approve (override variance)** (`supplier_bill.approve_variance`).
4. Payments then drive `partially_paid` / `paid`. Cancel a draft if the paper invoice is wrong.

A standalone bill (no PO/GRN) can still be approved when policy allows — the match panel will say there is nothing to match.

## Payments

**Money → Payments.**

Record a supplier payment and allocate to **open approved bills**. Same rules as receipts: no over-allocation. Bill status updates through the state machine, not by typing.

## Expenses

**Money → Expenses.** Factory overheads that are not supplier bills (utilities, petty cash). Post according to the form; they feed costing settings, they do not replace a GRN.

## What you should not do

- Raise an invoice from memory of what was packed. Use the challan button.
- Mark an invoice paid without a receipt allocation.
- Approve a supplier bill that failed quantity match by “being close”. Fix the lines or the GRN.
