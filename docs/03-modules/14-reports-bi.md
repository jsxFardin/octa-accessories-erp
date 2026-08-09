# Module 14 — Reporting & Dashboards

**Purpose:** turn the transactional data into the handful of numbers people actually act on. Not 300 reports nobody opens — a small set of dashboards plus a consistent report engine.

**Actors:** everyone, filtered by role.

**Reads:** all modules. Uses `v_order_book`, `v_machine_load`, `v_coc_reconciliation`, `stock_balances`.

---

## Principle

Every report in this system answers one of five questions:

1. **Are we selling?** — order book, inquiry conversion, revenue
2. **Are we making it?** — production output, WIP, OEE, on-time
3. **Are we making it well?** — DHU, defect Pareto, rejection, cost of quality
4. **Are we making money?** — cost variance, margin, profitability
5. **Can we prove it?** — traceability, compliance reconciliation, audit trail

A report that answers none of these does not get built.

---

## Dashboards

### Managing Director
| Tile | Source |
|---|---|
| Revenue this month vs target | `sales_invoices` |
| Open order book value | `v_order_book` |
| Late orders (count + value) | `v_order_book` |
| Machine utilisation today | `v_machine_load` + `capacity_calendars` |
| Production output this month vs last | `operation_logs` |
| Margin realised vs quoted | BR-23 |
| Outstanding receivables + overdue | `sales_invoices` |
| Inventory value | `stock_balances` |
| Certificates expiring in 90 days | `certifications` |

### Merchandiser
Open inquiries by age · quotations awaiting decision · artwork approvals pending · samples in transit · my orders and their progress · customers over credit limit.

### Planner
Unplanned confirmed lines · machine load next 14 days · material shortages by need date · job cards blocked (artwork/material/tool) · late-risk orders.

### Production Manager
Live machine status · today's output vs target by machine · WIP by operation · downtime minutes today by reason · waste % vs allowance.

### Quality Manager
DHU trend · defect Pareto (last 30 days) · rejection rate by stage · open NCRs and overdue CAPAs · lab pass rate.

### Store Keeper
Items below reorder level · lots expiring in 30 days · pending GRNs · pending issues · stock adjustments awaiting approval.

### Accounts
Ageing summary · invoices due this week · receipts today · bills awaiting approval · credit-hold orders.

---

## Report catalogue

Grouped by the five questions. Every report supports: date range, factory unit, drill-down to source document, Excel and PDF export, and a saved-filter feature.

### Sales
Inquiry funnel · quotation register · win/loss analysis · order book · order status detail · delivery performance · revenue by customer / product type / month · customer profitability · price history by product.

### Production
Daily production · production vs plan · WIP ageing · machine OEE · machine-wise output · operator productivity · downtime Pareto · setup/changeover analysis · waste analysis by type and value · job card cost sheet (actual) · cost variance (BR-23).

### Quality
Defect Pareto · DHU trend · rejection rate by stage · in-process failure by operation · lab test register and pass rate · certificate register · NCR/CAPA status · supplier quality · cost of quality · customer complaint analysis.

### Inventory & purchase
Stock on hand · stock ledger · lot genealogy · stock ageing · slow/non-moving · stock valuation · reorder list · open POs / pending receipts · purchase register · landed cost analysis · supplier performance · price variance · consumption vs standard (actual issue vs BOM).

### Compliance
CoC reconciliation · claim evidence pack · certified purchases · certified sales · certification expiry · audit findings.

### Finance
Customer ageing · supplier ageing · invoice register · collection performance · credit exposure · credit note analysis · exchange variance.

---

## Report engine

One engine, not fifty bespoke controllers.

| Concern | Approach |
|---|---|
| Definition | Each report is a class: name, permission, filter schema, query builder, column definitions, formatters |
| Filters | Declared once, rendered automatically (date range, select, multi-select, search) |
| Rendering | Server-paginated table (Inertia) with sticky headers and column totals |
| Export | Excel via a queued job for anything over 5,000 rows; download link on completion |
| PDF | Server-rendered, with the factory letterhead and the filter set printed in the footer |
| Scheduling | Any report can be scheduled (daily/weekly/monthly) and emailed to a list |
| Saved filters | Per user, shareable to a role |
| Drill-down | Every id column links to the source document |
| Permission | A report is invisible without its permission ([06-rbac](../06-rbac.md)) |

---

## Charts

Charts are rendered with **Apache ECharts** (self-hosted, no CDN). Chart types are constrained deliberately:

| Use | Chart |
|---|---|
| Trend over time | Line |
| Comparison across categories | Horizontal bar |
| Contribution to a total | Stacked bar (never a pie beyond 5 slices) |
| Defect/downtime priority | Pareto (bar + cumulative line) |
| Machine load | Heatmap (machine × day) |
| Progress against target | Single stat + sparkline |

Colour is consistent across the app: one accent for "actual", one muted for "target/plan", red only for genuine exceptions.

---

## Real-time

| Screen | Mechanism | Latency |
|---|---|---|
| Live production board | WebSocket (Laravel Reverb) | ≤ 5 s |
| Operator terminal counters | WebSocket | ≤ 2 s |
| Dashboard tiles | Polling, 60 s | 60 s |
| Reports | On demand | — |

Only the shop-floor screens justify a socket. Dashboards poll; polling is cheaper to operate and nobody makes a decision on a 5-second-old ageing figure.

---

## Performance

- Reports read from views and indexed columns; no report may run an unindexed sequential scan on `stock_ledger` or `operation_logs`.
- Anything heavier than ~2 seconds becomes a queued job with a progress indicator.
- Month-end aggregates (production, waste, OEE, revenue) are materialised nightly into summary tables once volume justifies it — the schema leaves room without requiring them on day one.
