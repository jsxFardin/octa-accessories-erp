# Plan — Export letters of credit and export shipments (Sales side)

Mirror of the Buying trade module (`docs/03-modules/16-trade-import-expenses.md`): where Buying has
*letter of credit → purchase orders → import shipment → receipts → landed cost*, Sales gets
*export LC → sales orders → export shipment → delivery notes → documents → proceeds*.

Status: proposal, 2026-10-15. Nothing here is built yet. Closes GAP F-07 (export documents, DF-6)
and adds the export-LC half that the spec only hints at (`sales_invoices.lc_no`, receipt method `lc`).

---

## 1. What exists today

| Buying (built) | Sales (today) |
|---|---|
| `letters_of_credit` (supplier, bank, currency, amount, tolerance, tenor, dates, incoterm, ports; `draft → applied → opened → shipped → retired → closed`, `cancelled`) | nothing; invoices carry a free-text `lc_no`, receipts have method `lc` |
| `lc_purchase_orders` (covered_amount, BR-56 same currency) | — |
| `lc_amendments` (delta, new dates, charges) | — |
| `import_shipments` (`draft → in_transit → arrived → cleared → costed → closed`), `grns.import_shipment_id`, `import_costs`, landed-cost allocation | `export_documents` table and model only (challan/order, doc_type CI/PL/COO/EXP/BL/AWB/UD/LC/insurance/other), no writer, no screen |
| `/letters-of-credit`, `/import-shipments`, nav under Buying, `letter_of_credit.*` (open, amend), `import_shipment.*` (cost, allocate) | `export_document.*` permission exists, granted to merchandiser and sales manager, unused |
| `TradeImportTest`, `LetterOfCreditCurrencyTest` | — |

---

## 2. Design decisions (recommendations)

1. **One `letters_of_credit` table, two directions.** Add `direction ENUM('import','export') DEFAULT 'import'`,
   make `supplier_id` nullable, add `customer_id` nullable, `CHECK ((direction='import') = (supplier_id IS NOT NULL))`.
   Amendments, BR-56 currency logic, expiry countdowns and the bank-account link are identical on both sides;
   duplicating the table would duplicate the bugs. Separate **controllers, pages, routes, permissions and
   number series** per direction, exactly as Buying and Sales are separate everywhere else in the app.
2. **Export LC lifecycle is the beneficiary's, not the applicant's.** We do not apply for it; the customer's bank
   issues it and ours advises it:
   `advised → accepted → shipped → presented → realised → closed`, with `discrepant` (reachable from `presented`,
   back to `presented` after correction) and `cancelled` (while not yet shipped).
   - `advised`: the bank's `lc_no` is known from day one (required on save, unlike import).
   - `accepted`: merchandiser confirmed terms are workable (dates, ports, tolerance, documents clause).
   - `shipped`: set by the first export shipment moving to `shipped`; never by hand.
   - `presented`: documents handed to the bank; `presented_on` stamped; `latest_presentation_days` (default 21 after shipment) drives a dashboard warning.
   - `realised`: proceeds received; set by a receipt with method `lc` linked to the LC, or by hand with the bank's realisation reference.
3. **Back-to-back.** `letters_of_credit.master_lc_id` (nullable FK to an export LC). An import LC opened against a
   master export LC names it; the export LC page lists its back-to-back import LCs and the total opened against
   it versus the allowed percentage (`btb_limit_pct`, default 75, a setting). Stated, not enforced, in phase 1.
4. **Export shipment groups delivery notes**, the way an import shipment groups receipts
   (`delivery_challans.export_shipment_id`, like `grns.import_shipment_id`). A shipment carries the transport
   facts once (vessel/flight, container, seal, BL/AWB, EXP, UD) instead of per challan.
   Lifecycle: `draft → booked → shipped → presented → delivered → closed`, `cancelled`.
   `shipped_on` is stamped by the transition. Moving to `closed` requires the document checklist complete (DF-6 AC3).
