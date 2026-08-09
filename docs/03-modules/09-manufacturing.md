# Module 09 — Manufacturing Execution

**Purpose:** run the job card across the floor — machine by machine, operator by operator, shift by shift — and capture output, waste and downtime as they happen.

**Actors:** Production Supervisor, Machine Operator, Store Keeper (issues), Planner (reads back), Maintenance (downtime).

**Tables:** `job_cards`, `job_card_operations`, `operation_logs`, `material_issues`, `material_issue_lines`, `downtime_reasons`, `downtime_logs`, `waste_logs`, `tools`, `tool_usages`. Writes `stock_lots`, `stock_ledger`.

**Rules:** BR-7, BR-8, BR-16, BR-17, BR-23.
**Invariants:** J1–J5.
**Workflows:** [05-workflows §6 Job Card](../05-workflows.md).

---

## Machine-centred, not line-centred

There is no sewing line, no bundle, no SMV. There is a **routing**: an ordered set of operations, each bound to a machine group. A woven care label passes CAD → warping → weaving → slitting → cutting → folding → QC → packing. A flexo label passes plate → print → dry → slit → cut → QC → pack. Same job card structure, different routing.

```
Job Card JC-26-004512   50,000 pcs   satin woven care label, centre fold
 ├─ 10 CAD punch          design      completed
 ├─ 20 Warping            WARP-02     completed   1,250 m in
 ├─ 30 Weaving            LOOM-14     in progress   860 m good, 24 m waste
 ├─ 40 Slitting           SLIT-01     ready
 ├─ 50 Cutting (hot)      CUT-07      pending
 ├─ 60 Folding            FOLD-03     pending
 ├─ 70 QC                 —           pending
 └─ 80 Packing            PACK-01     pending
```

---

## Screens

| Screen | Route | Notes |
|---|---|---|
| Job card list | `/production/job-cards` | Status, machine, due date, progress bar |
| Job card detail | `/production/job-cards/{id}` | Operations, materials, output, QC, cost |
| Job card print | `/production/job-cards/{id}/pdf` | With barcode, spec summary, artwork thumbnail |
| Shop-floor queue | `/floor/queue?machine={code}` | What this machine runs next |
| **Operator terminal** | `/floor/operation/{id}` | Scan-driven, large targets, see below |
| Material issue | `/inventory/issues?job={id}` | BOM vs issued vs remaining |
| Downtime entry | `/floor/downtime` | Machine, reason, duration |
| Waste entry | `/floor/waste` | Type, quantity, reason |
| Live production board | `/production/live` | Wall-mounted: machines, current job, hourly output vs target |

---

## The operator terminal

Designed for a cheap Android tablet beside a loom, used by someone wearing gloves.

```
┌────────────────────────────────────────────┐
│  LOOM-14        JC-26-004512               │
│  Satin care label · centre fold · 8 ends   │
│  [artwork thumbnail]                       │
│                                            │
│  Target this shift    1,400 m              │
│  Produced             860 m       61%      │
│  Waste                 24 m      2.7%      │
│                                            │
│   ┌──────────┐  ┌──────────┐  ┌─────────┐  │
│   │  START   │  │  PAUSE   │  │  FINISH │  │
│   └──────────┘  └──────────┘  └─────────┘  │
│   ┌──────────┐  ┌──────────┐               │
│   │ + OUTPUT │  │ DOWNTIME │               │
│   └──────────┘  └──────────┘               │
└────────────────────────────────────────────┘
```

Rules: scan the job card barcode to open; scan the operator badge (`employees.card_no`) to identify; four buttons maximum on screen; every action is one POST that works offline-queued (see [07-api-contracts](../07-api-contracts.md)).

---

## Output, WIP and lots

Each operation that transforms material produces a **WIP lot** (`stock_lots.kind = 'wip'`) whose `parent_lot_id` is the input lot. `operation_logs.input_lot_id` / `output_lot_id` carry the link. That is what makes the genealogy tree in [07-inventory](07-inventory.md) work through six operations.

The final operation's output becomes an FG receipt ([11-dispatch-fleet](11-dispatch-fleet.md)).

