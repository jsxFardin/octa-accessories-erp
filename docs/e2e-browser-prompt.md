# Octa ERP — end-to-end browser test prompt

Paste the block below into Claude in Chrome with the app running at `http://localhost:8000`.

---

You are testing **Octa ERP**, a label-manufacturing ERP (Laravel + Inertia + Vue) at
`http://localhost:8000`. Walk one order from customer creation to compliance reconciliation,
verifying that each business gate fires. Report defects; do not fix anything.

## Sign in

`/login` — `admin@maheenlabel.test` / `password` (super admin, all permissions).

The shop-floor terminal is a separate app at `/floor`: badge `BADGE-0009`, PIN `0009`. PINs are
stored hashed and set at Configuration → Users; the seed happens to use the badge's last four
digits. Leave the machine picker **empty** so every runnable operation is visible.

## Naming

Suffix every code you create with a run tag, e.g. `-T1`, so repeat runs don't collide:
`CUST-TEST-T1`, `PRD-TB-T1`, `AW-TB-T1`.

## The flow

Work in order. Each step depends on the last. After every save, note the document number
assigned and whether the status changed as expected.

1. **Customer** — Sales → Customers → New. Credit limit `5000`, min order value `100`,
   under/over tolerance `5`/`5`, currency BDT, any payment term.
2. **Inquiry** — Sales → Inquiries → New. Two lines with description + quantity only, no
   product. Save, then **Submit**.
3. **Product** — Products → Products → New, customer = your test customer, type `woven`,
   pick any woven routing.
4. **Spec and BOM** — expect to be **blocked**: no UI exists to create a product spec
   (`POST products/{product}/specs` has no screen) or a BOM (list only). Record this, then
   switch to a seeded product for the rest of the run: `CUST-L-01` / `PRD-L-01`, which has a
   current spec, active BOM, routing and approved artwork.
5. **Artwork** — Products → Artwork → New, upload version 1, Submit, Approve. (Already
   present on seeded products; do it once on a new artwork to exercise the state machine.)
6. **Quotation** — Sales → Quotations → New. Customer, currency BDT, exchange rate 1,
   valid-until +30 days. Add a line: product, quantity `30000`, margin `20`. **Do not type a
   rate** — it must compute. Then **Send** (numbers + locks the sheet), **Accept**,
   **Convert to sales order** with a customer PO and delivery date.
7. **Sales order** — press **Confirm**. Expect a credit-hold refusal if the order value
   exceeds the customer's credit limit; release it with a written reason. Confirm assigns the
   number and computes a promised date.
8. **Job card** — Floor → Job cards → New, pick the order line. Then **Release**.
9. **Material issue** — Inventory → Material issues → New. Job card, warehouse
   `Raw material store`, type `Issue`. Ask for each BOM item by quantity, **Suggest lots**,
   **Add to issue**, then **Post issue**.
10. **Floor** — `/floor`, run every operation in sequence:
    **START → OUTPUT → fill Input/Good/Waste → SAVE → FINISH.** Confirm the counters change
    after SAVE before pressing FINISH.
11. **QC** — Quality → Inspections → New, stage `Final`, lot size = packed quantity. Tap
    defects and watch the verdict recompute.
12. **Complete + FG receipt** — job card → **Complete**, then the Finished goods panel →
    quantity, warehouse FG → **Receive to FG**.
13. **Packing list** — Dispatch → Packing lists → New. One carton per 10,000 pieces, each
    naming the FG lot. **Confirm packed**.
14. **Challan** — Dispatch → Delivery notes → from the packing list → **Issue**.
15. **Trip** — Dispatch → Trips → New: vehicle, driver, add the challan as a stop, Start,
    then **Deliver** with a receiver name and the failure-reason field **left blank**.
16. **Invoice** — from the challan → **Create invoice**. Then Money → Receipts → record a
    payment and allocate it against the invoice.
17. **Compliance** — Quality → Compliance & CoC → **Reconciliation report**.

## Assertions

Check each of these and say pass/fail with the observed value.

- Quotation rate is computed, never typed; a sent quotation cannot be edited (Revise only).
- Margin is applied on price: `rate_per_m = unit_cost × 1000 ÷ (1 − margin)`. At 20% margin
  the margin line must equal 20% of the selling value, not 20% of cost.
- Sales order **Confirm** is refused without a current spec and an approved artwork version
  (Gate 1, panel `S3 · Gate 1`).
