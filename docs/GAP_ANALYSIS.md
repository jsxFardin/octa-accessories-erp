# Gap analysis — Trimflow (octa-erp)

Date: 15 October 2026. Branch `main` @ `bc89fa6`. Investigation only; no source file was changed.

**Method.** The specification (`docs/00`–`11`, `docs/03-modules/*`) was compared feature-by-feature and rule-by-rule against the code, routes, Vue pages and tests. Five parallel reviews covered the commercial modules (MD, SL, PD, AS, CS, PR, IN, PL), the manufacturing-to-finance modules (MF, QL, DF, CP, FN, RP, PT, TR), the non-functional requirements and security, engineering health and tests, and the shop-floor terminal, device API, offline queue, portal and notifications. The headline claims (marked ✔) were re-verified by hand. Static tools were run once: PHPStan, Pint, `composer audit`, `npm audit`. The Pest suite was run as a baseline.

**Baseline.** Pest: 1691 passed, 3 skipped, 1694 total, 0 failures. PHPStan level 6: 170 errors in 31 files. Pint: 1 file failing. No CI.

**Relation to the UX audit.** All 79 findings in `docs/UX_AUDIT.md` are closed. This document is about what the UX audit did not cover: spec coverage, business-rule enforcement, security, data integrity and engineering practice.

---

## 1. Executive summary

The commercial spine (inquiry → quotation → order → job card → operations → FG → challan → invoice → receipt) is implemented end to end and well tested at the HTTP level. The weaknesses fall into seven groups:

1. **Security gaps that a logged-in user can exploit today.** A holder of `user.update` can make any account super_admin. Sessions survive a password change. Every pre-existing employee's floor PIN is the last four characters of their badge number. The device API checks no permission. Write routes have no rate limit.
2. **Certification claims are not enforced.** The BR-41 threshold is computed but never applied; B-grade lots carry the full claim; a certified GRN line needs no transaction-certificate number; nothing checks the supplier's certificate validity; no certificate-expiry job exists. For an FSC/GRS-audited factory these are the most consequential functional gaps.
3. **Quality gates leak.** Received lots are `available` before incoming QC. An operation flagged `requires_qc` can be finished with no inspection. Downgrade and scrap dispositions change no stock. DHU excludes minor defects.
4. **Floor data integrity.** Queued offline output is booked under whoever is signed in when it flushes. A completed operation can be finished again. Start and downtime skip the release check. A brief server error at reconnect turns the whole queue into "Not sent".
5. **Whole spec'd surfaces are absent.** Sampling workflow, purchase returns, production plans, live board, export documents, customer complaints, claim validator, accounting export, customer ledger and ageing, report scheduling, ~50 of ~60 catalogue reports, the customer portal, domain events, e-mail notifications, bilingual desk.
6. **Costing and finance accuracy.** Invoice tax is hard-coded to zero and Mushak is never captured. Downtime is not excluded from machine hours. Waste is valued at zero. Weighted average is per item, not per warehouse. Short shipments pass with no override.
7. **Engineering practice.** No CI, PHPStan red, Pint red, one model factory for 138 models, zero component or end-to-end tests, locale switch inert, business logic in controllers, MRP and exports synchronous, dependency advisories open, docs describe packages that are not installed.

Recommended order: §6.

---

## 2. Security and access control