5. **Export documents hang off the shipment** (`export_documents.export_shipment_id`, keep the existing
   challan/order columns for documents that predate a shipment). Checklist is derived, not configured:
   CI, PL and COO always; `bl` for sea, `awb` for air; `exp_form` always (Bangladesh); `ud` when the customer
   is bonded (`customers.is_bonded`, new flag); `lc_document` when the shipment names an LC; `insurance` when
   incoterm is CIF/CIP. Each row gets `status` (`drafted`, `issued`, `submitted`) and `file_path`.
6. **Drafts generated from data (DF-6 AC1).** Commercial invoice, packing list and COO are print-registry
   documents built from the shipment's challans, cartons and invoices; "Generate" writes the PDF to storage and
   the `export_documents` row with `status = drafted`. EXP, UD, BL/AWB are typed in (numbers, dates, files).
7. **Utilisation and realisation are money rules, owned by Finance.** `sales_invoices.lc_id` (FK) replaces
   relying on the free-text `lc_no`; issuing an invoice under an LC is refused when invoices already drawn exceed
   `currentAmount() × (1 + tolerance_pct)`; `receipts.lc_id` on method `lc`; the LC page shows
   drawn, realised and outstanding.
8. **No export landed cost.** Freight, C&F and bank charges on an export are period costs that belong on the
   margin report, not in stock. `export_costs` is phase 2 if the factory wants cost-to-serve per customer.

---

## 3. Schema changes (base migrations + `docs/02a-schema.sql`, unreleased product)

- `letters_of_credit`: `direction`, `customer_id` (FK customers), `supplier_id` nullable, `master_lc_id`
  (self FK), `issuing_bank` VARCHAR(120), `advising_bank_account_id` (FK bank_accounts; `bank_account_id` keeps
  meaning the account charged on import), `latest_presentation_days` SMALLINT DEFAULT 21, `partial_shipment`
  BOOL DEFAULT TRUE, `transhipment` BOOL DEFAULT TRUE, `presented_on`, `realised_on`, `realisation_ref`
  VARCHAR(60); status CHECK extended with `advised, accepted, presented, discrepant, realised`.
- `lc_sales_orders` (lc_id, sales_order_id, covered_amount) — twin of `lc_purchase_orders`.
- `export_shipments`: number, customer_id, lc_id, mode (sea/air/road/courier), forwarder, carrier, vessel_or_flight,
  container_no, seal_no, transport_doc_no (BL/AWB), transport_doc_date, exp_form_no, exp_form_date, ud_no,
  port_of_loading, port_of_discharge, final_destination, incoterm, etd, eta, shipped_on, delivered_on,
  currency_id, exchange_rate, goods_value, gross_kg, net_kg, cbm, status, remarks, created_by, created_at.
- `delivery_challans.export_shipment_id` (nullable FK) + index.
- `export_documents`: `export_shipment_id` (FK), `status` VARCHAR(20) DEFAULT 'issued', `created_by`.
- `sales_invoices.lc_id`, `receipts.lc_id` (nullable FKs).
- `customers.is_bonded` BOOL DEFAULT FALSE.
- Number series: `export_lc => ['ELC', 5]`, `export_shipment => ['ESH', 5]`.
- Permissions: `export_lc` (Commercial; extras `accept`, `amend`, `realise`), `export_shipment` (Fulfilment;
  extras `ship`, `close`); `export_document` already exists.
  Grants: merchandiser and sales manager `export_lc.*` minus `realise`; accounts `export_lc.realise`,
  `export_lc:read`; dispatch officer `export_shipment.*`, `export_document.*`; merchandiser `export_shipment:read`.
- Settings: `btb_limit_pct` (75), `export_presentation_days` (21) with catalogue labels.

---

## 4. Code, mirroring Buying file for file

