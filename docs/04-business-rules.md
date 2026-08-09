# 04 — Business Rules, Formulas & Calculations

Written **before** the schema, because these formulas determine which columns must exist. Every rule here has an ID (`BR-n`) referenced from module docs and test cases.

Numeric conventions (AD-7): **money = `DECIMAL(18,4)`**, **quantity = `DECIMAL(18,6)`**, **percentage = `DECIMAL(9,4)` stored as a percentage value (5.5 means 5.5%), never as a fraction**. Rounding is applied only at presentation and at document totals, using half-up.

---

## 1. Units of measure

### BR-1 — Pricing unit
All label/tag selling prices are quoted **per 1000 pieces**, written `/M`. The database column is `rate_per_m`.

```
line_value = (qty_pcs / 1000) * rate_per_m
```

### BR-2 — Base UoM per item class
| Item class | Base UoM | Also transacted in | Conversion source |
|---|---|---|---|
| Yarn | kg | cone, carton | `uom_conversions` per item |
| Ribbon / tape | metre | roll | roll length on the lot |
| Ink / chemical | kg | tin, litre | density on the item |
| Art card / paper | sheet | ream, kg | GSM + sheet size |
| Film (heat transfer) | m² | roll | roll width × length |
| Plate / screen | piece | — | — |
| Polybag / carton | piece | bundle | pack size |
| Finished label | piece | 1000 pcs (`M`) | fixed 1:1000 |

### BR-3 — Conversion resolution order
When converting quantity between UoMs, resolve in this order and stop at the first hit:
1. Lot-level attribute (e.g. this specific roll is 2,000 m)
2. Item-level conversion row in `uom_conversions`
3. Global conversion (e.g. 1 kg = 1000 g)
4. Fail loudly — never silently assume 1:1.

---

## 2. Consumption formulas

These are the heart of both costing and MRP. `ROUND` is half-up. All millimetre inputs come from the current `product_spec`.

### BR-4 — Labels per metre of ribbon/web (length direction)

```
pitch_mm          = label_height_mm + cut_gap_mm
labels_per_metre  = 1000 / pitch_mm
```

`cut_gap_mm` defaults by cut type (overridable per product):

| cut_type | default cut_gap_mm |
|---|---|
| hot_cut | 2.0 |
| ultrasonic | 2.0 |
| laser | 1.5 |
| die_cut | 3.0 |
| straight_cut | 1.0 |

### BR-5 — Ends across the width

```
usable_width_mm  = web_width_mm - (2 * selvedge_mm)
ends             = FLOOR(usable_width_mm / (label_width_mm + lane_gap_mm))
```
`ends` is stored on the product spec once decided by engineering; the formula is the suggestion, not an override. `ends` must be ≥ 1 or the spec is invalid.

### BR-6 — Labels produced per metre of web

```
labels_per_web_metre = labels_per_metre * ends
```

### BR-7 — Gross ribbon/web requirement

```
net_metres    = order_qty_pcs / labels_per_web_metre
gross_metres  = net_metres * (1 + total_wastage_pct/100) + setup_metres
```

### BR-8 — Total wastage
Wastage is **additive across the routing**, taken from `routing_operations.wastage_pct` with a product-level override:

```
total_wastage_pct = SUM(operation.wastage_pct for operations that consume the web)
```

Seed defaults:

| Operation | wastage_pct | setup / make-ready |
|---|---|---|
| Warping | 1.5 | 30 m |
| Weaving | 3.0 | 50 m per shade change |
| Flexo printing | 2.5 | 80 m make-ready |
| Screen printing | 4.0 | 40 m |
| Heat transfer printing | 3.0 | 25 m |
| Offset printing | 3.5 | 200 sheets |
| Thermal printing | 1.0 | 20 labels |
| Slitting | 1.0 | 10 m |
| Cutting / folding | 2.0 | 10 m |

### BR-9 — Yarn requirement for woven labels

```
gsm_grams_per_metre = (web_width_mm / 1000) * fabric_gsm      -- g per linear metre
yarn_kg             = gross_metres * gsm_grams_per_metre / 1000
warp_kg             = yarn_kg * warp_ratio        -- warp_ratio default 0.60
weft_kg             = yarn_kg * (1 - warp_ratio)
```
Colour-wise weft split is taken from the spec's colour list weighting; if absent, split evenly across weft colours.

### BR-10 — Ink requirement (flexo / screen / offset)

```
ink_kg = coverage_pct/100
       * printed_area_m2
       * ink_lay_gsm            -- g/m² per colour, from item master
       * colours
       / 1000
printed_area_m2 = gross_metres * (web_width_mm / 1000)
```
`ink_lay_gsm` defaults: flexo 1.6, screen 8.0, offset 1.1, heat transfer 12.0 (includes adhesive powder as a separate BOM line).

