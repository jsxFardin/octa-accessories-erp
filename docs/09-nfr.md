# 09 — Non-Functional Requirements

Requirements that no single module owns but every module must satisfy. Each has an ID (`NFR-n`) and an acceptance test.

---

## 1. Performance

| ID | Requirement | Acceptance |
|---|---|---|
| NFR-1 | List screens render in < 800 ms at p95 with 3 years of data | Load test: 500k job cards, 5M ledger rows, 50k orders |
| NFR-2 | Operator terminal actions respond in < 300 ms at p95 | Measured on the factory LAN from a tablet |
| NFR-3 | MRP over a 90-day horizon completes in < 60 s | Queued, with progress; measured on production-size data |
| NFR-4 | Dashboard loads in < 2 s | Tiles cached 60 s |
| NFR-5 | Excel export of 100k rows completes in < 3 min | Queued, streamed via openspout |
| NFR-6 | No report performs a full table scan on `stock_ledger` or `operation_logs` | `EXPLAIN` assertion in CI for every report query (`type` must not be `ALL`) |
| NFR-7 | PDF generation (challan, invoice, job card) < 3 s | Server-rendered |

**Expected scale (3-year horizon):** ~60 concurrent internal users, 15 shop-floor devices, ~200 job cards/day, ~2,000 ledger movements/day, ~8M ledger rows, artwork storage ~200 GB.

---

## 2. Availability

| ID | Requirement | Notes |
|---|---|---|
| NFR-8 | 99.5% availability during factory hours (06:00–23:00 Asia/Dhaka) | ≈ 2.5 h/month budget |
| NFR-9 | Shop-floor devices continue working offline for ≥ 4 hours | Local queue, replay with `occurred_at` ([07-api-contracts §2](07-api-contracts.md)) |
| NFR-10 | Planned maintenance only in the 23:00–06:00 window | Announced 48 h ahead |
| NFR-11 | Zero-downtime deployment | Additive migrations; queue and Reverb restart without dropping work |

Offline tolerance matters more than raw uptime here: a loom does not stop because a server rebooted, so the terminal must keep accepting output and reconcile later.

---

## 3. Data integrity

| ID | Requirement | Enforcement |
|---|---|---|
| NFR-12 | Stock can never go negative | DB `CHECK` + row lock + service guard (BR-38, I4) |
| NFR-13 | `stock_ledger` is append-only | No UPDATE/DELETE in code; DB role for the app lacks those grants on that table; test asserts it |
| NFR-14 | Document numbers are unique and gap-free per series | `FOR UPDATE` allocation; unique index; concurrency test with 50 parallel inserts |
| NFR-15 | Approved artwork is unique per artwork | Unique key over a generated NULL-able column (A2) |
| NFR-16 | Issued invoices and test reports are immutable | Service-level block + test |
| NFR-17 | Every monetary and quantity column is `DECIMAL`, never `FLOAT`/`DOUBLE` | Static check over migrations in CI |
| NFR-18 | Every state transition is audit-logged | Test asserts an `audit_logs` row per transition |

---

## 4. Security