| # | Finding | Severity | Effort | Evidence |
|---|---|---|---|---|
| S-01 ✔ | `PUT admin/users/{user}` runs under `can:user.update` and calls `assignRole()`, which syncs any role including `super_admin`. Only `portal_customer` is refused. `user.assign_role` is bypassed. | Critical | S | `routes/web.php:810`; `app/Support/Platform/Http/Controllers/UserController.php:117,236-247` |
| S-02 ✔ | No `AuthenticateSession` middleware and no `logoutOtherDevices()`. A stolen or shared session survives a password reset (NFR-26). | High | S | `ProfileController.php:74`; `UserController.php:115`; grep over `app bootstrap config` = 0 |
| S-03 | Floor PIN backfill set every existing employee's PIN to the last four characters of their badge number; seeded users use `0001…`; `composer setup:uat` ships them. Anyone reading a lanyard can book output as that operator. | High | S | `database/migrations/2026_08_25_000300_add_pin_hash_to_employees_table.php:31-36`; `UserSeeder.php:85` |
| S-04 | PIN brute force: 10 attempts/min per IP, no per-badge lockout, no failed-attempt audit. A NAT'd floor shares one bucket. | High | S | `DeviceSessionRegistry.php:122-148`; `routes/api.php:14`; `routes/floor.php:31` |
| S-05 | Device API write routes (`start/log/finish/downtime`) check `device` + unit scope only; no `operation.*` permission. Any active employee with a user and PIN can post output. | High | S | `routes/api.php:17-32` vs `routes/floor.php:38` |
| S-06 | No rate limit on device writes (NFR-27: 120/min) or on any web write route (60/min). Only login and badge are throttled. | Medium | S | `AppServiceProvider.php:119-122`; `api` group has no limiter |
| S-07 | bcrypt not Argon2id; no MFA (`two_factor_secret` column unused); no password reset (`canResetPassword=false`) (NFR-19). | High | M | no `config/hashing.php`; `app/Models/User.php:27`; `AuthenticatedSessionController.php:22` |
| S-08 | Portal: `PortalContext` is bound and read by nothing; `BelongsToCustomer` scope does not exist; `portal_customer` role is seeded with `sales_order:view`, `sales_invoice:view`, `delivery_challan:view`. Mitigated only by `UserController` refusing to assign the role. Any other path (seeder, tinker, SQL) exposes every customer's data. | Medium (latent Critical) | L | `RoleSeeder.php:310-318`; `routes/portal.php:9-19`; `PortalContext.php` |
| S-09 | Device token not bound to a device; `machine_code` on the session is never checked on writes; token lives to its 9 h TTL after employee deactivation (`resolve()` does not re-check `is_active`); plaintext in `localStorage`. | Medium | M | `DeviceSession.php:51-61`; `DeviceSessionRegistry.php:196-206`; `Queue.vue:57` |
| S-10 | Web session idle timeout defaults to 120 min (`.env.example`); floor pages are web routes, so a kiosk can be bounced to login mid-shift independent of the 9 h device token. No separate portal lifetime. | Medium | S | `config/session.php:35` |
| S-11 | Audit gaps (NFR-32/33): user creation not logged; role permission edits not logged; `SalesInvoice`, `PurchaseOrder`, `Grn`, `Receipt`, `QcInspection`, `Customer`, `Supplier`, `User`, `Role`, `Trip` are not `Auditable` (transitions are still logged by the state machines). Report exports are not logged while list exports are (NFR-37). | Medium | S–M | `UserController.php:74-97`; `RoleController.php:83-100`; `ReportController.php:72-110` |
| S-12 | `supplier.approve` permission is seeded but never enforced: `is_approved` is a plain form boolean under `supplier.update`. | Low | S | `SupplierController.php:205`; `RoleSeeder.php:250` |
| S-13 | Dependency advisories: composer 8 (`laravel/framework` 13.24 < 13.30, `league/commonmark` 5× high transitive), npm 7 (`vue` < 3.5.42 high, `shell-quote` critical dev). `npm audit fix` resolves all npm ones. | Medium | S | `composer audit`; `npm audit` |

---

## 3. Business-rule and spec gaps

Severity: **Critical** blocks a core flow or corrupts data; **High** core flow works but a stated rule is unenforced; **Medium** missing convenience or report.

### 3.1 Compliance and chain of custody

| # | Finding | Sev | Eff | Evidence |
|---|---|---|---|---|
| C-01 ✔ | BR-41 claim threshold never enforced: `ClaimDilutionCalculator::meetsThreshold()` has zero callers. A packing list carries a GRS/FSC claim at any diluted percentage. | Critical | S | `PackingListStateMachine.php:205-228` |
| C-02 ✔ | Grade only matters for `reject`. B-grade FG lots become `available` and inherit the full certification claim; nothing flags grade at packing (DF-1 AC3). | Critical | S | `FgReceiptService.php:134-136,527-556` |
| C-03 | Certified GRN line: scheme, percentage and TC number all nullable (CP-2 AC1); no supplier-certificate validity check (CP-2 AC3); PO `cert_claim` never compared at receipt (PR-3 AC6). | High | S | `GrnController.php:385-387` |
| C-04 | No nightly certificate-expiry flip and no `reminder_days` alerts (CP-1); `issuing_body`, `scope_description`, `document_path` optional at registration. | Medium | M | `routes/console.php`; `ReferenceRegistry.php:650-672` |
| C-05 | Claim validator / evidence pack (CP-6), audit calendar and findings (CP-7), reconciliation Excel export (CP-5 AC4) absent. | Medium | L | no `/compliance/validate`; `Reconciliation.vue` |
| C-06 | BR-42 reconciles output against consumption, not GRN input. Deliberate but `docs/04-business-rules.md` not updated. | Low | S | `2026_08_25_000100_add_consumption_to_v_coc_reconciliation_view.php` |

### 3.2 Quality

