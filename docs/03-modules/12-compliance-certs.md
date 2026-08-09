# Module 12 — Compliance & Chain of Custody

**Purpose:** hold the factory's certifications, and prove — with an unbroken transaction chain — that certified output came from certified input. This is the module generic ERPs skip and auditors ask about first.

**Actors:** Compliance Officer (primary), Store Keeper (GRN claims), Production (conversion), Dispatch (output claims), MD.

**Tables:** `certifications`, `certification_scopes`, `coc_transactions`, `v_coc_reconciliation`. Reads `grn_lines`, `stock_lots`, `job_cards`, `packing_lists`.

**Rules:** BR-40 … BR-43.
**Invariants:** C1–C3. **Gate 2** ([01-domain-model §4](../01-domain-model.md#4-the-two-gates-that-define-the-system)).

---

## The certifications held

| Scheme | What it governs | ERP consequence |
|---|---|---|
| **FSC** | Paper/board from responsible forestry — hang tags, cartons | Chain of custody, input/output reconciliation, claim on invoice |
| **GRS-MDEL / GRS-MLTL** | Recycled content in materials | Chain of custody + minimum claim percentage + transaction certificates |
| **OEKO-TEX Standard 100** | Azo-free, harmful-substance limits | Certificate validity per article, test evidence |
| **BSCI / SMETA 4P** | Social compliance | Audit calendar, corrective actions, document register |
| **ISO 9001 / 14001** | Quality / environmental management systems | Document control, NCR/CAPA, internal audit schedule |
| **Scope Certificate** | Scope of certified products and processes | Which product types may carry which claim |

FSC and GRS are the two that impose *transactional* obligations. The rest impose *document and process* obligations. Both are modelled.

---

## The chain

```
GRN line (certified yarn, GRS 60%)          →  coc_transactions  direction = input
   ↓ creates
Stock lot  (cert_scheme='GRS', claim 60%)
   ↓ issued to
Job card   (mixes certified + non-certified) →  coc_transactions  direction = conversion
   ↓ produces
FG lot     (diluted claim, BR-40)
   ↓ packed into
Packing list (claim declared)                →  coc_transactions  direction = output
```

**Claim dilution (BR-40):**
```
grs_pct_output = Σ(input_qty × input_claim_pct) / Σ(input_qty)
```
rounded **down** to the nearest 1%. Non-certified input dilutes; nothing ever inflates a claim.

**Claim threshold (BR-41):** a product may only carry a scheme's claim if the output percentage meets the scheme's minimum, stored per scheme in `certification_scopes` — not hard-coded.

**Reconciliation (BR-42):** per scheme, per period,
```
conversion_factor = certified_output_qty / certified_input_qty
```
`v_coc_reconciliation` computes this. The report flags any period where output exceeds input × `max_conversion_factor` — precisely the test an auditor runs.

---

## Screens

| Screen | Route | Notes |
|---|---|---|
| Certification registry | `/compliance/certifications` | Scheme, number, issuer, validity, scope, document |
| Expiry dashboard | `/compliance/expiries` | Countdown per certificate, `reminder_days` alerts |
| Scope matrix | `/compliance/scopes` | Which product types / item categories each certificate covers |
| CoC ledger | `/compliance/coc` | Filter by scheme, period, direction; drill to source document |
| Reconciliation report | `/compliance/reconciliation` | Input vs output vs conversion factor, per scheme per month |
| Claim validator | `/compliance/validate?packing_list={id}` | Explains a claim: which lots, which GRNs, what percentage |
| Audit register | `/compliance/audits` | Audit calendar, findings, corrective actions |
| Document register | `/compliance/documents` | Controlled documents, versions, review dates |

---

## User stories

**CP-1 — Register a certificate**
- AC1: Scheme, certificate number, issuing body, issue and expiry dates, scope description and the PDF are mandatory.
- AC2: `reminder_days` (default 60) drives alerts to the compliance officer and MD.
- AC3: An expired certificate flips to `expired` by a nightly job.
- AC4: Certificates are versioned — renewals create a new row, the old one is retained.

**CP-2 — Capture certified input**
- AC1: On GRN, a line declaring a certification requires scheme, claim percentage and the supplier's transaction certificate number ([06-procurement](06-procurement.md) PR-6).
- AC2: A `coc_transactions` row with `direction = 'input'` is written on GRN posting.
- AC3: If the supplier's certificate is expired or missing, the claim is refused (the goods are still received) and a compliance task is raised.

**CP-3 — Track conversion**
- AC1: When a job card consumes certified lots, a `conversion` transaction records the quantities in and out.
- AC2: The output lot's claim is computed per BR-40 and stored on the lot.
- AC3: Waste is included in the conversion so the factor reflects reality, not theory.

**CP-4 — Declare an output claim**
- AC1: A packing list may declare a scheme only if every lot inside it carries that scheme.
- AC2: The declared percentage cannot exceed the computed diluted claim (BR-40).
- AC3: The claim must meet the scheme's minimum (BR-41); below it, the option is not offered.
- AC4: The certificate must be valid on the shipment date (BR-43); otherwise dispatch is blocked with the certificate named.
- AC5: An `output` transaction is written on challan issue.

**CP-5 — Reconcile a period**
- AC1: The report shows certified input, certified output and conversion factor per scheme per month.
- AC2: Periods exceeding `max_conversion_factor` are flagged red with drill-down to the offending transactions.
- AC3: Closing a period sets `is_locked = true` on its transactions (C3); locked rows cannot be edited or deleted.
- AC4: The report exports to the format auditors expect (Excel with transaction-level backing).

**CP-6 — Explain a claim**
*As a Compliance Officer, when an auditor points at a shipment, I show where the claim came from.*
- AC1: Given a packing list, the validator renders the full chain: cartons → FG lots → job cards → input lots → GRN lines → supplier transaction certificates.
- AC2: Each hop shows quantity and claim percentage, and the arithmetic of the dilution.
- AC3: Exportable as a single PDF evidence pack.

**CP-7 — Audit calendar and findings**
- AC1: Scheduled audits (BSCI, SMETA, ISO surveillance, buyer audits) with dates and responsible owner.
- AC2: Findings are recorded and linked to CAPAs in [10-quality-lab](10-quality-lab.md) — one CAPA system, not two.
- AC3: Overdue findings escalate to the MD dashboard.

---

## Reports

| Report | Content |
|---|---|
| Certification status | All certificates, validity, days to expiry |
| CoC reconciliation | Input vs output vs factor, per scheme per period |
| Claim evidence pack | Full chain for one shipment, auditor-ready |
| Certified purchase | Certified input by supplier, scheme, period |
| Certified sales | Certified output by customer, scheme, period |
| Non-conforming claims | Shipments where a claim was refused or downgraded, with reasons |
| Audit findings | Open findings, owners, due dates |

---

## Why this earns its place

An FSC or GRS audit failure suspends the certificate, and a suspended certificate loses the brand accounts that require it. Every competitor's ERP tracks stock; almost none can produce the input/output reconciliation on demand. Building it here turns a compliance burden into a two-minute report — and, if this product is ever sold to other label factories, it is the single clearest differentiator.
