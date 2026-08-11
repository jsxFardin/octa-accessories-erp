# Module 16 — Trade Finance, Import & Expenses

**Purpose:** carry a raw-material order from the credit that pays for it to the true cost of the kilo that reaches the store, and record the factory spend that never passes through a purchase order.

**Actors:** Purchase officer, Purchase manager, Accounts, MD.

**Tables:** `bank_accounts`, `letters_of_credit`, `lc_purchase_orders`, `lc_amendments`, `import_shipments`, `import_costs`, `landed_cost_allocations`, `expenses`, `expense_categories`, and `grns.import_shipment_id`.

**Rules:** BR-22 (currency), BR-34 (numbering), BR-36 (landed cost and weighted average).

---

## Why this module exists

Yarn comes from the UK, Turkey, China, Hong Kong and India; ribbon from China and India; ink and chemicals from the UK ([00-overview §2](../00-overview.md)). For an importer, the supplier's rate is not the cost:

```
landed cost = supplier rate + freight + insurance + duty + AIT + C&F + port + inland transport
```

Three properties of those costs break the purchase-order model, and each one shapes a table here:

| Property | Consequence |
|---|---|
| They arrive **after** the goods — the C&F bill lands weeks later | Cost capture cannot be a field on the GRN form; it is its own document, added over time |
| One bill covers **several receipts** — a container holds two POs | The bill belongs to a *shipment*, not to a receipt |
| They get **corrected** — bills are revised, duty is reassessed | Allocation is idempotent: re-running replaces, never stacks |

Until the bills are spread, every margin the system reports is overstated by the same amount, in the same direction, on exactly the material where it matters most.

---

## Screens

| Screen | Route | Notes |
|---|---|---|
| Letters of credit | `/letters-of-credit` | Expiry and last-shipment shown as a countdown, not as a date to compare |
| Credit | `/letters-of-credit/{id}` | Terms, covered POs, amendments, shipments |
| Shipments | `/import-shipments` | Flags a shipment whose costs are recorded but not yet in stock |
| Shipment | `/import-shipments/{id}` | Cost position, costs, linked receipts, landed cost by line |
| Expenses | `/expenses` | With committed/paid totals under the same filters as the list |
| Bank accounts | `/setup/bank-accounts` | Reference list |
| Expense categories | `/setup/expense-categories` | Reference list |

---

## Letter of credit

Two numbers, and they are not interchangeable: `number` is ours, allocated on save (BR-34); `lc_no` is the bank's, and does not exist until the credit is opened. Marking a credit `opened` without it is refused — a credit no shipping document can be matched to is a filing problem waiting to happen.

**Lifecycle:** `draft → applied → opened → shipped → retired → closed`, with `cancelled` reachable while it is still live. The ladder is enforced; jumps are a 422.

Past draft the commercial terms belong to the bank, so the edit form saves only the bank reference, issue date, bank account, charges and remarks. Value and dates move through an **amendment**, which is appended rather than merged: what was increased, when, and what the bank charged for it is the whole reason the record exists. `currentAmount()` is face value plus the deltas; `effectiveExpiry()` is the last amended expiry.

One credit commonly covers several POs to the same supplier — the pivot carries `covered_amount`, and attaching an order from a different supplier is refused.

`kind` includes `tt`, `da` and `dp`: not every import goes through a credit, and the ones that do not still need the shipment file.

---

## Shipment and landed cost (BR-36)

**Lifecycle:** `draft → in_transit → arrived → cleared → costed → closed`. `arrived_on` and `cleared_on` are stamped by the transition rather than typed.

Costs are added one at a time, each with its own currency and rate; `base_amount` is what gets summed, because a USD freight bill and a BDT port charge cannot be added in their own units. `is_allocable` is the one field with an opinion in it:

- **Allocable** — freight, insurance, duty, AIT, C&F, port, inland transport, inspection. These belong in the cost of a kilo of yarn.
- **Period cost** — demurrage, LC commission, bank charges. Burying a demurrage penalty in inventory hides the thing somebody should be angry about.

`LandedCostAllocator::allocate()` spreads the allocable total across the lines of every non-cancelled GRN linked to the shipment:

- **by value** (default) — a kilo of imported UK ink and a kilo of local carton board do not carry the same share of a duty bill;
- **by quantity** — where the cost really is per unit, such as inland transport charged per drum.

The last line takes the remainder rather than its own rounded share, so the parts sum to the bill exactly and the allocation reconciles against the C&F invoice. It writes `grn_lines.landed_rate` and `stock_lots.unit_cost`, then recomputes the item's weighted average from the lots that still hold stock.

**What it does not do** is write to `stock_ledger`. That ledger is append-only and records movements of *quantity* (I1); a revaluation moves none. The audit lives in `landed_cost_allocations` — cost by cost, line by line, with the basis — which is what the shipment screen renders as "landed cost by line".

Removing the costs and re-running resets the lines to the supplier's rate: a landed rate nothing supports is worse than no landed rate.

`import_shipment.allocate` is a permission of its own. It rewrites inventory valuation, which is not the same act as editing a shipment's ETA.

---

## Expenses

One amount, one category, one payee — anything more complicated is a purchase order. What the document carries that a cash book does not is the **approval**, and one rule with it: *nobody approves their own spend*. `expense.approve` and `expense.pay` are separate rights, and an approved expense cannot be edited, because an approval attached to an amount that later changed is not an approval.

An expense may name an `import_shipment_id`. That is a reporting link, not a costing one — what lands in a lot's cost is the matching `import_costs` row, joined through `import_costs.expense_id` so nothing is counted twice.

---

## User stories

**TR-1 — Raise a credit**
- AC1: A draft credit takes a supplier, currency, amount and dates; our number is allocated on save.
- AC2: `last_shipment_date` after `expiry_date` is rejected — the bank would reject it too.
- AC3: Marking it `opened` requires the bank's LC number.

**TR-2 — Amend a credit**
- AC1: Only a live credit (`applied`, `opened`, `shipped`) can be amended.
- AC2: The amendment records the value delta, the new dates and the bank's charge.
- AC3: The credit's effective amount and expiry follow the amendments; the face value stays as issued.

**TR-3 — Cover purchase orders**
- AC1: Only orders to the credit's supplier may be attached.
- AC2: `covered_amount` defaults to the order value.

**TR-4 — File a shipment**
- AC1: A shipment records the supplier invoice, transport document, mode, ports and dates.
- AC2: It may name a credit, or none for a TT/DP purchase.

**TR-5 — Land the cost**
- AC1: Costs are added with their own currency and rate; the base amount is derived.
- AC2: Allocation spreads allocable costs across the lines of linked receipts by value or quantity.
- AC3: The parts sum to the bill exactly.
- AC4: Re-running replaces the previous allocation.
- AC5: Cancelled receipts are excluded.
- AC6: The lot's unit cost and the item's average follow.

**EX-1 — Record an expense**
- AC1: Draft on save, with a number.
- AC2: Total is amount plus tax.

**EX-2 — Approve an expense**
- AC1: `expense.approve` is required.
- AC2: The person who raised it cannot approve it.
- AC3: An approved expense is frozen against editing.
- AC4: Only an approved expense can be marked paid, under `expense.pay`.
