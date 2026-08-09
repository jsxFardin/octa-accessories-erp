# 07 — Interface Contracts

Two interfaces, deliberately different:

1. **Inertia pages** — every internal screen. No JSON API, no client-side data fetching, no duplicated validation.
2. **Device JSON API** — shop-floor terminals, scanners and the driver app. Small, offline-tolerant, token-authenticated.

---

## 1. Inertia contract

### Conventions

| Concern | Rule |
|---|---|
| Page component | `resources/js/Pages/<Module>/<Screen>.vue` |
| Props | Explicit DTOs from Laravel Resources — never raw Eloquent models |
| Lists | Server-paginated; the page receives `data`, `meta`, `filters` |
| Filters | Bound to the query string; back/forward and refresh preserve state |
| Partial reload | `router.reload({ only: ['rows'] })` for filter changes |
| Deferred props | Heavy panels (cost sheet, genealogy tree) use Inertia deferred props |
| Validation | Server-side only; errors arrive as `errors` and bind to fields |
| Flash | `flash.success` / `flash.error` from the session |
| Permissions | `auth.can` — a map of the user's permissions, used to hide actions |

### Standard list page props

```ts
interface ListPage<TRow, TFilters> {
  rows: { data: TRow[]; meta: PaginationMeta }
  filters: TFilters              // echoed back so inputs stay filled
  options: Record<string, Option[]>   // select options for filters
  auth: { user: UserSummary; can: Record<string, boolean> }
  flash: { success?: string; error?: string }
}
```

### Standard form page props

```ts
interface FormPage<TModel, TRefs> {
  model: TModel | null           // null when creating
  refs: TRefs                    // lookup data: items, machines, customers…
  transitions: Transition[]      // allowed state transitions with labels + confirmations
  auth: { can: Record<string, boolean> }
}

interface Transition {
  to: string
  label: string                  // "Confirm order"
  requiresReason: boolean
  confirmMessage?: string
  blockedBy?: string[]           // human-readable guard failures, e.g. ["Artwork not approved"]
}
```

`blockedBy` is what makes guards teachable: the button is visible but disabled, with the reason next to it, instead of silently missing.

### Example — Job Card detail

```ts
interface JobCardPage {
  jobCard: {
    id: number
    number: string
    status: JobCardStatus
    product: { id: number; code: string; name: string; type: ProductType }
    spec: { widthMm: number; heightMm: number; ends: number; cutType: string; foldType: string }
    artworkVersion: { id: number; versionNo: number; previewUrl: string; approvedAt: string }
    plannedQty: number
    producedQty: number
    goodQty: number
    wasteQty: number
    grossMetres: number
    dueDate: string
    operations: Operation[]
    materials: MaterialRequirement[]   // required vs issued vs remaining
  }
  transitions: Transition[]
  auth: { can: Record<string, boolean> }
}
```

---

## 2. Device JSON API

For hardware that cannot run a full page: shop-floor tablets, handheld scanners, the driver's phone.

### Base

```
Base path      /api/v1
Auth           Bearer token (Laravel Sanctum), device-bound
Content type   application/json
Versioning     URI path; v1 is supported until v2 has been live for 6 months
Rate limit     120 req/min per device token
Idempotency    Idempotency-Key header required on every POST
```

### Idempotency — non-negotiable

Shop-floor wifi drops mid-request. The client retries. Without idempotency, output gets double-counted and stock goes wrong.

```http
POST /api/v1/operations/4471/log
Idempotency-Key: 7f3c1e2a-9b44-4f0e-b1a8-2c9d5e7a1f30
```

The server stores the key with the response for 24 hours. A repeat of the same key returns the **stored response**, status `200`, with header `Idempotent-Replay: true`. It does not re-execute.

### Offline queue

Devices queue POSTs locally (IndexedDB) while offline and flush in order when connectivity returns. Each queued request keeps its original `Idempotency-Key` and an `occurred_at` timestamp; the server records `occurred_at`, not receipt time, so a two-hour outage does not distort a shift report.

Conflicts (e.g. the operation was closed by a supervisor while the device was offline) return `409` with a machine-readable `code` and a human message; the device surfaces it for the operator to resolve.

---

## 3. Endpoints

### 3.1 Identity

```http
POST /api/v1/device/register        → device token (one-time setup code)
POST /api/v1/auth/badge             { card_no, pin } → operator session token
GET  /api/v1/me                     → user, permissions, factory unit, machines
```

### 3.2 Scanning — one endpoint, many barcodes

```http
GET /api/v1/scan/{barcode}
```
```json
{
  "type": "stock_lot",
  "id": 88412,
  "label": "L260802-00341 · Satin ribbon 40mm · Shade B-2214",
  "actions": [
    { "code": "issue_to_job", "label": "Issue to job card" },
    { "code": "transfer",     "label": "Transfer" },
    { "code": "view",         "label": "View lot" }
  ],
  "data": { "itemCode": "RIB-SAT-40", "balanceQty": 1840.5, "uom": "metre",
            "warehouse": "RM-01", "certScheme": "GRS", "certClaimPct": 60 }
}
```
`type` is one of `job_card`, `stock_lot`, `carton`, `employee`, `machine`, `tool`, `packing_list`, `delivery_challan`. The device does not need to know barcode formats — it scans and asks.

