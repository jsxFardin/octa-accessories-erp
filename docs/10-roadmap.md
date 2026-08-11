# 10 — Delivery Roadmap

Sequenced so that something usable reaches the factory early and each phase leaves the system in a coherent state. Estimates assume **two full-stack developers plus one part-time QA**, two-week sprints.

---

## The MVP boundary

Draw it here:

```
Master data → Product & spec → Artwork approval → Sales order
  → BOM/MRP → Job card → Operations → QC → Packing → Challan → Invoice
```

That is one complete order, end to end, in the system. Everything else — fleet optimisation, compliance reconciliation, portal, AI — is a layer on a working spine. A phase that delivers "all the master data screens" and nothing else has delivered nothing.

---

## Phase 0 — Foundation (Sprints 1–2, 4 weeks)

**Goal:** a running application skeleton nobody has to rewrite.

| Epic | Stories |
|---|---|
| Repo & CI | Laravel 12 + Inertia 2 + Vue 3 + Tailwind 4 scaffold; Docker Compose; GitHub Actions with Pint, Larastan, Pest, and the `02a-schema.sql` load check |
| Auth & RBAC | Login, MFA, password policy; roles, permissions, seeder generated from [06-rbac](06-rbac.md); permission middleware on every route |
| Platform services | `number_sequences` allocator (BR-34), audit log observer, attachment handling, state machine base class, settings |
| Shell UI | `AppLayout`, navigation, `Ui/` component set, table/filter/pagination pattern, toast, confirm dialog |
| Migrations | Full schema from [02a-schema.sql](02a-schema.sql) as Laravel migrations — one per table, view and deferred foreign key — plus factories |

**Exit criteria:** a user can log in, see an empty dashboard, and CI is green including the schema load.

---

## Phase 1 — Commercial spine (Sprints 3–7, 10 weeks)

**Goal:** quote and take an order correctly. This is where the industry-specific value lives, so it comes first.

| Sprint | Epic | Key stories |
|---|---|---|
| 3 | Master data | Items with technical attributes, UoM + conversions, customers, suppliers, machines, warehouses, currencies, taxes (MD-1…MD-6) |
| 4 | Product & spec | Products, versioned specs with live derived values, per-type validation, routings, BOM (PD-1…PD-4) |
| 5 | **Calculators** | `ConsumptionCalculator` (BR-4…BR-13) and `CostSheetCalculator` (BR-14…BR-22) with a unit test per rule ID — built and tested before any screen consumes them |
| 6 | Costing & quotation | Cost sheet panel, tool reuse, snapshotting, quotation with revisions, PDF (CS-1…CS-6, SL-2…SL-4) |
| 7 | Artwork & sales order | Artwork versions with approval gate, sales order with readiness panel, credit check, amendments (AS-1…AS-4, SL-1, SL-5…SL-9) |

**Exit criteria:** an inquiry becomes a costed, sent quotation, becomes a confirmed sales order that cannot be confirmed without an approved artwork and a current spec. Merchandising can stop using spreadsheets for costing.

**Highest-risk sprint:** 5. If the consumption and costing formulas are wrong, everything downstream is wrong. Validate the calculators against ~20 historical orders from the factory's own records before proceeding.

---

## Phase 2 — Manufacturing spine (Sprints 8–13, 12 weeks)

**Goal:** run a real job through the floor and out of the gate.

| Sprint | Epic | Key stories |
|---|---|---|
| 8 | Inventory core | Lots, append-only ledger, GRN, issue, transfer, adjustment, barcode printing (IN-1…IN-5) |
| 9 | Procurement | PR, RFQ comparison, PO with approval bands, GRN with landed cost and certification capture (PR-1…PR-6) |
| 10 | Planning & MRP | `MrpCalculator`, MRP run, shortage → PR, capacity calendar, planning board (PL-1, PL-4, PL-5, PL-8) |
| 11 | Job cards | Generation and splitting, release gate (J1), operation scheduling, job card print with barcode (PL-2, PL-3, PL-6, MF-1) |
| 12 | Shop floor | Device API with idempotency and offline queue, operator terminal, operation logging, downtime, waste, live board (MF-2…MF-6, MF-9) |
| 13 | Quality & dispatch | AQL inspection, defect capture, disposition, FG receipt, packing with scan-to-pack, delivery challan (QL-1…QL-4, DF-1…DF-3) |

**Exit criteria:** an order released in Phase 1 is produced, inspected, packed and dispatched entirely in the system, with full lot traceability from carton back to GRN.

**Rollout note:** run Phase 2 in parallel with the existing paper system for one month on one product type (start with flexo — shortest routing, fastest feedback), then extend to woven.

---

