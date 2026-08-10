# 06 — Roles & Permissions

Permission-based, not role-based, at the code level: every check asks for a **permission**, never a role name. Roles are bundles of permissions and are editable by an admin without a deploy.

**Tables:** `roles`, `permissions`, `role_permissions`, `user_roles`.
**Package:** `spatie/laravel-permission` (cached, Redis-backed).

---

## 1. Naming convention

```
<module>.<action>
```

Standard actions: `view`, `view_any`, `create`, `update`, `delete`, `export`.
`import` is standard only on the four master-data lists a spreadsheet can state in full —
`item`, `customer`, `supplier`, `product` (`App\Support\Import\ImportRegistry`). It is separate
from `create`: loading four hundred records in one upload, over records that already exist, is
not the same act as adding one.
Transition actions match the state machine: `submit`, `approve`, `confirm`, `release`, `post`, `cancel`, `close`.
Exceptional actions are explicit: `override_margin`, `waive_material`, `release_credit_hold`, `approve_variance`, `short_close`, `override_tolerance`.

Never write `if ($user->hasRole('admin'))`. Always `$user->can('sales_order.confirm')`.

---

## 2. Roles

| Role | Who | Scope |
|---|---|---|
| `super_admin` | System owner / implementer | Everything, including settings and user management |
| `md` | Managing Director | Read everything; approve exceptions; no data entry |
| `admin` | System administrator | Users, roles, master data, number sequences |
| `merchandiser` | Sales/merchandising | Inquiry → quotation → order → sample → artwork submission |
| `sales_manager` | Sales lead | Merchandiser + approve discounts, override margin, view all customers |
| `designer` | Studio | Artwork versions, previews, product specs (draft) |
| `engineer` | IE / product engineering | Product specs, BOMs, routings, tools, consumption standards |
| `planner` | Production planning | Plans, MRP, job card creation and release, machine scheduling |
| `production_supervisor` | Floor supervision | Job card execution, operation logs, downtime, waste, holds |
| `operator` | Machine operator | Operator terminal only: start/pause/finish, output, downtime |
| `store_keeper` | Stores | GRN, issues, transfers, counts, adjustments (submit only) |
| `store_manager` | Stores lead | Store keeper + approve adjustments, approve counts |
| `qc_inspector` | Quality inspection | Inspections, defect capture, dispositions (limited) |
| `quality_manager` | Quality lead | QC inspector + concessions, NCR/CAPA, AQL plan maintenance |
| `lab_technician` | Laboratory | Test worksheets, results |
| `compliance_officer` | Compliance | Certifications, CoC ledger, reconciliation, audits |
| `purchase_officer` | Purchasing | PR, RFQ, PO (draft), GRN |
| `purchase_manager` | Purchasing lead | Purchase officer + PO approval, supplier approval |
| `dispatch_officer` | Dispatch | Packing, challan, trip planning, export docs |
| `driver` | Fleet | Driver screen only: stops, POD |
| `accounts` | Finance | Invoices, receipts, credit notes, bills, payments, credit release |
| `read_only` | Auditors, visitors | `view` / `view_any` on everything, no exports |
| `portal_customer` | External customer contact | Portal routes only, scoped to their `customer_id` |

---

## 3. Permission matrix

`●` full · `○` view only · `A` approve/exception only · blank = none

