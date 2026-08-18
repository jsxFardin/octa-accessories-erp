# Quality

**Quality** is a hub. Tabs: Inspections, NCRs, Laboratory, Compliance & CoC.

## Inspections (AQL)

**Quality → Inspections → Record inspection** (or from the job card).

1. Job card, stage (`in_process` / `final`), lot size.
2. Defects found (critical / major / minor as the plan asks).
3. The verdict is **computed** from the AQL plan — you do not pick Pass to be nice.
4. Failures need a **disposition** (rework, scrap, concession, return to process) before you can save, when the form asks for it.

Final inspection: if Settings say every job needs final QC, the job card will not complete without an accepted final.

In-process QC can be required by the routing operation (`requires_qc`).

## NCRs / CAPA

**Quality → NCRs.**

Raised from a failed inspection or from the job card.

1. Containment, then root cause.
2. CAPA actions with owners and due dates.
3. Close when effectiveness is checked.

Overdue NCRs notify the quality manager (and sit on the dashboard). Do not leave `open` NCRs without an owner.

## Laboratory

**Quality → Laboratory.**

Catalogue of tests (grey scale, %, ΔE, pass/fail) is the left-hand list. Customer-specific thresholds override defaults.

**New test report:**

1. Optional customer / lot / product.
2. Enter a result per test. The **verdict is computed** from the scale and threshold (QL-5). You do not tick pass.
3. Save draft.
4. **Issue** — assigns `LAB-` number, stamps issued-at. An issued report is **immutable**. Cancel a draft if it is wrong; do not “edit” an issued sheet.

Mandatory tests with no result block issue.

## Compliance & CoC (Gate 2)

**Quality → Compliance & CoC.**

Certificates (GRS, FSC, OEKO-TEX, …) with expiry. The dashboard widget **Certificates expiring** reads this.

Chain of custody:

- **Input** is captured on the GRN line (scheme, claim %, document no) and copied onto the lot.
- **Output** is written when a certified packing list / challan is **issued**.
- Dilution is consumption-weighted and always **rounds down**. You cannot claim 100% GRS on output if the mix was 80%.

Reconciliation is a report, not a spreadsheet rebuild. If output claim exceeds diluted input, that is a failed audit — fix lots and challans, do not type a new percentage on the certificate.

## What you should not do

- Override an AQL fail by skipping disposition.
- Edit an issued lab report.
- Ship a GRS claim that the CoC report cannot support.