| # | Finding | Sev | Eff | Evidence |
|---|---|---|---|---|
| Q-01 ✔ | GRN posts lots as `available`; no `pending_qc`/quarantine, no GRN state machine, no accept/reject split, no release step, no supplier rating (PR-4 AC5, PR-5, QL-1). Lots are issuable before incoming QC. | Critical | M | `GrnController.php:547,579`; `QcInspectionController.php:145-147,232-236` |
| Q-02 | `requires_qc` operation can be finished with no inspection; only the successor's start is held (QL-2 AC1). Failed in-process QC raises an NCR but does not hold the card (QL-2 AC2). | High | M | `OperationBookingService.php:216-260,423-425`; `JobCardOperation.php:58-77` |
| Q-03 | `downgrade` creates no second-quality lot; `scrap` posts no waste movement; lots just go `blocked` (QL-4 AC4/5, BR-33). | High | M | `QcInspectionController.php:302-308`; `Ncr.php:128-176` |
| Q-04 | DHU excludes minor defects (BR-31 says total); `aql_plan_id` never written so QC1 "must reference an aql_plan" is unmet; `customer` stage in DB CHECK but omitted from controller `Rule::in`. | Medium | S | `AqlResolver.php:455`; `QcInspectionController.php:150,197-219` |
| Q-05 | Test report issue guard checks ≥1 line, not completeness of mandatory tests; "mandatory" means `lab_tests.is_active`, not `customer_test_requirements.is_mandatory`; no supersedes link; reports not visible from lot. | Medium | S | `TestReportStateMachine.php:395-444` |
| Q-06 | NCR severity does not drive CAPA deadline (QL-7 AC2); customer complaint flow absent (QL-8); `disposition_ref` not required for concession at inspection. | Medium | M | `NcrStateMachine.php`; grep `complaint` |

### 3.3 Manufacturing and floor

| # | Finding | Sev | Eff | Evidence |
|---|---|---|---|---|
| M-01 | Offline queue flushes with whichever device token is live. A second badge can sign in at `/floor` without END SHIFT, overwriting `octa.device_session`; the previous operator's queued output is booked under the new operator. Idempotency scope is keyed to the token, so a half-sent queue can double-book. | Critical | M | `useOfflineQueue.js:184-196`; `Queue.vue:56-62`; `OperationEventController.php:132,266-271` |
| M-02 | `finish` on a completed operation is accepted: `finished_at` overwritten, `actual_minutes` recomputed, tool `used_impressions` incremented again. No 409 anywhere. FINISH button shown regardless of status. | High | S | `OperationBookingService.php:216-279`; `Operation.vue:324` |
| M-03 | `start` and `downtime` skip the J1 released check (only `log` has it); START allowed on `on_hold` cards (MF-6). Resume from `on_hold` has no guard and `hold_reason` persists. | High | S | `OperationEventController.php:40-77,170-204`; `JobCardStateMachine.php:45,83-92` |
| M-04 | `flush()` permanently rejects every non-2xx (401, 429, 5xx) with no backoff. A ten-minute 503 at reconnect converts a shift's queue to "Not sent". Records older than exactly 4 h are expired to manual re-keying although the server accepts any past `occurred_at`. | High | S | `useOfflineQueue.js:15,116-125,230-235` |
| M-05 | Downtime is not subtracted from actual machine hours in post-production costing (MF-4 AC4 / BR-23). No OEE. | High | M | `PostProductionCosting.php:88-95` |
| M-06 | Waste valued at 0 and posts no `waste` ledger movement; no over-allowance supervisor flag (MF-5 AC2/3). | High | M | `OperationBookingService.php:286-322` |
| M-07 | J5 over-production refuses outright with no supervisor approval path (MF-7 AC3). Final QC inspection is not auto-created on `in_production → qc_pending`. Cancel does not return issued material. | Medium | M | `JobCardStateMachine.php:311-322,401-416`; `OperationBookingService.php:128-140` |
| M-08 | Over-issue beyond BOM requirement + wastage needs no override reason (MF-2 AC4). | Medium | S | `MaterialIssueController.php:351-390` |
| M-09 | No live production board, no broadcasting of any kind (MF-9, 07 §5); planning board has no refresh. | High | L | no Reverb/Echo; `routes/web.php` |
| M-10 | No pause endpoint; no machine-level downtime (operation required, always closed); no standalone waste; no scan endpoint; device API has 6 of 24 spec'd endpoints; error envelope lacks `code` on 401/403/404/428. | Medium | L | `routes/api.php`; `bootstrap/app.php:110-121` |
| M-11 | Tool life: `used_impressions` only for shot-counted kinds; no 90 % re-make worklist (PD-5 AC3, MF-8 AC3); scrap needs no reason. BR-13 reuse ignores `colour_index`. | Medium | S | `OperationBookingService.php:268-280`; `ConsumptionCalculator.php:280-296` |

### 3.4 Sales, product, costing, procurement, inventory, planning

