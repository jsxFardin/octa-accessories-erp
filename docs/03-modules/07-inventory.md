# Module 07 — Inventory & Lot Traceability

**Purpose:** know exactly what is in the building, where, in what shade, under which certification, and where every gram of it went.

**Actors:** Store Keeper, Production Supervisor, QC, Compliance, Accounts (valuation).

**Tables:** `stock_lots`, `stock_ledger`, `stock_reservations`, `stock_transfers`, `stock_transfer_lines`, `stock_adjustments`, `stock_adjustment_lines`, `physical_counts`, `physical_count_lines`, `stock_balances`, `v_stock_balances`.

**Rules:** BR-3, BR-36, BR-37, BR-38, BR-39.
**Invariants:** I1–I5.

---

## The ledger model

```
stock_lots      — identity of a physical quantity  (what/where/which shade/which claim)
stock_ledger    — append-only signed movements     (the truth)
stock_balances  — SUM(ledger) per lot              (summary table, refreshed)
v_stock_balances — the same, recomputed live         (reconciliation reference)
stock_lots.balance_qty — derived cache             (fast reads, rebuildable)
```

**No UPDATE, no DELETE on `stock_ledger`** (I1). A mistake is corrected with a reversing entry that stays visible. This is the difference between an inventory system an auditor trusts and one they don't.

Every movement carries a polymorphic source (`source_type`, `source_id`) so any ledger row answers "which document did this?".

### Movement types

| Type | Sign | Source |
|---|---|---|
| `grn_receipt` | + | GRN line |
| `purchase_return` | − | Purchase return |
| `issue_to_job` | − | Material issue |
| `return_from_job` | + | Material issue (return type) |
| `production_output` | + | Operation log (WIP lot) |
| `wip_transfer` | ± | Operation handoff |
| `transfer_in` / `transfer_out` | ± | Stock transfer |
| `adjustment_in` / `adjustment_out` | ± | Stock adjustment |
| `waste` / `scrap` | − | Waste log |
| `sample_issue` | − | Sample job |
| `fg_receipt` | + | FG receipt |
| `dispatch` | − | Delivery challan |
| `sales_return` | + | Sales return |
| `count_variance` | ± | Physical count |

---

## Lots

A lot is created by exactly one of: a GRN line (raw material), an operation output (WIP), or an FG receipt (finished goods). It carries:

- `lot_no` + `barcode` — printed and scanned
- `shade_code` — the reason BR-37 exists
- `roll_length_m` — the roll's actual length, overriding item-level UoM conversion (BR-3)
- `cert_scheme`, `cert_claim_pct`, `cert_document_no` — inherited from the GRN line (I5), never invented
- `parent_lot_id` — genealogy through WIP
- `expiry_date` — ink, adhesive, chemicals

`stock_lots.balance_qty` has `CHECK (balance_qty >= 0)`. Negative stock is not a setting (BR-38).

---

## Lot selection on issue — the shade rule

Default is FIFO by receipt date. But for `is_shade_critical` items (yarn, ribbon, ink), the system suggests **lots of the same shade batch first**, even when that breaks FIFO, because shade variation within one customer order is a rejection. The override is recorded in `material_issue_lines.fifo_override_reason` (BR-37).

For certified production, only lots carrying the required claim are selectable.

---

## Screens

| Screen | Route | Notes |
|---|---|---|
| Stock enquiry | `/inventory/stock` | By item, warehouse, shade, certification, ageing |
| Lot detail | `/inventory/lots/{id}` | Ledger, genealogy tree, QC, certification |
| Lot traceability | `/inventory/lots/{id}/trace` | Backward to GRN, forward to cartons |
| Issue to job | `/inventory/issues` | Suggested lots with shade-first ordering |
| Transfer | `/inventory/transfers` | Two-step: dispatch → receive |
| Adjustment | `/inventory/adjustments` | Requires reason + approval |
| Physical count | `/inventory/counts` | Sheet generation, variance, posting |
| Barcode scanner | `/scan` | Large-target mobile screen, see [07-api-contracts](../07-api-contracts.md) |
| Ageing | `/reports/stock-ageing` | Buckets per BR-39 |
| Valuation | `/reports/stock-valuation` | Weighted average per BR-36 |

---

## Traceability

Two questions must be answerable in ≤ 3 clicks (goal G6):

