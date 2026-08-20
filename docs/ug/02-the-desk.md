# The desk

The desk is the white application with the left sidebar. Operators on `/floor` can skip this chapter.

## Sidebar

Groups follow the factory, not the org chart. Click a heading to open that group’s first screen. The chevron peeks without leaving the page. Only the group you are in starts open.

| Group | What is in it |
|---|---|
| **Dashboard** | Always visible (no heading) |
| **Sales** | Inquiries, Quotations, Sales orders, Customers, Price lists |
| **Buying** | Requisitions, RFQs, Purchase orders, Goods receipts, Suppliers, Import shipments, Letters of credit |
| **Floor** | Planning board, Job cards, Material plan, Machines |
| **Products** | Products, Artwork, BOMs, Routings, Tools |
| **Inventory** | On-hand, Lots, Material issues, Transfers, Adjustments, Physical counts, Materials |
| **Quality** | Inspections, NCRs, Laboratory, Compliance & CoC |
| **Dispatch** | Packing lists, Delivery notes, Trips |
| **Money** | Invoices, Receipts, Credit notes, Supplier bills, Payments, Expenses |
| **Reports** | All reports, then each operational register |

Every screen is its own row. There are no folders inside folders, and no tab strip under the page title.

**⌘B** / **Ctrl-B** collapses the sidebar to icons.

**Configuration** (footer) is a separate shell: Lists, Settings, number sequences, users, roles, audit log. **Exit configuration** returns to the factory. Admin screens never sit next to job cards.

You only see rows your permissions allow. A missing menu is not a bug.

## Search

**⌘K** / **Ctrl-K**, or **Search** in the sidebar footer.

Type a screen name (`inquiries`, `trips`, `lists`, `users`) or a document number (`SO-`, `LOT-`, `GRN-`). Configuration screens are included. Results are permission-filtered. You cannot jump to a record you may not open.

## Dashboard

**Dashboard.** Two questions:

1. **What is stuck on me?** — the work queue (POs to approve, overdue NCRs, artwork waiting, and so on). Each tile is work you are allowed to do.
2. **How is the factory?** — open / late orders, job cards on the floor, stock value, certificates.

Click a queue tile. Do not hunt six lists for a status column.

## Lists

Every index looks the same:

- **Filter bar** — search box plus status / customer / supplier.
- **Table** — click a row or the number to open the document.
- **Sort** — column headers that allow it.
- **Pagination** — bottom of the table; change rows per page there.
- **Empty state** — a real empty list says so; a filtered list offers **Clear filters**.

Export, when you have permission, is on the page header.

## Forms

The action bar is **docked at the bottom**: Cancel, primary save, unsaved-changes hint.

| | |
|---|---|
| Save | **⌘S** / **Ctrl-S**, or the primary button |
| Leave with unsaved changes | Browser and in-app prompt |
| Validation | Red text under the field; the document is not saved |

Drafts can be edited. After Confirm / Issue / Post, most headers become read-only. Quantity or date changes on a confirmed sales order become **amendments**, not silent edits.

## Statuses

A coloured badge is the document’s state. Buttons next to it are the **legal next steps** for you.

Typical life:

```
draft → (numbered) → in progress → done
                 ↘ cancelled
```

If Confirm is missing on a draft sales order, open the amber **Gate 1** panel — a line is missing a current spec or an approved artwork.

## Document numbers

Numbers are assigned on the **first real transition** (submit, confirm, issue, post), not when the form opens. A draft shows `(draft)` or `(unnumbered)` until then. That is intentional (BR-34): opening a form and walking away does not consume `SO-26-00007`.

## Notifications

The bell in the header. Document events that need you (overdue NCR, credit hold, and similar) land here. Unread count is on the icon. Open the item to go to the document.

## Your account

Initials (top right) → **My account**: password and language (`en` / `bn`). Floor operators default to Bangla; desk users to English.

## Print

Documents that have a print view open a print layout (no sidebar). Use the browser print dialog. Blind count sheets **hide system quantities** on purpose.