| # | Finding | Sev | Eff | Evidence |
|---|---|---|---|---|
| P-01 ✔ | Sampling workflow absent (AS-5/6/7): four models, no routes, controllers, pages or state machine; `job_cards.sample_request_line_id` never set. | Critical | L | `app/Modules/Sampling/` |
| P-02 ✔ | `so_delivery_schedules` has no writer; `MrpCalculator::splitIntoJobCards()` (BR-28) is unit-tested but never called. Multi-shipment orders cannot be modelled. | High | M | `SoDeliverySchedule.php`; `MrpCalculator.php:109` |
| P-03 | Purchase return absent (PR-7): models only. | High | M | `app/Modules/Procurement/Models` |
| P-04 | Closing a sales order never releases reservations (SL-8 AC3, IN-7, DF-7 AC3); only job-card paths release. | High | S | `ReservationService::releaseForJob` callers |
| P-05 | Inquiry status set inline from any status to any; quotation `sent → revised` via direct `update()` outside the state machine. No permission, no SM audit on those paths. | High | S | `InquiryController.php:346-367`; `QuotationController.php:511` |
| P-06 | MRP simplifications: stored `qty_per_base` (ignores `formula_ref`), `on_order` ignores due date (BR-24), lead time from `suppliers` not `supplier_items` (BR-26), demand aggregated per item not need date, PRs from a run get `origin='manual'` with no requirement link, covered shortages not excluded. Runs synchronously in the request (NFR-3). | High | M | `MrpController.php:62,90-91,125-127`; `StockAvailability.php:41-48`; `PurchaseRequisitionController.php:132` |
| P-07 | PO: no MOQ/multiple rounding (BR-25), no revision path although the UI says "changes create revision n+1", no over-receipt tolerance (PR-4 AC2), no PR approval thresholds (PR-1 AC3). | High | M | `PurchaseOrderStateMachine.php:115`; `PurchaseOrderController.php:215,230`; `GrnController.php:104` |
| P-08 | Scheduler validates machine group only; web width, max colours, rate/kW/efficiency never checked (MD-3 AC2/3, PL-2 AC1). `breakdown` status has no consumer (PL-7). | High | S | `OperationScheduler.php:180-203` |
| P-09 | No production plans or frozen window (PL-1); promised date computed from `delivery_date` (or now + 3 weeks), not last operation finish (BR-29), and never recomputed on reschedule or amendment (PL-8, SL-7 AC4). | High | L | `SalesOrderStateMachine::onConfirmed`; `MrpCalculator.php:90-104` |
| P-10 | Tolerances default from settings, not `customers.over/under_tolerance_pct` (BR-44, MD-4 AC1, SL-5 AC4). Customer PO nullable on conversion (SL-5 AC2). Customer with open orders and item with stock can be deactivated (MD-4 AC4, MD-1 AC5); base UoM mutable after stock exists (MD-1 AC2). | High | S | `QuotationConversionService.php:181-182`; `QuotationController.php:486`; `CustomerController.php:289` |
| P-11 | Short shipment under BR-44 band issues silently; only `over` is guarded. `gate_pass_no` not in validation so cannot be captured. | High | S | `DispatchService.php:273`; `DeliveryChallanController.php:75-83` |
| P-12 | Weighted average per item, not per item per warehouse (BR-36). UoM conversion calculator has no callers outside Calculators, so conversions are not applied at GRN/issue (MD-2). | Medium | M | `StockPostingService.php:184-198`; `InventoryValuator.php:170-189` |
| P-13 | Quotation revision copies lines but not cost sheets; no revision diff (SL-4). Cost-override knobs carry no reason or original value (CS-3). Minimum-charge not acknowledged at send (CS-4 AC2). No what-if / price breaks (CS-6). No ±5 % variance review flag (CS-7 AC3). | Medium | M | `QuotationController.php:498-515`; `QuotationStateMachine.php` |
| P-14 | Product can be activated without approved artwork (PD-1 AC4); BOM activation validates nothing (PD-3 AC4); `bom_lines.formula_ref` stored but never evaluated (PD-3 AC3). Artwork preview never rendered; no submission sheet; new approval does not flag open job cards (AS-1/2/4). | Medium | M | `ItemActivationChecklist`; `BomController::promote`; `BomLine.php:19` |
| P-15 | Stock: adjustment reason is free text (IN-5); no nightly `expired` status (IN-8 AC2); no stock-ageing or valuation reports; trace tree shows parent only. | Medium | S–M | `StockAdjustmentController.php:86`; `routes/console.php` |
| P-16 | Price lists exist but quotation never reads them. Inquiry idle >7 days follow-up list absent (SL-1 AC5). Order-book screen absent (SL-9). No domain events at all (SL-6 AC4, AS-3 AC4). | Medium | M | `QuotationController.php`; `WorkQueue.php` |

### 3.5 Dispatch, finance, trade

