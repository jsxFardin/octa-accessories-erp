# Module 02 — CRM & Sales

**Purpose:** capture demand and turn it into a confirmed, producible order. Inquiry → Quotation → Sales Order.

**Actors:** Merchandiser (primary), Sales Manager, Accounts (credit release), MD (approval on exceptions).

**Tables:** `inquiries`, `inquiry_lines`, `quotations`, `quotation_lines`, `sales_orders`, `sales_order_lines`, `so_delivery_schedules`, `so_amendments`. Reads `customers`, `products`, `product_specs`, `artwork_versions`, `cost_sheets`.

**Rules:** BR-1, BR-21, BR-22, BR-29, BR-34, BR-35, BR-44, BR-45, BR-46.
**Workflows:** [05-workflows §2 Quotation, §3 Sales Order](../05-workflows.md).

---

## Screens

| Screen | Route | Key elements |
|---|---|---|
| Inquiry list | `/sales/inquiries` | Filter by status, customer, merchandiser, age |
| Inquiry form | `/sales/inquiries/{id}` | Lines with product type, qty, target rate |
| Quotation list | `/sales/quotations` | Revision indicator, validity countdown |
| Quotation form | `/sales/quotations/{id}` | Lines + per-line **Cost Sheet** drawer |
| Quotation print | `/sales/quotations/{id}/pdf` | Customer-facing, per-1000 rates |
| Sales order list | `/sales/orders` | Status, delivery date, delivered % |
| Sales order form | `/sales/orders/{id}` | Lines, delivery schedule, readiness panel |
| Order book | `/sales/order-book` | `v_order_book` — open lines with progress |
| Customer 360 | `/sales/customers/{id}` | Orders, samples, outstanding, complaints |

---

## The readiness panel

The single most useful screen element in the module. On every sales order line it shows four traffic lights, each linking to the blocking record:

| Light | Green when |
|---|---|
| **Spec** | Product has a `current` product spec |
| **Artwork** | Artwork has an `approved` version |
| **BOM** | An `active` BOM exists for the product |
| **Material** | MRP shows no shortage for the line's need date |

A line cannot be confirmed with Spec or Artwork red (invariant S3). BOM and Material red are warnings that route to Planning.

---

## User stories

**SL-1 — Log an inquiry**
*As a Merchandiser I log a customer inquiry so it can be quoted.*
- AC1: Customer is mandatory; contact and brand optional.
- AC2: Each line records product type, quantity and optionally a target rate per 1000.
- AC3: A line may reference an existing Product (repeat business) or be free text (new development).
- AC4: On save the inquiry gets number `INQ-{YY}-{#####}` (BR-34) and status `open`.
- AC5: Inquiries idle > 7 days appear on the merchandiser's follow-up list.

**SL-2 — Build a quotation from an inquiry**
*As a Merchandiser I convert an inquiry into a quotation.*
- AC1: All inquiry lines copy across; lines may be added or dropped.
- AC2: Each line opens a Cost Sheet (see [05-costing](05-costing.md)); the computed `rate_per_m` populates the line rate and is editable with a recorded override reason.
- AC3: Currency defaults from the customer; exchange rate is fetched and **snapshotted** (BR-22, Q1).
- AC4: If `qty × unit_cost < customer.min_order_value`, a `minimum_charge` line is added automatically and the quotation is flagged (BR-21).
- AC5: `valid_until` defaults to +30 days.

**SL-3 — Send a quotation**
*As a Merchandiser I send the quotation to the customer.*
- AC1: Sending requires every line to have a rate > 0 and a cost sheet.
- AC2: On send: status → `sent`, `sent_at` stamped, all cost sheets `is_locked = true`, PDF generated and attached.
- AC3: A sent quotation is read-only. Changes require a revision.

**SL-4 — Revise a quotation**
*As a Merchandiser I revise a sent quotation after customer feedback.*
- AC1: Creates revision `n+1` copying all lines and cost sheets; the prior revision becomes `revised` and read-only (Q4).
- AC2: Printed reference is `{number}/R{n}` (BR-35).
- AC3: The revision diff (rate/qty changes) is shown before saving.

**SL-5 — Convert a quotation to a sales order**
*As a Merchandiser I convert an accepted quotation into a sales order.*
- AC1: Only `accepted` quotations convert (Q3).
- AC2: The customer's PO number is mandatory.
- AC3: Rates, currency and exchange rate copy from the quotation and are not editable on the order without an amendment.
- AC4: Delivery tolerances default from the customer, overridable per line (BR-44).
- AC5: Delivery schedule defaults to a single dated shipment; the merchandiser may split it into multiple dated rows.

**SL-6 — Confirm a sales order**
*As a Merchandiser I confirm the order so production can plan it.*
- AC1: Confirmation is blocked unless every line's Spec and Artwork lights are green (S3). The block message names the missing artefact and links to it.
- AC2: Credit check runs (BR-46). If exceeded, status → `credit_hold` and only `sales_order.release_credit_hold` holders may release it.
- AC3: On confirm, `promised_date` is computed per line (BR-29) and the order appears in the planning queue.
- AC4: `confirmed_at` stamped; an event is dispatched for Planning.

**SL-7 — Amend a confirmed order**
*As a Merchandiser I change quantity or date on a confirmed order.*
- AC1: Quantity may not drop below `produced_qty` (S1).
- AC2: A reason is mandatory; a `so_amendments` row is written with old/new values (S2).
- AC3: Reducing quantity below what job cards already cover raises a warning listing the affected job cards.
- AC4: Date changes trigger recomputation of `promised_date` and flag affected job cards for rescheduling.

**SL-8 — Close an order**
*As a Merchandiser I close a completed or short-shipped order.*
- AC1: Auto-close when delivered ≥ ordered × (1 − under_tolerance) (BR-45).
- AC2: Manual short-close requires a reason and permission `sales_order.short_close`.
- AC3: Closing releases any remaining stock reservations.

**SL-9 — Track the order book**
*As a Sales Manager I see every open line and its progress.*
- AC1: Columns: customer, SO, product, ordered, produced, delivered, promised date, days late, status.
- AC2: Late lines (promised date < today, not delivered) are highlighted and sortable to the top.
- AC3: Exportable to Excel.

---

## Reports

| Report | Content |
|---|---|
| Inquiry funnel | Inquiries → quoted → won/lost, conversion % by merchandiser and customer |
| Quotation register | With revision history and win/loss reason |
| Order book | Open lines, progress, ageing |
| Delivery performance | Promised vs actual delivery date, on-time % by customer |
| Customer profitability | Revenue, quoted cost, actual cost, margin (joins BR-23) |
| Lost order analysis | Reason codes, value lost, by competitor where captured |

---

## Events emitted

| Event | Consumers |
|---|---|
| `QuotationSent` | Notification, activity log |
| `QuotationAccepted` | Sales (conversion prompt) |
| `SalesOrderConfirmed` | Planning (create plan lines), Compliance (claim check) |
| `SalesOrderAmended` | Planning (reschedule), Production (flag job cards) |
| `SalesOrderClosed` | Inventory (release reservations), AR (final invoice check) |