| Module | super_admin | md | admin | merch | sales_mgr | designer | engineer | planner | prod_sup | operator | store | store_mgr | qc | qual_mgr | lab | compliance | purch | purch_mgr | dispatch | driver | accounts | read_only |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| Users & roles | ● | ○ | ● | | | | | | | | | | | | | | | | | | | |
| Settings & sequences | ● | ○ | ● | | | | | | | | | | | | | | | | | | | |
| Master: items | ● | ○ | ● | ○ | ○ | | ○ | ○ | ○ | | ● | ● | ○ | ○ | ○ | ○ | ● | ● | ○ | | ○ | ○ |
| Master: machines | ● | ○ | ● | | | | ● | ● | ○ | ○ | | | | | | | | | | | | ○ |
| Master: customers | ● | ○ | ● | ● | ● | ○ | ○ | ○ | | | | | ○ | ○ | | ○ | | | ○ | | ● | ○ |
| Master: suppliers | ● | ○ | ● | | | | | ○ | | | ○ | ○ | ○ | ○ | | ○ | ● | ● | | | ● | ○ |
| Inquiry | ● | ○ | ○ | ● | ● | ○ | | | | | | | | | | | | | | | | ○ |
| Quotation | ● | ○ | ○ | ● | ● | | ○ | | | | | | | | | | | | | | ○ | ○ |
| Cost sheet | ● | ○ | | ● | ● | | ○ | | | | | | | | | | ○ | ○ | | | ○ | |
| — override margin | ● | A | | | A | | | | | | | | | | | | | | | | | |
| Sales order | ● | ○ | ○ | ● | ● | ○ | ○ | ○ | ○ | | ○ | ○ | ○ | ○ | | ○ | | | ○ | | ● | ○ |
| — release credit hold | ● | A | | | | | | | | | | | | | | | | | | | A | |
| — short close | ● | A | | | A | | | | | | | | | | | | | | | | | |
| Product & spec | ● | ○ | ○ | ○ | ○ | ● | ● | ○ | ○ | ○ | ○ | | ○ | ○ | ○ | ○ | | | ○ | | | ○ |
| Artwork | ● | ○ | ○ | ● | ● | ● | ○ | ○ | ○ | ○ | | | ○ | ○ | | ○ | | | ○ | | | ○ |
| — approve version | ● | A | | A | A | | | | | | | | | | | | | | | | | |
| BOM & routing | ● | ○ | ○ | ○ | ○ | ○ | ● | ● | ○ | | ○ | | | | | | ○ | ○ | | | | ○ |
| Tools | ● | ○ | ○ | | | ○ | ● | ● | ● | ○ | ○ | ○ | | | | | | | | | | ○ |
| Samples | ● | ○ | ○ | ● | ● | ● | ○ | ○ | ● | ○ | ○ | | ● | ● | ○ | | | | ● | | ○ | ○ |
| Purchase requisition | ● | ○ | ○ | | | | | ● | ○ | | ● | ● | | | | | ● | ● | | | ○ | ○ |
| RFQ & supplier quote | ● | ○ | ○ | | | | | ○ | | | | | | | | | ● | ● | | | ○ | ○ |
| Purchase order | ● | ○ | ○ | | | | | ○ | | | ○ | ○ | | | | ○ | ● | ● | | | ○ | ○ |
| — approve PO | ● | A | | | | | | | | | | | | | | | | A | | | | |
| GRN | ● | ○ | ○ | | | | | ○ | ○ | | ● | ● | ● | ● | | ○ | ● | ● | | | ○ | ○ |
| Inventory & lots | ● | ○ | ○ | ○ | ○ | | ○ | ● | ● | ○ | ● | ● | ○ | ○ | ○ | ○ | ○ | ○ | ● | | ○ | ○ |
| — adjustments | ● | ○ | | | | | | | | | ● | ● | | | | | | | | | | |
| — approve adjustment | ● | A | | | | | | | | | | A | | | | | | | | | | |
| Planning & MRP | ● | ○ | ○ | ○ | ○ | | ○ | ● | ● | ○ | ○ | ○ | | | | | ○ | ○ | ○ | | | ○ |
| Job card | ● | ○ | ○ | ○ | ○ | ○ | ○ | ● | ● | ○ | ○ | ○ | ○ | ○ | | ○ | | | ○ | | ○ | ○ |
| — release | ● | | | | | | | ● | ● | | | | | | | | | | | | | |
| — waive material | ● | A | | | | | | A | | | | | | | | | | | | | | |
| Operation logging | ● | ○ | | | | | | ○ | ● | ● | | | ○ | ○ | | | | | | | | ○ |
| Downtime & waste | ● | ○ | | | | | | ○ | ● | ● | ○ | ○ | ○ | ○ | | | | | | | | ○ |
| QC inspection | ● | ○ | ○ | ○ | ○ | | ○ | ○ | ○ | ○ | ○ | ○ | ● | ● | ○ | ○ | ○ | ○ | ○ | | | ○ |
| — concession | ● | A | | | | | | | | | | | | A | | | | | | | | |
| Lab tests & reports | ● | ○ | ○ | ○ | ○ | | ○ | | ○ | | ○ | | ● | ● | ● | ● | | | ○ | | | ○ |
| NCR / CAPA | ● | ○ | ○ | ○ | ○ | | ○ | ○ | ● | | ○ | ○ | ● | ● | ○ | ● | ○ | ○ | ○ | | ○ | ○ |
| Compliance & CoC | ● | ○ | ○ | ○ | ○ | | ○ | | ○ | | ○ | ○ | ○ | ● | ○ | ● | ○ | ○ | ○ | | ○ | ○ |
| Packing & dispatch | ● | ○ | ○ | ○ | ○ | | | ○ | ● | | ● | ● | ● | ● | | ○ | | | ● | ○ | ○ | ○ |
| Fleet & trips | ● | ○ | ○ | | | | | ○ | ○ | | ○ | ○ | | | | | | | ● | ● | ○ | ○ |
| Export documents | ● | ○ | ○ | ● | ● | | | | | | | | | | | ○ | ○ | ○ | ● | | ● | ○ |
| Invoices & receipts | ● | ○ | ○ | ○ | ○ | | | | | | | | | | | | ○ | ○ | ○ | | ● | ○ |
| Credit notes | ● | ○ | | ○ | ○ | | | | | | | | | ○ | | | | | | | ● | ○ |
| — approve credit note | ● | A | | | | | | | | | | | | | | | | | | | A | |
| Supplier bills & payments | ● | ○ | ○ | | | | | | | | | | | | | | ○ | ○ | | | ● | ○ |
| Reports | ● | ● | ○ | ● | ● | ○ | ● | ● | ● | ○ | ● | ● | ● | ● | ● | ● | ● | ● | ● | | ● | ○ |
| Audit log | ● | ○ | ● | | | | | | | | | | | | | ○ | | | | | ○ | ○ |

