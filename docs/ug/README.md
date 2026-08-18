# Octa ERP — User Guide

How to use the system on a working day. The specification in [`docs/`](../README.md) is for implementers; this folder is for the people who sell, plan, weave, inspect, dispatch and invoice.

Octa is a **label and garment-accessory ERP**. You will not find sewing lines or SMV. You will find machines, job cards, lots, artwork approval, and chain-of-custody.

---

## Read this first

1. [Getting started](01-getting-started.md) — sign in, demo accounts, where you land, the seeded walkthrough order
2. [The desk](02-the-desk.md) — sidebar, search, forms, statuses, numbers, notifications

Then the factory sequence:

| # | Chapter | Who it is for |
|---|---|---|
| 3 | [Sales](03-sales.md) | Merchandiser, sales manager |
| 4 | [Products & artwork](04-products-and-artwork.md) | Designer, engineer |
| 5 | [Buying](05-buying.md) | Purchase officer / manager |
| 6 | [Production](06-production.md) | Planner, supervisor, operator |
| 7 | [Inventory](07-inventory.md) | Store keeper / manager |
| 8 | [Quality](08-quality.md) | QC, lab, quality manager, compliance |
| 9 | [Dispatch](09-dispatch.md) | Dispatch officer, driver |
| 10 | [Money](10-money.md) | Accounts |
| 11 | [Reports & setup](11-reports-and-setup.md) | Managers, implementer |
| 12 | [By role](12-by-role.md) | “I just logged in — what do I click?” |

---

## How a document works, everywhere

Every commercial or operational record follows the same pattern:

1. **Create a draft.** It has no number yet.
2. **Fill the lines.** Save with **⌘S** / **Ctrl-S**.
3. **Advance the status** with the buttons on the document (Submit, Confirm, Issue, Post…). The system assigns a number on the first real step and refuses the step if a guard is not met.
4. **Never type a status.** If a button is missing, you either lack permission or the document is not ready. The amber banner on the page usually says why.

If something is blocked, read the banner before opening another screen. The factory’s two hard gates are:

- **Gate 1 — artwork.** A sales order cannot be confirmed, and a job card cannot be released, without an **approved** artwork version.
- **Gate 2 — certified input.** You cannot ship more GRS/FSC claim than the lots you consumed. Compliance will see the gap on the CoC report.

---

## What this guide is not

It is not the schema, the formula sheet, or the permission matrix. Those live in [04-business-rules](../04-business-rules.md), [05-workflows](../05-workflows.md) and [06-rbac](../06-rbac.md). When a screen cites `BR-44` or `S3`, that is the rule ID you can look up there.