| ID | Requirement | Detail |
|---|---|---|
| NFR-19 | Authentication | Email + password, Argon2id hashing; MFA available, mandatory for `md`, `admin`, `accounts` |
| NFR-20 | Shop-floor authentication | Badge scan + 4-digit PIN, shift-length session, device-bound token |
| NFR-21 | Authorisation | Permission-checked on every route; global scopes for factory unit and customer ([06-rbac §4](06-rbac.md#4-data-scoping)) |
| NFR-22 | Transport | TLS 1.3 everywhere including the factory LAN; HSTS |
| NFR-23 | At rest | Full-disk encryption on database and file storage; database backups encrypted |
| NFR-24 | Secrets | Environment variables via the platform's secret store; never in the repository |
| NFR-25 | File access | Signed, expiring URLs only; no publicly reachable storage paths |
| NFR-26 | Session | 8 h idle timeout internal, 30 min portal; invalidate on password change |
| NFR-27 | Rate limiting | 60 req/min per user on write routes; 120/min per device token; 20/min on auth endpoints |
| NFR-28 | Input | All input validated server-side; Eloquent parameter binding only; no raw SQL string interpolation |
| NFR-29 | Output | Vue escapes by default; `v-html` is forbidden outside a reviewed allowlist |
| NFR-30 | Dependency hygiene | `composer audit` and `npm audit` in CI; build fails on high/critical |
| NFR-31 | Portal isolation | Cross-customer access tested on every portal route |
| NFR-32 | Admin actions | User creation, role change, permission change, credit release, cost override — all audit-logged with actor and reason |

---

## 5. Auditability

| ID | Requirement |
|---|---|
| NFR-33 | Every create, update, delete and status change on a transactional document writes an `audit_logs` row with user, timestamp, IP, old and new values |
| NFR-34 | Audit records are retained 7 years and are not editable through the application |
| NFR-35 | Any document shows its full history in one click |
| NFR-36 | Compliance transactions (`coc_transactions`) become immutable when their period closes (C3) |
| NFR-37 | Data exports are logged: who exported what, when, with which filters |

Bangladeshi VAT and buyer social-compliance audits both ask for this. It is cheaper to build it in than to reconstruct it later.

---

## 6. Backup and recovery

| ID | Requirement |
|---|---|
| NFR-38 | **RPO ≤ 15 minutes** — binary log shipping to off-site storage |
| NFR-39 | **RTO ≤ 4 hours** — documented, rehearsed restore procedure |
| NFR-40 | Nightly full backup (`mysqldump --single-transaction`, or Percona XtraBackup once the dataset outgrows it), retained 30 days daily / 12 months monthly |
| NFR-41 | Artwork and document storage snapshotted daily, retained 90 days |
| NFR-42 | Restore rehearsed quarterly into staging; the rehearsal is signed off in writing |
| NFR-43 | A replica exists (MySQL asynchronous replication, GTID-based) and can be promoted |

A backup that has never been restored is a hope, not a backup. NFR-42 is the one that matters.

---

## 7. Usability

| ID | Requirement |
|---|---|
| NFR-44 | Desk screens are keyboard-operable end to end; every list has type-ahead search and every form submits with Ctrl+Enter |
| NFR-45 | Shop-floor screens: minimum 48 px touch targets, minimum 18 px text, contrast ≥ 7:1, maximum four primary actions per screen |
| NFR-46 | Every blocked action explains why and links to the blocker — never a disabled button with no reason ([07-api-contracts `blockedBy`](07-api-contracts.md)) |
| NFR-47 | Every destructive action requires confirmation naming what will be affected |
| NFR-48 | Bilingual UI: English and Bangla, switchable per user; shop-floor screens default to Bangla |
| NFR-49 | All dates display in Asia/Dhaka; all timestamps are stored in UTC |
| NFR-50 | Numbers use the local grouping convention consistently; quantities and rates follow the display rules in BR-47 |

NFR-48 is not optional. An operator terminal in English is an operator terminal nobody uses correctly.

---

## 8. Maintainability

| ID | Requirement |
|---|---|
| NFR-51 | Larastan level 6 passes with no baseline suppressions for new code |
| NFR-52 | Pint (Laravel preset) formatting enforced in CI |
| NFR-53 | Every business rule ID from [04-business-rules](04-business-rules.md) has at least one named test |
| NFR-54 | Module boundaries are enforced by an automated test, not convention |
| NFR-55 | `docs/` is updated in the same pull request as any behaviour change it describes |
| NFR-56 | Migrations are additive-first; a column is deprecated in one release and dropped in a later one |
| NFR-57 | Seed data is idempotent and re-runnable |

---

## 9. Observability

| ID | Requirement |
|---|---|
| NFR-58 | Structured JSON logs with a request id correlating web, queue and websocket entries |
| NFR-59 | Error tracking (Sentry or equivalent) with release tagging |
| NFR-60 | Queue depth, failed jobs, and job runtime monitored; alert on depth > 500 or repeat failure |
| NFR-61 | Database slow-query log at 500 ms, reviewed weekly during the first quarter after go-live |
| NFR-62 | Health endpoint checking database, Redis, queue and storage |
| NFR-63 | Business alerts, not just technical ones: MRP run failed, certificate expiring, machine idle beyond threshold, stock negative attempt |

---

## 10. Compliance & retention

| ID | Requirement |
|---|---|
| NFR-64 | Transactional records retained 7 years (VAT and customs) |
| NFR-65 | Artwork files retained for the life of the customer relationship plus 3 years |
| NFR-66 | Test certificates retained 5 years |
| NFR-67 | Compliance transactions retained for the certification cycle plus 5 years |
| NFR-68 | Personal data (employees, contacts) removable on request, with transactional references anonymised rather than deleted |

---

## 11. Localisation

| Concern | Setting |
|---|---|
| Timezone | Asia/Dhaka (per factory unit) |
| Base currency | BDT; quoting in USD, EUR, GBP |
| Language | English + Bangla |
| Date format | dd-MMM-yyyy (unambiguous on printed documents) |
| Fiscal year | July–June (Bangladesh) |
| Tax | VAT with Mushak challan references; AIT where applicable |
| Paper | A4 for documents; 100×150 mm carton labels; 50×25 mm lot labels |