- Confirm past the credit limit lands on **credit hold**, and releasing it requires a reason.
- Job card release shows four green checks (artwork, active BOM, tools, material) — J1.
- Material issue: lot suggestion is FIFO/shade-first; breaking FIFO demands a reason;
  another job card's reservation is refused with P1-2 naming the shortfall.
- Operations run in sequence; an operation flagged QC blocks its successor until an
  inspection is accepted.
- The floor booking ceiling J3 (good + waste ≤ input) and the job ceiling J5 (3% overrun)
  both refuse over-booking. Try each once.
- QC verdict is computed from the ISO 2859-1 sampling plan: one critical defect rejects
  regardless of the plan; majors reject at the reject number.
- Job card **Complete** is refused without an accepted **final** inspection (P1-1).
- FG receipt is capped by the final operation's good quantity.
- The FG lot inherits a consumption-weighted certification claim, **rounded down**
  (e.g. 96 kg certified of 123 kg issued → 78%).
- Challan line shows band `within` when the quantity is inside the customer's ±tolerance.
- Issuing the challan moves the sales order's delivered quantity — it is never typed.
- Invoice due date = challan date + the customer's payment terms.
- CoC reconciliation balances **shipped against consumed**, in the same unit, with a conversion
  factor of 1 or less. Received is context, not the basis.
- A certified shipment is refused when its certificate is inactive, out of date, or has no
  document on file (BR-43).
- Job card completion is refused when a non-optional BOM item was never issued, unless waived
  with a documented reason (I7).
- An operator — four permissions, no `job_card.update` — can close the final operation.

## Known defects — each carries its current status

An earlier run found all ten; six have been fixed since. Try every one. A *(fixed)* item that
reproduces is a regression; a *(still open)* item is expected and needs no new report unless it
behaves differently from the description.

1. **Challan band double-counts after issue.** *(fixed — a posted challan should read `within`.)* `Challans/Show.vue → overBand()` adds the
   challan's own quantity to `delivered_qty`, which already includes it once issued. A
   challan reading `within` as a draft flips to `over band` the moment it posts.
2. **Invoice shows "Paid" when fully credited.** *(still open — a fully credited invoice is reported as a collection.)* Apply a credit note for the invoice total:
   status becomes `paid` with `received_amount = 0`. A write-off is reported as a collection.
3. **FINISH with no output.** *(still open — an operation may close with good = 0 and no warning.)* On the floor, press START then FINISH without SAVE. The
   operation closes with good = 0 and no warning; utilisation is understated permanently.
4. **Failed trip stop vs delivered order.** *(fixed — a failed stop now returns the challan: stock comes back, delivered_qty falls, the CoC claim is withdrawn.)* Mark a stop failed (type anything in the failure
   reason). Stock has already left on the challan and the sales order still reads delivered;
   the challan is stranded in `in_transit` with no route to `delivered` and no retry action.
5. **Operation input has no ceiling.** *(still open for input; output is now refused at J5 when booked.)* Book an input far above the operation's planned
   quantity (e.g. 5000 against a plan of 121). Accepted silently; only good + waste ≤ input
   is enforced.
6. **Job card output sums mixed units.** *(still open — the header total is not unit-aware.)* With one operation booked in metres and another in
   pieces, the header "Good against planned" adds them, and the J5 ceiling is evaluated on
   that sum.
7. **No spec or BOM screens** *(still open.)*, so a newly created product can never be quoted through the UI.
8. **PIN equals the last four of the badge** *(fixed — PINs are hashed and set at Configuration → Users; the seed value is still the badge's last four until changed.)* (`DeviceSessionRegistry::issue`), and the badge
   is printed on the card the operator wears. Any operator can sign in as any other.
9. **Certificates are placeholders** *(fixed — a claimed shipment is refused unless the certificate is active, in date, and has a document on file.)* — `*-PENDING`, `document_path` null. Validity is checked;
   whether a signed certificate was ever uploaded is not.
10. **BOM completeness is not enforced at completion.** *(fixed — completion is refused under I7 unless every non-optional BOM item was issued or the shortfall waived.)* Issue only some BOM items (e.g. yarn
    but not cartons); the job still completes with material unaccounted for.

## Report format

For each of the 17 steps: what you did, the document number, the status reached, pass/fail.

Then:
- **New defects** — steps to reproduce, expected vs actual, screenshot, severity.
- **Known defects** — confirmed / not reproduced.
- **UX friction** — anything that needed guessing: unlabelled fields, silent refusals,
  buttons whose effect wasn't obvious, terms colliding (GRN vs FG receipt vs cash receipt).

Do not modify code, settings, or seeded records beyond what the flow requires. Everything you
create should carry your run tag.