## Phase 3 — Completing the picture (Sprints 14–19, 12 weeks)

| Sprint | Epic | Key stories |
|---|---|---|
| 14 | Laboratory | Lab tests, worksheets, customer thresholds, immutable certificates (QL-5, QL-6) |
| 15 | Compliance & CoC | Certification registry, CoC ledger, claim dilution, reconciliation, evidence pack (CP-1…CP-6) |
| 16 | Fleet | Trips, drop sequencing, driver screen, offline POD capture, vehicle compliance alerts (DF-4, DF-5) |
| 17 | Finance | Invoicing from challan, receipts and allocation, credit notes, ageing, credit control, accounting export (FN-1…FN-7) |
| 18 | Reporting | Report engine, exports, scheduling; the dashboards from [14-reports-bi](03-modules/14-reports-bi.md) |
| 19 | Samples, NCR/CAPA, export docs | Sample lifecycle, NCR/CAPA, export documentation checklist (AS-5…AS-7, QL-7, QL-8, DF-6) |

**Exit criteria:** the paper system is switched off. Compliance can produce a GRS reconciliation without opening Excel.

---

## Phase 4 — Extension (Sprints 20–25, 12 weeks)

| Epic | Content |
|---|---|
| Customer portal | Pilot with 2–3 customers: order status → documents → artwork approval → inquiry ([15-customer-portal](03-modules/15-customer-portal.md)) |
| Maintenance | Preventive maintenance schedules, breakdown history, spare parts, machine cost of ownership |
| HR-lite | Employee attendance import, shift rosters feeding capacity, operator skill matrix |
| Advanced planning | Finite-capacity sequencing, changeover minimisation (group same-colour jobs on a press), what-if scheduling |
| Integrations | Accounting package export, courier tracking webhooks, customer EDI where a brand requires it |

---

## Phase 5 — Intelligence (later, data-dependent)

Only after ≥ 12 months of clean data from this system. Each of these is worthless on bad data and dangerous on assumed data.

| Candidate | Prerequisite |
|---|---|
| Delivery-date prediction | 12 months of promised vs actual, with reasons |
| Consumption learning | Actual vs standard consumption per product, to auto-tune wastage percentages |
| Quality prediction | Defect history correlated with machine, operator, material lot and shade |
| Demand forecasting | Repeat-order history per customer/product |
| Bottleneck detection | Machine load and downtime history |
| Reorder optimisation | Consumption variability + real lead-time distribution per supplier |

---

## Summary timeline

| Phase | Sprints | Weeks | Cumulative | Outcome |
|---|---|---|---|---|
| 0 Foundation | 1–2 | 4 | 4 | Skeleton, auth, CI |
| 1 Commercial | 3–7 | 10 | 14 | Quote → confirmed order |
| 2 Manufacturing | 8–13 | 12 | 26 | Order → dispatched goods |
| 3 Completion | 14–19 | 12 | 38 | Paper system off |
| 4 Extension | 20–25 | 12 | 50 | Portal, maintenance, integrations |
| 5 Intelligence | — | — | — | Data-dependent |

**~26 weeks to a factory running on the system; ~38 weeks to full replacement.**

---

## Risks

| Risk | Impact | Mitigation |
|---|---|---|
| Consumption/costing formulas wrong | Every price and every material plan is wrong | Sprint 5 validates against 20 historical orders before any screen is built on them |
| Shop-floor adoption fails | Data stops at the office door; the whole system becomes a reporting shell | Bangla UI, four-button screens, badge login, on-floor training, supervisor-visible output board from day one |
| Master data migration is dirty | Garbage in, distrust out | Dedicated migration sprint task with CSV dry-run preview; freeze and clean items/customers before Phase 1 exit |
| Offline sync produces wrong totals | Silent data corruption on the floor | Idempotency keys, `occurred_at` ordering, and explicit conflict tests ([07-api-contracts §7](07-api-contracts.md)) |
| Scope creep into full GL/payroll | Phase 3 never ends | Non-goals are written down in [00-overview §5](00-overview.md#5-scope); changes go through a written scope decision |
| Two-developer team, 38 weeks | Key-person risk | `docs/` in the repo, tests per business rule ID, no undocumented tribal knowledge |
| Certification audit lands mid-build | Compliance module not ready | Phase 3 sprint 15 is movable earlier if an audit is scheduled |

---

## Definition of done (every story)

1. Acceptance criteria met and demonstrated on staging with realistic data.
2. Business rules referenced by ID; each has a passing named test.
3. Permission enforced on the route and reflected in the UI.
4. State transitions audit-logged.
5. Larastan and Pint clean; Pest green.
6. Relevant `docs/` page updated in the same pull request.
7. Reviewed by the other developer.
