# Module 05 — Costing

**Purpose:** compute a defensible price per 1000 pieces from the product spec, and later compare it to what the order actually cost.

**Actors:** Merchandiser, Costing/Commercial, MD (margin override).

**Tables:** `cost_sheets`, `cost_sheet_lines`. Reads `product_specs`, `boms`, `bom_lines`, `items`, `machines`, `machine_groups`, `routing_operations`, `tools`, `settings`, `exchange_rates`.

**Rules:** BR-4 … BR-23. Every cost sheet line stores the `formula_ref` that produced it.

---

## The cost sheet

One cost sheet per quotation line. Structure and sequence are fixed (BR-14) so two merchandisers produce comparable sheets.

```
INPUTS (from spec + BOM + masters)
  order qty, label geometry, ends, colours, coverage %, routing

CONSUMPTION (BR-4 … BR-13)
  gross metres, yarn kg, ink kg, sheets, packing, tools

COST LINES (BR-14)
  1  material_yarn      kg      × rate
  2  material_ribbon    metre   × rate
  3  material_ink       kg      × rate
  4  material_chemical  kg      × rate
  5  material_paper     sheet   × rate
  6  material_film      m²      × rate
  7  tooling            piece   (amortised, BR-15)
  8  machine            hour    (BR-16)
  9  labour             hour    (BR-17)
 10  energy             kWh     (BR-18)
 11  packing            piece   (BR-12)
 12  outsourcing        job
 13  freight            job
 14  overhead           %       (BR-19)
 15  margin             %       (BR-20)

OUTPUT
  total_cost, unit_cost, rate_per_m
```

### The margin trap

`rate_per_m = unit_cost × 1000 / (1 − margin_pct/100)` — **margin on price**, not cost-plus. Getting this backwards understates price by roughly `margin²`. At 25% margin that is a 6.25% silent loss on every order. This is called out in BR-20 and must have a unit test.

---

## Screens

| Screen | Route | Notes |
|---|---|---|
| Cost sheet drawer | opens from a quotation line | Three panes: inputs · consumption · cost lines |
| Cost sheet print | `/cost-sheets/{id}/pdf` | Internal only; never sent to the customer |
| Rate maintenance | `/settings/costing-rates` | Overhead %, admin %, default margin, energy tariff |
| Cost variance | `/reports/cost-variance` | Quoted vs actual by job card / order / customer |
| What-if | `/cost-sheets/{id}/simulate` | Change qty, margin or a material rate and see the price move |

The consumption pane shows the formula reference beside every number (`BR-9`, `BR-16`). When a customer challenges a price, the merchandiser can point at the arithmetic instead of defending a black box.

---

## Snapshotting

On quotation send (Q1):
- every rate used (item rates, machine hourly rates, labour rate, energy tariff, exchange rate) is written as a **value** into `cost_sheet_lines`;
- `overhead_pct`, `admin_pct`, `margin_pct` are copied onto `cost_sheets`;
- `is_locked` = true.

Reprinting a two-year-old quotation must reproduce the original numbers exactly. Nothing may join back to live master data.

---

## User stories

**CS-1 — Generate a cost sheet**
*As a Merchandiser I generate a cost sheet for a quotation line.*
- AC1: Requires a `current` product spec and an `active` BOM; missing either blocks with a link to create it.
- AC2: Consumption is computed per BR-4…BR-13 and displayed with formula references.
- AC3: Material rates default to `items.avg_rate`, falling back to `std_rate`; the source is labelled on each line.
- AC4: Machine, labour and energy come from the routing operations and machine master (BR-16…BR-18).
- AC5: Overheads and default margin come from `settings`.

**CS-2 — Reuse existing tooling**
*As a Merchandiser I want reused plates to not inflate the price.*
- AC1: The system finds tools for the spec with enough remaining life (BR-13) and sets tooling cost to zero, listing the tools reused.
- AC2: If new tooling is required, the sheet shows the choice: amortise into the rate, or bill separately as a tooling charge.
- AC3: For a running programme (`products.is_running_programme`), amortisation uses `annual_forecast_qty` instead of the order quantity (BR-15).

**CS-3 — Override a computed value**
*As a Merchandiser I override a rate or a wastage percentage.*
- AC1: Every override records the original value, the new value and a reason.
- AC2: Overridden lines are visually marked on the sheet and listed in the approval summary.
- AC3: Overriding margin below the floor in `settings` requires permission `cost_sheet.override_margin`.

**CS-4 — Handle minimum order value**
- AC1: If order value < `customers.min_order_value`, a `minimum_charge` line is added to bring the total up (BR-21).
- AC2: The quotation shows a visible flag; it cannot be sent without acknowledging it.

**CS-5 — Multi-currency**
- AC1: Costs are computed in BDT and converted at the quotation-date rate (BR-22).
- AC2: The rate used is stored on the quotation; the sheet shows both currencies.
- AC3: A missing rate for the quotation date blocks send with a clear message.

**CS-6 — What-if simulation**
- AC1: Quantity, margin and any material rate can be varied without saving.
- AC2: A price-break table is produced for 5k / 10k / 25k / 50k / 100k pieces, showing how setup and tooling amortise.
- AC3: Simulation never mutates the saved sheet.

**CS-7 — Cost variance after production**
*As Costing I compare quoted cost to actual cost.*
- AC1: Actual = material issued at lot cost + machine hours × rate + labour + energy, over good quantity produced (BR-23).
- AC2: Variance is shown per cost element, not just as a total — material vs machine vs waste.
- AC3: Variance beyond ±5% raises a review flag on the order.
- AC4: The report rolls up by product, customer and month.

---

## Reports

| Report | Content |
|---|---|
| Cost sheet register | All sheets with quoted rate, margin, and win/loss |
| Cost variance | Quoted vs actual per element, by job card / order / customer |
| Margin analysis | Realised margin by customer, product type, month |
| Material cost trend | Yarn, ribbon, ink rate movement and its effect on standing prices |
| Price break table | Per product, for the quotation conversation |

---

## Test cases (must exist)

| ID | Assertion |
|---|---|
| CST-1 | BR-20 margin-on-price: cost 100, margin 25% → price 133.33, not 125 |
| CST-2 | BR-4/BR-6: 70 mm label, 2 mm gap, 8 ends → 111.11 labels/m/end, 888.9 labels per web metre |
| CST-3 | BR-8 wastage is additive across web-consuming operations only |
| CST-4 | BR-15 reused tool contributes zero cost; new tool amortises over forecast for a running programme |
| CST-5 | Snapshot: changing an item rate after send does not change the sent quotation's printed values |
| CST-6 | BR-21 minimum charge is applied and cannot be bypassed silently |
