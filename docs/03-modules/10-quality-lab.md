# Module 10 — Quality & Laboratory

**Purpose:** inspect incoming material, in-process output and finished lots; run the in-house physical tests; issue customer-facing test certificates; drive NCR/CAPA.

**Actors:** QC Inspector, Lab Technician, Quality Manager, Production Supervisor, Compliance Officer.

**Tables:** `defects`, `aql_plans`, `qc_inspections`, `qc_inspection_defects`, `lab_tests`, `customer_test_requirements`, `test_reports`, `test_report_lines`, `ncrs`, `capas`.

**Rules:** BR-30 (AQL), BR-31 (DHU), BR-32 (lab thresholds), BR-33 (disposition).
**Invariants:** QC1–QC3.

---

## Four inspection stages

| Stage | Trigger | Blocks |
|---|---|---|
| `incoming` | GRN line for an inspection-flagged item | Material stays in quarantine; cannot be issued |
| `in_process` | Routing operation with `requires_qc` | Next operation cannot start |
| `final` | Job card reaching `qc_pending` | FG receipt cannot be posted |
| `pre_shipment` | Before packing, on customer demand | Packing list cannot be dispatched |
| `customer` | Third-party or buyer inspection | Recorded for history; may raise an NCR |

---

## AQL, by the book

`aql_plans` holds ISO 2859-1 General Inspection Level II, seeded from BR-30. Final inspection:

1. Look up the plan row for the lot size.
2. Present sample size, accept number, reject number.
3. Inspector records defects by defect code and severity.
4. `major_found ≥ reject_number` → rejected. Any critical defect → rejected.
5. DHU is computed (BR-31).

The plan is data, not code. A brand demanding AQL 1.5 or Level I is a lookup row, not a release.

---

## The laboratory

The factory's advertised in-house tests, seeded in `lab_tests` with methods and thresholds (BR-32):

| Test | Method | Scale | House pass |
|---|---|---|---|
| Colour fastness to washing | ISO 105-C06 | grey 1–5 | ≥ 4 change, ≥ 3-4 staining |
| Colour fastness to rubbing (dry) | ISO 105-X12 | grey 1–5 | ≥ 4 |
| Colour fastness to rubbing (wet) | ISO 105-X12 | grey 1–5 | ≥ 3 |
| Colour fastness to hot ironing | ISO 105-X11 | grey 1–5 | ≥ 4 |
| Sublimation / dry heat | ISO 105-P01 | grey 1–5 | ≥ 4 |
| Colour bleeding | in-house | pass/fail | pass |
| Colour staining (multifibre) | ISO 105-A03 | grey 1–5 | ≥ 3-4 |
| Dimensional shrinkage | ISO 5077 | % | ≤ 3% |
| Shade variation vs standard | in-house | ΔE | ≤ 1.0 |

`customer_test_requirements` lets a brand impose a stricter threshold for all its products or one specific product, without forking the catalogue.

`test_reports` becomes a customer-facing certificate: lot, product, date, technician, each test with method / result / threshold / verdict, overall pass-fail, signature block. Once `issued`, results are immutable (QC3) — reprinting must reproduce the original byte-for-byte.

---

## Screens

| Screen | Route | Notes |
|---|---|---|
| Incoming QC queue | `/quality/incoming` | GRN lines awaiting inspection |
| In-process check | `/floor/qc/{operation}` | Fast form on the shop-floor tablet |
| Final inspection | `/quality/inspections/{id}` | AQL panel, defect capture, disposition |
| Defect capture | inside inspection | Tap-to-count grid of defect codes |
| Lab worksheet | `/lab/tests/{id}` | Enter results per test, auto-verdict |
| Test certificate | `/lab/reports/{id}/pdf` | Customer-facing |
| NCR list / form | `/quality/ncrs` | Source, severity, owner, status |
| CAPA | `/quality/ncrs/{id}/capa` | Root cause, action, due date, effectiveness |
| Quality dashboard | `/quality/dashboard` | DHU trend, defect Pareto, rejection rate |

