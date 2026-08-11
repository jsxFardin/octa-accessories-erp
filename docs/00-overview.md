# 00 — Overview

**Product:** Octa ERP — Label & Garment-Accessory Manufacturing ERP
**Primary customer:** Maheen Label (Bangladesh) — garment accessories manufacturer
**Document status:** Specification, v1.0. No application code exists yet.
**Stack (locked):** Laravel 12 · Inertia 2 · Vue 3 · Tailwind 4 · MySQL 8.0 · Redis 7 · single-company deployment

---

## 1. Why this is not a garment ERP

Maheen Label does not cut, sew, or finish garments. It manufactures the *accessories that go into* garments: woven labels, printed labels, tags, tickets and transfers, sold to garment exporters and brands.

A conventional garment ERP is organised around **sewing lines, SMV, bundle tracking, line balancing and operator efficiency**. None of those concepts exist here. This factory is organised around **machines and process routings**: a loom, a flexo press, a screen table, a heat-transfer press, an offset press, a thermal printer. Each product type follows a different route through different machines with different consumables.

Consequently the core production entity is a **Job Card bound to a routing**, not a line plan bound to an order quantity.

## 2. What the business actually does

Confirmed from the company's published capabilities:

**Products**
| Product type | Description |
|---|---|
| Woven label | Damask / satin / taffeta labels woven on needle looms |
| Flexo printed label | Roll-fed flexographic printing on satin, taffeta, nylon, twill |
| Screen printed label | Manual/semi-auto screen printing, heavy ink deposit |
| Heat transfer label | Printed onto carrier film, heat-applied to the garment |
| Offset printed tag / ticket | Sheet-fed offset on art card — hang tags, price tickets |
| Thermal printed label | Variable data — barcodes, size, PO numbers, GS1 |

**Also manufactured / supplied:** ribbon, twill tape, cotton tape, elastic tape, hang tag string, polybags, cartons and related packaging.

**In-house capability**
- Design studio (15+ yrs) — artwork origination and adaptation
- Negative/positive and flexographic plate production
- Screen exposure and development
- Weaving and printing
- Post-production: cutting (hot / ultrasonic / laser / die), folding, heat-seal
- Laboratory: colour bleed, sublimation, wash fastness, shrinkage, shade variation, hot-iron fastness, colour staining, rubbing fastness
- Independent QC team
- Own transport fleet, multi-route delivery

**Raw material sourcing** — yarn (UK, Turkey, China, Hong Kong, India); ribbon (Leader, King, Unifful — China; Sky — India); ink and chemicals (Perfectos — UK).

**Certifications** — FSC, BSCI, GRS-MDEL, GRS-MLTL, ISO 9001, ISO 14001, SMETA 4P, OEKO-TEX Standard 100 (Azo-free), Scope Certificate.

The certification set is a **functional requirement**, not a marketing detail. GRS and FSC both mandate chain-of-custody: certified input must be reconciled against certified output. That drives a dedicated ledger in this system (see [12-compliance-certs](03-modules/12-compliance-certs.md)).

## 3. Vision

One system covering the full commercial and manufacturing lifecycle:

```
Inquiry → Quotation (cost sheet) → Artwork → Sample → Approval
   → Sales Order → BOM → MRP → Purchase → GRN
   → Production Plan → Job Card → Operations → QC + Lab
   → Packing → Delivery Challan → Own-fleet Trip / Export
   → Invoice → Receipt → Compliance reconciliation
```

Every physical unit — yarn cone, ribbon roll, ink tin, plate, screen, WIP roll, carton — carries a barcode and a lot number, so any finished carton can be traced back to its inputs and forward to its customer.

## 4. Goals

| # | Goal | Measured by |
|---|---|---|
| G1 | Kill the parallel Excel/paper system | 0 production-critical spreadsheets after go-live |
| G2 | Quote accuracy | Actual cost vs quoted cost variance < 5% per order |
| G3 | On-time delivery visibility | Every open order shows a system-computed ETA |
| G4 | Waste reduction | Wastage % per machine tracked and trending down |
| G5 | Audit readiness | GRS/FSC/OEKO reconciliation report produced on demand, not rebuilt by hand |
| G6 | Traceability | Any carton → its lots → its GRNs, in ≤ 3 clicks |
| G7 | Artwork discipline | Zero production runs against unapproved artwork |

