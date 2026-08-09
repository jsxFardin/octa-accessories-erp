# 05 — Workflows & State Machines

Every document has one status column, one set of allowed transitions, and guards on each. Nothing changes status by direct assignment — transitions go through a state machine class so guards, side effects and audit entries cannot be skipped.

**Implementation:** one `StateMachine` per document (`app/Modules/<Module>/States/`). Transition method signature: `transition(Model $doc, string $to, array $context): void` — validates the guard, applies side effects and writes an `audit_logs` row with `event = 'status_changed'`.

Legend: **Guard** = must be true to transition. **Effect** = happens atomically on transition.

---

## 1. Inquiry

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> open : submit
    open --> quoted : quotation created
    quoted --> won : quotation accepted
    quoted --> lost : quotation rejected
    open --> lost : no bid
    draft --> cancelled
    open --> cancelled
    won --> [*]
    lost --> [*]
    cancelled --> [*]
```

| Transition | Guard | Effect |
|---|---|---|
| draft → open | ≥ 1 line | Assign number (BR-34), set merchandiser |
| open → quoted | A quotation exists | — |
| quoted → won | Quotation `accepted` | — |
| → lost | `lost_reason` present | Feeds win/loss analysis |

---

## 2. Quotation

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> sent : send
    sent --> accepted : customer accepts
    sent --> rejected : customer rejects
    sent --> revised : create revision
    sent --> expired : valid_until passed
    revised --> [*]
    accepted --> [*]
    rejected --> [*]
    expired --> [*]
    draft --> cancelled
```

| Transition | Guard | Effect |
|---|---|---|
| draft → sent | Every line has rate > 0 and a cost sheet | Assign number; **snapshot** rates, overheads, margin, exchange rate (Q1); lock cost sheets; generate and attach PDF; stamp `sent_at` |
| sent → accepted | — | Stamp `decided_at`; enable conversion to SO (Q3) |
| sent → rejected | `reject_reason` present | Update inquiry to `lost` if no other open quotation |
| sent → revised | — | Create revision `n+1` copying lines and sheets (Q4); prior becomes read-only |
| sent → expired | `valid_until < today` | Nightly job; may be reopened by revising |

A `sent` quotation is immutable. There is no edit path.

---

## 3. Sales Order

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> credit_hold : credit check fails
    draft --> confirmed : confirm
    credit_hold --> confirmed : credit released
    confirmed --> in_production : first job card started
    in_production --> partially_delivered : first challan
    confirmed --> partially_delivered : first challan
    partially_delivered --> delivered : tolerance met
    delivered --> closed
    partially_delivered --> closed : short close
    confirmed --> cancelled
    draft --> cancelled
    closed --> [*]
    cancelled --> [*]
```

| Transition | Guard | Effect |
|---|---|---|
| draft → confirmed | Every line: `current` spec **and** `approved` artwork (S3) | Assign number; compute `promised_date` (BR-29); reserve nothing yet; emit `SalesOrderConfirmed` |
| draft → credit_hold | `outstanding + value > credit_limit` (BR-46) | Notify Accounts and MD |
| credit_hold → confirmed | Permission `sales_order.release_credit_hold` + reason | Audit-logged release |
| → in_production | A job card started | — |
| → partially_delivered | A challan delivered | Update `delivered_qty` |
| → delivered | `delivered ≥ ordered × (1 − under_tolerance)` (BR-45) | — |
| → closed | Auto on delivered, or manual short-close with reason | Release reservations; final invoice check |
| → cancelled | No produced quantity, or MD approval | Cancel open job cards; release reservations |

Any quantity or date change after `confirmed` writes an `so_amendments` row (S2).

---

## 4. Artwork Version

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> submitted : submit to customer
    submitted --> approved : customer approves
    submitted --> rejected : customer rejects
    rejected --> [*]
    approved --> superseded : newer version approved
    superseded --> [*]
```

| Transition | Guard | Effect |
|---|---|---|
| draft → submitted | File uploaded with checksum | Generate submission PDF; stamp `submitted_at`; add to follow-up list |
| submitted → approved | `customer_ref` present (evidence required) | Supersede the previous approved version **in the same transaction** (A2); stamp `approved_at`, `approved_by`; emit `ArtworkVersionApproved` |
| submitted → rejected | `rejection_reason` present | Copy reason into the comment thread; design rework queue |

