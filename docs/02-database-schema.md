# 02 — Database Schema

Executable companion: **[02a-schema.sql](02a-schema.sql)** — the authoritative DDL. This document explains *why* the tables look the way they do. If the two disagree, the SQL wins.

Target: **MySQL 8.0** (InnoDB, `utf8mb4`, `utf8mb4_0900_ai_ci`). **146 tables · 4 views**.

Minimum version is **8.0.16** — earlier releases parse `CHECK` constraints and silently ignore them, which would turn every invariant in §3 into a comment.

---

## 1. Conventions

| Concern | Rule |
|---|---|
| Primary key | `BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY`, always named `id` |
| Foreign key | `BIGINT UNSIGNED` — signedness must match or InnoDB rejects the constraint |
| Money | `DECIMAL(18,4)` — never `FLOAT`, never `DOUBLE`, never integer cents |
| Quantity | `DECIMAL(18,6)` — fractional metres and kilograms are normal here |
| Percentage | `DECIMAL(9,4)`, stored as a percentage (`5.5` = 5.5%) |
| Dimensions | `DECIMAL(9,2)` millimetres |
| Timestamps | `DATETIME(3)` storing **UTC**, default `CURRENT_TIMESTAMP(3)` |
| Dates | `DATE`, default `(CURRENT_DATE)` — the parentheses are required for expression defaults |
| Status | `VARCHAR(n)` + `CHECK (... IN (...))`, never MySQL `ENUM` (AD-5) |
| Vocabulary | `VARCHAR(n)` + `FOREIGN KEY` into a §1a lookup table — for lists an administrator maintains (product type, cut type, customer kind, inquiry source, order priority, product status, defect severity, QC disposition). A status is a lifecycle the code drives; a vocabulary is data, and its behaviour is columns on the row |
| Short strings | `VARCHAR` sized to purpose (code 20–40, name 120–180, email 190) |
| Long text | `TEXT` for notes/descriptions — keeps the InnoDB 65,535-byte row limit out of reach |
| Soft delete | `deleted_at DATETIME(3)` on **master data only**; transactions are cancelled, not deleted |
| Line tables | Always `UNIQUE (<parent>_id, line_no)`, `line_no SMALLINT UNSIGNED` starting at 1 |
| Document number | `number VARCHAR(30)` + `UNIQUE`, nullable while draft (BR-34) |
| Polymorphic | `<x>_type VARCHAR(120)` + `<x>_id BIGINT UNSIGNED`, no FK, always indexed as a pair |
| Free attributes | `JSON NOT NULL` (MySQL forbids a literal default on `JSON`; the application writes `{}`) |

**Naming:** snake_case, plural table names, singular column names. Foreign keys are `<referenced_table_singular>_id`. Constraint names carry the table prefix (`job_cards_status_chk`) because **`CHECK` constraint names are unique per schema in MySQL**, not per table. Indexes are `<table>_<purpose>_idx`; unique keys `<table>_<purpose>_uq`.

`email VARCHAR(190)` rather than 255: a `utf8mb4` unique index tops out at 3072 bytes and 190×4 leaves headroom on older row formats.

---

## 2. Section map

| § | Group | Tables | Notes |
|---|---|---|---|
| 1 | Platform | 9 | users, roles, permissions, audit, attachments, settings, numbering |
| 1a | Vocabularies | 8 | product type, cut type, customer kind, inquiry source, order priority, product status, defect severity, QC disposition — editable in Setup, with the costing and QC behaviour as columns |
| 2 | Organisation & master data | 28 | units, machines, warehouses, items, customers, suppliers, price lists |
| 3 | Product / artwork / BOM / routing / tooling | 10 | the engineering core |
| 4 | CRM & sales | 10 | inquiry → quotation → cost sheet → sales order |
| 5 | Sampling | 4 | |
| 6 | Procurement | 14 | PR → RFQ → PO → GRN → bill |
| 7 | Inventory | 10 | lots + append-only ledger |
| 8 | Planning & MRP | 5 | |
| 9 | Manufacturing | 9 | job cards, operations, issues, downtime, waste, tool usage |
| 10 | Quality & lab | 9 | |
| 11 | Compliance & CoC | 3 | |
| 12 | FG, packing, dispatch, fleet | 11 | |
| 13 | AR / AP subledger | 7 | |
| 14 | Derived objects | 1 table + 4 views | balances, order book, machine load, CoC reconciliation |
| 15 | Partial-index emulation register | — | documentation block inside the DDL |

