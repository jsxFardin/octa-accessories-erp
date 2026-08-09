# Module 13 — Receivables & Payables (Subledger)

**Purpose:** invoice what was delivered, collect it, pay suppliers, and control credit. Deliberately a **subledger**, not a general ledger — the existing accounting package keeps the GL (non-goal in [00-overview §5](../00-overview.md#5-scope)).

**Actors:** Accounts, Commercial, Merchandiser (reads outstanding), MD.

**Tables:** `sales_invoices`, `sales_invoice_lines`, `receipts`, `receipt_allocations`, `credit_notes`, `supplier_bills`, `supplier_bill_lines`, `payments`, `payment_allocations`.

**Rules:** BR-1 (per-1000 pricing), BR-22 (currency), BR-46 (credit control).

---

## Scope boundary

| In | Out |
|---|---|
| Sales invoice from challan | Chart of accounts |
| Receipts and allocation | Journal entries |
| Credit notes | Trial balance / P&L / balance sheet |
| Customer ageing and credit control | Fixed assets, depreciation |
| Supplier bills and 3-way match | Bank reconciliation |
| Payments and allocation | Payroll |
| VAT (Mushak) references | Statutory filing |
| Export to accounting package | |

Everything out of scope is served by a **posting export**: a periodic file (CSV/JSON) of AR and AP movements the accountant imports. The export format is agreed with the accountant during Phase 3.

---

## Screens

| Screen | Route | Notes |
|---|---|---|
| Invoice list | `/finance/invoices` | Status, due date, outstanding |
| Invoice form | `/finance/invoices/{id}` | Generated from challan(s) |
| Invoice print | `/finance/invoices/{id}/pdf` | With Mushak reference where applicable |
| Receipt | `/finance/receipts` | Method, reference, allocation grid |
| Credit note | `/finance/credit-notes` | Reason-coded, links to NCR |
| Customer ledger | `/finance/customers/{id}/ledger` | Invoices, receipts, notes, running balance |
| Ageing | `/finance/ageing` | 0-30/31-60/61-90/90+ by customer |
| Supplier bills | `/purchase/bills` | 3-way match panel |
| Payments | `/finance/payments` | Allocation to bills |
| Credit control | `/finance/credit-control` | Customers at/over limit, orders on hold |

---

## Invoicing

An invoice is generated **from delivery challans**, never from an order — you bill what left the building. Multiple challans may be consolidated onto one invoice for a period, per customer preference.

Line quantity is in pieces; rate is `rate_per_m` (per 1000, BR-1); `amount = qty/1000 × rate_per_m`. Rounding follows BR-47: lines round to 2 decimals, the total is the sum of rounded lines, so the printed document foots.

Currency and exchange rate copy from the sales order (snapshot), not from today's rate.

---

## Credit control (BR-46)

```
outstanding = Σ issued invoices − Σ allocated receipts − Σ applied credit notes
```
On sales order confirmation, if `outstanding + order_value > credit_limit`, the order goes to `credit_hold`. Only `sales_order.release_credit_hold` (Accounts or MD) releases it, and the release is audit-logged with a reason.

The credit control screen lists customers by utilisation percentage, with their held orders inline — so the conversation about releasing an order happens with the numbers visible.

---

## User stories

**FN-1 — Raise an invoice**
- AC1: Source is one or more `delivered` challans for the same customer and currency.
- AC2: Lines default from challan lines with the order's rate; rate changes require a reason.
- AC3: Tax is applied per line from `taxes`; VAT (Mushak) reference is captured where applicable.
- AC4: Issuing stamps `due_date` from the payment term and increments `invoiced_qty` on the order line.
- AC5: An issued invoice is immutable; corrections are credit notes.

**FN-2 — Record a receipt**
- AC1: Method: cash, cheque, bank transfer, LC, adjustment.
- AC2: Allocation grid lists open invoices oldest first; partial allocation supported.
- AC3: Unallocated balance stays on the receipt as an advance and shows on the customer ledger.
- AC4: A bounced cheque reverses the allocation and sets the receipt to `bounced`.

**FN-3 — Issue a credit note**
- AC1: Reason is coded: quality claim, short delivery, rate difference, return, discount, other.
- AC2: A quality-claim note must reference an NCR (link to [10-quality-lab](10-quality-lab.md)).
- AC3: Approval is required above a value threshold (`credit_note.approve`).
- AC4: Applying a note reduces the invoice outstanding and the customer's exposure.

**FN-4 — Match and approve a supplier bill**
- AC1: Three-way match against PO and GRN, tolerances per [06-procurement](06-procurement.md).
- AC2: Quantity or value breaches block; rate breaches within tolerance warn and require approval.
- AC3: Approved bills enter AP ageing.

**FN-5 — Pay a supplier**
- AC1: Payment allocates across one or more bills; partial allocation supported.
- AC2: Foreign-currency payments record the rate used; the difference against the bill rate is reported as exchange variance.

**FN-6 — Credit control**
- AC1: Order confirmation runs the credit check (BR-46).
- AC2: Held orders are listed with the customer's outstanding, limit and utilisation.
- AC3: Release requires permission and a reason, and is audit-logged.
- AC4: A daily digest of over-limit customers goes to Accounts and the MD.

**FN-7 — Export to accounting**
- AC1: Period export of AR and AP movements in an agreed format.
- AC2: Re-export of a closed period is possible and produces identical output.
- AC3: The export logs what was exported, by whom and when.

---

## Reports

| Report | Content |
|---|---|
| Customer ageing | 0-30 / 31-60 / 61-90 / 90+ with contact details |
| Supplier ageing | Same buckets, payable side |
| Invoice register | By customer, period, status, currency |
| Collection performance | Days sales outstanding, by customer and month |
| Credit exposure | Outstanding vs limit vs open order value |
| Credit note analysis | By reason — quality claims are a quality metric, not just a financial one |
| Exchange variance | Realised gain/loss on foreign receipts and payments |
| Revenue analysis | By customer, product type, month; with margin from [05-costing](05-costing.md) |