The unique key `artwork_versions_one_approved_uq` — over the generated column `approved_key`, which is NULL unless the version is approved — makes a double-approval physically impossible.

---

## 5. Sample Request

```mermaid
stateDiagram-v2
    [*] --> requested
    requested --> in_development : artwork/spec work
    in_development --> in_production : sample job card
    requested --> in_production : spec ready
    in_production --> ready : produced + QC
    ready --> dispatched : courier
    dispatched --> approved : customer approves
    dispatched --> rejected : customer rejects
    rejected --> in_development : revise
    approved --> [*]
    requested --> cancelled
```

| Transition | Guard | Effect |
|---|---|---|
| → in_production | Spec exists; artwork at least `submitted` | Create sample job card (`job_cards.sample_request_line_id`) |
| → ready | Sample job card completed and QC passed | — |
| → dispatched | Courier and tracking captured | Notify merchandiser; start ageing clock |
| → approved | Decision recorded per line | Unblock bulk production where a pre-production sample is required |
| → rejected | Comment required | Return to development |

---

## 6. Job Card

The most guarded transition in the system.

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> planned : scheduled
    planned --> material_pending : shortage
    material_pending --> released : material available or waived
    planned --> released : release
    released --> in_production : first operation started
    in_production --> on_hold : hold
    on_hold --> in_production : resume
    in_production --> qc_pending : all operations complete
    qc_pending --> in_production : rework
    qc_pending --> completed : QC accepted
    completed --> closed : close
    draft --> cancelled
    planned --> cancelled
    released --> cancelled
    closed --> [*]
    cancelled --> [*]
```

| Transition | Guard | Effect |
|---|---|---|
| draft → planned | Operations scheduled on compatible machines | Snapshot consumption plan (`gross_metres`, `ends`, `labels_per_metre`) |
| planned → released | **All four of J1**: artwork `approved` · BOM `active` · required tools available · material in stock or waived with reason + permission | Reserve stock; first operation → `ready`; appears on shop-floor queue |
| released → in_production | An operation started | Stamp `actual_start`; set the sales order to `in_production` |
| in_production → on_hold | `hold_reason` present | Free the machine slot on the planning board |
| in_production → qc_pending | All operations `completed` or `skipped` (J2 order respected) | Create the final QC inspection |
| qc_pending → in_production | QC disposition = `rework` | Reopen the named operation |
| qc_pending → completed | QC `accepted` or `accepted_with_concession` | Enable FG receipt |
| completed → closed | No open operations, no unresolved QC (J4); produced ≤ planned × (1 + overrun) (J5) | Release reservations; compute actual cost (BR-23); prompt to return unused material |
| → cancelled | No production logged, or supervisor approval | Release reservations; return issued material |

---

## 7. Purchase Order

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> pending_approval : submit
    pending_approval --> approved : approve
    pending_approval --> draft : return for changes
    approved --> sent : send to supplier
    sent --> partially_received : first GRN
    partially_received --> received : fully received
    sent --> received : single full GRN
    received --> closed
    approved --> cancelled
    sent --> cancelled
    closed --> [*]
```

| Transition | Guard | Effect |
|---|---|---|
| draft → pending_approval | Supplier `is_approved`; ≥ 1 line; quantities rounded per BR-25 | Route by value band |
| pending_approval → approved | Approver's band covers the value; ≥ 3 quotations above threshold or override reason | Stamp `approved_by`, `approved_at`; PO becomes read-only |
| approved → sent | — | Generate PDF; email supplier |
| → partially/received | GRN posted | Update `received_qty` per line |
| → closed | Fully received/billed, or manual close with reason | Remove from open-PO reports |

Changes to an approved PO create revision `n+1` with a reason.

---

## 8. GRN

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> pending_qc : post, item needs incoming QC
    draft --> posted : post, no QC required
    pending_qc --> accepted : QC pass
    pending_qc --> partially_accepted : QC partial
    pending_qc --> rejected : QC fail
    accepted --> posted
    partially_accepted --> posted
    rejected --> [*]
    posted --> [*]
    draft --> cancelled