**Backward** — "Carton 14 of PL-26-00876 came from which yarn?"
`carton_contents.lot_id → stock_lots.parent_lot_id ⟶ … ⟶ material_issue_lines.lot_id → grn_lines → purchase_orders → suppliers`

**Forward** — "GRN-26-01902 yarn lot went where?"
`grn_lines → stock_lots → material_issue_lines → job_cards → operation_logs.output_lot_id ⟶ … ⟶ fg_receipts → carton_contents → packing_lists → delivery_challans → customers`

Rendered as a genealogy tree, not a table.

---

## User stories

**IN-1 — Post a stock movement**
- AC1: Every movement writes exactly one `stock_ledger` row inside the same transaction as its source document.
- AC2: The lot row is locked (`SELECT … FOR UPDATE`) before the balance cache is updated.
- AC3: A movement that would make the balance negative is rejected with the current balance in the message (BR-38).
- AC4: Ledger rows are never updated or deleted; corrections are reversals.

**IN-2 — Issue material to a job card**
- AC1: The issue screen lists BOM requirements vs already issued vs remaining.
- AC2: Lot suggestions are shade-first for shade-critical items, otherwise FIFO (BR-37).
- AC3: Selecting a lot outside the suggestion requires a reason, stored on the line.
- AC4: For certified job cards only lots with the required claim appear.
- AC5: Posting decrements the lots, releases the matching reservations, and stamps unit cost onto the issue line for BR-23.

**IN-3 — Return unused material**
- AC1: An `issue_type = 'return'` document returns material to the same lot at the same unit cost.
- AC2: Returned quantity cannot exceed issued minus consumed.

**IN-4 — Transfer between warehouses**
- AC1: Two-step: `in_transit` then `received`. Stock is visible in a transit warehouse in between.
- AC2: Transit warehouses are non-nettable, so MRP does not double-count.
- AC3: Short receipt raises a variance requiring an adjustment with a reason.

**IN-5 — Adjust stock**
- AC1: Reason is mandatory and drawn from a controlled list.
- AC2: Adjustments above a value threshold require approval (`stock_adjustment.approve`).
- AC3: Posting writes `adjustment_in` / `adjustment_out` rows.

**IN-6 — Physical count**
- AC1: A count freezes affected lots (status `blocked`) while counting.
- AC2: The count sheet prints with system quantity hidden (blind count) by default.
- AC3: `variance_qty` is a generated column; the reconciliation screen groups by value impact.
- AC4: Posting writes `count_variance` movements and unfreezes the lots.

**IN-7 — Reservation**
- AC1: Confirming a job card reserves stock against its BOM requirement.
- AC2: Reserved quantity is excluded from MRP availability (BR-24).
- AC3: Reservations release on issue, on job card cancellation, and on order closure.

**IN-8 — Expiry and ageing**
- AC1: Lots with `expiry_date` within 30 days appear on the store's alert list.
- AC2: Expired lots move to status `expired` by a nightly job and cannot be issued.
- AC3: Ageing report uses the buckets in BR-39.

---

## Reports

| Report | Content |
|---|---|
| Stock on hand | By item / warehouse / lot / shade / certification |
| Stock ledger | Full movement history with source document links |
| Lot genealogy | Backward and forward trace |
| Stock ageing | BR-39 buckets, value at risk |
| Slow / non-moving | No movement in 90 / 180 / 365 days |
| Valuation | Weighted average, by category and warehouse |
| Reservation vs availability | What is promised vs what is free |
| Shade inventory | Available shade batches per item — used before committing an order |

---

## Performance notes

- MySQL has no materialised views. `stock_balances` is a summary **table** keyed on `lot_id`, upserted by the posting service after each batch and rebuilt on a short schedule from `v_stock_balances`. Screens read `stock_lots.balance_qty` for immediacy; reconciliation compares `stock_balances` against `v_stock_balances` and reports any drift as a defect, not as a routine correction.
- `stock_ledger` is the largest table in the system. Partition by `RANGE (YEAR(occurred_at))` once it exceeds ~50M rows; the schema is partition-ready because the ledger has no updates. Note the MySQL constraint: every unique key on a partitioned table must include the partition column, so partitioning requires promoting the primary key to `(id, occurred_at)` — plan it as a deliberate migration, not an afterthought.
