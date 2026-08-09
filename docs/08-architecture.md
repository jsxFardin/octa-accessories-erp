# 08 — Application Architecture

Stack locked in [00-overview §8](00-overview.md#8-key-architectural-decisions): **Laravel 12 · Inertia 2 · Vue 3 · Tailwind 4 · MySQL 8.0 · Redis 7**, single-company deployment.

---

## 1. Shape

A **modular monolith**. One repository, one deployable, one database — with hard internal boundaries so it does not rot into a ball of mud.

Why not microservices: this is a single-site factory with maybe 60 concurrent users. Distributed transactions across "inventory service" and "production service" would add failure modes with no benefit. The module boundaries below give most of the discipline at none of the operational cost.

```
app/
  Modules/
    MasterData/
    Product/
    Sales/
    Costing/
    Sampling/
    Procurement/
    Inventory/
    Planning/
    Manufacturing/
    Quality/
    Compliance/
    Dispatch/
    Finance/
    Reporting/
    Portal/
  Support/            cross-cutting: numbering, audit, state machines, exports, barcodes
  Providers/
```

Each module:

```
Modules/Manufacturing/
  Models/            JobCard.php, JobCardOperation.php, OperationLog.php
  Services/          JobCardReleaseService.php, OperationLoggingService.php
  States/            JobCardStateMachine.php
  Actions/           single-purpose invokable classes
  Http/
    Controllers/
    Requests/
    Resources/       Inertia DTOs
  Events/            JobCardReleased.php, OperationCompleted.php
  Listeners/
  Policies/
  routes.php
  Tests/
```

### Boundary rules

1. A module may read another module's **models** but must write through its **services**. Manufacturing does not `StockLedger::create()`; it calls `InventoryService::issue()`.
2. Cross-module reactions go through **domain events**, queued. Sales does not call Planning directly; it dispatches `SalesOrderConfirmed`.
3. Shared concerns live in `Support/`, never duplicated per module.
4. A static test (`ModuleBoundaryTest`) asserts no module writes to another module's tables directly.

---

## 2. Request lifecycle

```
Route (permission middleware)
  → FormRequest        validation + authorisation
  → Controller         thin: resolve, call service, return Inertia response
  → Service            business rules, transactions, invariants
  → Model              persistence only
  → Event              dispatched inside the transaction, handled after commit
  → Listener (queued)  side effects
```

Controllers contain no business logic. Models contain no business logic. If a rule from [04-business-rules](04-business-rules.md) is not in a service or a dedicated calculator class, it is in the wrong place.

### Calculators

The formula-heavy rules get their own classes with no persistence, so they are trivially testable:

```
Support/Calculators/
  ConsumptionCalculator.php    BR-4 … BR-12
  CostSheetCalculator.php      BR-14 … BR-22
  MrpCalculator.php            BR-24 … BR-26
  CapacityCalculator.php       BR-27
  ClaimDilutionCalculator.php  BR-40 … BR-42
  AqlResolver.php              BR-30
```

Each has a unit test per business rule ID. When someone disputes a price, the argument is settled by pointing at `CostSheetCalculatorTest::test_br20_margin_on_price()`.

---

## 3. Transactions and invariants

| Rule | How |
|---|---|
| Stock movement | `DB::transaction()`; `SELECT … FOR UPDATE` on the lot; ledger insert + balance cache update together |
| Document numbering | Sequence row locked `FOR UPDATE` inside the same transaction as the insert (BR-34) |
| State transition | Guard, status change, side effects and audit row in one transaction |
| Artwork approval | Approve vN and supersede the previous, atomically (A2) |
| Events | Dispatched inside the transaction, delivered `afterCommit` |

Long-running work (MRP, exports, PDF batches, preview rendering) never runs in a web request. It is queued with progress reporting.

---

## 4. Frontend

```
resources/js/
  Pages/            one per route, matches the module tree
  Layouts/          AppLayout, FloorLayout, PortalLayout, PrintLayout
  Components/
    Ui/             Button, Input, Select, Modal, Table, Tabs, Badge
    Domain/         LotPicker, MachinePicker, DefectGrid, CostSheetPanel,
                    PlanningBoard, GenealogyTree, ArtworkViewer
  Composables/      useFilters, usePermissions, useScanner, useOfflineQueue
  stores/           Pinia — only for genuinely global state (scanner session, offline queue)
```

### Frontend rules

- **No client-side data fetching for pages.** Inertia supplies props. Data changes happen via `router.post` / `router.reload`.
- **Pinia is for device state**, not server state. The offline queue and scanner session are legitimate; a cached customer list is not.
- **Three layouts, three design languages.** `AppLayout` is dense (desk users, keyboard-driven). `FloorLayout` is large-target, high-contrast, four buttons maximum (gloves, glare). `PortalLayout` is mobile-first and branded.
- **Print is server-rendered.** Challans, invoices, job cards, certificates and carton labels are PDFs generated server-side, not browser print CSS. They are legal and customer-facing documents.

### Key components worth naming

| Component | Why it is hard |
|---|---|
| `PlanningBoard` | Machine × day grid, drag-drop, live utilisation, compatibility validation |
| `CostSheetPanel` | Three linked panes, live recomputation, formula references beside every number |
| `ArtworkViewer` | Version rail, overlay diff, comment pins |
| `GenealogyTree` | Recursive lot ancestry, both directions |
| `DefectGrid` | Tap-to-count on a tablet |
| `LotPicker` | Shade-first suggestions with FIFO fallback and override reason capture |

---

## 5. Packages

| Need | Package | Note |
|---|---|---|
| Permissions | `spatie/laravel-permission` | Cached |
| Media | `spatie/laravel-medialibrary` | Artwork, attachments, previews |
| Activity log | `spatie/laravel-activitylog` | Feeds `audit_logs` |
| Excel | `openspout/openspout` | Streams; PhpSpreadsheet dies on large exports |
| PDF | `barryvdh/laravel-dompdf` for documents; `spatie/laravel-pdf` (headless Chrome) where layout is complex | |
| Barcodes | `picqer/php-barcode-generator` (Code 128) + `bacon/bacon-qr-code` | |
| WebSockets | `laravel/reverb` | Self-hosted, no third-party |
| Queues | Redis + Horizon | |
| Charts | Apache ECharts (npm, self-hosted) | No CDN |
| Testing | Pest 3 | |
| Static analysis | Larastan level 6 | |

Deliberately **not** used: a state-machine package (the domain rules are specific enough to warrant explicit classes), an admin panel generator (the shop-floor and planning screens are bespoke and a generator fights them), GraphQL (nothing needs it).

---

## 6. Queues

| Queue | Work | Priority |
|---|---|---|
| `default` | Notifications, events, cache warming | normal |
| `reports` | Excel/PDF exports | low |
| `mrp` | MRP runs | high |
| `media` | Artwork preview rendering | low |
| `integrations` | Accounting export, courier webhooks | normal |

Horizon supervises. Failed jobs are retained 7 days and alert on repeat failure.

---

## 7. Caching

| What | Where | Invalidation |
|---|---|---|
| Master data (items, machines, customers) | Redis, tagged | On write |
| Permissions | Redis (spatie) | On role/permission change |
| Dashboard tiles | Redis, 60 s TTL | Time |
| Report results | Redis, 5 min, keyed by filter hash | Time |
| `stock_balances` | MySQL summary table (no materialised views) | Upserted by the posting service after batch posts; rebuilt on schedule from `v_stock_balances` |

Nothing that affects a stock decision is served from a TTL cache. Availability checks read live.

---

## 8. Configuration and environments

| Environment | Purpose |
|---|---|
| local | Docker Compose: app, mysql, redis, reverb, mailpit |
| staging | Mirror of production, anonymised copy of production data |
| production | Single VM or small cluster; MySQL primary with a GTID-based replica |

Business parameters (overhead %, margin floor, tolerances, cut gaps, approval bands) live in the `settings` table and are editable by an admin. `.env` holds infrastructure only. A rule that changes without a deploy is a setting; a rule that must not change quietly is code with a test.

---

## 9. Testing strategy

| Layer | Tool | Coverage target |
|---|---|---|
| Business rules (calculators) | Pest unit | 100% of BR-* rules, one test per rule ID |
| Services / invariants | Pest feature, real DB | Every invariant in [01-domain-model §3](01-domain-model.md#3-aggregates-and-their-invariants) |
| State machines | Pest feature | Every transition, including every blocked guard |
| Permissions | Pest feature | Every route rejects an unpermitted user |
| Device API | Pest feature | Idempotency, offline ordering, conflicts |
| Portal isolation | Pest feature | Cross-customer access on every portal route |
| Frontend | Vitest | Calculators and formatters only; no component-render theatre |
| Smoke | Playwright | Six critical paths (order → job card → QC → pack → challan → invoice) |

**Non-negotiable tests** (these encode the two gates):

```
it('cannot release a job card without an approved artwork version')
it('cannot approve two artwork versions for the same artwork')
it('cannot drive a lot balance negative')
it('cannot claim GRS output exceeding diluted input')
it('cannot dispatch against an expired certificate')
it('generated key columns still reject a second approved artwork version')
it('margin is applied on price, not on cost')
```

---

## 10. Deployment

```
GitHub Actions:
  lint (Pint) → static analysis (Larastan) → tests (Pest, MySQL 8.0 service)
  → schema check (load docs/02a-schema.sql into a scratch database)
  → invariant check (the five assertions in 02-database-schema §9)
  → build assets (Vite) → deploy
```

Zero-downtime deploy: build, migrate, cache config/routes/views, restart queues and Reverb. Migrations are additive-first — a deploy never drops a column in the same release that stops using it.

Backups: nightly full `mysqldump --single-transaction` (Percona XtraBackup once the dataset outgrows it) plus binary-log shipping for point-in-time recovery; artwork and document storage snapshotted daily; restore rehearsed quarterly (see [09-nfr](09-nfr.md)).

---

## 11. Repository layout

```
octa-erp/
  app/                      Modules/, Support/, Providers/
  bootstrap/  config/  database/{migrations,seeders,factories}
  docs/                     this specification — kept in the repo, versioned with the code
  resources/js/             Pages/, Layouts/, Components/, Composables/
  resources/views/pdf/      server-rendered document templates
  routes/                   web.php, api.php, portal.php, floor.php, console.php
  tests/                    Unit/, Feature/, Browser/
  docker/                   compose + Dockerfiles for local dev
```

`docs/` lives in the repository. A specification in someone's Drive is out of date within a month; a specification beside the code is reviewed in the same pull request that changes the behaviour.