```

| Transition | Guard | Effect |
|---|---|---|
| draft → posted / pending_qc | Quantities ≤ PO outstanding + tolerance; certification fields present when the PO line declares a claim | Create lots; write `grn_receipt` ledger rows; apportion landed cost (BR-36); update weighted average; write CoC `input` transaction (BR-42) |
| pending_qc → accepted | Inspection recorded | Lots move quarantine → available |
| pending_qc → rejected | Disposition recorded (BR-33) | Draft NCR against supplier; update supplier rating |

---

## 9. QC Inspection

```mermaid
stateDiagram-v2
    [*] --> pending
    pending --> accepted : within accept number
    pending --> rejected : at/over reject number or critical defect
    pending --> accepted_with_concession : customer concession
    rejected --> [*]
    accepted --> [*]
```

The verdict is **computed** from the AQL plan (BR-30), never typed. The inspector chooses only the disposition, which is mandatory on rejection (DB-enforced, BR-33).

---

## 10. Packing List → Delivery Challan → Trip

```mermaid
stateDiagram-v2
    state "Packing List" as PL {
        [*] --> pl_draft
        pl_draft --> packed
        packed --> pl_dispatched
        pl_dispatched --> pl_delivered
    }
    state "Delivery Challan" as DC {
        [*] --> dc_draft
        dc_draft --> issued
        issued --> in_transit
        in_transit --> dc_delivered
        in_transit --> returned
    }
    state "Trip" as TR {
        [*] --> planned
        planned --> loading
        loading --> tr_in_transit
        tr_in_transit --> tr_completed
    }
    packed --> dc_draft
    issued --> planned
```

| Transition | Guard | Effect |
|---|---|---|
| PL draft → packed | ≥ 1 carton; all lots passed final QC (D1) | Compute totals; validate certification claim (BR-40, BR-41) |
| DC draft → issued | Packing list `packed` (D3); quantity within delivery tolerance (BR-44) or override; certificate valid on challan date (BR-43) | Assign number; post `dispatch` ledger movement; increment `delivered_qty`; write CoC `output` transaction |
| DC → in_transit | Assigned to a started trip, or handed to courier | — |
| DC → delivered | POD captured (per customer policy) | Update delivery schedule; may auto-close the order line (BR-45) |
| DC → returned | Failure reason recorded | Reverse the dispatch movement; return stock |

---

## 11. Sales Invoice

```mermaid
stateDiagram-v2
    [*] --> draft
    draft --> issued
    issued --> partially_paid
    issued --> overdue : past due date
    partially_paid --> paid
    partially_paid --> overdue
    overdue --> partially_paid
    overdue --> paid
    issued --> paid
    paid --> [*]
    draft --> cancelled
```

Issued invoices are immutable; corrections are credit notes. `overdue` is set by a nightly job when `due_date < today` and the balance is outstanding.

---

## 12. NCR / CAPA

```mermaid
stateDiagram-v2
    [*] --> open
    open --> investigating
    investigating --> action_taken : CAPA recorded
    action_taken --> verified : effectiveness reviewed
    verified --> closed
    closed --> [*]
```

An NCR cannot reach `verified` without at least one CAPA with a root cause, a completed action, and a recorded effectiveness review.

---

## 13. Cross-cutting rules

| Rule | Detail |
|---|---|
| **Numbering** | Assigned on the first transition out of `draft`, never on form open (BR-34) |
| **Cancellation** | Cancelled documents keep their number and status; they are never deleted |
| **Audit** | Every transition writes an `audit_logs` row with old/new status, user, timestamp, IP |
| **Atomicity** | Guard check, status change, side effects and audit row are one database transaction |
| **Idempotence** | Transitioning to the current status is a no-op, not an error — protects against double-submit on flaky shop-floor wifi |
| **Permissions** | Every transition maps to a permission (`<document>.<transition>`) — see [06-rbac](06-rbac.md) |
| **Events** | Transitions with downstream consequences dispatch a domain event; listeners are queued |