### BR-11 — Offset sheet requirement (tags/tickets)

```
tags_per_sheet   = FLOOR(sheet_length_mm / (tag_length_mm + bleed_mm))
                 * FLOOR(sheet_width_mm  / (tag_width_mm  + bleed_mm))
gross_sheets     = CEIL(order_qty / tags_per_sheet * (1 + wastage_pct/100)) + setup_sheets
```

### BR-12 — Packing requirement

```
labels_per_bundle = spec.bundle_size            -- default 500
bundles           = CEIL(order_qty / labels_per_bundle)
polybags          = bundles
cartons           = CEIL(bundles / spec.bundles_per_carton)   -- default 20
```

### BR-13 — Tool (plate/screen/die) requirement
A tool is required when `product_type ∈ {flexo, screen, offset_tag, heat_transfer}` or `cut_type = die_cut`.

```
tools_needed = colours              (flexo, screen, offset: one per colour)
             | 1                    (die)
reuse: if an existing tool for (product_spec_id, colour_index) has
       remaining_life_impressions >= required_impressions -> reuse, cost = 0
       else -> new tool, full cost enters the cost sheet
required_impressions = gross_metres * labels_per_metre   (or gross_sheets for offset)
```

---

## 3. Costing

### BR-14 — Cost sheet structure
A cost sheet has typed lines; each line has `cost_type`, `basis`, `qty`, `rate`, `amount`. Ordered as follows:

| Seq | cost_type | Basis | Formula |
|---|---|---|---|
| 1 | `material_yarn` | kg | BR-9 × item rate |
| 2 | `material_ribbon` | metre | BR-7 × item rate |
| 3 | `material_ink` | kg | BR-10 × item rate |
| 4 | `material_chemical` | kg | recipe qty × rate |
| 5 | `material_paper` | sheet | BR-11 × rate |
| 6 | `material_film` | m² | area × rate |
| 7 | `tooling` | piece | BR-13 (amortised, see BR-15) |
| 8 | `machine` | hour | BR-16 |
| 9 | `labour` | hour | BR-17 |
| 10 | `energy` | kWh | BR-18 |
| 11 | `packing` | piece | BR-12 × rates |
| 12 | `outsourcing` | job | subcontract quote, if any |
| 13 | `freight` | job | delivery cost estimate |
| 14 | `overhead` | % | BR-19 |
| 15 | `margin` | % | BR-20 |

### BR-15 — Tool amortisation

```
if tool is reused                     -> tool_cost_in_sheet = 0
else if customer pays tooling         -> billed as a separate quotation line, excluded from /M rate
else amortise over expected volume:
     tool_cost_in_sheet = tool_purchase_cost / amortisation_qty
     amortisation_qty defaults to the order qty, or to the annual forecast if the
     customer is on a running programme (flag on the product)
```

### BR-16 — Machine cost

```
machine_hours   = gross_output_units / standard_rate_per_hour
                  + setup_minutes/60
machine_cost    = machine_hours * machine.hourly_rate
```
`standard_rate_per_hour` lives on `routing_operations` (per machine group) and may be overridden per machine. Units are metres/hour for looms and presses, sheets/hour for offset, labels/hour for thermal.

### BR-17 — Labour cost

```
labour_cost = SUM over operations of
              machine_hours * operation.manning_level * labour_rate_per_hour
```
`manning_level` = operators per machine (e.g. loom 0.25 — one operator watches four looms; screen table 2.0).

### BR-18 — Energy cost

```
energy_cost = SUM over operations of machine_hours * machine.kw_rating * tariff_per_kwh
```

### BR-19 — Overhead

```
factory_overhead = (material + tooling + machine + labour + energy) * overhead_pct/100
admin_overhead   = subtotal * admin_pct/100
```
Defaults: factory 12%, admin 5%. Held in `settings`, snapshotted onto the sheet (Q1).

### BR-20 — Margin and selling price

```
total_cost     = all lines 1..14
unit_cost      = total_cost / order_qty
rate_per_m     = unit_cost * 1000 / (1 - margin_pct/100)
```
Note the **margin-on-price** convention (divide), not margin-on-cost (multiply). Using the wrong one is the single most common costing error in this industry.

### BR-21 — Minimum order value
If `order_qty * unit_cost < customer.min_order_value`, the quotation is flagged and the sheet adds a `minimum_charge` line bringing the total up to the minimum. Cannot be silently ignored.

