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

**All three figures are base currency** (BR-51). `customers.credit_limit` is stated in the
factory's own currency, like `min_order_value` beside it on the same row — BR-21 compares that
against the cost-sheet subtotal, which BR-22 computes in the base currency. A customer's own
`currency_id` says what they are *traded* in, not what their limits are stated in.

Neither of the other two operands was converted, so this was the BR-50 defect living inside a
financial control rather than a report:

- open exposure was `SUM(total - received_amount)` across every currency at face value; and
- the order being confirmed was measured in whatever currency it was raised in, so a USD 10,000
  order was compared against a taka limit as the number `10000` — an eighth of the BDT 1,225,000
  it actually commits.

Both understate exposure, so the control failed silently and always in the direction of letting
the order through.

```
exposure = Σ(invoice.total − invoice.received_amount) × invoice.exchange_rate   -- BR-22, each document's own
         + order.total × order.exchange_rate
hold when exposure > customer.credit_limit
```

Each document converts at the rate **it** snapshotted, never a live rate, which would restate
the decision every time the screen was opened. `SalesOrderStateMachine::baseValue()` is the same
shape as `PurchaseOrderStateMachine::baseValue()` on the buying side: one conversion rule, said
the same way twice. The screen, the flash message and the credit-hold notification all label
these figures with the base currency, because they are no longer in the order's.

A zero limit still means "no limit set", not "no credit".

Tests: `tests/Feature/Sales/CreditControlCurrencyTest.php`.

### BR-48 — Finished goods are made out of issued material

Finished goods may only be received against a job card up to the quantity the material issued
to it can account for:

```
required_i(qty)  = bom.scaleTo(bom_line_i.qty_per_base, qty)      -- mandatory lines only
issued_i         = SUM(issue lines for item i) - SUM(return lines for item i)
receivable       = max qty such that issued_i >= required_i(qty) for every mandatory line i
```

`bom_lines.is_optional` is what "mandatory" means; a job with no BOM, or one whose BOM carries
no mandatory line, requires no issue at all — some processes genuinely consume nothing from
the store, and refusing those would invent work rather than prevent an error.

Receiving beyond `receivable` requires permission `job_card.waive_material` and a typed reason,
which is written to the audit log against the job card.

The rule and the valuation are the same fact seen twice: FG unit cost is the job's issued
material value over its final-operation good output, so a job with nothing issued values its
output at zero. Under BR-48 a zero-cost finished-goods lot is only reachable through a
documented waiver or a job with no material requirement.

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

**Every displayed amount names its currency.** Most of this factory's quotations, orders and
invoices are raised in USD while the cost sheet behind them is computed in BDT, so an
unlabelled figure is not merely ambiguous — read against the wrong currency it is wrong by two
orders of magnitude. This applies to rates (`/M`) and unit costs as much as to totals:
`money()`, `ratePerM()` and `unitCost()` in `resources/js/plugins/formatting.js` all label
themselves, and a block that states its currency once passes `false` rather than leaving the
number bare.

Where a document and its cost sheet are in different currencies, both are named and the
exchange rate that ties them is shown (BR-22).

### BR-49 — Job-card planned quantity ceiling

A job card may not plan more than the order line it is raised against can still absorb.

```
allowance = ordered_qty * (1 + over_tolerance_pct / 100)     -- the BR-44 band, reused
committed = MAX(SUM(planned_qty of live job cards on the line), produced_qty)
headroom  = MAX(0, allowance - committed)
```

A card is refused when `planned_qty > headroom`. Three things this deliberately is **not**:

- **Not the bare outstanding quantity.** Over-production inside the customer's agreed
  band is legitimate and already has a rule; BR-49 asks BR-44 where the top is rather than
  restating it, so the two cannot drift apart.
- **Not per-card.** Every live card on the line counts. Two cards of 3,000 against a 3,000
  line is the same over-commitment as one card of 6,000, and only the second was ever visible.
