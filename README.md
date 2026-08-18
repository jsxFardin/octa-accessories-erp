# Octa ERP

Label & garment-accessory manufacturing ERP for **Maheen Label** — woven labels, flexo/screen/heat-transfer/thermal printed labels, offset tags and tickets.

Built to the specification in [`docs/`](docs/README.md). Every business rule in the code carries the ID it implements (`BR-9`, `A2`, `J1`), and every rule has a test named after it.

**Stack:** Laravel 12 · Inertia 2 · Vue 3 · Tailwind 4 · MySQL 8.0 · Redis 7 · single-company deployment.

---

## Using it

Day-to-day how-to (sign-in, roles, each module) is the **[User Guide](docs/ug/README.md)**.

## Getting it running

```bash
composer install
npm install

cp .env.example .env            # then set DB_* and REDIS_*
php artisan key:generate

mysql -u root -p -e "CREATE DATABASE octa_erp CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
php artisan migrate --seed      # schema, reference data, users, and the demo walkthrough

npm run build                   # or: npm run dev
php artisan serve
```

Requires **MySQL ≥ 8.0.16**. Earlier releases parse `CHECK` constraints and silently ignore them, which would turn the invariants below into comments.

### Signing in

Every role has a seeded user; the password is `password` for all of them. **Change it before
this is anything but a demo** — `/profile` lets each user change their own, and the screen
says so while the seed password is still in place.

After signing in, each role lands on a page it can actually open: the dashboard for desk
users, `/floor` for operators, `/trips` for drivers. Nobody is redirected into a 403.

| Login | Role | What it shows off |
|---|---|---|
| `admin@maheenlabel.test` | super_admin | Everything |
| `merchandiser@maheenlabel.test` | merchandiser | Inquiry → quotation → order |
| `designer@maheenlabel.test` | designer | Artwork versions and the approval gate |
| `planner@maheenlabel.test` | planner | Planning board, MRP, job card release |
| `operator@maheenlabel.test` | operator | Four permissions, and nothing else |
| `compliance@maheenlabel.test` | compliance_officer | GRS/FSC reconciliation |
| `auditor@maheenlabel.test` | read_only | View everything, export nothing |

The shop-floor terminal is at `/floor` and signs in by badge (`BADGE-0009`, PIN `0009`).

---

## What is built

| Area | State |
|---|---|
| Schema | Complete — 129 tables, 4 views, 364 foreign keys, 165 check constraints, loaded from `docs/02a-schema.sql` |
| Business rules | `BR-1` … `BR-47` implemented as pure calculators, 96 unit tests, one per rule ID |
| Gate 1 — artwork approval | Complete: generated key column, state machine, release gate, UI |
| Gate 2 — certified input | Complete: claim inheritance, dilution, reconciliation report |
| RBAC | 23 roles, 418 permissions, permission middleware on every route |
| Platform | Numbering (BR-34), audit log, settings, state machine base |
| Master data | Items, customers, suppliers, machines — full CRUD |
| Engineering | Products, versioned specs with live derived geometry, artwork, BOM, routings, tools |
| Commercial | Inquiries, quotations with live cost sheets, sales orders with the S3 readiness panel |
| Supply | GRN with landed cost and certification capture; PO and requisition read screens |
| Inventory | Lots, append-only ledger, stock enquiry with ledger reconciliation, material issue with BR-37 lot suggestion |
| Operations | Planning board, MRP run, job cards with the J1 release gate, shop-floor terminal with an offline queue |
| Assurance | AQL inspection with computed verdict, lab catalogue, compliance registry and CoC reconciliation |
| Fulfilment / Money | List screens; packing, challan, trip and invoice *workflows* are Phase 3 |

Deferred by the roadmap, not by omission: the customer portal (Phase 4), fleet trip execution, invoicing from challan, the report engine, and PDF document rendering. See [`docs/10-roadmap.md`](docs/10-roadmap.md).

---

## The two gates

Almost every production defect in this industry traces to one of two failures, and the model makes both structurally impossible.

**Gate 1 — artwork approval.** `artwork_versions.approved_key` is a `STORED` generated column that evaluates to the artwork id when the version is approved and to `NULL` otherwise, under a plain `UNIQUE` index. MySQL treats `NULL`s as distinct, so only approved rows compete. Combined with `job_cards.artwork_version_id NOT NULL`, there is no code path that releases production against an unapproved design.

