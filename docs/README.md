# Octa ERP — Specification

**Using the live system?** Start at the [User Guide](ug/README.md) — sign-in, roles, and the factory sequence (inquiry → invoice). This folder is the build specification.

Build-ready specification for a **Label & Garment-Accessory Manufacturing ERP**, scoped to Maheen Label (woven labels, flexo/screen/heat-transfer/thermal printed labels, offset tags and tickets).

Not a garment ERP. Production here is machine-centred and process-routed — loom, flexo press, screen table, heat-transfer press, offset press, thermal printer — with in-house plate and screen making, a physical testing laboratory, FSC/GRS/OEKO-TEX chain-of-custody obligations, and an owned delivery fleet.

**Stack:** Laravel 12 · Inertia 2 · Vue 3 · Tailwind 4 · MySQL 8.0 · Redis 7 · single-company deployment.

---

## Read in this order

| # | Document | What it gives you |
|---|---|---|
| 00 | [Overview](00-overview.md) | Why this is not a garment ERP, scope, non-goals, personas, glossary, architectural decisions |
| 01 | [Domain Model](01-domain-model.md) | Bounded contexts, ERD, aggregates, **invariants**, the two gates |
| 04 | [Business Rules](04-business-rules.md) | Every formula: consumption, costing, MRP, AQL, chain of custody, numbering |
| 02 | [Database Schema](02-database-schema.md) | Design notes · executable DDL in [02a-schema.sql](02a-schema.sql) |
| 03 | [Modules](03-modules/) | 15 module specs with user stories and acceptance criteria |
| 05 | [Workflows](05-workflows.md) | State machines, guards, effects |
| 06 | [RBAC](06-rbac.md) | Roles, permissions, matrix, data scoping |
| 07 | [Interface Contracts](07-api-contracts.md) | Inertia page props + offline-tolerant device API |
| 08 | [Architecture](08-architecture.md) | Modular monolith layout, packages, testing strategy |
| 09 | [Non-Functional](09-nfr.md) | Performance, security, backup, auditability, localisation |
| 10 | [Roadmap](10-roadmap.md) | Phased sprints, MVP boundary, risks |

## Modules

| | | |
|---|---|---|
| [01 Master Data](03-modules/01-master-data.md) | [02 CRM & Sales](03-modules/02-crm-sales.md) | [03 Product & Spec](03-modules/03-product-definition.md) |
| [04 Artwork & Sampling](03-modules/04-artwork-sampling.md) | [05 Costing](03-modules/05-costing.md) | [06 Procurement](03-modules/06-procurement.md) |
| [07 Inventory](03-modules/07-inventory.md) | [08 Planning & MRP](03-modules/08-planning-mrp.md) | [09 Manufacturing](03-modules/09-manufacturing.md) |
| [10 Quality & Lab](03-modules/10-quality-lab.md) | [11 Dispatch & Fleet](03-modules/11-dispatch-fleet.md) | [12 Compliance & CoC](03-modules/12-compliance-certs.md) |
| [13 Finance AR/AP](03-modules/13-finance-ar-ap.md) | [14 Reports & BI](03-modules/14-reports-bi.md) | [15 Customer Portal](03-modules/15-customer-portal.md) |

---

## The four things that make this specification worth following

**1. The formulas are written down before the schema.**
Labels are quoted per 1000 pieces but consumed in metres of ribbon across N ends of a loom. [BR-4 … BR-13](04-business-rules.md#2-consumption-formulas) define that arithmetic, and every column exists because a formula needs it. BR-20 in particular — margin is applied *on price*, not on cost; getting it backwards silently loses `margin²` on every order.

**2. Two gates are enforced by the database, not by discipline.**
No job card may be released without an `approved` artwork version (unique key over a generated NULL-able column + `NOT NULL` FK). No shipment may claim GRS/FSC certification beyond its diluted certified input (chain-of-custody ledger). See [01-domain-model §4](01-domain-model.md#4-the-two-gates-that-define-the-system).

**3. Chain of custody is a first-class module.**
FSC and GRS require input/output reconciliation. Most competing systems cannot produce it. [Module 12](03-modules/12-compliance-certs.md) makes it a report, not a month of spreadsheet archaeology.

**4. The shop floor is designed for the shop floor.**
Badge login, four buttons, Bangla by default, idempotent writes, and a four-hour offline queue — because a loom does not stop when the wifi does. [07-api-contracts §2](07-api-contracts.md).

---

## Verification

The specification is checkable, not merely readable:

```bash
# 1. DDL loads clean — proves every FK, type, constraint and view resolves
mysql -u root -e "DROP DATABASE IF EXISTS erpspec;
                  CREATE DATABASE erpspec CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
mysql -u root erpspec < docs/02a-schema.sql
```

2. **Invariants hold** — the five behavioural assertions in [02-database-schema §9](02-database-schema.md#9-verification) (double artwork approval, second base currency, negative stock, QC rejection without disposition) each fail at the database, not in application code.

2. **Mermaid renders** — every diagram block in 01 and 05 parses.
3. **Traceability** — every table in the schema is referenced by at least one module doc; every user story names its tables and rules.
4. **Domain walkthrough** — trace one real order through the docs end to end: *inquiry for 50,000 centre-fold satin woven care labels → quote → artwork v2 approved → SO → BOM → yarn shortage → PO → GRN → job card (warp/weave/cut/fold) → wash-fastness test → AQL → cartons → challan → own-fleet trip → invoice → GRS reconciliation.* Any step the docs cannot answer is a gap.

---

## Conventions used throughout

| Marker | Meaning |
|---|---|
| `BR-n` | Business rule, defined in [04-business-rules](04-business-rules.md) |
| `P1`, `A2`, `J3`, `I5`, `C1`… | Aggregate invariant, defined in [01-domain-model §3](01-domain-model.md#3-aggregates-and-their-invariants) |
| `AD-n` | Architectural decision, [00-overview §8](00-overview.md#8-key-architectural-decisions) |
| `NFR-n` | Non-functional requirement, [09-nfr](09-nfr.md) |
| `MD-1`, `SL-3`, `MF-7`… | User story, in the module doc whose prefix it carries |

Business rules and invariants are referenced by ID from code comments and test names, so a disputed number is settled by opening one file.
