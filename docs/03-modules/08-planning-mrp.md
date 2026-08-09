# Module 08 — Production Planning & MRP

**Purpose:** decide what runs on which machine on which day, and make sure the material is there when it does.

**Actors:** Planner (primary), Production Manager, Purchase (consumes shortages), Merchandiser (reads promised dates).

**Tables:** `production_plans`, `production_plan_lines`, `capacity_calendars`, `mrp_runs`, `material_requirements`. Writes `job_cards`, `purchase_requisitions`. Reads `sales_order_lines`, `boms`, `machines`, `v_machine_load`, `stock_balances`.

**Rules:** BR-24 … BR-29.

---

## Two loops

```
CAPACITY LOOP                          MATERIAL LOOP
confirmed SO lines                     released + planned job cards
  → plan lines                           → BOM explosion (BR-24)
  → machine group assignment             → net requirement
  → sequence on capacity calendar        → suggested PO qty (BR-25)
  → job cards                            → place-by date (BR-26)
  → promised date (BR-29)                → purchase requisitions
```

Both run against the same horizon. A plan that ignores either one produces dates nobody can meet.

---

## Planning board

The main screen: **machines down the left, days across the top**, operations as draggable blocks.

| Element | Behaviour |
|---|---|
| Block | One `job_card_operation`. Width = planned minutes. Colour = job card status |
| Row header | Machine with today's utilisation % (BR-27) |
| Cell shading | Red when load > available, grey on holidays |
| Drag | Reschedules; recomputes downstream operations and the promised date |
| Drop on incompatible machine | Rejected with the reason (web width, max colours, process type) |
| Material badge | Green/amber/red from the latest MRP run |
| Artwork badge | Red when the artwork is not approved — the block cannot be released |

Overbooking beyond 100% requires an override with a reason, recorded on the plan line.

---

## Job card generation

A sales order line becomes one or more job cards (BR-28):

- quantity above the routing's `max_lot_size` → split;
- multiple dated shipments in `so_delivery_schedules` → one job card per shipment;
- multiple colourways → one job card per colourway.

At creation the job card **snapshots** its consumption plan (`gross_metres`, `ends`, `labels_per_metre`) so a later spec revision does not silently change a run in progress.

---

## MRP run

Batch job, typically nightly plus on demand. Persists its output (`mrp_runs`, `material_requirements`) rather than recomputing on view, so a planner can answer "what did the system tell me on Tuesday?".

Per item, per need date (BR-24):

```
gross_req  = Σ BOM requirement of open job cards
on_hand    = Σ nettable warehouse balances
reserved   = Σ active reservations for other job cards
on_order   = Σ open PO quantity arriving before need date
net_req    = gross_req − (on_hand − reserved) − on_order
```

Shortages produce a suggested PO quantity (BR-25) and a place-by date (BR-26). Long-lead imported yarn surfaces weeks earlier than local ribbon because `lead_time_days` is per supplier-item.

---

## User stories

**PL-1 — Create a production plan**
- AC1: A plan covers a date range for one factory unit.
- AC2: Confirmed sales order lines not yet planned appear in the unplanned queue, sorted by promised date and priority.
- AC3: `frozen` prevents further changes inside the frozen window (typically the next 3 days).

**PL-2 — Schedule operations**
- AC1: Dragging an operation to a machine validates process type, web width and max colours against the product spec.
- AC2: Utilisation is recomputed live per BR-27 using `capacity_calendars` and `v_machine_load`.
- AC3: Scheduling past 100% requires an override reason stored on the plan line.
- AC4: Operations respect routing sequence; an operation cannot be scheduled before its predecessor finishes unless `allow_parallel`.

**PL-3 — Generate job cards**
- AC1: Splitting follows BR-28.
- AC2: The job card copies product, spec, **approved artwork version**, BOM, routing and colourway.
- AC3: Job card operations are created from routing operations with planned minutes computed from `std_rate_per_hour` and setup.
- AC4: Creation fails, with a named reason, if there is no approved artwork version (Gate 1).

**PL-4 — Run MRP**
- AC1: Runs for a horizon and a factory unit; records `run_at`, `run_by`, `shortage_count`.
- AC2: Derived BOM lines (`formula_ref`) are recomputed from the spec, not taken from the stored quantity.
- AC3: Results persist; previous runs remain viewable.
- AC4: A run over a 90-day horizon on a realistic dataset completes in under 60 seconds (queued, with progress).

**PL-5 — Act on shortages**
- AC1: The shortage list shows item, quantity, need date, place-by date, suggested supplier and lead time.
- AC2: Multi-select creates purchase requisitions in one action, linked back to `material_requirements`.
- AC3: Items already on an open PR/PO are marked as covered and excluded by default.

**PL-6 — Release a job card**
- AC1: Release checks: artwork approved · BOM exists · tools available · material available or explicitly waived.
- AC2: A material waiver requires a reason (`material_waiver_reason`) and permission `job_card.waive_material`.
- AC3: On release, stock is reserved and the job card appears on the shop-floor queue.

**PL-7 — Reschedule on disruption**
*As a Planner I reschedule when a machine breaks down.*
- AC1: Setting a machine to `breakdown` lists every operation scheduled on it.
- AC2: The planner can bulk-move them to another compatible machine or push the dates.
- AC3: Affected promised dates are recomputed and the impacted orders are listed for the merchandiser to communicate.

**PL-8 — Compute promised dates**
- AC1: `promised_date` = last operation finish + QC days + packing days + transit days (BR-29).
- AC2: Recomputed whenever the schedule changes.
- AC3: A promised date later than the customer's requested date raises a visible warning on the order.

---

## Reports

| Report | Content |
|---|---|
| Machine load / utilisation | Planned vs available minutes, by machine and day |
| Capacity vs order book | Where the bottleneck is over the horizon |
| Shortage report | Items, quantities, need dates, coverage status |
| Plan vs actual | Scheduled vs actual start/finish per operation |
| Late risk | Orders whose computed finish exceeds the promised date |
| Job card pipeline | Counts by status, ageing in each status |
