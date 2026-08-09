# Module 01 — Master Data

**Purpose:** the foundation every other module reads. Organisation structure, machines, warehouses, units of measure, items, customers, suppliers, tax and currency.

**Actors:** System Admin, Merchandiser (customers), Store Keeper (items), Maintenance (machines).

**Tables:** `factory_units`, `departments`, `employees`, `shifts`, `machine_groups`, `machines`, `warehouses`, `bins`, `uoms`, `uom_conversions`, `currencies`, `exchange_rates`, `taxes`, `payment_terms`, `item_categories`, `items`, `supplier_items`, `customers`, `customer_contacts`, `customer_addresses`, `brands`, `buying_houses`, `agents`, `suppliers`, `supplier_contacts`, `price_lists`, `price_list_lines`.

**Rules:** BR-2, BR-3 (UoM), BR-21, BR-44, BR-46 (customer guard rails).

---

## Screens

| Screen | Route | Notes |
|---|---|---|
| Factory units | `/settings/factory-units` | Rarely changes; admin only |
| Departments | `/settings/departments` | Typed by `kind` — drives shop-floor routing |
| Employees | `/hr/employees` | `card_no` is the badge scanned on the floor |
| Shifts | `/settings/shifts` | Feeds capacity calendar |
| Machine groups | `/production/machine-groups` | Process type + output UoM |
| Machines | `/production/machines` | Rate, hourly cost, kW, width, max colours |
| Warehouses & bins | `/inventory/warehouses` | `is_nettable` flag matters for MRP |
| Units of measure | `/settings/uoms` | Plus conversion matrix |
| Currencies & rates | `/settings/currencies` | Daily rate entry |
| Taxes & payment terms | `/settings/taxes` | |
| Item categories | `/inventory/item-categories` | Tree, typed by `item_class` |
| Items | `/inventory/items` | The biggest master; see below |
| Customers | `/sales/customers` | Contacts, addresses, commercial terms |
| Suppliers | `/purchase/suppliers` | Approval status, lead time, item catalogue |
| Brands / buying houses / agents | `/sales/brands` etc. | |
| Price lists | `/sales/price-lists` | Optional; `price_list_lines` hold per-product rate breaks that default onto a quotation |

---

## Item master — the fields that actually matter

Generic ERPs get items wrong here. This factory's items need technical attributes that feed the consumption formulas directly:

| Field | Used by |
|---|---|
| `base_uom_id`, `purchase_uom_id` | BR-2, BR-3 |
| `density` | ink/chemical litre ↔ kg |
| `gsm` | paper/film sheet weight |
| `ink_lay_gsm` | BR-10 ink requirement |
| `is_shade_critical` | BR-37 lot selection (yarn, ribbon, ink) |
| `has_expiry`, `shelf_life_days` | BR-39 ageing alerts (ink, adhesive, chemicals) |
| `min_order_qty`, `order_multiple`, `safety_days` | BR-25, BR-26 |
| `reorder_level` | reorder-point PR generation |
| `attributes` (JSON) | denier/count for yarn, mesh for screen fabric, width for ribbon |

`supplier_items` holds per-supplier code, rate, currency, lead time and MOQ. Imported yarn from three countries has three different lead times for the same item — a single `items.lead_time` column would be wrong.

---

## User stories

**MD-1 — Create an item**
*As a Store Keeper I create an item so it can be purchased and consumed.*
- AC1: Code is unique; auto-suggested as `{category_code}-{seq}`.
- AC2: Base UoM is mandatory and immutable once any stock lot exists.
- AC3: If `item_class = 'ink'`, `ink_lay_gsm` and `density` are required.
- AC4: If `has_expiry`, `shelf_life_days` is required.
- AC5: Deactivating an item with open POs or stock is blocked with a listing of blockers.

**MD-2 — Define UoM conversion**
*As an Admin I define how a purchase UoM converts to base UoM.*
- AC1: `from_uom ≠ to_uom`; factor > 0.
- AC2: Item-specific conversions override global ones (BR-3).
- AC3: Attempting a conversion with no rule raises a visible error, never a silent 1:1 (BR-3 step 4).

**MD-3 — Register a machine**
*As Maintenance I register a machine with its capability and cost.*
- AC1: `machine_group_id` determines `process_type` and therefore which routing operations can be scheduled on it.
- AC2: `std_rate_per_hour`, `hourly_rate`, `kw_rating`, `efficiency_pct` are required before the machine can be scheduled.
- AC3: `web_width_mm` and `max_colours` are validated against the job card's product spec at scheduling time; a mismatch is a hard block with a named reason.
- AC4: Setting status to `breakdown` immediately flags all operations scheduled on it today.

**MD-4 — Create a customer**
*As a Merchandiser I create a customer with commercial terms.*
- AC1: `credit_limit`, `min_order_value`, and both tolerance percentages default onto every order line (BR-44).
- AC2: At least one address of kind `billing` or `both`, and one of `delivery` or `both`.
- AC3: `transit_days` on the delivery address feeds promised date (BR-29).
- AC4: A customer with open orders cannot be deactivated.

**MD-5 — Approve a supplier**
*As Purchase I mark a supplier approved so POs can be raised.*
- AC1: A PO cannot be created for a supplier with `is_approved = false`.
- AC2: Approval is permission-gated (`supplier.approve`) and audit-logged.

**MD-6 — Daily exchange rate**
*As Accounts I enter today's rate so quotations price correctly.*
- AC1: One rate per currency per date.
- AC2: A quotation dated D uses the latest rate effective on or before D, and snapshots it (BR-22).
- AC3: Missing rate blocks quotation send with a clear message.

---

## Reports

| Report | Content |
|---|---|
| Item master listing | Filter by class, category, active, shade-critical |
| Machine register | Capability, rate, status, last maintenance |
| Customer listing | With credit limit, outstanding, tolerance |
| Supplier listing | Approval, rating, lead time, country |
| UoM conversion matrix | Gaps highlighted |

---

## Non-functional

- Master data is cached (Redis, tagged) and invalidated on write. Items and machines are read on nearly every screen.
- All master tables use `deleted_at` soft delete; transactions reference them forever.
- Import: CSV import with dry-run preview for `items`, `customers`, `suppliers` — the go-live data migration path.
