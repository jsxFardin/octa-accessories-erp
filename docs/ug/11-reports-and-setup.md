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

Name, email, password, **role(s)**, active flag, employee link (factory unit, department, **badge** `card_no`, **floor PIN**).

The employee row is what the shop floor terminal signs in with, and it needs three things to
work: an active employee record, a **badge number**, and a **floor PIN**. Missing any one of
them and the operator is refused at the terminal with "Badge or PIN not recognised". The
factory unit on that same row is what scopes their work queue — without it the queue has
nothing to show.

An operator who only ever uses `/floor` does not need a desk password, but they do need all
three of the above. The PIN is stored hashed and never shown back: a forgotten one is reset,
not looked up. Do not set it to digits from the badge — the badge is worn where anyone can
read it.

Deactivate leavers; do not reuse badges.

### Scheduled checks

Three commands run on a schedule (`php artisan schedule:work`, or a cron entry calling
`schedule:run` every minute). They report; none of them changes data.

| Command | When | What it answers |
|---|---|---|
| `ncr:notify-overdue` | 01:00 daily | Which NCRs are past their due date, to the people who own them. |
| `stock:reconcile` | 02:00 daily | Does the derived stock balance still match the append-only ledger (AD-6)? |
| `queue:health` | hourly | Is anything actually draining the queue? Notifications are queued, so without a worker the inbox is silently always empty. |

`stock:reconcile` exits non-zero when a balance disagrees with the ledger, so it can be wired to
an alert. It deliberately does **not** correct the figures: a difference means some write
bypassed the one service allowed to move stock, and the difference is the only evidence that
path exists. Find the path first.

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