### BR-22 — Currency
Quotations may be in USD, EUR, GBP or BDT. Costs are computed in BDT and converted at the `exchange_rates` row effective on quotation date. The rate used is snapshotted onto the quotation (Q1).

### BR-23 — Cost variance (post-production)

```
actual_unit_cost = (actual material issued at lot cost
                  + actual machine hours * rate
                  + actual labour + energy) / good_qty_produced
variance_pct     = (actual_unit_cost - quoted_unit_cost) / quoted_unit_cost * 100
```
Reported per job card and rolled up per order and per customer.

---

## 4. Planning & MRP

### BR-24 — Gross-to-net requirement

```
gross_req  = SUM over open job cards of BOM qty
on_hand    = SUM(stock_ledger.qty) for the item across nettable warehouses
on_order   = SUM(po_lines.qty - received_qty) for open POs due before need date
reserved   = SUM(stock_reservations.qty) for other job cards
net_req    = gross_req - (on_hand - reserved) - on_order
if net_req > 0 -> raise shortage
```

### BR-25 — Order quantity rounding

```
suggested_po_qty = MAX(net_req, item.min_order_qty)
                   rounded up to item.order_multiple
```

### BR-26 — Need date and lead time

```
material_need_date = operation_start_date - item.safety_days
po_place_by_date   = material_need_date - supplier.lead_time_days
```
Imported yarn from UK/Turkey/China carries long lead times; `lead_time_days` is per supplier-item, not global.

### BR-27 — Capacity model

```
available_minutes(machine, date) = shift_minutes
                                 * (1 - planned_downtime_pct/100)
                                 * machine.efficiency_pct/100
load_minutes(machine, date)      = SUM of scheduled operation minutes
utilisation                      = load / available
```
The planning board blocks scheduling beyond 100% unless the planner overrides with a reason.

### BR-28 — Job card splitting
A sales order line splits into multiple job cards when:
- quantity exceeds `max_lot_size` for the routing, **or**
- delivery schedule has multiple dated shipments, **or**
- colourways differ (one job card per colourway).

### BR-29 — Promised date

```
promised_date = last_operation_finish_date
              + qc_days (default 1)
              + packing_days (default 1)
              + transit_days (from customer address)
```

---

## 5. Quality

### BR-30 — AQL sampling (ISO 2859-1, normal, single sampling)
Stored in `aql_plans` as a lookup, not hard-coded. General Inspection Level II, AQL 2.5 is the default for labels.

| Lot size | Sample size | Accept | Reject |
|---|---|---|---|
| 51–90 | 13 | 1 | 2 |
| 91–150 | 20 | 1 | 2 |
| 151–280 | 32 | 2 | 3 |
| 281–500 | 50 | 3 | 4 |
| 501–1,200 | 80 | 5 | 6 |
| 1,201–3,200 | 125 | 7 | 8 |
| 3,201–10,000 | 200 | 10 | 11 |
| 10,001–35,000 | 315 | 14 | 15 |
| 35,001–150,000 | 500 | 21 | 22 |
| 150,001–500,000 | 800 | 21 | 22 |
| 500,001+ | 1,250 | 21 | 22 |

Rule: `major_defects_found >= reject_number  ->  lot rejected`. Critical defects: reject at 1.

### BR-31 — DHU

```
DHU = total_defects_found / units_inspected * 100
```

### BR-32 — Laboratory tests and pass thresholds
Grey-scale ratings are 1 (worst) to 5 (best), half-steps allowed (4-5 = 4.5).

| Test | Method | Scale | Default pass |
|---|---|---|---|
| Colour fastness to washing | ISO 105-C06 | grey 1–5 | ≥ 4 change, ≥ 3-4 staining |
| Colour fastness to rubbing (dry) | ISO 105-X12 | grey 1–5 | ≥ 4 |
| Colour fastness to rubbing (wet) | ISO 105-X12 | grey 1–5 | ≥ 3 |
| Colour fastness to hot ironing | ISO 105-X11 | grey 1–5 | ≥ 4 |
| Sublimation / dry heat | ISO 105-P01 | grey 1–5 | ≥ 4 |
| Colour bleeding | in-house | pass/fail | pass |
| Colour staining (multifibre) | ISO 105-A03 | grey 1–5 | ≥ 3-4 |
| Dimensional shrinkage | ISO 5077 | % | ≤ 3% |
| Shade variation (batch to batch) | in-house vs standard | ΔE | ≤ 1.0 |

Thresholds are per customer where a brand specifies stricter limits (`customer_test_requirements` override).