| # | Finding | Sev | Eff | Evidence |
|---|---|---|---|---|
| F-01 | Invoice `tax_amount` hard-coded 0, `mushak_no` never set (FN-1 AC3). One challan per invoice; accepts `issued/in_transit` challans where spec says `delivered`. | High | M | `SalesInvoiceController.php:63-76,136,143` |
| F-02 | Supplier bill 3-way match: value breach not checked; match keyed by `item_id` so duplicate items collapse (FN-4, PR-8). | Medium | S | `SupplierBillStateMachine.php:107-147` |
| F-03 | Credit note: no NCR link for quality claims (FN-3 AC2); approval band keyed to MD *role* not a permission. | Medium | S | `CreditNoteController.php:67-108`; `CreditNoteStateMachine.php:103-105` |
| F-04 | Missing finance surfaces: customer ledger, ageing buckets, credit-control screen, over-limit daily digest (FN-6 AC4), FX variance report (FN-5 AC2), accounting posting export (FN-7). Invoice `overdue` is never set on a schedule; a test asserts it is not. | Medium | M–L | `ReceivableReport.php`; `NotificationTest.php:528-539` |
| F-05 | Packing: no `bundle_size`/`bundles_per_carton` defaults (DF-2 AC2); carton labels not in the print registry. | Medium | S | `PackingListController.php` |
| F-06 | Trips: vehicle `capacity_kg` selected but never compared (DF-4 AC3); POD has no signature/photo, no "arrived", no offline queue; failed delivery reverses stock rather than re-queueing (DF-5). Vehicle/driver expiry alerts absent. | Medium | M | `TripController.php:74,123-194,336-408` |
| F-07 | Export documents (DF-6) absent: model only. | Medium | L | `ExportDocument.php` |
| F-08 | LC ladder permits `opened→closed` and `shipped→closed` jumps where spec says 422; shipment `costed` reachable manually from `cleared`. | Low | S | `LetterOfCreditController.php:225-231`; `ImportShipmentController.php:200` |

---

## 4. Data integrity and platform

| # | Finding | Sev | Eff | Evidence |
|---|---|---|---|---|
| D-01 | `stock_ledger` append-only is convention only: no DB grant restriction, no trigger, no model guard, no test asserting UPDATE/DELETE is refused (NFR-13, I1). `operation_logs` cascade-delete from `job_card_operations`. | Medium | S | `StockLedgerEntry.php`; migration `…000104:43` |
| D-02 | `quotations.number` and `mrp_runs.number` have no unique index (NFR-14); 18 of 20 document tables do. No 50-parallel concurrency test. | Medium | S | `…000058_create_quotations_table.php`; `…create_mrp_runs_table.php` |
| D-03 | Sales invoice has a `paid → issued` reversal path; immutability of issued invoices and test reports is by absence of an edit route only (NFR-16). | Low | S | `SalesInvoiceStateMachine.php:46` |
| D-04 | Notifications: `database` channel only, `MAIL_MAILER=log`; ~10 spec'd events missing (artwork submitted/approved, quotation sent, QC fail hold, dispatched, shortages, cert expiry, waste over allowance, portal inquiry). Queued, so silently absent without a worker. | Medium | M | `DocumentNotification.php:26-39`; `Notifier.php` |
| D-05 | Scheduling: invoice overdue, MRP nightly, certificate expiry, over-limit digest, stock expiry not scheduled. `queue:health` has no depth-500 or failed-job alert. | Medium | S–M | `routes/console.php` |
| D-06 | No job classes anywhere: MRP, 50k-row exports, PDF all run inside the request (NFR-3/5). Dashboard tiles uncached (NFR-4). | Medium | M | `MrpController.php:58`; `ExportController.php:44-76`; `DashboardController.php` |
| D-07 | Observability: text logs, no request id, no error tracker, `/up` checks nothing (NFR-58/59/62). No backup command or package (NFR-38–43). No retention or anonymisation (NFR-64–68). | Medium | S–M | `config/logging.php`; `bootstrap/app.php:19` |
| D-08 | `.env.example` defaults to `DB_CONNECTION=sqlite` although CHECK constraints and views only exist on MySQL; `composer setup` copies it verbatim. | Medium | S | `.env.example:15`; `README.md:60` |

---

## 5. Engineering practice