### 3.3 Shop floor

```http
GET  /api/v1/machines/{code}/queue           → job cards queued on this machine
GET  /api/v1/operations/{id}                 → operation detail + live counters
POST /api/v1/operations/{id}/start           { operator_card_no, shift_id, occurred_at }
POST /api/v1/operations/{id}/log             { good_qty, waste_qty, waste_type?, occurred_at }
POST /api/v1/operations/{id}/pause           { reason_code, occurred_at }
POST /api/v1/operations/{id}/finish          { good_qty, waste_qty, occurred_at }
POST /api/v1/downtime                        { machine_id, reason_code, started_at, ended_at? }
POST /api/v1/waste                           { job_card_id, waste_type, qty, uom_id, occurred_at }
```

Response to any log/finish:
```json
{
  "operation": { "id": 4471, "status": "in_progress",
                 "goodQty": 884.0, "wasteQty": 24.0, "inputQty": 1250.0 },
  "jobCard":   { "id": 3312, "producedQty": 884.0, "plannedQty": 1250.0, "progressPct": 70.7 },
  "warnings":  ["Waste 2.7% exceeds allowance 2.0%"]
}
```

`warnings` is advisory. Hard failures are `422` with field errors, or `409` for state conflicts.

### 3.4 Store

```http
GET  /api/v1/job-cards/{id}/materials        → required vs issued vs remaining
GET  /api/v1/items/{id}/lot-suggestions      ?qty=&job_card_id=   (shade-first, BR-37)
POST /api/v1/material-issues                 { job_card_id, lines: [{lot_id, qty, override_reason?}] }
POST /api/v1/stock-transfers/{id}/receive    { lines: [{line_id, received_qty}] }
POST /api/v1/counts/{id}/lines               { lot_id, counted_qty }
```

### 3.5 Quality

```http
GET  /api/v1/inspections/{id}                → AQL plan, sample size, accept/reject, defect list
POST /api/v1/inspections/{id}/defects        { defect_id, qty }
POST /api/v1/inspections/{id}/complete       { disposition?, remarks? }
```

### 3.6 Packing & dispatch

```http
POST /api/v1/packing-lists/{id}/cartons      { lines: [{lot_id, qty, bundles}] }
POST /api/v1/cartons/{id}/close              { gross_weight_kg, net_weight_kg }
GET  /api/v1/trips/{id}                      → stops with addresses and carton counts
POST /api/v1/trip-stops/{id}/arrive          { occurred_at, lat?, lng? }
POST /api/v1/trip-stops/{id}/deliver         { received_by_name, signature (base64 png),
                                               photo?, occurred_at }
POST /api/v1/trip-stops/{id}/fail            { failure_reason, occurred_at }
```

Signature and photo upload as base64 in the body (they are small and must survive the offline queue), stored to disk server-side.

---

## 4. Error format

```json
{
  "code": "OPERATION_ALREADY_CLOSED",
  "message": "This operation was finished by supervisor Karim at 14:32.",
  "details": { "operationId": 4471, "finishedAt": "2026-08-02T14:32:11+06:00" }
}
```

| HTTP | Meaning | Device behaviour |
|---|---|---|
| 400 | Malformed request | Log, do not retry |
| 401 | Token invalid/expired | Re-authenticate |
| 403 | Permission denied | Show message, do not retry |
| 409 | State conflict | Surface to operator, drop from queue |
| 422 | Validation failed | Show field errors |
| 429 | Rate limited | Exponential backoff |
| 5xx | Server error | Retry with backoff, keep in queue |

`code` is a stable enum. Clients switch on `code`, never on `message` — messages are localised.

---

## 5. Real-time channels

Laravel Reverb (WebSockets), private channels authorised by permission.

| Channel | Events | Consumers |
|---|---|---|
| `factory.{unitId}.machines` | `MachineStatusChanged`, `OperationProgressed` | Live production board |
| `machine.{machineId}` | `OperationStarted`, `OperationLogged`, `OperationFinished` | Operator terminal |
| `job-card.{id}` | status changes, QC result | Job card detail page |
| `user.{id}` | notifications, approval requests | Header bell |

Payloads are small — an id and the changed fields. The client refetches if it needs more. Never push a full document over a socket.

---

## 6. File handling

| Concern | Rule |
|---|---|
| Upload | Direct to the app (artwork is ≤ 100 MB); chunked above 20 MB |
| Storage | Local disk in phase 1, S3-compatible from phase 3; driver switch only |
| Access | Signed, expiring URLs — never a public path |
| Artwork | Original stored immutably with SHA-256; a raster preview is generated by a queued job |
| Barcode / labels | Server-generated PDF (Code 128 for lots and cartons, QR for job cards) |
| Exports | Queued job, notification with a signed download link, link expires in 24 h |

---

## 7. Contract testing

| Test | Assertion |
|---|---|
| Idempotency | Replaying a POST with the same key does not double-post; response is byte-identical |
| Offline order | Queued requests applied out of order still produce correct totals (`occurred_at` wins) |
| Conflict | Logging to a closed operation returns 409 with `OPERATION_ALREADY_CLOSED` |
| Permission | Every `/api/v1` route rejects a token without the required permission |
| Scope | A device token bound to factory unit A cannot read unit B |
| Shape | Portal and device responses contain no cost, margin or supplier fields |