---

## 3. Design notes by group

### 3.1 Platform

`number_sequences` is the only table that is intentionally hot-locked. Allocation uses `SELECT … FOR UPDATE` inside the same transaction that inserts the document (BR-34). Do not cache sequences in Redis — gaps and duplicates in a VAT-relevant document series are an audit finding.

`audit_logs` is written by a model observer, not by triggers. Triggers cannot see the authenticated user; the application can. Indexed on `(auditable_type, auditable_id, created_at)`.

`attachments` is polymorphic and stores a `checksum_sha256`. Artwork files are legally significant — a checksum proves the file approved by the customer is the file sent to plate-making (invariant A3).

### 3.2 Master data

`items` carries the technical fields the consumption formulas need: `density` (ink/chemical volume↔mass), `gsm` (paper/film), `ink_lay_gsm` (BR-10), `is_shade_critical` (drives BR-37 lot selection), `has_expiry` + `shelf_life_days`.

`machines` carries `std_rate_per_hour`, `hourly_rate`, `kw_rating` and `efficiency_pct` — everything BR-16, BR-18 and BR-27 need, on the machine, not in a config file. `web_width_mm` and `max_colours` let the planner reject an impossible machine assignment before it reaches the floor.

`warehouses.is_nettable` decides whether stock counts toward MRP availability (BR-24). Scrap and transit warehouses are non-nettable.

`customers` holds the commercial guard rails: `credit_limit` (BR-46), `min_order_value` (BR-21), and per-customer delivery tolerances (BR-44) that default onto every order line.

`customer_addresses.transit_days` and `route_zone` feed the promised-date calculation (BR-29) and the fleet trip planner.

### 3.3 Product, artwork, BOM, routing, tooling

**`products` vs `product_specs`.** The product is the commercial identity (customer, brand, code, style ref). The spec is the technical truth and is versioned. Invariant P2 — exactly one `current` spec per product — is enforced in the database, not in application code, via the emulation described in §5.

The spec carries every input the consumption formulas consume: `label_width_mm`, `label_height_mm`, `web_width_mm`, `selvedge_mm`, `lane_gap_mm`, `cut_gap_mm`, `ends`, `fabric_gsm`, `warp_ratio`, `colours`, `coverage_pct`. Type-specific extras (loom pick density, anilox volume, screen mesh, die number) go in `attributes JSON` so a new product type is a seed change, not a migration (AD-2).

`colour_list JSON` is an ordered array `[{index, name, pantone, yarn_item_id, weight_pct}]`. `bom_lines.colour_index` points into it, so a BOM can say "yarn X is colour 2" without a separate colour table.