- **Not a sum of planned and produced.** A card that overran its own plan has consumed the
  larger of the two; adding them would count the same pieces twice.

A cancelled card releases its quantity. The rule is enforced in
`JobCardPlanningGuard`, which both the POST handler and the planning form consult, so the
disabled button and the server's refusal cannot disagree. Tests:
`tests/Feature/Manufacturing/JobCardQuantityCeilingTest.php`.

The decision is taken **inside the transaction that writes the card, under a `FOR UPDATE` lock
on the `sales_order_lines` row**. It used to run before the transaction against an unlocked
`SUM(planned_qty)`, so two planners submitting at the same moment both read the same headroom,
both passed, and both inserted — the line finished over-committed by the rule that exists to
prevent it, with neither request having broken anything. The lock is taken on the order line
because that row is what the committed quantity is grouped by, so every competing card for the
line serialises on it. Tests: `tests/Feature/Manufacturing/JobCardCeilingAtomicityTest.php`.

### BR-50 — A report spanning currencies names them, and converts before it totals

Documents in this factory are raised in more than one currency: most quotations, orders and
invoices are USD while the cost sheets behind them are computed in BDT. A report over such a
set has no single unit, and two separate things went wrong for the same reason — no report row
carried a currency at all:

- the screen fell back to the factory's currency, so `INV-26-00005` at **USD 11.63** was
  reported as **BDT 11.63**; and
- `SUM()` added dollars to taka at face value, understating outstanding receivables by roughly
  23% on the live data (36,040 against a real exposure of 44,254).

The rule:

| | |
|---|---|
| **Row amounts** | shown in the currency of the document they came from, never the factory's by default |
| **Totals** | converted to the base currency, at the rate **each document itself recorded** (BR-22) — never a live rate, which would restate history every time the report was opened |
| **Breakdown** | the unconverted figure per currency is shown beside the total, so the conversion can be checked rather than trusted |
| **Quantities** | summed as they are; pieces are pieces whatever the invoice was raised in |

Implemented once on `ReportQuery` (`currencyColumn()`, `baseRateColumn()`, `totals()`,
`totalsMeta()`) so every money report inherits it. A single-currency report declares neither
and behaves exactly as before. Tests: `tests/Feature/Reporting/MixedCurrencyReportTest.php`.

### BR-51 — Approval bands are base-currency figures

Every threshold in `settings` — the purchase-order manager band, the credit-note accounts band,
the stock-adjustment band — is expressed in the factory's own currency. A document raised in
another currency must be **converted before it is compared**.

Left raw this was an authorisation bypass reached by choosing a currency rather than by holding
a permission: at the seeded rate of 122.5 a **USD 1,000** purchase order is **BDT 122,500**,
comfortably over a BDT 100,000 manager band, and it passed the guard as the number `1000`. A
purchase manager could approve, alone, an order that needed the Managing Director.

```
base_value = document.total * document.exchange_rate     -- BR-22, the snapshotted rate
refuse when base_value > band and the approver is not the MD
```

The same conversion is applied to the **work queue** that decides whose list a pending order
appears in, so the queue and the guard cannot disagree — a count you are shown but may not
clear is worse than no count. A credit note records no rate of its own, so it converts at the
rate of the invoice it credits.

Tests: `tests/Feature/Procurement/ApprovalBandCurrencyTest.php`.

### BR-52 — Finished goods are not received at zero value without an authorised waiver

BR-48 asks whether the issued material accounts for the *pieces*. It says nothing about their
*value*, and it returns early for a job whose BOM has nothing mandatory on it. Such a job then
values its output at zero and posts it into stock in silence — no shortage, no waiver, no
trace. Twenty-five thousand pieces in the live database are carried at 0.00 that way, two
thousand of which were dispatched: a delivery with no cost of sale behind it.

Stock worth nothing is an accounting event, not the absence of one. It understates inventory
and makes the job's cost variance (BR-23) meaningless.