| # | Finding | Sev | Eff | Evidence |
|---|---|---|---|---|
| E-01 | No CI. Pest, vitest, Pint, PHPStan, `composer audit`, `npm audit` never run automatically. Roadmap claims GitHub Actions. | Critical | S | `.github/` absent; `docs/10-roadmap.md:26,150` |
| E-02 | PHPStan level 6: 170 errors in 31 files (82 in Costing); includes a real type hole where a `Model` is passed as `JobCard`. Pint fails on `bootstrap/app.php`. Both are roadmap exit criteria. | High | M | `CostSheetPage.php:75,88-113`; `CostStages.php`; `SalesOrderStateMachine.php` |
| E-03 | One factory (`UserFactory`) for 138 models; `::factory(` used in 0 tests; 149 of 151 feature files read the seeded walkthrough; 29 conditional `markTestSkipped` sites depend on the seed's shape and can silently drop coverage. | High | L | `database/factories/`; `ProductionChainBypassTest.php:69-173`; `tests/TestCase.php:25` |
| E-04 | Money/stock HTTP actions with no behavioural test: credit-note apply/refund, sales-return store/transition, import-shipment store/transition/removeCost, LC store/detachOrder, carton edit/delete, BOM activate, spec makeCurrent, artwork transition, `ProfileController@updatePassword`, `MachineController` 0/7. 300 of 392 controller actions covered; 92 GET routes smoke-only. | High | M | route-coverage run (scratch `routecov3.php`) |
| E-05 | Zero Vue component or page tests (130 pages, 47 components); no Playwright/Dusk although `docs/08-architecture.md:246` promises six smoke paths. `tests/Js` covers pure modules only (20 files, 161 cases). | High | L | `package.json`; `docs/e2e-browser-prompt.md` is manual |
| E-06 | Locale switch is inert outside `/floor`: no `lang/`, 0 `__()`, ~1,390 hard-coded Vue strings, ~146 PHP refusal strings. Only `resources/js/floor/dictionary.js` is bilingual (NFR-48). | High | L | `SetLocale.php`; `HandleInertiaRequests.php:69,88` |
| E-07 | Business logic in controllers: `GrnController::postReceipt` inserts `coc_transactions` directly, `JobCardController::store` builds the consumption plan and inserts operations, `MaterialIssueController::storeReturn` inserts lines. Procurement has no `Services/` directory. Direct `->insert(` in 12 controllers. | Medium | L | `GrnController.php:495-579`; `JobCardController.php:314-382`; `MaterialIssueController.php:608` |
| E-08 | N+1: material-issue index 3 queries per row; sales-return index customer per row; trip index `stops()->count()` per row; sales-order show 4 aggregates + `activeBom()->exists()` per line. | Medium | S | `MaterialIssueController.php:78-81`; `SalesReturnController.php:52`; `TripController.php:64`; `SalesOrderController.php:273,303-327` |
| E-09 | Duplicated CSV/XLSX streaming (`ExportController` vs `ReportController`); PHP money formatting in three places ignoring `number_locale` (PDFs, notifications, work queue); JS `toFixed(2)` in receipts/payments/allocation bypassing `formatting.js`; ad-hoc `round()` in 25+ files, 2 dp in controllers vs 4 dp schema. | Medium | M | `ExportController.php:104-140`; `ReportController.php:102-120`; `DocumentComposer.php:105`; `allocation.js:14` |
| E-10 | Docs drift: `docs/08` lists Reverb, Horizon, spatie packages, Pinia, Docker Compose, Pest 3, Playwright; `docs/10` and README say Laravel 12 / Inertia 2 (code is 13 / 3); `docs/00` says "No application code exists yet"; `tests/Pest.php:30` cites a `VocabularySeedTest` that does not exist; README omits vitest/Pint/PHPStan; UX audit still says Ziggy/Pinia installed (they are gone). | Medium | S | `docs/08-architecture.md:185,188,227,246`; `README.md:7,165-186` |
| E-11 | Reports: 9 of ~60 catalogue reports; single `report.view` permission; no scheduling or saved filters; `dispatch`, `payables`, `purchases` reports never requested by a test; 19 of 26 print/PDF routes smoke-only. | Medium | L | `ReportCatalogue.php:29-42`; `DocumentPrintController` |
| E-12 | No JS lint/type-check; `@vitejs/plugin-vue` in `dependencies`; no `engines`/`.nvmrc`. | Low | S | `package.json` |

**Strengths worth keeping.** Tests assert ledger, DB and audit state rather than status codes; `PermissionEnforcementTest` proves every route is `can:`-guarded; `RouteSmokeTest` opens every GET; device-API idempotency and offline paths are tested on both sides; numbering is centralised under lock; no TODO/debug leftovers; unit scoping on floor reads and writes; `CocPeriodGuard` covers all three CoC writers; sanitised SVG logos; secrets out of the repo.

---

## 6. Recommended order

1. **Security quick wins (≈1 day).** S-01 route role sync through `user.assign_role` and refuse `super_admin` unless the actor holds it. S-02 add `AuthenticateSession` to the web group and call `logoutOtherDevices()` on password change. S-03 null the PIN backfill and force a PIN set on first floor sign-in. S-04 per-badge lockout with audit. S-05 permission checks on the device API. S-06 `RateLimiter::for('api')` per token, `throttle` on web writes.
2. **CI and dependencies (≈half a day).** E-01 GitHub Actions: Pint, PHPStan, Pest (serial, MySQL service), vitest, `composer audit`, `npm audit`. S-13 `composer update laravel/framework`, `npm audit fix`. Fix Pint. D-08 `.env.example` to MySQL.
3. **Small integrity fixes (S each, ≈1 week together).** C-01 call `meetsThreshold` in the packing-list guard. C-02 exclude non-A grades from the claim and flag at packing. M-02 refuse finish on a completed operation (409). M-03 J1 check on start/downtime, no START on hold, guard on resume. M-04 retry policy in `flush()` by status, drop the 4 h hard expiry. M-01 stamp the operator on the queued record and send it; bind idempotency to the record not the token. P-04 release reservations on order close. P-05 route inquiry and quotation revision through their state machines. P-11 guard short shipments. D-02 unique indexes. P-10 customer tolerances. C-03 required cert fields.
4. **Medium rule gaps (≈3–4 weeks).** Q-01 GRN quarantine and incoming QC with release. Q-02 `requires_qc` finish gate and hold on fail. Q-03 downgrade/scrap stock effects. M-05 downtime exclusion. M-06 waste valuation and ledger. F-01 tax and Mushak. C-04 and D-05 scheduled jobs. S-11 audit coverage. E-02 PHPStan to green. E-08 N+1s.
5. **Large surfaces (roadmap items, prioritise with the factory).** P-01 sampling. P-03 purchase returns. P-02 delivery schedules and job-card split. P-09 production plans and promised-date recomputation. M-09 live board. F-04 customer ledger and ageing. F-07 export documents. Q-06 complaints. C-05 claim validator. E-11 report catalogue. E-06 i18n. S-08 portal scope before any portal work. E-03/E-05 factories, component and E2E tests. D-06 queued MRP and exports. D-07 observability and backups.