**Artwork approval (Gate 1)** combines two mechanisms:
```sql
approved_key BIGINT UNSIGNED GENERATED ALWAYS AS
             (IF(status = 'approved', artwork_id, NULL)) STORED,
UNIQUE KEY artwork_versions_one_approved_uq (approved_key)
```
plus `job_cards.artwork_version_id NOT NULL`. Together these make it impossible to release production against an unapproved design without deliberately breaking the schema. See [01-domain-model §4](01-domain-model.md#4-the-two-gates-that-define-the-system).

`boms.base_qty` defaults to **1000** because everything in this business is quoted and consumed per 1000 pieces (BR-1). `bom_lines.formula_ref` records when a quantity is *derived* (e.g. `'BR-9'` for yarn) rather than fixed — the MRP engine recomputes those from the spec instead of using the stored number.

`routing_operations` holds `wastage_pct`, `setup_qty`, `manning_level` and `consumes_web`. `consumes_web = false` marks operations (packing, QC) that must be excluded from the additive wastage total in BR-8.

`tools` model plates, screens, dies and CAD patterns with `life_impressions` / `used_impressions`; `tool_usages` records each consumption event. BR-13 reuse logic reads exactly these columns.

### 3.4 Sales

`quotations.exchange_rate` and `cost_sheets.overhead_pct` / `admin_pct` / `margin_pct` are **snapshot columns** — copies of master data at the moment of sending (invariant Q1). Never join to `exchange_rates` when printing an old quotation.

`cost_sheets.is_locked` flips on send. `cost_sheet_lines.formula_ref` stores which rule produced the line (`'BR-9'`, `'BR-16'`), so a costing dispute is answerable in seconds.

`sales_order_lines` tracks four running quantities: `ordered_qty`, `produced_qty`, `delivered_qty`, `invoiced_qty`. These are maintained by the application in the same transaction as the event that moves them, and are reconcilable against the ledger.

`so_amendments` exists so invariant S2 is auditable: every post-confirmation change is a row with a reason and an approver.

### 3.5 Procurement

`grn_lines` is where certification enters the system: `cert_scheme`, `cert_claim_pct`, `cert_document_no`. These copy onto the stock lot (I5) and are the only legitimate origin of a certified claim. Nothing downstream may invent one.

`grns` carries `freight_amount`, `duty_amount`, `clearing_amount` for landed-cost apportionment (BR-36) — mandatory given that yarn and ink are imported from the UK, Turkey, China and India.

### 3.6 Inventory

The heart is two tables:

- `stock_lots` — the identity of a physical quantity: lot number, barcode, shade, roll length, certification claim, expiry.
- `stock_ledger` — **append-only**, signed `qty`, polymorphic source document. No UPDATE. No DELETE.

`stock_lots.balance_qty` exists as a **derived cache** with `CHECK (balance_qty >= 0)` (BR-38, I4). The authoritative balance is `v_stock_balances`, which sums the ledger. The cache is updated under a row lock in the same transaction as the ledger insert.

`stock_lots.roll_length_m` implements BR-3 step 1 — a specific ribbon roll's length overrides the item-level conversion.

`stock_lots.shade_code` plus `stock_lots_shade_idx` make BR-37's shade-first lot suggestion a single indexed query. `material_issue_lines.fifo_override_reason` records when the operator broke FIFO to keep shade consistent.

### 3.7 Planning

`capacity_calendars` is per machine (or per group) per date per shift, holding `available_minutes` and `planned_downtime_pct` — the denominator of BR-27. Holidays are rows with `is_holiday = true`, not a hard-coded calendar.

`mrp_runs` + `material_requirements` persist the *output* of an MRP run rather than recomputing on demand. A planner must be able to answer "what did the system tell me on Tuesday?" — a live recompute cannot.

### 3.8 Manufacturing

`job_cards` is the busiest table in the system and carries the full context needed to run without joins on the shop floor: product, spec, **approved artwork version**, BOM, routing, colourway, planned qty, and the snapshot of the consumption plan (`gross_metres`, `ends`, `labels_per_metre`).

Snapshotting the consumption plan matters: if engineering revises the spec mid-run, the job card must keep producing to the numbers it was released with.

`job_card_operations` carries a database-level guard for invariant J3:
```sql
CONSTRAINT job_card_operations_output_chk
    CHECK (good_qty + waste_qty <= input_qty + 0.000001)
```
The epsilon absorbs `DECIMAL(18,6)` accumulation from repeated partial logs.

`operation_logs` is the shift-level detail — one row per operator per stint, with `input_lot_id` and `output_lot_id`. This is what makes forward and backward traceability work through WIP.

`downtime_logs` and `waste_logs` are separate from operation logs deliberately: downtime is attributed to a machine (OEE), waste is attributed to a job card and a waste type (cost variance, BR-23).

### 3.9 Quality & lab

`aql_plans` is a seeded lookup of ISO 2859-1 (BR-30), not code. Changing to Level I or AQL 1.5 for a demanding brand is a data change.

`qc_inspections` enforces BR-33 in the database:
```sql
CONSTRAINT qc_inspections_rejected_chk
    CHECK (result <> 'rejected' OR disposition IS NOT NULL)
```
No lot can be rejected and then quietly forgotten.

`lab_tests` seeds exactly the tests the factory advertises. `test_reports` / `test_report_lines` become the customer-facing certificate; both go read-only at `status = 'issued'` (invariant QC3), enforced in the application layer and asserted by a test.

`customer_test_requirements` lets a brand impose stricter thresholds than the house default without forking the catalogue.

### 3.10 Compliance & chain of custody

`coc_transactions` is a three-directional ledger: `input` (from a GRN line), `conversion` (through a job card), `output` (onto a packing list). `v_coc_reconciliation` computes the conversion factor per scheme per month — the exact figure a GRS or FSC auditor asks for (BR-42).

`is_locked` freezes a closed reporting period (invariant C3).

### 3.11 Dispatch & fleet

`packing_lists → cartons → carton_contents` gives carton-level traceability: each carton's contents name their `lot_id`, so a customer complaint about carton 14 resolves to a weaving lot in one join.

`trips` / `trip_stops` model the owned fleet, including multi-drop routes and POD capture (`received_by_name`, `signature_path`, `photo_path`). `delivery_challans.mode` distinguishes own fleet from courier and freight forwarder.

### 3.12 AR / AP

Deliberately a **subledger, not a general ledger** (non-goal in [00-overview §5](00-overview.md#5-scope)). Invoices, receipts with allocations, credit notes, supplier bills and payments with allocations — enough for ageing, credit control (BR-46) and export to the existing accounting package. `sales_invoices.mushak_no` carries the Bangladesh VAT challan reference.

---

## 4. Derived objects

MySQL has no materialised views, so the balance model is split in two:

| Object | Kind | Purpose |
|---|---|---|
| `stock_balances` | **Table** | Summary keyed on `lot_id`, upserted by the posting service after each batch and rebuilt on a schedule. What screens and reports read. |
| `v_stock_balances` | View | The same figures recomputed live from `stock_ledger`. The reconciliation reference and the rebuild source. |
| `v_order_book` | View | Open order lines with delivered % — sales dashboard and planning board |
| `v_machine_load` | View | Scheduled minutes per machine per day — numerator of BR-27 |
| `v_coc_reconciliation` | View | Certified input vs output per scheme per period — auditor-facing |

**Reconciliation is a defect check, not routine maintenance.** A nightly job compares `stock_balances` to `v_stock_balances`; any difference means a posting path wrote the ledger without updating the summary, and is raised as a bug rather than silently corrected.

`v_coc_reconciliation` uses `SUM(CASE WHEN direction = … )` rather than the SQL-standard `FILTER` clause, which MySQL does not support.

---

## 5. MySQL-specific decisions

These are the places where the model would look different on PostgreSQL. Each is a deliberate trade, not an accident.

### 5.1 No partial indexes → generated NULL-able key columns

PostgreSQL would write `CREATE UNIQUE INDEX … WHERE status = 'approved'`. MySQL has no partial indexes. The equivalent is a **`STORED` generated column that evaluates to `NULL` when the condition is false**, plus a plain `UNIQUE` key — because MySQL treats `NULL`s as distinct, only the rows satisfying the condition compete for uniqueness.

| Table | Generated column | Enforces | Rule |
|---|---|---|---|
| `currencies` | `base_key` | one base currency | — |
| `product_specs` | `current_key` | one `current` spec per product | P2 |
| `artwork_versions` | `approved_key` | one `approved` version per artwork | **A2 / Gate 1** |
| `boms` | `active_key` | one `active` BOM per product | PD-3 |

The same technique covers "unique over a nullable column", where MySQL's NULL-distinct behaviour would otherwise let duplicates through:

| Table | Generated column | Meaning of NULL |
|---|---|---|
| `uom_conversions` | `item_key` | NULL item = global conversion (BR-3) |
| `bom_lines` | `colour_key` | NULL colour = applies to all colours |
| `customer_test_requirements` | `product_key` | NULL product = applies to all products (BR-32) |

**Consequences to respect:**
- Application code must never write these columns; they are `GENERATED`.
- A migration that changes a status vocabulary must revisit the `IF()` expression, not only the `CHECK` constraint. A test asserts each emulation still rejects a duplicate.
- MySQL forbids `ON DELETE CASCADE` / `SET NULL` on a column that feeds a generated column. Four foreign keys therefore use the default `RESTRICT`: `uom_conversions.item_id`, `product_specs.product_id`, `artwork_versions.artwork_id`, `boms.product_id`. Deleting a product or artwork now requires deleting its children first — acceptable, because none of these are deleted in normal operation (masters are soft-deleted, versions are superseded).

### 5.2 No materialised views → summary table + live view
See §4.

### 5.3 `CHECK` constraint names are schema-scoped
Unlike PostgreSQL, two tables cannot both have a constraint named `status_chk`. Every constraint therefore carries its table as a prefix. Anonymous constraints would be auto-named `<table>_chk_1`, which is unique but useless in an error message — the goal is that a violated constraint names itself.

### 5.4 `JSON` columns
MySQL 8 has a native `JSON` type with functional indexing, but no direct GIN equivalent. Columns are declared `JSON NOT NULL` **without** a default (MySQL disallows literal defaults on `JSON`); the application always writes at least `{}`. If a specific JSON key becomes a hot filter, add a `STORED` generated column extracting it and index that — do not add a second physical column.

### 5.5 `DATETIME(3)`, not `TIMESTAMP`
`TIMESTAMP` is bounded at 2038 and silently converts by session timezone. `DATETIME(3)` stores exactly what Laravel writes — UTC — with millisecond precision for shop-floor ordering. Display conversion to Asia/Dhaka happens in the application (NFR-49).

### 5.6 `BIGINT UNSIGNED` everywhere
Laravel's default. Signedness must match across a foreign key or InnoDB refuses the constraint with a bare "Cannot add foreign key constraint" — the single most common error when hand-writing MySQL DDL.

### 5.7 Every foreign key gets an index
InnoDB creates one automatically if absent, but auto-created indexes are named unpredictably and are easy to duplicate later. All 364 are declared explicitly.

### 5.8 Partitioning `stock_ledger`, when the time comes
The ledger is the growth table. Partitioning by `RANGE (YEAR(occurred_at))` is the eventual plan, with one MySQL constraint to plan around: **every unique key on a partitioned table must contain the partition column**, so the primary key must become `(id, occurred_at)`. That is a deliberate migration, not a switch to flip. Nothing in the current schema blocks it — the ledger has no updates and no incoming foreign keys.

---

## 6. Indexing strategy

PostgreSQL would use partial indexes on open documents. MySQL cannot, so the **status column leads the composite index** instead — same effect for the queries that matter, at the cost of some index size:

```sql
KEY sales_orders_open_idx  (status, customer_id, delivery_date)
KEY job_cards_open_idx     (status, factory_unit_id, due_date)
KEY stock_lots_item_wh_idx (item_id, warehouse_id, status)
```

Composite index on every polymorphic pair (`attachments`, `comments`, `audit_logs`, `stock_ledger.(source_type, source_id)`).

Covering indexes on the hot list screens (order book, job card queue, stock enquiry) are added once real query plans exist — not speculatively.

---

## 7. Things intentionally *not* in the schema

| Not present | Why |
|---|---|
| `company_id` / tenant column | Single-company decision (AD-3). `factory_unit_id` covers multi-unit (AD-4) |
| Stored stock balance as truth | Ledger is truth (I3); the cache column and summary table are rebuildable |
| MySQL `ENUM` types | Column-rewrite cost on a large table when a status is added (AD-5) |
| Triggers for audit | Cannot see the authenticated user |
| `deleted_at` on transactions | Documents are cancelled with a status and keep their number (BR-34) |
| Chart of accounts / journal entries | GL is out of scope for phase 1 |

---

## 8. Seed data required before first use

| Table | Seed |
|---|---|
| `uoms` | pcs, M (1000 pcs), metre, kg, g, litre, sheet, m², roll, cone, carton |
| `currencies` | BDT (base), USD, EUR, GBP |
| `machine_groups` | design, warping, weaving, flexo, screen, heat_transfer, offset, thermal, slitting, cutting, folding, packing |
| `routings` + `routing_operations` | One default routing per product type, with the wastage and setup defaults from BR-8 |
| `aql_plans` | ISO 2859-1 Level II AQL 2.5 table from BR-30 |
| `lab_tests` | The nine tests from BR-32 with their methods and thresholds |
| `defects` | Standard weaving / printing / cutting / packing defect catalogue |
| `downtime_reasons` | Mechanical, electrical, material, quality, changeover, power, manpower, planned |
| `certifications` | The factory's current FSC, GRS-MDEL, GRS-MLTL, OEKO-TEX, BSCI, SMETA, ISO 9001, ISO 14001 certificates |
| `number_sequences` | One row per document type for the current year |
| `roles` + `permissions` | From [06-rbac](06-rbac.md) |
| `settings` | overhead_pct=12, admin_pct=5, default_margin_pct=20, cut gaps by cut type (BR-4) |

---

## 9. Verification

```bash
mysql -u root -e "DROP DATABASE IF EXISTS erpspec;
                  CREATE DATABASE erpspec CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root erpspec < docs/02a-schema.sql
```

A clean run proves every foreign key target exists and matches in type and signedness, every check constraint parses, every generated column expression is deterministic, and every view resolves. Run it in CI on any change to the DDL.

The application itself no longer executes this file. Each statement in it has a migration of its own under `database/migrations/2026_01_02_*`, carrying the same DDL verbatim — so a change here is a change there too, and `SchemaInvariantsTest` fails the build if the document names an object no migration creates.

Expected object counts:

| Object | Count |
|---|---|
| Base tables | 129 |
| Views | 4 |
| Foreign keys | 364 |
| Check constraints | 165 |
| Unique keys | 106 |
| Generated columns (emulation, §5.1) | 7 (+1 for `physical_count_lines.variance_qty`) |

Beyond "it loads", four behavioural assertions must pass — these are the invariants the database, not the application, is responsible for:

```sql
-- A2 / Gate 1: a second approved version on one artwork must fail
INSERT INTO artwork_versions (artwork_id,version_no,status,file_path) VALUES (1,2,'approved','/a/v2.ai');
--> ERROR 1062 Duplicate entry '1' for key 'artwork_versions.artwork_versions_one_approved_uq'

-- …but a draft alongside an approved version must succeed
INSERT INTO artwork_versions (artwork_id,version_no,status,file_path) VALUES (1,2,'draft','/a/v2.ai');   --> OK

-- one base currency
INSERT INTO currencies (code,name,is_base) VALUES ('USD','Dollar',1);
--> ERROR 1062 Duplicate entry '1' for key 'currencies.currencies_one_base_uq'

-- BR-38 / I4: negative stock
INSERT INTO stock_lots (lot_no,item_id,kind,warehouse_id,uom_id,balance_qty) VALUES ('L1',1,'raw_material',1,1,-5);
--> ERROR 3819 Check constraint 'stock_lots_balance_chk' is violated

-- BR-33 / QC2: rejection without a disposition
INSERT INTO qc_inspections (stage,result) VALUES ('final','rejected');
--> ERROR 3819 Check constraint 'qc_inspections_rejected_chk' is violated
```
