# Module 03 — Product & Label Specification

**Purpose:** define what a label *is*, precisely enough that costing, MRP and production can all compute from the same source. This is the module that makes the ERP industry-specific.

**Actors:** Designer/Studio, Merchandiser, IE/Engineering, Production Manager.

**Tables:** `products`, `product_specs`, `boms`, `bom_lines`, `routings`, `routing_operations`, `tools`. Reads `customers`, `brands`, `items`, `machine_groups`.

**Rules:** BR-4 … BR-13.
**Invariants:** P1–P4 ([01-domain-model §3.1](../01-domain-model.md#31-product-root-products)).

---

## Why "product spec", not "style"

A garment ERP models a *style* with a size/colour matrix. A label has no sizes. It has **geometry, construction, and finish**, and the same design produced 40 mm wide behaves nothing like the same design at 25 mm — different ends across the loom, different consumption, different price.

So the technical definition is versioned separately from the commercial identity:

- `products` — customer, brand, code, customer style ref, product type. Stable.
- `product_specs` — the measurable truth. Versioned, immutable once referenced (P3).

---

## Spec fields by product type

Common to all: `label_width_mm`, `label_height_mm`, `colours`, `colour_list`, `bundle_size`, `bundles_per_carton`, `claims`, `care_symbols`, `fibre_composition`, `country_of_origin`.

| Product type | Additional required fields | Typical `attributes` JSON keys |
|---|---|---|
| **woven** | `web_width_mm`, `selvedge_mm`, `ends`, `fabric_gsm`, `warp_ratio`, `base_material` (satin/taffeta/damask), `cut_type`, `fold_type` | `picks_per_cm`, `warp_count`, `weft_count`, `loom_type`, `cad_file_ref` |
| **flexo** | `web_width_mm`, `ends`, `cut_type`, `fold_type`, `coverage_pct` | `anilox_bcm`, `plate_thickness`, `substrate`, `print_direction` |
| **screen** | `web_width_mm`, `ends`, `coverage_pct` | `mesh_count`, `squeegee_durometer`, `cure_temp_c`, `cure_seconds` |
| **heat_transfer** | `coverage_pct`, `finish` | `film_type`, `adhesive_gsm`, `application_temp_c`, `application_seconds`, `peel` (hot/cold) |
| **offset_tag** | sheet geometry, `finish` (lamination, eyelet, string) | `sheet_length_mm`, `sheet_width_mm`, `bleed_mm`, `board_gsm`, `die_no`, `string_type` |
| **thermal** | `cut_type` | `ribbon_type` (wax/resin), `dpi`, `barcode_symbology`, `min_grade` (A/B), `variable_fields` |

Validation of required `attributes` keys per type is a **PHP rule set**, not a database constraint (AD-2) — adding a product type must not require a migration.

---

## Derived values shown on the spec form

Computed live as the user types, so engineering sees the consequence of a dimension change immediately:

| Shown | Formula |
|---|---|
| Labels per metre | BR-4 |
| Suggested ends | BR-5 |
| Labels per web metre | BR-6 |
| Metres per 1000 labels | `1000 / labels_per_web_metre` |
| Estimated yarn g per 1000 | BR-9 |
| Estimated ink g per 1000 per colour | BR-10 |
| Tags per sheet (offset) | BR-11 |

---

## Routings

One routing per product type, seeded (see [02-database-schema §7](../02-database-schema.md#7-seed-data-required-before-first-use)):

| Product type | Operations in sequence |
|---|---|
| woven | CAD/design punch → warping → weaving → slitting → cutting → folding → QC → packing |
| flexo | plate making → printing → drying → slitting → cutting → QC → packing |
| screen | screen preparation/exposure → printing → curing → cutting → QC → packing |
| heat_transfer | film/plate → printing → curing → sheeting/die-cut → QC → packing |
| offset_tag | plate → printing → lamination → die-cut → eyelet/stringing → QC → packing |
| thermal | variable-data printing → barcode verification → QC → packing |

Each `routing_operations` row carries `machine_group_id`, `std_rate_per_hour`, `setup_minutes`, `setup_qty`, `wastage_pct`, `manning_level`, `consumes_web`, `allow_parallel`, `requires_qc`.

A product may override the default routing (`products.routing_id`) — e.g. a woven label that also needs heat-seal coating.

---

## BOM

`boms.base_qty = 1000` always. Lines are either **fixed** (a printed hangtag string: 1 piece per label) or **derived** (`formula_ref = 'BR-9'` for yarn), in which case MRP recomputes from the spec rather than trusting a stored number.

BOM lines carry `colour_index` pointing into `product_specs.colour_list`, so a 4-colour label with 4 yarn shades is four lines against the same item family.

---

## Tooling

`tools` covers flexo plates, screens, offset plates, cutting dies, embossing dies and woven CAD patterns.

BR-13 reuse logic on job card release:
1. Find tools for `(product_spec_id, colour_index)` with status `available`.
2. If `life_impressions - used_impressions >= required_impressions`, reuse — zero cost to the order.
3. Otherwise create a new tool, add its cost to the cost sheet per BR-15, and set status `in_production` until the plate room delivers it.

Tools nearing end of life (< 10% remaining) appear on a re-make worklist so a plate is never discovered worn at 2 a.m.

---

## User stories

**PD-1 — Create a product**
- AC1: Code unique; suggested as `{customer_code}-{seq}`.
- AC2: Customer mandatory (P1); brand optional.
- AC3: `product_type` sets the default routing and the required spec fields.
- AC4: Status starts `development`; becomes `active` only when a `current` spec **and** an `approved` artwork version exist.

**PD-2 — Create/revise a product spec**
- AC1: Version 1 starts `draft`; publishing sets it `current` and the previous `superseded` (P2 — enforced by the `product_specs_one_current_uq` unique key).
- AC2: Required-field validation is per `product_type`.
- AC3: If any quotation, sales order or job card references the current spec, editing is blocked — the UI offers "create version n+1" instead (P3).
- AC4: Derived values (BR-4…BR-11) recompute live and are shown beside the inputs.
- AC5: `ends` must be ≥ 1; if it exceeds the BR-5 suggestion by more than 1, a confirmation is required.

**PD-3 — Build a BOM**
- AC1: One `active` BOM per product (`boms_one_active_uq` unique key).
- AC2: Each line: item, UoM, qty per 1000, wastage %, optional colour index.
- AC3: A line may be marked derived with a `formula_ref`; the qty column then shows the computed value and is read-only.
- AC4: Publishing a BOM validates that every item is active and has a resolvable UoM conversion (BR-3).

**PD-4 — Assign/override a routing**
- AC1: Default routing comes from `product_type`.
- AC2: Override selects any active routing of the same product type, or a custom one.
- AC3: Changing a routing on a product with open job cards does not affect those job cards (they snapshot their operations).

**PD-5 — Manage tools**
- AC1: Creating a tool records kind, cost, life in impressions, location.
- AC2: `used_impressions` increments automatically as job card operations complete.
- AC3: A tool at ≥ 90% life appears on the re-make worklist.
- AC4: Scrapping a tool requires a reason and blocks if it is assigned to a released job card.

---

## Reports

| Report | Content |
|---|---|
| Product catalogue | By customer/brand/type, with spec summary and artwork status |
| Spec revision history | What changed, when, by whom, which orders used which version |
| BOM explosion | Materials per 1000 and for a given order quantity |
| Tool register | Life used %, location, cost, next re-make |
| Consumption standards | Metres, yarn g, ink g per 1000 by product — the engineering reference sheet |