| | |
|---|---|
| **Invariant** | an FG receipt whose computed unit cost is zero is refused |
| **UI remedy** | the message names the job and says to issue the material it was made from |
| **Server guard** | `FgReceiptService::guardValuation()`, inside the same transaction and row lock as BR-48 |
| **Exception** | a typed waiver reason, and the `job_card.waive_material` permission — the **same** waiver BR-48 uses, deliberately not a second mechanism beside it |
| **Audit** | recorded against the job card as `waived_for: fg_receipt_zero_value`, with the quantity |

The waiver field on the FG receipt form is shown for this case as well as BR-48's. It was keyed
on `material_required`, which is false for exactly the jobs BR-52 catches — so the rule named a
remedy the screen then hid, which makes a rule unsatisfiable from inside the application.

A job that genuinely consumes nothing from the store is a real thing, which is why this is a
waiver rather than a refusal. Tests:
`tests/Feature/Manufacturing/ZeroValueFinishedGoodsTest.php`.

### BR-53 — An order reduced below its committed production says so

**S1** (01-domain-model §3) has always read "`ordered_qty` may only be reduced above the sum of
already-produced quantity". It was quoted in a comment inside `recordAmendments()` and enforced
nowhere: `ordered_qty` was validated as `numeric|gt:0` and nothing more, so an order could be
cut below production that had already happened and could never be delivered against. It is now
a hard guard on the update path (`SalesOrderController::guardReduction()`).

BR-53 is the lesser case S1 must **not** refuse. Reducing below what job cards have merely
*committed* is legitimate — the work may not have started, the customer really did cut the
order, and cancelling a card is a planner's decision rather than a side effect of an edit. So
that reduction is allowed, and the resulting conflict is made **explicit** rather than left for
someone to discover at the loading bay:

```
excess = committed - allowance        -- committed and allowance as defined by BR-49
```

Reported by `JobCardPlanningGuard::overAllocation()` and shown on the affected order line.
BR-49 continues to prevent any *new* card being raised into the conflict. Tests:
`tests/Feature/Sales/OrderReductionTest.php`.

### BR-54 — A contextual handoff parameter names a record, or nothing

`?inquiry=`, `?po=`, `?job_card=` and the rest are chosen by whoever types the URL, so each is
resolved on three separate questions before it preselects anything:

| Question | Answered by |
|---|---|
| Does the parameter **name an id** at all? | `ContextualId::contextualId()` — digits only |
| May this viewer **read** that record? | the source document's own permission |
| Is the record in a **state** this handoff allows? | the same rule the write path enforces |

`$request->integer()` casts with PHP's rules, which are forgiving in a way a URL is not:
`1.5`, `1 OR 1=1` and `1'` all became the integer `1`. No privilege was gained — the same user
could pass `1` outright — but a request for a record that does not exist was being answered with
a different record that does, which is a screen that no longer describes the request that
produced it. `contextualId()` accepts a parameter only when it is written exactly as the id is:
digits, no sign, no decimal point, no tail. (Surrounding whitespace never reaches it: Laravel's
global `TrimStrings` middleware normalises `%201` to `1` before any controller runs, as it does
for every other field in the application.)

The third question is the one `?pr_id=` on the RFQ form failed. `assertRequisition()` allows an
RFQ to be raised only from an **approved** requisition, while the prefill called a bare
`find()` — so a draft or rejected requisition's number, status and every line it carries were
handed to the screen, and the buyer discovered the refusal only after filling the form in. That
is both a read-around and a **dead remedy**: a form offering a workflow the save will refuse.

Applied to every handoff: inquiry → quotation, order → job card, order line → job card, job card
→ material issue, job card → QC, PO → GRN, customer → inquiry, customer → product, supplier →
PO, requisition → PO, requisition → RFQ, order → packing list.