---

## User stories

**MF-1 — Release and start a job card**
- AC1: Release requires the four checks in J1; each failure names the blocker and links to it.
- AC2: The first operation moves to `ready` and appears in its machine's queue.
- AC3: Starting the first operation sets the job card to `in_production` and stamps `actual_start`.

**MF-2 — Issue material**
- AC1: The issue screen shows BOM requirement (recomputed from the job card's snapshot), already issued, and remaining.
- AC2: Lot suggestion is shade-first for shade-critical items (BR-37).
- AC3: Issuing writes `issue_to_job` ledger rows and stamps unit cost for BR-23.
- AC4: Issuing more than requirement + wastage allowance requires an override reason.

**MF-3 — Log an operation stint**
*As an Operator I record what I produced this shift.*
- AC1: Start captures machine, operator, shift and timestamp.
- AC2: Output entry captures good quantity and waste quantity; waste requires a waste type.
- AC3: `good_qty + waste_qty` may not exceed input quantity — enforced by a DB check (J3) and a friendly error in the UI.
- AC4: Finishing an operation sets `finished_at`, computes `actual_minutes`, and moves the next operation to `ready` (J2).
- AC5: Multiple stints per operation are supported (three shifts on one loom run).

**MF-4 — Record downtime**
- AC1: Reason comes from the controlled `downtime_reasons` list, categorised.
- AC2: Downtime may be logged against a machine with or without a current operation.
- AC3: Open downtime (no `ended_at`) shows on the live board in red.
- AC4: Downtime minutes are excluded from machine hours in cost calculation but included in OEE availability.

**MF-5 — Record waste**
- AC1: Waste types: setup, shade, print defect, weave defect, cutting, edge trim, damaged, expired, other.
- AC2: Waste is valued at the input lot's unit cost and posts a `waste` ledger movement.
- AC3: Waste beyond the routing's `wastage_pct` allowance flags the operation for supervisor review.

**MF-6 — Handle a hold**
- AC1: A job card may be put `on_hold` with a mandatory reason (material, quality, artwork change, customer instruction).
- AC2: Held job cards free their machine slot on the planning board.
- AC3: Resuming requires the hold reason to be resolved or explicitly overridden.

**MF-7 — Complete and close a job card**
- AC1: All operations `completed` or `skipped` → status `qc_pending`.
- AC2: Closing is blocked while any mandatory QC inspection is unresolved (J4).
- AC3: Produced quantity may not exceed `planned_qty × (1 + overrun_tolerance_pct)` (J5); exceeding requires supervisor approval.
- AC4: On close, remaining reservations are released, actual cost is computed (BR-23), and unconsumed material is prompted for return.

**MF-8 — Tool usage**
- AC1: Assigning a tool to an operation validates remaining life against required impressions.
- AC2: Completing the operation increments `used_impressions`.
- AC3: A tool crossing 90% life is added to the re-make worklist automatically.

**MF-9 — Live production board**
- AC1: Wall display: every active machine, its current job card, hourly output vs target, and status colour.
- AC2: Updates in near real time (WebSocket, ≤ 5 s).
- AC3: Machines idle beyond a threshold (setting) turn red.

---

## OEE

```
Availability = (planned_minutes − downtime_minutes) / planned_minutes
Performance  = actual_output / (std_rate_per_hour × running_hours)
Quality      = good_qty / (good_qty + waste_qty)
OEE          = Availability × Performance × Quality
```
Computed per machine per shift and per day. This is the number that tells the MD whether the fourth loom is needed.

---

## Reports

| Report | Content |
|---|---|
| Daily production | Output by machine, shift, operator, job card |
| WIP | Where every open job card is, and for how long |
| Machine OEE | Availability / performance / quality / OEE by machine and period |
| Downtime Pareto | Minutes by reason, by machine — the maintenance priority list |
| Waste analysis | Quantity and value by type, machine, product, operator |
| Operator productivity | Output and quality by operator and skill grade |
| Job card cost | Actual vs quoted per element (BR-23) |
| Setup time analysis | Changeover minutes by machine and product pair |