The defect capture grid is a tap-to-count layout: defect codes as large buttons, each tap increments. An inspector holding labels cannot type into a form.

---

## User stories

**QL-1 — Incoming inspection**
- AC1: Items flagged for incoming QC create an inspection automatically on GRN posting; stock sits in quarantine.
- AC2: The inspector records accepted and rejected quantities plus defects.
- AC3: The accepted portion moves to `available`; the rejected portion requires a disposition (BR-33).
- AC4: Rejection auto-drafts an NCR against the supplier and updates the supplier rating.

**QL-2 — In-process inspection**
- AC1: An operation with `requires_qc` cannot be marked complete until its inspection is recorded.
- AC2: A failed in-process check puts the job card on hold and notifies the supervisor.
- AC3: The form is a 30-second interaction on a tablet: pass/fail, defect taps, optional photo.

**QL-3 — Final AQL inspection**
- AC1: Lot size defaults from the job card's good quantity; the AQL plan row is fetched automatically (BR-30).
- AC2: Sample size, accept and reject numbers are displayed before inspection begins.
- AC3: Defects are recorded by code with severity from the defect master.
- AC4: The verdict is computed, not typed. The inspector may not override it; they may only record a disposition.
- AC5: DHU is computed and stored (BR-31).

**QL-4 — Disposition a rejected lot**
- AC1: Exactly one of `rework`, `concession`, `downgrade`, `scrap`, `release` (BR-33). The DB enforces that a rejected inspection has a disposition.
- AC2: `rework` requires naming the operation to return to; the job card reopens at that operation.
- AC3: `concession` requires a customer approval reference — text alone is not enough.
- AC4: `downgrade` creates a `second_quality` lot; that lot can never be shipped against a certified claim.
- AC5: `scrap` writes a waste movement at lot cost.

**QL-5 — Run a lab test**
- AC1: The worksheet lists the tests required for this customer/product (house defaults plus `customer_test_requirements`).
- AC2: Result entry is scale-aware: grey-scale ratings offer 1 … 5 with half steps; shrinkage takes a percentage; ΔE takes a number.
- AC3: Pass/fail per line is computed against the applicable threshold, not typed.
- AC4: Overall result is `fail` if any mandatory test fails.

**QL-6 — Issue a test certificate**
- AC1: Issuing requires all mandatory tests to have results.
- AC2: On issue, the report and its lines become immutable (QC3); a correction is a new report referencing the original.
- AC3: The PDF carries lot number, product, customer, methods, thresholds, results and the technician's name.
- AC4: Certificates are retrievable by lot from the lot detail screen and (phase 3) the customer portal.

**QL-7 — Raise and close an NCR**
- AC1: Sources: incoming, in-process, final, customer complaint, audit, lab.
- AC2: Severity drives the response deadline (setting).
- AC3: Closing requires at least one CAPA with a root cause and a completed action.
- AC4: Effectiveness review is a separate step; an NCR cannot close as verified without it.

**QL-8 — Customer complaint**
- AC1: A complaint records customer, order, lot, description and claimed quantity.
- AC2: The lot's genealogy is attached automatically (from [07-inventory](07-inventory.md)).
- AC3: Resolution may create a credit note ([13-finance-ar-ap](13-finance-ar-ap.md)) and always creates an NCR.

---

## Reports

| Report | Content |
|---|---|
| Defect Pareto | Defect counts by code, machine, operator, product — the 80/20 list |
| DHU trend | By product, machine, month |
| Rejection rate | By stage, supplier, customer |
| Lab test register | All tests with results, pass rate by test and product |
| Certificate register | Issued certificates by customer and period |
| NCR / CAPA status | Open, overdue, by owner and severity |
| Cost of quality | Rework + scrap + concession value by period |
| Supplier quality | Incoming rejection % by supplier and item |