**The second question was documented here before it was implemented.** It ran on two handoffs —
requisition → RFQ and the inquiry *contents* on quotation → SO — and on the other ten the
parameter was resolved on shape alone. A QC inspector holds `grn.create` and no purchase-order
permission at all: `/purchase-orders/1` refuses them, while `/grns/create?po=1` returned that
order's number, supplier, currency, exchange rate and every line on it. The read question now
lives in `ContextualId::contextualId($request, $key, $permission)` and every handoff passes its
source document's permission, so a parameter cannot reach around a refusal the same user meets
at the front door. The **picker** on the target screen is scoped by the same permission, because
a handoff and the list it preselects into must offer the same set — otherwise the read-around
simply moves from the parameter to the dropdown beside it.

One deliberate exception, unchanged: `?inquiry=` on the quotation form keeps the **id** for a
user who may not read inquiries, because a merchandiser's assistant who arrives from *Quote it*
must still file the quotation against the inquiry it answers. What is withheld is the inquiry's
*contents*, which is where the reading actually happens.

Tests: `tests/Feature/Gates/ContextualHandoffSafetyTest.php` (every handoff against every
malformed shape, the RFQ state rule, and the read-around on PO → GRN, order → job card and
customer → product); `tests/Feature/Workflow/DocumentHandoffTest.php` (the inquiry exception).

### BR-55 — Every amount on a screen names the currency it is in

BR-50 made *reports* currency-aware and stopped there, so the same defect stayed alive one
document down. `money(value)` with no currency falls back to the factory's, which is correct for
a figure that genuinely is in taka — a stock lot's cost, an approval band, a machine's hourly
rate — and silently wrong for one that is not.

On this data every letter of credit, every import shipment and every supplier quotation is
raised in USD, and each of them printed as BDT. Two other shapes of the same fault:

- the LC list selected `currency_id` and handed the screen a **foreign key**, which no formatter
  can label with, so it fell back to the base currency anyway; and
- the shipment cost table printed the code *after* an amount the formatter had already labelled,
  giving `BDT 500.00 USD` — two currencies on one figure, neither of them reliable.

| | |
|---|---|
| **A document figure** | labelled with the document's own currency, passed explicitly |
| **A base-currency figure** | a stock valuation, an approval band, a settings threshold — labelled with the base currency, and said out loud where it sits beside a document figure |
| **A block already headed with its currency** | passes `false`, so the code is stated once rather than on every row |
| **Two units on one screen** | each names itself; the GRN receipt is the worked example (BR-59) |

