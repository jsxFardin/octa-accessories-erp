# By role

What to open first after sign-in. Full how-to is in the chapter linked at the end of each row.

| Role | Login (demo) | Open first | Then | Chapter |
|---|---|---|---|---|
| Super admin | `admin@…` | Dashboard, then Configuration | Walk the seeded SO through to invoice once | [Getting started](01-getting-started.md) |
| Managing Director | `md@…` | Dashboard queue | Approvals above manager bands, credit-hold releases, exceptions | [Reports](11-reports-and-setup.md) |
| Merchandiser | `merchandiser@…` | Sales → Inquiries | Quote, submit artwork, confirm SO when Gate 1 is green | [Sales](03-sales.md) |
| Sales manager | `sales@…` | Quotations / orders | Margin overrides, short close | [Sales](03-sales.md) |
| Designer | `designer@…` | Products → Artwork | New versions, submit | [Products & artwork](04-products-and-artwork.md) |
| Engineer | `engineer@…` | Products, BOMs, Routings | Make spec current, activate BOM | [Products & artwork](04-products-and-artwork.md) |
| Planner | `planner@…` | Planning board, Material plan, Job cards | Release the demo job card, schedule operations | [Production](06-production.md) |
| Production supervisor | `supervisor@…` | Job cards | Holds, waivers, waste, FG receipt | [Production](06-production.md) |
| Operator | `operator@…` | `/floor` (badge `BADGE-0009` / PIN `0009`) | Queue → start / finish | [Production](06-production.md) |
| Store keeper | `store@…` | Inventory, GRNs, Issues | Post receipts, issue to job cards | [Inventory](07-inventory.md), [Buying](05-buying.md) |
| Store manager | `storemanager@…` | Adjustments, counts | Approve write-offs, post counts | [Inventory](07-inventory.md) |
| Purchase officer | `purchase@…` | RFQs, POs, GRNs | Three quotes, receive | [Buying](05-buying.md) |
| Purchase manager | `purchasemanager@…` | Dashboard (PO queue) | Approve within band | [Buying](05-buying.md) |
| QC inspector | `qc@…` | Quality → Inspections | Record AQL, disposition | [Quality](08-quality.md) |
| Quality manager | `quality@…` | NCRs | CAPA, overdue queue | [Quality](08-quality.md) |
| Lab technician | `lab@…` | Quality → Laboratory | New test report, issue | [Quality](08-quality.md) |
| Compliance officer | `compliance@…` | Compliance & CoC | Certificates, reconciliation | [Quality](08-quality.md) |
| Dispatch officer | `dispatch@…` | Packing lists | Pack → challan → trip | [Dispatch](09-dispatch.md) |
| Driver | `driver@…` | Trips | Start, POD, complete | [Dispatch](09-dispatch.md) |
| Accounts | `accounts@…` | Invoices, Receipts, Bills, Payments | Invoice from challan; allocate cash | [Money](10-money.md) |
| Auditor | `auditor@…` | Dashboard, Reports, Audit log | View only — no export | [Reports](11-reports-and-setup.md) |

Demo password for all desk users: `password`. Change it on **My account**.

---

## One order, many hands

The seeded walkthrough is 50,000 Nordfjell care labels. After a local seed it sits here:

```
Inquiry / quote     (you may create new ones; the seed jumped to SO)
       ↓
Sales order         confirmed  ← merchandiser
       ↓
Job card            draft      ← planner releases
       ↓
Material issue                 ← store
       ↓
Operations                     ← operator on /floor
       ↓
FG receipt + QC                ← supervisor + QC
       ↓
Packing list → challan         ← dispatch
       ↓
Trip + POD                     ← driver (own fleet)
       ↓
Invoice → receipt              ← accounts
       ↓
CoC reconciliation             ← compliance
```

If you are learning the product, sign in as **admin**, open that sales order, and do not skip a box. Every skip is a gate you will hit in production with a real customer waiting.
