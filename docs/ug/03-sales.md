# Sales

Inquiry → quotation → sales order. This is the busiest path. Hiding any of it behind a tab would cost a click every time, so all three are sidebar rows.

**Sales → Customers** is a hub (Customers + Price lists).

## Inquiry

**Sales → Inquiries → New inquiry.**

1. Pick the customer (or create them first under Customers).
2. Add lines: product if you know it, otherwise a description and quantity.
3. Save draft.
4. **Submit** — assigns the inquiry number, sets the merchandiser. Needs at least one line.

Statuses: `draft` → `open` → `quoted` / `lost` / `cancelled`. Winning a quote marks the inquiry `won`.

An open inquiry that will not be bid: transition to **lost** with a reason. That feeds win/loss; do not leave it open forever.

## Quotation

From the inquiry, raise a quotation, or **Sales → Quotations → New**.

1. Customer, validity date, currency.
2. Lines with quantity and a **cost sheet**. Rate per thousand (`/M`) is the commercial unit; the cost sheet is the factory unit.
3. Margin is applied **on price**, not on cost. Below the floor, sending the quote needs `cost_sheet.override_margin` (sales manager / MD).
4. **Send** — every line needs a rate > 0 and a cost sheet. The system snapshots rates, locks the sheets, numbers the quote. A sent quote **cannot be edited**.
5. Customer accepts → **Accept**, then **Convert to sales order** (customer PO number and delivery date).
6. Customer rejects → **Reject** with a reason.
7. Need a new price → **Revise**. That creates revision *n+1*; the old sent quote stays read-only.

Do not invent a sales order without an accepted quotation when the commercial process requires one. Conversion copies lines and rates so the order matches what was sold.

## Sales order

**Sales → Sales orders.**

The seeded walkthrough order is customer PO `NFJ-PO-2026-0918` (Nordic Apparel). It is already **confirmed**.

### Confirm (the gate you will hit)

A draft order **Confirm** is refused unless every line has:

- a **current** product spec, and
- an **approved** artwork version.

The amber **S3 · Gate 1** panel lists the blocking lines *before* you press the button. Send the designer / merchandiser there; do not keep clicking Confirm.

If outstanding AR plus this order exceeds the customer’s credit limit, Confirm becomes **credit hold**. Accounts or MD releases it with a reason (`sales_order.release_credit_hold`).

Confirm assigns the SO number and computes **promised date** from routing + QC + packing allowances.

### After confirm

- Quantities and dates go through **Edit**; the change is stored as an amendment.
- Job cards appear on the order when planning creates them.
- Delivered quantity comes from issued challans, not from typing.
- **Close** when delivered (or **short close** with permission and a reason).
- **Cancel** only if nothing has been produced, or with MD approval.

Under- and over-delivery bands (BR-44) live on the customer and copy onto the line. Dispatch cannot issue above the over-band without an override reason.

## Customers

**Sales → Customers.**

Commercial guard rails that actually fire later:

| Field | Later effect |
|---|---|
| Credit limit | Confirm → credit hold |
| Payment terms | Invoice due date |
| Under / over tolerance % | Dispatch band (BR-44) |
| Min order value | Warning on small quotes |
| Currency | Quote and invoice currency |

Addresses: at least one **delivery** address. Transit days and route zone feed promised dates and trip planning.

**Price lists** (tab): optional rate breaks that default onto a quotation line. They do not bypass the cost sheet.

## What you should not do

- Confirm an order to “get a number” before artwork is approved. The number appears on confirm; the gate is the point.
- Edit a sent quotation. Revise it.
- Type delivered quantity on the sales order. Pack and issue a challan.
