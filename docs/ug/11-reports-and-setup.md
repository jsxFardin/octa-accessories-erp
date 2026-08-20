# Reports & setup

## Reports

**Reports → All reports**, or open a named register from the group.

Operational registers with filters (dates, status, customer/supplier) and pagination:

| Report | Use it for |
|---|---|
| Fulfilment | Order lines: ordered vs produced vs delivered |
| Production | Job cards and output |
| Stock | On-hand and value |
| Dispatch register | Challans / trips |
| Receivables | Issued invoices, ageing, overdue |
| Payables | Supplier bills, outstanding |
| Purchases | PO lines: ordered vs received vs pending |
| NCR / CAPA | Quality actions and ageing |

Permission is `report.view` (dashboard is `report.dashboard`). Read-only auditors can open reports but **cannot export**.

## Configuration shell

Sidebar footer → **Configuration**. The main factory menu is replaced until you **Exit configuration**.

### Lists

Grouped on one page (Factory, People, Commercial, Units & money, Inventory, Production, Quality, Vocabularies). Open a list to add or edit. Company name and logo are in **Settings**, not here.

Do not put a new customer here — customers have their own screen under Sales.

### Settings

Numeric policy: overhead %, margin floor, cut gaps, delivery tolerances, QC/packing days, PO and adjustment approval bands, RFQ three-quote threshold, bill rate tolerance, expiry warning, merchandiser scoping, “final QC required”.

Changing a setting does **not** rewrite old documents. It applies to the next calculation or the next approval.

Organisation name and icon (sidebar mark) are here too.

### Number sequences

Prefixes and next numbers for SO, GRN, LOT, TRP, … Do not reset a sequence that has already issued documents.

### Users

Name, email, password, **role(s)**, active flag, employee link (factory unit, department, **badge** `card_no`).

The employee row is what the floor terminal logs in with. An operator does not need a desk password if they only ever use `/floor`, but they need a badge.

Deactivate leavers; do not reuse badges.

### Roles & permissions

Roles are bundles of permissions (`sales_order.confirm`, `stock_issue.create`, …). Prefer granting a standard role to adding one-off permissions. Never grant by checking “is MD” in a process — the button already is a permission.

### Audit log

Who changed what: creates, updates, **status_changed**. This is the answer to “who confirmed this order?” Filter by document and date. Read-only for auditors with `audit_log.view_any`.

## Keyboard

| Shortcut | Action |
|---|---|
| ⌘K / Ctrl-K | Search |
| ⌘B / Ctrl-B | Collapse sidebar |
| ⌘S / Ctrl-S | Save form |
| Esc | Close menus / dialogs |