```
tests/Feature/Database/SchemaInvariantsTest.php   the database refuses
tests/Feature/Gates/ArtworkApprovalGateTest.php   the application agrees
```

**Gate 2 — certified input.** A GRS or FSC claim enters the system on a GRN line, copies onto the stock lot, and dilutes by consumption-weighted average — rounding **down**, always. Output that exceeds diluted input is flagged by the same figure an auditor asks for.

```
tests/Feature/Gates/CertifiedInputGateTest.php
```

---

## Where things live

```
app/
  Modules/            one directory per bounded context (08-architecture §1)
    <Module>/
      Models/         persistence only
      Services/       business rules, transactions, invariants
      States/         state machines — nothing changes status by assignment
      Http/           thin controllers, form requests
  Support/
    Calculators/      BR-1 … BR-47, pure, no persistence, no framework
    Numbering/        BR-34 sequence allocator
    Audit/            audit_logs writer and the Auditable trait
    States/           state machine base class
    Settings/         the coefficients of the rules
docs/                 the specification, versioned with the code
resources/js/
  Pages/              one per route, matching the module tree
  Layouts/            AppLayout (dense desk) · FloorLayout (gloves and glare)
  Components/Ui/      Button, Badge, Card, DataTable, Modal, FilterBar…
  Composables/        useOfflineQueue — the four-hour shop-floor queue
```

**Boundary rule:** a module may read another module's models but must write through its services. Manufacturing does not call `StockLedgerEntry::create()`; it calls `StockPostingService::issueToJob()`.

---

## Tests

```bash
php artisan test                       # everything
./vendor/bin/pest --testsuite=Unit     # calculators, no database, ~100 ms
./vendor/bin/pest --testsuite=Feature  # invariants, gates, RBAC, route smoke
```

The feature suite runs against **MySQL**, not SQLite: the generated key columns behind Gate 1, the named `CHECK` constraints and the four views have no SQLite equivalent, so a SQLite run would pass while proving nothing.

```bash
mysql -u root -p -e "CREATE DATABASE octa_erp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
```

| Suite | Covers |
|---|---|
| `tests/Unit/Calculators` | One test per `BR-*` rule ID. A disputed number is settled by opening the test named after the rule. |
| `tests/Feature/Database` | The five behavioural assertions from `02-database-schema §9`, written in raw SQL so they bypass every service. |
| `tests/Feature/Gates` | Both gates, from the application side. |
| `tests/Feature/Inventory` | Append-only ledger, derived balances, BR-38 negative-stock refusal. |
| `tests/Feature/Rbac` | Every route is permission-guarded; every permission a route names exists. |
| `tests/Feature/Smoke` | Opens every GET screen — catches a mistyped column before a user does. |

---

## The demo walkthrough

`DemoDataSeeder` seeds the domain walkthrough from the specification as real data, through the same services and state machines the UI uses:

> 50,000 centre-fold satin woven care labels for Nordic Apparel → spec v1 current → artwork v1 rejected, v2 approved → BOM active → GRS-certified yarn received → sales order confirmed → job card planned with its consumption plan snapshotted.

The job card is deliberately left at `planned` rather than `released`: with 180 kg of white yarn received against a larger requirement, the J1 gate has something real to report, which is the point of seeing it.

```bash
php artisan db:seed --class=DemoDataSeeder
```

---

## Conventions

| Marker | Meaning |
|---|---|
| `BR-n` | Business rule — [`docs/04-business-rules.md`](docs/04-business-rules.md) |
| `P1`, `A2`, `J1`, `I4`, `C1` | Aggregate invariant — [`docs/01-domain-model.md`](docs/01-domain-model.md) |
| `AD-n` | Architectural decision — [`docs/00-overview.md`](docs/00-overview.md) |
| `Q1`, `S3`, `QC2`, `D1` | Document invariants, same file as the aggregates |

Money is `DECIMAL(18,4)`, quantity is `DECIMAL(18,6)`, percentages are stored as percentages (`5.5` means 5.5%). Never `FLOAT`. Never integer cents.

Margin is applied **on price** — `unit_cost × 1000 ÷ (1 − margin)` — not on cost. Getting it backwards silently loses margin² on every order, which is why it has its own test.
