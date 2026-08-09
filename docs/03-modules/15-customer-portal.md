# Module 15 — Customer Portal (Phase 3)

**Purpose:** let customers do the chasing themselves — check order status, approve artwork, download certificates and invoices — instead of emailing a merchandiser.

**Status:** deferred to Phase 3. Specified now so the data model does not have to change later.

**Actors:** Customer contact (external), Merchandiser (moderates), System Admin.

**Tables:** no new tables. Uses `customer_contacts.portal_user_id`, `users`, `comments.is_external`, and read-only projections of sales, artwork, dispatch and lab data.

---

## Architectural approach

Not a separate application (AD-1). The portal is a **route group with its own middleware, layout and permission set** inside the same Laravel + Inertia monolith:

```
routes/portal.php        →  /portal/*
Middleware: EnsurePortalUser  (resolves customer_id from the authenticated user)
Global scope: every query is constrained to that customer_id
Layout: PortalLayout.vue     (different navigation, factory branding)
```

Portal users are rows in `users` linked from `customer_contacts.portal_user_id`. They hold only portal permissions and never appear in internal user pickers.

**Security posture:** the customer scope is applied as a **global query scope on every portal-exposed model**, not as a controller-level `where`. A missed `where` in one controller would leak another customer's orders; a global scope fails safe. This is asserted by tests that attempt cross-customer access on every portal route.

---

## What the customer sees

| Area | Content |
|---|---|
| **Dashboard** | Open orders, items awaiting their approval, recent shipments, outstanding invoices |
| **Orders** | Order list with status and progress %, line detail, delivery schedule, promised dates |
| **Artwork** | Versions submitted to them, preview, approve / reject with comments |
| **Samples** | Sample requests, dispatch tracking numbers, decision capture |
| **Shipments** | Packing lists, carton counts, challan/tracking, POD once delivered |
| **Documents** | Test certificates, certification claims, commercial invoices, packing lists |
| **Inquiries** | Raise a new inquiry directly (feeds [02-crm-sales](02-crm-sales.md)) |
| **Messages** | The external comment thread per order/artwork |

### What they must never see
Cost sheets, margins, machine assignments, other customers' anything, internal comments (`is_external = false`), supplier names, stock levels, employee names beyond a named account contact.

That list is a test suite, not a guideline.

---

## User stories

**CP-1 — Portal invitation**
- AC1: A merchandiser invites a `customer_contacts` row to the portal; an email with a signed, expiring link is sent.
- AC2: The invitee sets a password; MFA is optional and can be mandated per customer.
- AC3: Access can be revoked instantly; revocation is audit-logged.

**CP-2 — Check order status**
- AC1: Order list shows number, PO, product, ordered/delivered quantity, promised date, status.
- AC2: Progress is expressed in customer terms ("in production", "packed", "shipped"), not internal job card states.
- AC3: No internal cost, machine or operator information appears anywhere in the payload — verified by a response-shape test.

**CP-3 — Approve artwork**
*The highest-value feature in the module.*
- AC1: Versions with status `submitted` for this customer appear in an approval queue.
- AC2: The customer views the preview, downloads the source file if permitted, and approves or rejects with comments.
- AC4: Approval records the portal action id as `artwork_versions.customer_ref` — better evidence than an email thread (AS-3 AC1).
- AC5: Approval fires the same `ArtworkVersionApproved` event as an internally recorded approval; there is one code path, not two.

**CP-4 — Track a shipment**
- AC1: Shipments show challan number, carton count, quantity, dispatch date, mode and tracking number.
- AC2: Once delivered, the POD (receiver name, timestamp, signature image) is visible.

**CP-5 — Download documents**
- AC1: Test certificates for the customer's lots, commercial invoices, packing lists.
- AC2: Every download is logged (who, what, when) — several brands audit this.

**CP-6 — Raise an inquiry**
- AC1: The customer submits an inquiry with lines, quantities and an optional artwork attachment.
- AC2: It lands as an `inquiries` row with `source = 'portal'`, assigned to the account's merchandiser.
- AC3: The merchandiser is notified; the customer sees it acknowledged.

---

## Rollout

| Step | Why |
|---|---|
| 1. Read-only order status for 2–3 pilot customers | Lowest risk, immediate reduction in "where is my order?" emails |
| 2. Document downloads | Still read-only |
| 3. Artwork approval | First write path; the one that saves the most internal time |
| 4. Inquiry submission | Second write path |
| 5. General availability | After the pilot customers stop finding surprises |

---

## Non-functional

| Concern | Requirement |
|---|---|
| Isolation | Global customer scope on every model; cross-customer access tests on every route |
| Rate limiting | Stricter than internal routes; per-user and per-IP |
| Session | Shorter idle timeout than internal (30 min) |
| File access | Signed, expiring URLs; no direct storage paths |
| Audit | Every portal action written to `audit_logs` with the portal user |
| Branding | Factory branding, mobile-first — buyers check status from a phone |
| Availability | Same SLA as internal; a portal outage is visible to customers |