| Buying | Sales |
|---|---|
| `Trade/Http/Controllers/LetterOfCreditController` | `Trade/Http/Controllers/ExportLetterOfCreditController` (index with expiring/last-shipment/presentation countdowns, create, store, show, edit, update, transition, attachOrder, detachOrder, amend, destroy) |
| inline ladder in `transition()` | `Trade/States/ExportLcStateMachine` (proper `StateMachine`: guards for `lc_no` on save, `accepted` needs expiry and last-shipment, `shipped` system-only, `realised` needs realisation ref or linked receipt; audit rows) — and, as a follow-up, move the import ladder onto the same base |
| `amend()` body | `Trade/Services/LcAmendmentService` shared by both directions |
| `ImportShipmentController` | `Dispatch/Http/Controllers/ExportShipmentController` (index flags shipped-not-presented and presented-past-N-days; show: transport facts, challans, documents checklist, LC position) + `ExportShipmentStateMachine` |
| `linkReceipt/unlinkReceipt` | `attachChallan/detachChallan` (same customer, challan `dispatched`/`delivered`, not already on a shipment) |
| `import_costs` | — (phase 2) |
| — | `Dispatch/Services/ExportDocumentChecklist` (required types per shipment, missing list) and `ExportDocumentController` (store typed/uploaded docs, generate CI/PL/COO drafts via `DocumentRegistry`, mark submitted, delete draft) |
| `Trade/LettersOfCredit/{Index,Form,Show}.vue` | `Trade/ExportLettersOfCredit/{Index,Form,Show}.vue`; `Dispatch/ExportShipments/{Index,Form,Show}.vue` |
| Buying nav | Sales nav: "Export LCs" → `/export-lcs`; Dispatch nav: "Export shipments" → `/export-shipments` |
| PO page "Covered by" | Sales order page: "Letter of credit" card (covered amount, expiry, last shipment vs promised dates, warning when a promised date is after last shipment); Delivery note page: "Export shipment" card with attach/open; Invoice page: LC select instead of free text, drawn/available figures; Receipt form: LC select when method is `lc` |
| `WorkQueue` cards | `export_lcs_expiring`, `export_shipments_unpresented`, `export_documents_missing` |
| Reports | Export register (EXP-wise, customer, value, realised), LC utilisation and realisation, Export document status (missing documents by shipment) |

---

## 5. Steps and commits (each with tests, one Pest run, one commit)

1. **trade(E-01): export LC record** — schema, model `direction` scopes, controller, pages, routes, nav, permissions,
   number series, amendments shared, cover sales orders (BR-56). Tests: lifecycle ladder and refused jumps,
   `lc_no` required at advice, amendment moves effective dates, same-currency and same-customer cover rules,
   permission gates. (M)
2. **finance(E-02): drawn and realised** — `sales_invoices.lc_id`, issue guard against available balance with
   tolerance, `receipts.lc_id`, LC page position, `realised` through the receipt. Tests: over-draw refused at
   the tolerance edge, receipt realises, position arithmetic. (S)
3. **dispatch(E-03): export shipment** — schema, model, state machine, controller, pages, attach challans,
   `shipped` stamps and moves the LC to `shipped`, work-queue cards. Tests: lifecycle, challan rules, LC follow. (M)
4. **dispatch(E-04): export documents** — checklist service, typed documents with files, generated CI/PL/COO
   drafts from challan and carton data, `closed` gate on checklist, document status report. Tests: checklist by
   mode/terms/bonded, close refused with missing docs, generated draft row and file. (M)
5. **trade(E-05): back-to-back and dashboards** — `master_lc_id` on import LCs, the export LC's back-to-back
   list and limit warning, expiring and presentation cards, export register report, docs
   (`03-modules/16` gets an "Export" section; roadmap row 19 updated). (S)

Effort: about two weeks for one developer, steps 1 and 3 the bulk. Steps 1–2 can ship before 3–5 and
already replace the free-text `lc_no` on invoices.

---

## 6. Open questions for the factory

- Does every export go through an LC, or are TT and DA/DP exports common enough to need `kind` on the
  export side from day one? (Recommendation: yes, reuse `kind`.)
- Is the UD (utilisation declaration) always required, or only for bonded customers? Drives the checklist rule.
- Who presents documents to the bank — merchandising or accounts? Decides who holds `export_lc.realise`.
- Should a back-to-back import LC be refused when it would exceed the master LC's limit, or only flagged?