---

## 4. Data scoping

Permission alone is not enough; several roles must also be scoped to rows.

| Scope | Applies to | Implementation |
|---|---|---|
| **Factory unit** | planner, production_supervisor, operator, store_keeper, dispatch | `factory_unit_id` filter from the user's assignment; global scope on operational models |
| **Customer** | portal_customer | Global scope on every portal-exposed model, resolved from `customer_contacts.portal_user_id` |
| **Own records** | merchandiser (optional) | Setting `merchandiser_sees_own_only`; when on, filters by `merchandiser_id` |
| **Machine** | operator | Operator terminal shows only machines in the operator's department |

Scoping is applied as a **global query scope**, never as a controller-level `where`. A missing `where` in one controller is a data leak; a global scope fails safe.

---

## 5. Approval bands

Value thresholds live in `settings`, not in code, so they change without a deploy.

| Document | Band | Approver |
|---|---|---|
| Purchase order | ≤ 100k BDT | purchase_manager |
| Purchase order | > 100k BDT | md |
| Stock adjustment | ≤ 25k BDT value | store_manager |
| Stock adjustment | > 25k BDT | md |
| Credit note | ≤ 50k BDT | accounts |
| Credit note | > 50k BDT | md |
| Margin below floor | any | sales_manager or md |
| Credit limit release | any | accounts or md |

---

## 6. Special cases

**Operator terminal** — the `operator` role holds exactly four permissions: `operation.start`, `operation.log`, `operation.finish`, `downtime.create`. It cannot open any other screen. Login is by badge scan plus a short PIN, with a shift-length session.

**Driver screen** — the `driver` role holds `trip.view_own`, `trip_stop.update`, `pod.create`. Scoped to trips where they are the assigned driver.

**MD** — read-everything plus approval permissions, but no create/update on transactional documents. An MD who enters data is an MD who breaks the audit trail.

**read_only** — `view` and `view_any` on everything, no `export`. Exporting is a data-exfiltration path and is granted deliberately.

---

## 7. Implementation notes

- Permissions are seeded from a single source file (`database/seeders/PermissionSeeder.php`) generated from this document; a test asserts the seeded set matches the route middleware in use, so a route can never reference an undefined permission.
- Every route carries `->middleware('can:<permission>')`. A route with no permission middleware fails a static test.
- Every Inertia page receives the user's permission set; the frontend hides what the user cannot do — but the backend check is the security boundary, and the frontend hiding is a courtesy.
- Permission caches are flushed on role or permission change.
- Role changes are audit-logged with old and new role sets.