---

## 7. Fix log

One row per finding as it is closed. `Done (sha)` names the commit; `Deferred` and `Needs decision` say why.

| ID | Status |
|---|---|
| S-01 | Done (db83a9b) |
| S-02 | Done (db83a9b) |
| S-03 | Done (a07b9bc) |
| S-04 | Done (6f59edc) |
| S-05 | Done (da7d358) |
| S-06 | Done (da7d358) |
| E-01 | Done (1412f3e) — CI stays red until E-02 (PHPStan) is green |
| S-13 | Done — framework ^13.36, inertia ^3.5, vue ^3.5.44, vitest ^5, concurrently ^10; both audits clean. Lock files are gitignored, so the constraints are what CI resolves from |
| D-08 | Done — `.env.example` defaults to MySQL |
| D-02 | Not reproduced — `quotations_number_uq` is `(number, revision_no)` by design (revisions share the number); `mrp_runs` has no `number` column at all |
| C-01 | Done (9d34e5a) |
| C-02 | Done (9d34e5a) |
| M-02 | Done (ddcc031) |
| M-03 | Done (ddcc031) |
| P-04 | Done (9ced4e6) |
| P-05 | Done (507d9e0) |
| P-10 | Done (642ab33) — tolerances; deactivation guards (MD-4 AC4, MD-1 AC5) and the customer-PO rule remain open |
| P-11 | Done (d9976fd) — gate pass captured. Under-band short shipment is by design: a partial delivery is normal while more is coming, and a short *close* already needs a reason and `sales_order.short_close` (BR-45) |
| C-03 | Done (feb3378) — cert fields together, PO claim honoured. Supplier-certificate validity (CP-2 AC3) needs a supplier certificate register that does not exist; deferred to C-05 |
| M-01 | Done (see floor commit) — token per record; Login page does not yet warn about another operator's pending records |
| M-04 | Done (see floor commit) — retry policy, backoff, 24 h window |
| D-05 | Done (see schedule commit) — invoice overdue, certificate expiry, lot expiry. MRP nightly and over-limit digest remain open |
| C-04 | Done (see schedule commit) — expiry flip and reminders; registration fields still optional |
| P-15 | Partly done — lot expiry job; adjustment reason list, ageing/valuation reports open |
| S-11 | Done (5f1a06c) |
| E-08 | Done (fa98753) |
| F-01 | Done (dcda1f0) — tax from the line's tax code, Mushak/LC references. One challan per invoice remains |
| Q-02 | Done — a `requires_qc` step (other than the final one, which is J4's) finishes only once inspected; a failed in-process check holds the card and notifies supervisors, except on a rework disposition where the step is reopened instead |
| Q-03 | Done — scrap writes a `scrap` movement at lot cost and a valued waste log; downgrade moves the pieces to a `second_quality` lot with no claim. Both act on the job's blocked FG lots at final rejection |
| M-06 | Done — waste valued at the named input lot's cost. No ledger movement: the lot was already issued to the job; an unnamed lot stays at zero rather than an invented figure. Over-allowance supervisor flag (MF-5 AC3) open |
| C-04 | Done — issuing body, scope and the signed-certificate path are required at registration; expiry job and reminders landed with D-05. Versioned renewals (AC4) open |
| M-05 | Done — downtime logged against an operation is taken out of the hours it is charged for; the machine line says how much. OEE still open |
| Q-01 | Done — `items.requires_incoming_qc` receives the lot into quarantine and raises a pending incoming inspection with its number; the inspector's verdict lands on that row, moves the lot to available or blocked, and sets the GRN line's accepted/rejected quantities. Whole-lot granularity; supplier rating (AC4) open |
| S-09 | Done — a device token stops working at the next request once the employee or user is deactivated. Token still not bound to a device; `machine_code` not enforced on writes |
| S-10 | Done — `.env.example` idle timeout is eight hours (NFR-26). The portal has no sessions yet |
| S-12 | Done — approval is `supplier.approve`: the edit form ignores the box for anyone else, and the supplier page has an Approve action for purchase managers, audited |
| Q-04 | Done — DHU counts minor defects; every inspection stores the AQL plan row it sampled from; the `customer` stage is reachable |
| M-08 | Done — issuing past the BOM requirement plus wastage needs a reason on the line, stored on the issue line |
| P-14 | Partly done — a made item that prints artwork cannot go active without an approved version. BOM activation validation, `formula_ref`, artwork preview/submission sheet and re-approval flagging remain open |
| D-07 | Partly done — every response carries `X-Request-Id`, shared into the log context; `/up` proves the database, cache and disk and reports the queue. No error tracker, no backups |
| S-07 | Partly done — Argon2id with rehash on login; self-service password reset (`/forgot-password`, mailed link, same password rule, other sessions ended). MFA for md/admin/accounts remains open |
| D-01 | Done — the ledger model refuses update and delete; the production DB role should still lack those grants |
| P-13 | Partly done — a revision copies each line's cost sheet, open, so it can be sent without re-saving. Revision diff, override reasons on quotation knobs, minimum-charge acknowledgement, what-if and the ±5 % flag remain open |
| M-07 | Partly done — reaching QC raises the final inspection and the verdict lands on it. J5 supervisor approval and returning issued material on cancel remain open |
| F-03 | Partly done — a quality-claim credit note can name its NCR and the note page links to it. Approval band is still keyed to the MD role |
| C-06 | Done — BR-42 in the business rules now says the input side is consumption with a receipt fallback |
| E-10 | Done — README, overview, architecture and roadmap name Laravel 13 / Inertia 3, the real test and CI stack, no Reverb/Horizon/spatie/Pinia/Docker; `VocabularySeedTest` now exists |
| E-12 | Done — build plugin is a dev dependency; `engines.node >= 22` |
| M-11 | Done — retiring a tool needs a reason, recorded on its audit row; the dashboard lists tools past 90 % of their rated life. Shot counting for plates/screens/dies and BR-13 colour-index pairing remain open |
| Q-05 | Partly done — a report issues only when every test the customer requires (for the product or for everything they buy) has a result. Supersedes link and reports-by-lot remain open |
| Q-06 | Partly done — the CAPA due date follows the NCR severity (settings `ncr_capa_days_*`) when the investigator sets none. Customer complaints (QL-8) remain open |
| F-02 | Partly done — a bill whose total exceeds the receipt at order rates beyond `supplier_bill_value_tolerance_pct` is refused. Matching is still keyed by item |
| F-06 | Partly done — a trip over the vehicle's capacity needs a reason to start, shown before Start is pressed. POD signature/photo and offline remain open |
| P-15 | Done for adjustments — a controlled reason code plus detail (`stock_adjustments.reason_code`). Stock-ageing and valuation reports remain open |
| P-16 | Partly done — open inquiries idle past `inquiry_follow_up_days` appear on the dashboard. Price lists on quotations, order book and domain events remain open |
| D-04 | Partly done — notifications go by mail as well as the inbox once a real mailer is configured. The spec'd missing events remain open |
| S-08 | Partly done — `BelongsToCustomer` exists and confines orders, quotations, inquiries, invoices, credit notes, receipts, returns, packing lists, delivery notes and test reports to the portal customer on portal requests. The role stays withheld until the portal has data routes with cross-customer tests (NFR-31) |
| P-07 | Partly done — receiving past the order line plus `over_receipt_tolerance_pct` needs a reason, kept on the GRN line. BR-25 rounding stays a form suggestion by design (the buyer may order below MOQ knowingly); PO revision path and PR value thresholds need a decision on who approves above the band |
| P-08 | Partly done — the scheduler refuses a machine narrower than the job's web or with fewer colours than the spec. Rate/kW required before scheduling (MD-3 AC2) stays open: seeded and test machines carry no rate |
| E-04 | Partly done — behavioural tests now cover credit-note apply and refund, the customer return (draft, approve, post), carton edit and removal, BOM activation, and the machine register, all through their routes. Import shipments, letters of credit, spec make-current and artwork transitions by route remain open |
| P-06 | Partly done — open orders count as cover only when due by the need date (BR-24); lead time comes from the supplier-item agreement before the supplier default (BR-26); a requisition raised from a run is MRP-origin, its lines answer the run's shortage rows, and covered shortages are not offered again (PL-5). Stored BOM quantities, per-item (not per-date) aggregation and the synchronous run remain |
| D-06 | Partly done — the material plan runs as a queued job (`RunMrp`): the run row opens at once in `running`, the page shows it, and a failure marks the row with its reason. Exports still stream inside the request |
| E-02 | Done — PHPStan level 6 reports 0 errors; Pint clean. Agent cleared the costing, master-data, product, quality, finance, support and seeder files; the rest was relation generics on the models |
