# Module 04 — Artwork & Sampling

**Purpose:** control the design asset through revisions and customer approval, and manage the physical samples that earn that approval. Production may never run against an unapproved design.

**Actors:** Designer/Studio, Merchandiser, Customer (via email or portal), Sample room, QC.

**Tables:** `artworks`, `artwork_versions`, `comments` (polymorphic), `attachments`, `sample_requests`, `sample_request_lines`, `sample_dispatches`, `sample_approvals`.

**Invariants:** A1–A4. **Gate 1** ([01-domain-model §4](../01-domain-model.md#4-the-two-gates-that-define-the-system)).
**Workflows:** [05-workflows §4 Artwork, §5 Sample](../05-workflows.md).

---

## Artwork versioning

```
Artwork (per product)
 ├── v1  draft → submitted → rejected      "logo 2mm too small"
 ├── v2  draft → submitted → approved      ← the only version production may use
 └── v3  draft                             (next season's revision, in progress)
```

Rules the schema enforces:

- Versions number contiguously from 1 and never renumber (A1).
- At most one `approved` version per artwork — `artwork_versions_one_approved_uq` unique key (A2).
- The file is immutable after upload; `checksum_sha256` is recorded (A3). A correction is a new version.
- An approved version cannot be deleted while a job card references it (A4).

Supported formats: `ai`, `eps`, `pdf`, `cdr`, `psd`, `png`, `jpg`, `svg`. A raster preview is generated on upload for in-browser viewing (queued job) so no one needs Illustrator to check a label.

---

## Screens

| Screen | Route | Notes |
|---|---|---|
| Artwork workspace | `/products/{id}/artwork` | Version rail on the left, preview centre, comments right |
| Version compare | `/artworks/{id}/compare?a=2&b=3` | Side-by-side preview with an overlay/difference toggle |
| Approval queue | `/artworks/pending` | Everything `submitted`, aged, by customer |
| Sample request list | `/sales/samples` | Status, type, required-by, ageing |
| Sample request form | `/sales/samples/{id}` | Lines, colourways, dispatch, approval |
| Sample dispatch | `/sales/samples/{id}/dispatch` | Courier, tracking, recipient |

---

## Comment thread

`comments` is polymorphic and carries `is_external`. Internal notes stay internal; external comments are the customer-visible thread and are what the portal ([15-customer-portal](15-customer-portal.md)) exposes later. Threading via `parent_id`.

---

## Sample types

| Type | Purpose | Typically charged |
|---|---|---|
| `proto` | First physical interpretation of a new design | Sometimes |
| `approval` | Formal submission for sign-off | Yes |
| `colour` | Shade confirmation against a standard | Yes |
| `size_set` | Range of sizes/variants of one design | Sometimes |
| `pre_production` | Made on production tooling before bulk | No |
| `shipment` | Retained reference from the bulk run | No |
| `counter` | Retained in-house reference copy | No |

---

## User stories

**AS-1 — Upload an artwork version**
*As a Designer I upload a new version of a label design.*
- AC1: Version number is assigned automatically as `max + 1`; it cannot be typed (A1).
- AC2: File is stored with a SHA-256 checksum; a preview render is queued.
- AC3: Status starts `draft`; only `draft` versions may be replaced (and replacing bumps the version, it does not overwrite — A3).
- AC4: Uploading requires permission `artwork.upload`.

**AS-2 — Submit for approval**
*As a Merchandiser I submit a version to the customer.*
- AC1: Status `draft` → `submitted`; `submitted_at` stamped.
- AC2: A PDF submission sheet is generated: preview, dimensions, colours, material, fold, cut, care content.
- AC3: Notification to the merchandiser's follow-up list; ageing tracked from `submitted_at`.

**AS-3 — Record customer approval**
*As a Merchandiser I record the customer's decision.*
- AC1: `approved` requires `customer_ref` (email subject, approval sheet number, or portal action id) — approval without evidence is rejected by validation.
- AC2: Approving vN automatically sets the previously approved version to `superseded` (A2), in one transaction.
- AC3: `rejected` requires `rejection_reason`; the reason is copied into the comment thread.
- AC4: On approval, an event notifies Planning that any blocked sales order lines may now proceed.

**AS-4 — Block production without approval**
*As the system I refuse to release production against an unapproved design.*
- AC1: `job_cards.artwork_version_id` is `NOT NULL` and must reference a version whose status is `approved` at release time.
- AC2: If the approved version changes after release, open job cards are flagged (not silently switched) and the planner must decide.
- AC3: A test asserts that no code path can create a released job card without an approved version.

**AS-5 — Raise a sample request**
*As a Merchandiser I request samples for customer approval.*
- AC1: Sample type is mandatory; may link to an inquiry or a sales order.
- AC2: Lines carry product/spec/artwork version, quantity and colourway.
- AC3: If chargeable, a charge amount is captured for later invoicing.
- AC4: A sample line produces a **sample job card** (`job_cards.sample_request_line_id`) so sample production consumes real material and is costed like any other run.

**AS-6 — Dispatch samples**
*As Sample room I dispatch samples to the customer.*
- AC1: Courier, tracking number and recipient are recorded.
- AC2: Status → `dispatched`; the tracking number appears on the merchandiser's follow-up list.
- AC3: `delivered_on` may be updated later, manually or from a courier webhook (phase 3).

**AS-7 — Record sample approval**
*As a Merchandiser I record the sample decision per line.*
- AC1: Decision is one of `approved`, `approved_with_comments`, `rejected`.
- AC2: `approved_with_comments` and `rejected` require comment text.
- AC3: Approval of a `pre_production` sample is a prerequisite for bulk job card release when the customer requires it (flag on the customer).

---

## Reports

| Report | Content |
|---|---|
| Artwork approval ageing | Submitted versions by days waiting, by customer |
| Version history | Every version, decision, and who approved with which reference |
| Sample turnaround | Request → dispatch → decision, average days by type and customer |
| Sample rejection analysis | Reasons grouped, repeat offenders by product/designer |
| Chargeable samples not invoiced | Revenue leak report |

---

## Events emitted

| Event | Consumers |
|---|---|
| `ArtworkVersionSubmitted` | Notification, follow-up list |
| `ArtworkVersionApproved` | Sales (unblock line), Planning, Tooling (plate/screen can be made) |
| `ArtworkVersionRejected` | Design (rework queue) |
| `SampleDispatched` | Merchandiser follow-up |
| `SampleApproved` | Sales, Planning (bulk may proceed) |