Applied across letters of credit, import shipments, supplier bills, supplier quotations and the
RFQ comparison, credit notes, receipts, payments, expenses, supplier item rates, the inquiry
target value (which has no currency of its own and takes the customer's), and the purchase-order,
quotation, sales-order and GRN forms.

Tests: `tests/Feature/Trade/LetterOfCreditCurrencyTest.php`,
`tests/Feature/Procurement/GrnLineContextTest.php`, and the browser content pass.

### BR-56 — A letter of credit is drawn on by orders in its own currency

`covered` on a credit is the sum of `lc_purchase_orders.covered_amount`, read against the
credit's face value. Nothing stopped a purchase order in another currency being attached, which
made that sum add taka to dollars at face value — BR-50's defect, one document down.

A credit is opened in one currency and the bank pays in that currency; an order payable in
another cannot be drawn on it. The picker offers only orders in the credit's currency, and
`attachOrder()` refuses the rest **on the server**, because the id arrives by POST.

Tests: `tests/Feature/Trade/LetterOfCreditCurrencyTest.php`.

### BR-57 — Money is allocated only within one currency

A receipt allocation lands in `sales_invoices.received_amount` and is measured against the P2-1
outstanding balance. Both are figures in the **invoice's** currency, and nothing checked that the
receipt was in it: a BDT 11.63 receipt would settle a USD 11.63 invoice in full and report it
paid — a debt of about BDT 1,425 cleared with BDT 11.63. The identical hole sat between a payment
and a supplier bill.

```
refuse when receipt.currency_id <> invoice.currency_id
refuse when payment.currency_id <> bill.currency_id
```

Refused inside the same transaction and row lock as the outstanding-balance check, so a
concurrent allocation cannot slip between the two. Settling a foreign debt is done with a
document raised in that currency, which is what the bank statement will show anyway.

The forms follow the guard rather than restate it: choosing the bill or invoice **sets** the
currency (it used to only default it, so changing the chosen document left the first one's
currency behind and the server refused with no way to correct it from the screen).

Tests: `tests/Feature/Finance/CrossCurrencyAllocationTest.php`.

### BR-58 — The rate a document is booked at is decided by the server

Every base-currency figure in this system is `amount × exchange_rate`: BR-50's report totals,
BR-51's approval bands, the "in the books" line on a document. The rate was a free numeric field
on ten forms, defaulted to `1` wherever a request omitted it, and **hard-coded to `1`** on the
RFQ → purchase-order path. The `exchange_rates` reference table already held the published rates
and nothing read it.

A `1` on a USD document is not a rounding error:

- it is an **authorisation bypass** — at the published 122.5 a USD 5,000 order is BDT 612,500 and
  needs the Managing Director under BR-51; booked at parity it is the number `5000`, comfortably
  inside a purchase manager's own band; and
- it understates that document in **every** base-currency total that reads it, always in the same
  direction.

| | |
|---|---|
| **Base currency** | booked at `1`; any other rate is refused as the mis-keyed field it is |
| **Another currency** | booked at the published rate effective on or before the document's own date — the snapshot (BR-22), never today's rate re-read later |
| **A submitted rate** | accepted while within `exchange_rate_tolerance_pct` (default 5%) of that reference, because a contracted or bank rate differs from the card by a little and not by a factor of a hundred |
| **No rate on file** | the document is refused, which is a better failure than valuing it at parity |

Implemented once in `App\Support\Currency\ExchangeRateResolver` and applied by the quotation,
sales-order, purchase-order, supplier-bill, receipt, payment, expense, import-shipment,
import-cost and letter-of-credit writes, and by RFQ → PO.

BR-51 extends with it: the **three-quote threshold** (`rfq_three_quote_value_threshold`) is a
base-currency figure and was compared against `supplier_quotations.total` raw, so a USD 600
quotation — BDT 73,500, well over the BDT 50,000 control — read as `600` and slipped under it. A
procurement control bypassed by choosing a currency, which is the shape BR-51 already closed for
the purchase-order band.

Tests: `tests/Feature/Finance/BookedExchangeRateTest.php`,
`tests/Feature/Procurement/SupplierRfqTest.php`.

### BR-59 — Stock is valued in the factory's currency, whatever the order was priced in

A goods receipt is priced in the **purchase order's** currency: the line rate, the landed rate,
and the freight, duty and clearing apportioned across them (BR-36). The stock ledger is kept in
the factory's currency, because that is the only unit stock can be valued in.

Nothing converted between the two. Received against a USD order at 122.5, a lot entered stock at
a hundred-and-twenty-second of what the material cost, and everything downstream inherited it in
the same direction: the item's weighted average, the material cost of the job it is issued to,
the margin on the order that job was made for — and, at the far end, a finished-goods lot close
enough to nothing to be indistinguishable from the zero-value ones BR-52 exists to stop.

```
lot.unit_cost = landed_rate * purchase_order.exchange_rate      -- BR-22, the snapshotted rate
```

Converted **once**, at the boundary where the receipt becomes ledger. `grn_lines.rate` and
`grn_lines.landed_rate` stay in the order's currency — they are what the supplier charged, and
they are what the three-way match compares against the order — and the screen names both units
rather than leaving them to be told apart by size.

Tests: `tests/Feature/Procurement/GrnLineContextTest.php`.