## 5. Scope

### In scope (this specification)
Master data · product & label specification · artwork versioning · sampling · CRM & sales (inquiry → quotation → sales order) · costing · BOM · procurement · **trade finance, import and landed cost** · inventory & lots · production planning & MRP · manufacturing execution · machines & downtime · quality & laboratory · compliance and chain of custody · packing, dispatch and own fleet · AR/AP invoicing · **factory expenses** · reporting.

### Explicit non-goals (deferred, named here so scope is unambiguous)
| Deferred | Why | Revisit |
|---|---|---|
| Payroll & attendance | Separate system in place | Phase 4 |
| Machine IoT / PLC counters | Manual operation logs first; instrument later once the data model is proven | Phase 4 |
| AI forecasting / demand planning | Needs ≥ 12 months of clean historical data from this system | Phase 5 |
| Customer self-service portal | Spec'd at [15-customer-portal](03-modules/15-customer-portal.md), built later | Phase 3 |
| Native mobile apps | Shop-floor uses browser-based scanner screens on Android devices | Phase 3 |
| Multi-tenant SaaS | Single-company deployment decided; see §8 | — |

## 6. Personas

| Persona | Primary jobs | Key screens |
|---|---|---|
| **Managing Director** | Sales vs target, late orders, machine utilisation, profit per order | Executive dashboard, profitability report |
| **Merchandiser / Sales** | Log inquiry, build cost sheet, issue quotation, convert to SO, chase approval | Inquiry, Quotation, Cost Sheet, Sales Order, Sample tracker |
| **Designer / Studio** | Receive artwork brief, upload versions, respond to customer comments, release to plate/screen | Artwork workspace, version diff, approval queue |
| **Planner** | Sequence job cards on machines, run MRP, flag shortages | Planning board (machine × day), MRP run, shortage report |
| **Production Supervisor** | Release job card, assign operator/machine, log output, waste and downtime | Job card, shop-floor terminal |
| **Machine Operator** | Scan job card, start/stop operation, enter good/waste qty | Scanner screen (large touch targets) |
| **Store Keeper** | Receive GRN, issue material to job card, transfer, count | GRN, Issue, Transfer, Stock enquiry |
| **QC Inspector** | Incoming QC, in-process check, final AQL inspection, record defects | QC inspection, defect capture |
| **Lab Technician** | Run fastness/shrinkage tests, record ratings, issue test certificate | Lab worksheet, test report |
| **Compliance Officer** | Maintain certificates, reconcile GRS/FSC input vs output, prepare audits | Certification registry, CoC ledger, reconciliation report |
| **Dispatch / Fleet** | Pack, print packing list, raise challan, plan trip, capture POD | Packing, Challan, Trip planner |
| **Accounts** | Raise invoice, apply receipts, track ageing, enter supplier bills | Invoice, Receipt, Customer ledger |
| **System Admin** | Users, roles, number sequences, master data, audit trail | Admin settings |

## 7. Glossary

Terms used with a precise meaning throughout the spec. Module docs assume these definitions.