### BR-33 — Disposition of a rejected lot
Exactly one of: `rework` (returns to a named operation), `concession` (accepted with documented customer approval reference), `downgrade` (moved to a non-certified/second-quality lot), `scrap` (written off, waste ledger). No lot leaves QC without a disposition.

---

## 6. Document numbering

### BR-34 — Sequence allocation
`number_sequences` holds `(document_type, series_key, prefix, next_number, padding)`. `series_key` is typically the 2-digit year, so numbering resets annually.

Allocation is atomic:
```sql
SELECT next_number FROM number_sequences
 WHERE document_type = ? AND series_key = ? FOR UPDATE;
UPDATE number_sequences SET next_number = next_number + 1 WHERE ...;
```
Rules:
- The number is assigned **on first save of a non-draft document**, never on opening a blank form. Drafts show "(unnumbered)".
- Numbers are never reused, even if the document is later cancelled. A cancelled document keeps its number and its status.
- Formats are listed in [01-domain-model §5](01-domain-model.md#5-identity-and-numbering).

### BR-35 — Revisions
Quotations and sales orders carry `revision_no`. The printed reference is `{number}/R{revision_no}` when `revision_no > 0`.

---

## 7. Inventory valuation

### BR-36 — Costing method
**Weighted average per item per warehouse**, recomputed on every receipt:

```
new_avg = (qty_on_hand * old_avg + received_qty * received_rate)
        / (qty_on_hand + received_qty)
```
Issues are valued at the current average. Landed cost (freight, duty, clearing) is apportioned to GRN lines by value before the average is updated.

### BR-37 — Lot selection on issue
Default **FIFO by lot receipt date**, with two overrides:
- Shade-critical items (yarn, ribbon, ink): the system suggests lots of the **same shade batch** first, to prevent shade variation within an order, even if that breaks FIFO. The override is logged.
- Certified production (GRS/FSC): only lots carrying the required claim are selectable.

### BR-38 — Negative stock
Prohibited. An issue that would drive a lot balance below zero is rejected. There is no "allow negative" setting.

### BR-39 — Stock ageing buckets
0–30, 31–60, 61–90, 91–180, 181–365, 365+ days from lot receipt date. Ink and chemicals additionally flag against `expiry_date` at 30 days out.

---

## 8. Compliance / chain of custody

### BR-40 — Claim inheritance
An output lot's certification claim is derived from its input lots:

```
grs_pct_output = SUM(input_lot.qty_consumed * input_lot.grs_pct) / SUM(input_lot.qty_consumed)
```
Non-certified input dilutes the claim. Rounding is **down** to the nearest 1%, never up.

### BR-41 — Claim threshold
A product may be sold as GRS-certified only if `grs_pct_output >= 20` (GRS minimum for the "GRS" claim; 50% for the labelled claim). The threshold is stored per scheme in `certification_scopes`, not hard-coded.

### BR-42 — Reconciliation
Per scheme, per reporting period:
```
certified_input_qty  = SUM(GRN lot qty where claim = scheme)
certified_output_qty = SUM(packing list qty claimed as scheme)
conversion_factor    = certified_output / certified_input
```
The report flags any period where `certified_output_qty > certified_input_qty * max_conversion_factor` — the exact condition an auditor tests.

### BR-43 — Certificate validity
A shipment cannot claim a scheme whose certificate is expired on the shipment date. The system blocks it and names the expired certificate.

---

## 9. Sales & delivery tolerances

### BR-44 — Delivery tolerance

```
acceptable_delivery = ordered_qty * (1 - under_tolerance_pct/100)
                   .. ordered_qty * (1 + over_tolerance_pct/100)
```
Defaults 5% / 5%, overridable per customer and per order line. Shipping outside the band requires an override with reason.

### BR-45 — Order closure
A sales order line closes when cumulative delivered quantity ≥ `ordered_qty * (1 - under_tolerance_pct/100)`, or when a user force-closes it with a reason (short-close).

### BR-46 — Credit control
On sales order confirmation, if `customer_outstanding + order_value > customer.credit_limit`, the order is held at `credit_hold` and only Accounts or the MD may release it.

---

## 10. Rounding and presentation

### BR-47
| Value | Stored | Displayed |
|---|---|---|
| Quantity (pcs) | integer-valued numeric | thousands separated, no decimals |
| Quantity (m, kg) | DECIMAL(18,6) | 3 decimals |
| Rate per M | DECIMAL(18,4) | 4 decimals |
| Line/document money | DECIMAL(18,4) | 2 decimals, rounded half-up at line level then summed |
| Percentage | DECIMAL(9,4) | 2 decimals with `%` |

Document totals are the sum of **rounded line values**, so the printed document always foots.