| Term | Meaning |
|---|---|
| **Item** | A stock-keeping raw material, consumable or packaging (yarn, ribbon, ink, plate stock, art card, carton) |
| **Product** | A saleable finished label/tag defined for a specific customer and style |
| **Product Spec** | An immutable, versioned technical definition of a Product (size, material, colours, cut, fold, finish) |
| **Artwork** | The visual design asset for a Product; carries ordered **Artwork Versions** |
| **Approved Version** | The single Artwork Version a customer has signed off; production may only run against this |
| **Tool** | A reusable production aid — flexo plate, screen, cutting die, offset plate. Has a life in impressions |
| **BOM** | Bill of Materials — items and quantities consumed per 1000 finished pieces of a Product |
| **Routing** | Ordered list of operations (with machine group and standard rates) for a product type |
| **Job Card** | A production order for a quantity of one Product, bound to a Routing and an Approved Version |
| **Operation** | One step of a Job Card executed on one machine by one operator during one shift |
| **Lot** | A tracked, barcoded quantity of an Item or WIP with a single origin (one GRN line, or one operation output) |
| **Stock Ledger** | Append-only record of every stock movement; balances are derived, never stored |
| **MPQ / M-piece** | Pricing unit — price is quoted **per 1000 pieces**, abbreviated `/M` |
| **Ends** | Number of label columns woven or printed side-by-side across the ribbon/web width |
| **Cut gap** | Millimetres of ribbon consumed between adjacent labels by the cutting process |
| **Make-ready** | Setup waste consumed bringing a press/loom to saleable quality |
| **AQL** | Acceptance Quality Limit — statistical sampling plan for final inspection (ISO 2859-1) |
| **DHU** | Defects per Hundred Units |
| **CoC** | Chain of Custody — traceable link from certified input material to certified output claim |
| **NCR / CAPA** | Non-Conformance Report / Corrective & Preventive Action |
| **Challan** | Delivery note accompanying goods leaving the factory |
| **POD** | Proof of Delivery — receiver's signature/stamp captured against a trip stop |

## 8. Key architectural decisions

| ID | Decision | Rationale | Consequence |
|---|---|---|---|
| AD-1 | Laravel 12 + Inertia 2 + Vue 3 monolith | ERP is ~150 CRUD screens with shared auth and validation; a split API+SPA doubles the surface for no benefit at this stage | Customer portal is a separate route group + middleware, not a separate app |
| AD-2 | MySQL 8.0 (InnoDB, utf8mb4) | Customer's existing operational stack; `JSON` columns for per-product-type spec attributes, enforced `CHECK` constraints (8.0.16+), `DECIMAL` exactness for money and quantity | No partial indexes and no materialised views — both are emulated, see [02-database-schema §5](02-database-schema.md#5-mysql-specific-decisions). Requires MySQL ≥ 8.0.16 |
| AD-3 | Single company, no `company_id` | Decided by the customer. Simplest schema | A second factory as a *tenant* requires a schema-wide migration. Mitigated by AD-4 |
| AD-4 | `factory_unit_id` on all operational tables | Maheen may run more than one production unit/floor | Multi-unit works from day one without multi-tenancy |
| AD-5 | Status enums as `VARCHAR` + `CHECK`, not MySQL `ENUM` | Adding or renaming a status must not rewrite a column type across a large table | Constraint names are the migration surface; CHECK names are schema-unique in MySQL, so they carry a table prefix |
| AD-6 | Append-only `stock_ledger`; balances derived | Auditability, no lost-update races on quantity | MySQL has no materialised views: `stock_balances` is an application-maintained summary table, with `v_stock_balances` recomputing live for reconciliation |
| AD-7 | Money as `DECIMAL(18,4)`, quantity as `DECIMAL(18,6)` | Label pricing is per-1000 with 4-decimal unit rates; consumption is fractional metres | Never use `FLOAT`/`DOUBLE`. Never use integer cents |
| AD-8 | Document numbering via `number_sequences` table with row lock | Human-readable, gap-free, per-document-type, per-year series | Numbers are assigned on save, not on draft creation |

## 9. How to read this spec

| Read this | If you are |
|---|---|
| [01-domain-model](01-domain-model.md) | Anyone — start here after this file |
| [04-business-rules](04-business-rules.md) | Implementing costing, consumption, MRP or numbering |
| [02-database-schema](02-database-schema.md) | Writing migrations |
| [03-modules/](03-modules/) | Building a specific feature |
| [05-workflows](05-workflows.md) | Implementing status transitions |
| [06-rbac](06-rbac.md) | Wiring permissions |
| [07-api-contracts](07-api-contracts.md) | Building shop-floor/scanner screens |
| [08-architecture](08-architecture.md) | Setting up the repo |
| [10-roadmap](10-roadmap.md) | Planning sprints |
