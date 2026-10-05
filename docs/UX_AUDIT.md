# UX audit — Octa ERP

Audit date: 5 October 2026. Scope: the whole web application in this repository (desk shell, shop-floor terminal, administration). Investigation only; no source file was changed.

> **Naming note.** The audit brief called the product "TrimFlow". This repository is Octa ERP (`octa-erp`) and contains no reference to TrimFlow. The audit was run against Octa ERP on the owner's confirmation. The product context in the brief was blank, so users, tasks and journeys below are inferred from `docs/00-overview.md`, `routes/`, `resources/js/navigation.js` and the seeded roles. Assumptions are listed in §2.3.

**How it was done**

- Code: every page under `resources/js/Pages` (160 Vue files), the 34 shared components in `resources/js/Components/Ui`, both layouts, the plugins and composables, and the controllers behind each page.
- Browser: the app was served locally from the existing production build against the local demo database, and about 60 screens were opened at 1280 px and 360 px as the seeded admin and store keeper. Only read actions and form typing were performed; nothing was saved.
- Each finding says how it was checked: **B** = seen in the browser, **C** = read in the code, **C\*** = read in the code and would need a save to prove, which the audit did not do.
- Not tested: 768 px rendering, real Android hardware, screen readers, slow networks, the customer portal beyond its single page.

---

## 1. Executive summary

Octa ERP is in better shape than most systems of its size: one consistent component kit, URL-backed filters, an unsaved-changes guard on full-page forms, reasoned confirmation dialogs on the riskiest approvals, a product setup checklist, and a floor terminal built for touch and offline use. The problems are not general roughness. They are a small number of broken paths and a larger number of places where the system refuses an action without telling the user why.

Nine tasks cannot be completed from the screen today: saving a customer return, saving a sales order typed by hand, applying or refunding a credit note, paying a foreign-currency supplier bill, saving a partly finished stock count, recording a failed delivery, and — on the floor terminal — keeping queued output through an end of shift or an outage longer than four hours. The second theme is silent refusal. Server errors on line items often have no cell to appear in, refused status changes close the dialog and erase the typed reason, and the "Clear filters" button on every empty list throws an error. The third is language: the desk has no translation layer although users can pick বাংলা, the floor terminal is English wherever the guidance is longest, and developer wording and internal rule codes ("BR-29", "The state machine will still apply its own checks") appear on user screens. Fourth, typing a date as `20/10/2026` silently empties the field. Fifth, a new installation or a new user gets no guidance: no setup checklist on the dashboard, no password reset, no help entry.

Fixing the nine broken paths and the dead "Clear filters" button is mostly small work and should come before anything else.

---

## 2. App map

### 2.1 Stack

| Layer | What is used |
|---|---|
| Server | Laravel 13, PHP 8.3+, MySQL; modules under `app/Modules/*` |
| Client | Inertia 3 + Vue 3.5 (`<script setup>`), one page component per screen, lazy-loaded |
| Styling | Tailwind 4 with theme tokens in `resources/css/app.css`; no component library; own kit in `resources/js/Components/Ui` |
| Routing | Server routes in `routes/web.php`, `floor.php`, `portal.php`, `api.php` (353 routes). Links are literal paths. Ziggy is installed but unused |
| State | Inertia props; filters, sort and paging in the URL; small module-level singletons for toasts, confirm and overlays. Pinia is installed but has no stores |
| Forms | Inertia `useForm`; `ResourceForm` → `FormLayout` → `FormPage` with `FormFooter` for save, dirty guard and Ctrl+S |
| i18n | None. No `lang/` directory, no translation helper in JS. `users.locale` (`en`/`bn`) is stored but nothing reads it on the desk. Floor pages hard-code Bangla · English pairs |
| Formatting | `resources/js/plugins/formatting.js`, configured from the organisation profile (number locale, date format, base currency) |
| Build | Vite 8, per-page chunks; largest JS chunk 191 KB (vendor), CSS 90 KB; fonts self-hosted |

### 2.2 Screens

Desk shell (sidebar groups from `resources/js/navigation.js`):

| Group | Screens |
|---|---|
| Overview | Dashboard |
| Sales | Inquiries, Quotations, Sales orders, Customers, Price lists |
| Buying | Requisitions, RFQs (+ comparison), Purchase orders, Goods receipts, Suppliers, Import shipments, Letters of credit |
| Production | Planning board, Job cards, Material plan (MRP), Machines, link to Shop floor terminal |
| Products | Products (with specs), Artwork, BOMs, Routings, Tools |
| Inventory | On-hand, Lots, Material issues, Transfers, Adjustments, Physical counts, Materials |
| Quality | Inspections, NCRs, Laboratory, Compliance & CoC (+ reconciliation) |
| Dispatch | Packing lists, Delivery notes, Trips |
| Money | Invoices, Receipts, Credit notes, Customer returns, Supplier bills, Payments, Expenses |
| Reports | Index and eight reports |
| Configuration (separate shell) | Lists (reference data), Settings, Number sequences, Users, Roles & permissions, Audit log |
| Other | Login, Profile, Error page, Customer portal (one placeholder page) |

Shop-floor terminal (`/floor`, own layout, service worker and offline queue): badge login, work queue, operation screen.

Most documents follow Index → Form (create/edit) → Show. Exceptions: Receipts and Payments create in a modal on the list and have no detail page; Expenses has a form page but no detail page; Tools is a read-only list; BOMs are created from the product page only.

### 2.3 Core journeys (inferred)

1. **Quote to order** — Inquiry → Quotation (priced by cost sheet) → Sales order.
2. **Product setup** — Product → Specification → Artwork approval → BOM → Routing.
3. **Buy to pay** — Requisition → RFQ → Purchase order → Goods receipt → Supplier bill → Payment.
4. **Store work** — Goods receipt → Lot → Material issue / Transfer / Count / Adjustment.
5. **Make** — Sales order → Job card → Planning board → Release → Operator books output on the floor terminal → Inspection → NCR.
6. **Ship and collect** — Packing list → Delivery note → Trip → Proof of delivery → Invoice → Receipt → Credit note / Return.
7. **First run** — First login, system setup, adding users.

Assumptions: desktop is the main device for desk roles; the floor terminal runs on shared Android touch devices in Bangla with unreliable wifi (stated in the code comments and `docs/00-overview.md`); store keepers and operators have the lowest technical comfort; the people who use the desk mostly work on Windows.

---

## 3. Findings

Severity: **Critical** = a core task cannot be completed, or data is lost. **High** = frequent confusion or significant delay. **Medium** = annoyance or inconsistency. **Low** = polish. Effort: S ≈ under a day, M ≈ days, L ≈ a week or more. Paths are relative to the repository root; `Pages/…` means `resources/js/Pages/…`, `Ui/…` means `resources/js/Components/Ui/…`.

### 3.1 Critical

| ID | Area | Issue | Where | User impact | Sev | Effort | Recommended fix | Check | Status |
|---|---|---|---|---|---|---|---|---|---|
| C-01 | Forms | Customer return: "Save draft" is a `type="submit"` button rendered outside the `<form>` (the `#footer` slot sits after `</form>`), with no click handler | `Pages/Sales/SalesReturns/Form.vue:213-223`; `Ui/FormLayout.vue:27-41` | A return cannot be saved | Critical | S | Use `FormFooter` with `@save="submit"` like every other form | B | Done (09ef498) |
| C-02 | Forms | Customer return: choosing an invoice reloads the page with only `invoice` and `lines`; the warehouse and invoice lists come back empty and the typed reason and date are wiped. `?invoice=` preselect never loads lines | `app/Modules/Sales/Http/Controllers/SalesReturnController.php:86-92`; `Pages/Sales/SalesReturns/Form.vue:15-42,105-111` | Even with C-01 fixed, "Back into" has no options and the save fails | Critical | S | Return the option lists from `lines()` or use a partial reload that keeps state; load lines on mount when preselected | C | Done (09ef498) |
| C-03 | Forms | Sales order form never sets `product_spec_id` (`''`), which the server requires | `Pages/Sales/SalesOrders/Form.vue:36`; `app/Modules/Sales/Http/Controllers/SalesOrderController.php:483` | An order typed by hand cannot be saved; only orders converted from a quotation work. The error has no cell and shows only as a toast | Critical | S | Set it from the product's current spec on product change (or default it on the server); block products with no current spec and link to them | C\* | Done (4c7ea07) |
| C-04 | Finance | Credit note page declares a prop named `money` and imports the `money()` formatter; the import wins, so `money.available` is undefined | `Pages/Finance/CreditNotes/Show.vue:13,19,139-142` | "Apply credit" and "Refund" never appear; the "This credit" card shows blank figures | Critical | S | Rename the prop (controller `CreditNoteController.php:135` and page) | C\* | Done (e382657) |
| C-05 | Finance | Payment modal posts a hard-coded `exchange_rate: 1` with no field for it; the server rejects a rate more than 5% from the reference, under a key the modal never renders | `Pages/Finance/Payments/Index.vue:51`; `app/Modules/Finance/Http/Controllers/PaymentController.php:111-115`; `app/Support/Currency/ExchangeRateResolver.php:136-146` | A foreign-currency supplier bill cannot be paid, and nothing visible explains why | Critical | S | Drop the field from the payload so the server uses the reference rate (Receipts already does), or show a prefilled rate | C\* | Done (dc5f3cf) |
| C-06 | Inventory | Physical count: saving requires a counted quantity on every line; uncounted lines are sent as `''` | `app/Modules/Inventory/Http/Controllers/PhysicalCountController.php:174`; `Pages/Inventory/Counts/Form.vue:29,83-99` | A count must be keyed in one sitting while every lot in the warehouse stays blocked; a closed tab loses it all | Critical | M | Make the quantity nullable on save (reconcile already rejects nulls), send only changed lines, show "n of N counted" | C\* | Done (2b3cb02) |
| C-07 | Floor | Offline queue silently drops entries older than four hours (`continue`), without filing them as rejected or telling anyone | `resources/js/Composables/useOfflineQueue.js:83-88` | A long outage or a kiosk left overnight loses output with no trace | Critical | S | Move expired entries to the rejected list with reason "expired" and show them to a supervisor for re-keying | C | Done (ba255c0) |
| C-08 | Floor | END SHIFT clears the device session and cache before the sign-out request, with no check for unsent records | `Pages/Floor/Queue.vue:89-95` | Ending a shift during an outage discards everything queued | Critical | M | Block END SHIFT while records are unsent ("3 records not sent yet"); clear local state only after sign-out succeeds | C | Done (745feca) |
| C-09 | Dispatch | Proof of delivery: typing a failure reason disables "Received by" ("Not needed"), but the server requires it | `Pages/Dispatch/Trips/Show.vue:113-122`; `app/Modules/Dispatch/Http/Controllers/TripController.php:218` | A failed drop cannot be recorded without typing a fake receiver | Critical | S | `required_without:failure_reason` on the server; split the dialog into "Delivered" and "Could not deliver" | C\* | Done (2b9adcd) |

### 3.2 High

| ID | Area | Issue | Where | User impact | Sev | Effort | Recommended fix | Check | Status |
|---|---|---|---|---|---|---|---|---|---|
| H-01 | Tables | "Clear filters" on the filtered-empty state uses `window` in a template, which Vue does not expose; the click throws. 36 list pages | e.g. `Pages/Manufacturing/JobCards/Index.vue:90`, `Pages/Sales/Inquiries/Index.vue:67` | The only button on "Nothing matches these filters" is dead (the chip row's "Clear all" still works) | High | S | Let `EmptyState` clear filters itself from `usePage().url` | B | Done (57152aa) |
| H-02 | Forms | `DateInput` accepts only `YYYY-MM-DD` when typed; anything else empties the field on blur with no message. The closed field shows "05 Oct 2026" | `Ui/DateInput.vue:22,148-167` | Typing `20/10/2026`, the local convention, wipes the value | High | M | Parse `dd/mm/yyyy`, `dd-mm-yy` and the display format; keep the old value and show an error when unparseable | B | Done (f522348) |
| H-03 | Errors | Line-item errors often have nowhere to show. `LineItemsTable` maps errors by column key, so fields inside a combined column are lost (GRN lot/cert fields, routing operations, order tolerance, unit of measure). Hand-rolled line tables render none (material issue, BOM, spec colours) | `Ui/LineItemsTable.vue:39-43`; `Pages/Procurement/Grns/Form.vue:153-160,295-323`; `Pages/Inventory/Issues/Form.vue:270-311,453-529`; `Pages/Product/Routings/Form.vue:78-86`; `Pages/Product/Boms/Form.vue:156-205`; `Pages/Product/Specs/Form.vue:278-283` | The user presses Save, gets a toast saying "and 3 more fields to fix", and cannot tell which cell | High | M | Add `errorKeys`/`errorPrefix` to `LineItemsTable`; move the hand-rolled tables onto it; add an error summary to `FormFooter` | C | Done (7329d1a) |
| H-04 | Errors | Refused status changes are returned as a success with a flash error, so the modal's `onSuccess` closes it and resets the typed reason | `Pages/Manufacturing/JobCards/Show.vue:340-347,455-473`; `app/Modules/Manufacturing/Http/Controllers/JobCardController.php:563-567`; `Pages/Dispatch/Challans/Show.vue:29-34` | A supervisor writes a waiver or override reason, is refused, and finds the dialog closed and the text gone | High | M | Return refusals as validation errors, or keep the modal open when `flash.error` is set and show the message inside it | C | Done (41c195d) |
| H-05 | Errors | Session expiry (419) on a form save replaces the page with the error screen | `bootstrap/app.php:73-97`; `resources/js/app.js:25-33` | Back from a break, pressing Save loses the form | High | M | Catch 419 on Inertia requests on the client: show "session expired — sign in in another tab, then save again", or refresh the token and retry | C | Done (45c742a) |
| H-06 | Data loss | Forms inside `Modal`/`SlideOver` close on a backdrop click or Escape with no dirty check (24 pages combine them with `useForm`) | `Ui/Modal.vue:11,24,69`; `Ui/SlideOver.vue:30,61`; e.g. `Pages/Admin/Users.vue:171`, `Pages/Admin/Roles.vue:231` | A half-typed user or role matrix vanishes on a stray click | High | S | Give overlays a `dirty` prop and confirm before closing; default form overlays to not close on backdrop | C | Done (b9c453a) |
| H-07 | Sales | Exchange rate defaults to 1 and is never filled from the reference table when the currency or customer changes; the server rejects it on save. Same on purchase orders, bills and shipments | `Pages/Sales/Quotations/Form.vue:76-81,336-344`; `Pages/Sales/SalesOrders/Form.vue:53-54`; `Pages/Procurement/PurchaseOrders/Form.vue:64,239-241`; `Pages/Procurement/Bills/Form.vue:32,90` | Every foreign-currency document shows wrong figures while typing and fails once on save | High | M | Send the reference rate with each currency, fill it on change, hide the field for the base currency | C | Done (5bf728d) |
| H-08 | Sales | A quotation cannot be saved until every line is priced; pricing failures ("no current spec", "priced at zero") give no link to the product | `Pages/Sales/Quotations/Form.vue:214-242,371-373,582` | The merchandiser must abandon the quotation, fix the product elsewhere, and retype | High | M | Allow saving a draft with unpriced lines (block only sending); link each failure to the product in a new tab | B (disabled state), C | Done (53423fe): product and price optional on a draft; quantity stays required (database check constraint) |
| H-09 | Sales | Inquiry lines hold `product_id` in state and the server accepts it, but the form has no product column | `Pages/Sales/Inquiries/Form.vue:37-39,80-86`; `Pages/Sales/Quotations/Form.vue:299-303` | Every quotation line prefilled from an inquiry needs the product picked again | High | S | Add an optional product picker to inquiry lines | C | Done (9cc2f5b) |
| H-10 | Sales | Quotation live pricing re-prices all lines on every keystroke with no debounce and no stale-response guard | `Pages/Sales/Quotations/Form.vue:108-161,181-185` | Flicker, and a slow earlier response can overwrite a newer rate | High | S | Debounce, price only the changed line, ignore out-of-date responses | C | Done (53423fe) |
| H-11 | Sales | Detail pages drop entered data: quotation terms, tooling charge and lead time; order notes and priority | `Pages/Sales/Quotations/Show.vue:308-315`; `Pages/Sales/Quotations/Form.vue:45-46`; `Pages/Sales/SalesOrders/Form.vue:194-196,317-325` | Text typed for the planner or customer is visible only by reopening Edit | High | S | Show them on the detail pages; add the missing inputs or remove the state | C | Done (b58d32d, 53423fe) |
| H-12 | Product | Spec form: colour name is required by the server but unmarked, the first row starts empty, and its error is not shown | `Pages/Product/Specs/Form.vue:41-43,265,278-294` | First spec save fails on a field that does not look required | High | S | Mark it required, show row errors, say when weights do not total 100% | C | Open |
| H-13 | Product | Tools is a read-only list with no create, edit or retire | `Pages/Product/Tools/Index.vue:33-61`; `routes/web.php:206-207` | Plates, screens and dies cannot be registered in the UI | High | M | Add create/edit/retire, or state in the empty state where tools come from | C | Open |
| H-14 | Product | Artwork upload has no size/format hint, no progress, no inline error; a wrong draft version cannot be withdrawn | `Pages/Product/Artworks/Show.vue:93,114-119,207-217` | Large uploads look frozen; mistakes stay forever | High | M | Show limits (50 MB) and progress, render `errors.file`, allow withdrawing a draft | C | Open |
| H-15 | Product | Routing form: two unlabelled stacked inputs per cell, same aria-label, no unit on rate, no reorder | `Pages/Product/Routings/Form.vue:28-29,81,94,145-165` | Minutes and metres get swapped; inserting a step means retyping rows | High | M | Separate labelled columns, up/down controls, unit on rate | C | Open |
| H-16 | Sales | Price list: no control to reactivate after "Deactivate" | `Pages/Sales/PriceLists/Form.vue:36`; `Pages/Sales/PriceLists/Index.vue:22-35` | A list deactivated by mistake must be recreated | High | S | Add an Active checkbox and a "Reactivate" action | C | Open |
| H-17 | Buying | Item pickers search and show the code only (`label-key="code"`, no `hint-key`); the RFQ form does it properly | `Pages/Procurement/Requisitions/Form.vue:131-138`; `Pages/Procurement/PurchaseOrders/Form.vue:266-273`; `Pages/Procurement/Grns/Form.vue:255-262`; `Pages/Inventory/Issues/Form.vue:340-346` | A store keeper who knows "black polyester yarn" but not its code cannot find it | High | S | Add `hint-key="name"` | C | Done (90efb0a) |
| H-18 | Buying | Goods receipt posts stock in one click: no confirm, no draft, no reversal on the detail page | `Pages/Procurement/Grns/Form.vue:381-386`; `app/Modules/Procurement/Http/Controllers/GrnController.php:254-377` | A wrong quantity or rate can only be corrected by a stock adjustment | High | M | Confirm with a summary of lines and value; ideally Save draft + Post | C | Partly done (f6d6c0d): confirm with lines, value, warehouse; draft + post is Phase 2 |
| H-19 | Buying | Choosing a purchase order inside the GRN form does not load its lines (only the `?po=` link does); the summary rail never renders because its slot is nested in the wrong component | `Pages/Procurement/Grns/Form.vue:82,184-191,325-356` | Starting from "New GRN" means retyping every line with no outstanding-quantity hint | High | M | Reload lines on PO change; move the rail to `FormLayout` | C | Done (b23bb57) |
| H-20 | Buying | RFQ: selecting a winner above the three-quote threshold fails silently; the error key is not rendered and the comparison page has no reason field | `Pages/Procurement/Rfqs/Show.vue:60-70,151-162`; `Pages/Procurement/Rfqs/Compare.vue:21-23` | "Select" appears to do nothing | High | S | Open a reason dialog when required and show the error | C | Open |
| H-21 | Buying | Supplier bill: a draft cannot be edited; hand-typed lines have no item so they are never matched | `routes/web.php:605-614`; `Pages/Procurement/Bills/Form.vue:22,109-124` | One typo means a new bill | High | M | Add edit; add an item picker to manual lines | C | Open |
| H-22 | Buying | PO approval figures are base-currency values printed with the order's currency label | `Pages/Procurement/PurchaseOrders/Show.vue:152,156,245-246` | A USD 5,000 order reads "USD 612,500.00" | High | S | Label with the base currency and show the order-currency total beside it | C | Done (b101edf) |
| H-23 | Inventory | Material issue: changing the warehouse silently clears all lines, although the page says a job card usually draws from several stores | `Pages/Inventory/Issues/Form.vue:226-239,332-333` | Picked lots vanish without warning | High | M | Keep the lines, or confirm first and state "one issue per store" | C | Done (979eab7): asks before clearing and explains one store per issue; multi-store issues would need a data-model change |
| H-24 | Inventory | Transfer and adjustment lot pickers are silently capped at the first 400 lots | `app/Modules/Inventory/Http/Controllers/StockTransferController.php:368-394`; `StockAdjustmentController.php:322` | Beyond 400 lots, a lot is simply not in the list | High | M | Server-side search scoped to the chosen warehouse | C | Done (8f54e91) |
| H-25 | Inventory | Lots are described as barcoded, but there is no label print and no scan field anywhere | `Pages/Procurement/Grns/Form.vue:250,372`; `app/Support/Print/DocumentRegistry.php:45-187` | Every issue, transfer and count is a manual dropdown pick | High | L | Lot-label print; a scan field that adds a lot line | C | Open |
| H-26 | Inventory | Physical count has no way back from "Reconciled" | `app/Modules/Inventory/States/PhysicalCountStateMachine.php:38-40`; `Pages/Inventory/Counts/Show.vue:96-119` | A mis-keyed line found at reconciliation can only be posted as a wrong variance | High | M | Add "Recount" | C | Done (7217918) |
| H-27 | Finance | Receipts and payments allocate to one invoice or bill per entry, have no detail page, and cannot be voided or bounced | `Pages/Finance/Receipts/Index.vue:56,103-104,157-182`; `Pages/Finance/Payments/Index.vue:54,100-101` | One cheque covering five invoices is five entries; a mistake cannot be corrected | High | L | Multi-row allocation with "oldest first"; add void/bounce | C | Open |
| H-28 | Confirm | The shared status-change confirm is generic and developer-worded: "Move to qc pending JC-…?", "The state machine will still apply its own checks.", button "Continue". Posting stock is not marked destructive; "Start counting" does not say it blocks the warehouse | `resources/js/composables/useTransitionConfirm.js:12-55` | The dialog does not say what will happen | High | S | Per-call title, consequence sentence and button label | C | Done (0c4e0c8) |
| H-29 | Confirm | Irreversible actions with no confirm and no busy state: delivery-note Cancel / Mark delivered / Create invoice; floor FINISH; trade LC and shipment Cancel / Close / Remove; bulk Approve / Submit; NCR Close; packing carton removal; "Make current" spec; BOM "Activate" | `Pages/Dispatch/Challans/Show.vue:25-26,77-88`; `Pages/Floor/Operation.vue:195`; `Pages/Trade/LettersOfCredit/Show.vue:71-79`; `Pages/Trade/Shipments/Show.vue:84-114`; `Pages/Procurement/PurchaseOrders/Index.vue:36-48`; `Pages/Quality/Ncrs/Show.vue:107-132`; `Pages/Dispatch/PackingLists/Show.vue:389-405`; `Pages/Product/Products/Show.vue:128-134,357-359` | One slip cancels, closes or posts; a double-click may create duplicates | High | M | Route through the existing confirm dialog with a consequence line; disable while in flight | C | Done (3029f40, ebee4fa) |
| H-30 | Errors | Refusals sent with `abort(422, …)` are not in the handled status list, so the message is likely lost (not reproduced) | `bootstrap/app.php:73`; `app/Modules/Finance/Http/Controllers/ExpenseController.php:146`; `app/Modules/Trade/Http/Controllers/LetterOfCreditController.php:216-249` | A refused approval shows a raw response instead of a reason | High | S | Return a flash or validation error | C | Done (a02d4c8) |
| H-31 | Floor | SAVE, START and FINISH have no in-flight lock and a new idempotency key is minted per send | `Pages/Floor/Operation.vue:61-83,239-245`; `resources/js/Composables/useOfflineQueue.js:108-113` | A double tap books output twice | High | S | Disable while sending; create the key when the form opens | C | Done (34a0449) |
| H-32 | Floor | Queued and sent look the same ("Logged"); totals do not change when offline | `Pages/Floor/Operation.vue:55-66,112-116` | The operator sees "Logged", sees no change, and books it again | High | S | A distinct "saved on this device" state; add queued figures to the tiles | C | Done (34a0449) |
| H-33 | Floor | Rejected records are a bare count that never clears, shown on one screen only, with no list of what or why | `Pages/Floor/Operation.vue:156-158`; `resources/js/Composables/useOfflineQueue.js:37-41` | The supervisor cannot tell which job, quantity or reason | High | M | A "Not sent" screen listing each record with re-key and dismiss | C | Open |
| H-34 | Floor | Server refusals are English with rule codes ("J3: output … exceeds …"); long guidance on login and queue is English only; one line is Bangla only | `app/Modules/Manufacturing/Services/OperationBookingService.php:94-334`; `Pages/Floor/Login.vue:42-139`; `Pages/Floor/Queue.vue:114,149-153` | Bangla-first operators cannot act on the message that explains why a button did nothing | High | M | Stable error codes rendered as short bilingual sentences; one floor dictionary | B (login), C | Open |
| H-35 | Floor | Quantities on the terminal carry no unit, while the desk page adds one to every figure | `Pages/Floor/Operation.vue:160-177,200-210` | Metres and pieces are entered in identical boxes | High | S | Send and print the operation's unit | C | Done (34a0449) |
| H-36 | Dispatch | Marking a stop failed flashes "Stop delivered."; the dialog does not say that failing returns the goods to stock | `app/Modules/Dispatch/Http/Controllers/TripController.php:222-268` | A green "delivered" toast for a failed drop | High | S | Correct the flash; state the consequence | C | Done (2b9adcd) |
| H-37 | Dispatch | Delivery mode is hard-coded to own fleet; start odometer has no input | `Pages/Dispatch/PackingLists/Show.vue:250-254`; `Pages/Dispatch/Trips/Form.vue:25-26` | Courier and pickup deliveries are mislabelled | High | S | Ask the mode when creating the delivery note | C | Open |
| H-38 | Quality | Lab form requires a value for every catalogue test and shows no row errors; thresholds stay at the house default after a customer is chosen | `Pages/Quality/Lab/Form.vue:26-34,86-99`; `app/Modules/Quality/Http/Controllers/LabController.php:102-104` | A technician who ran 3 of 9 tests presses Save and nothing visible happens | High | M | Send only filled rows, show errors, load the applicable threshold | C | Open |
| H-39 | Language | The desk has no translation layer, yet Profile and Users offer "English / বাংলা" | `Pages/Profile/Edit.vue:64-72`; `Pages/Admin/Users.vue:219-224` | Choosing বাংলা changes nothing | High | L | Remove the selector from desk screens until translations exist, or add a translation layer starting with the kit strings | C | Decided (bfab0be): desk stays English; selector removed. No translation layer planned |
| H-40 | Language | Changing language sits in the change-password form; the dedicated route is never called | `Pages/Profile/Edit.vue:13-21`; `routes/web.php:93` | Language cannot be changed without changing the password | High | S | Give language its own card posting to `/profile/locale` | C | Done (bfab0be): selector removed per decision 2 |
| H-41 | Reports | Reports have no export and no print | `Pages/Reports/Show.vue:130-197`; `routes/web.php:105-110` | Ageing and stock reports are retyped or screenshotted | High | M | Reuse `ExportDialog`; add Print | C | Open |
| H-42 | Import | Choosing a file imports it at once: no preview, no confirmation | `Ui/ImportDialog.vue:54-67,122` | A wrong spreadsheet updates up to 1,000 master records with no undo | High | M | Validate first, show "N created, M updated, K skipped", then an explicit Import button | C | Open |
| H-43 | First run | No password reset and no guidance on the login page | `routes/web.php:80-81`; `Pages/Auth/Login.vue:35-67` | A locked-out user has no path | High | M | "Forgot password? Ask your administrator" with a contact, or a reset flow | B | Done (e03be6c): ask-administrator help per decision 10 |
| H-44 | First run | A new installation shows a dashboard of zero tiles with no setup path; "Needs you" is hidden when empty; the shared seed password is flagged only on Profile | `Pages/Dashboard.vue:84,118-133`; `Pages/Setup/Index.vue:85-111` | A new admin does not know where to start | High | M | A first-run checklist card driven by record counts; a banner while the seed password is in use | C | Done (45c742a) |
| H-45 | Feedback | Error toasts disappear after 10 seconds, and several errors collapse to "and N more fields to fix" | `resources/js/composables/useToasts.js:27`; `resources/js/app.js:30-32` | For forms without inline errors (see H-03) the only explanation is gone | High | S | Keep errors until dismissed; list every message | C | Done (c4211a8) |
| H-46 | Help | Rule tooltips are clipped by the card they sit in | `Ui/RuleHint.vue:38`; `Ui/Card.vue:18` | The plain-language explanation of a rule is cut off at the top; used on 67 cards | High | M | Render the tooltip in `body` with fixed positioning, as `SelectInput` does | B | Done (d862de2) |
| H-47 | Accessibility | `Modal`, `SlideOver` and the command palette do not move, trap or return focus and have no accessible name; `Modal` has no close button | `Ui/Modal.vue:73-78`; `Ui/SlideOver.vue:71-76`; `Ui/CommandPalette.vue:113-125,194-207` | Keyboard users tab into the page behind the dialog (WCAG 2.4.3, 4.1.2) | High | M | Reuse the focus logic `ConfirmDialog` already has | C | Open |
| H-48 | Accessibility | `SelectInput`: no `aria-activedescendant`, unlabelled search box, clear row unreachable by arrows, Tab closes the list while focus is inside it | `Ui/SelectInput.vue:144-149,184-186,215-268` | The only select in the system is hard to use without a mouse | High | M | Refocus the trigger before closing; add ids and roles | C | Open |
| H-49 | Accessibility | `text-ink-400` is 3.4:1 on white and carries real content (line-item headers, placeholders, hints): 136 uses in 64 files, 30 of them at 10–11 px | `resources/css/app.css:41,107`; `Ui/LineItemsTable.vue:80,86`; `Ui/FilterBar.vue:138` | Small grey text is hard to read on cheap panels (WCAG 1.4.3) | High | M | Darken the token to about today's 500; set a 12 px floor for content | C | Open |
| H-50 | Data | A database seeded before customer returns and refunds were added has no number sequence for them. Approving a return then fails with the 500 page (`No number sequence for document type [sales_return]`). Found on the local demo database while verifying C-04; the seeder defines both (`database/seeders/ReferenceDataSeeder.php:557-559`) but nothing adds them to an existing installation | `app/Support/Numbering/NumberAllocator.php:47`; `database/seeders/ReferenceDataSeeder.php:557-559` | Returns cannot be approved and refunds cannot be raised on any installation older than those features | High | S | A data migration that inserts any sequence the seeder defines and the database lacks, for the current year | B | Done (b62a1f6): data migration approved 5 Oct 2026 |

### 3.3 Medium

| ID | Area | Issue | Where | User impact | Sev | Effort | Recommended fix | Check | Status |
|---|---|---|---|---|---|---|---|---|---|
| M-01 | Dashboard | At 1280 px the eight tiles truncate their labels ("Job cards o…" and "Job cards n…" look alike), "BDT 431,150.67" overflows its tile, and the order book clips the Promised and Status columns | `Pages/Dashboard.vue:118-131,135-146` | The first screen is hard to read on the most common laptop width | Medium | S | Four tiles per row below 1536 px; wrap labels; let the table scroll or drop a column | B | Done (330c18e) |
| M-02 | Dashboard | Every role sees the same tiles; a store keeper gets Quotations out and Artwork awaiting approval, and Receivables/Payables in the Reports menu | `Pages/Dashboard.vue:25-38`; `resources/js/navigation.js:135-146` | The landing page is not about the user's own work | Medium | M | Filter tiles and report links by permission; lead with the role's own queue | B | Done (45c742a) |
| M-03 | Microcopy | Developer and specification wording on user screens. Examples: "numbered on submit, never on form open"; "Snapshotted on send; a reprint never re-reads it"; "Written by a model observer, not a trigger"; "a `grn_receipt` ledger row"; "This is a bug in a posting path"; "the database refuses this row, so it cannot exist"; "the two gates, live" | `Pages/Sales/Inquiries/Index.vue:39`; `Pages/Sales/Quotations/Form.vue` (exchange-rate hint); `Pages/Admin/AuditLog.vue:27`; `Pages/Procurement/Grns/Form.vue:373`; `Pages/Inventory/Stock/Index.vue:60-61`; `Pages/Quality/Inspections/Show.vue:56-58`; `Pages/Dashboard.vue:77` | Users read implementation notes where they need "what is this screen for" | Medium | M | Rewrite subtitles and hints in task language | B, C | Open |
| M-04 | Microcopy | Internal rule codes in visible text and flashes ("BR-29", "Gate 1 · A2", "P0-3:", "J5 ceiling", "S2:", "QL-5 —"); several `rule` props are not in the dictionary, so the tooltip says "Enforced by an internal rule." | `Pages/Manufacturing/JobCards/Show.vue:677,758,1134`; `Pages/Quality/Lab/Form.vue:55`; `Pages/Dispatch/Trips/Form.vue:54`; `resources/js/plugins/rules.js:73-79`; `app/Modules/Sales/Http/Controllers/SalesOrderController.php:278,342` | Codes read as errors | Medium | S | Keep codes inside the tooltip only; add the missing dictionary entries | B, C | Open |
| M-05 | Microcopy | Flash messages and audit lists print raw status keys ("moved to pending_approval", "draft → in_transit"); permission errors print raw keys ("[job_card.waive_material]") | `app/Modules/Procurement/Http/Controllers/PurchaseOrderController.php:257`; `app/Support/States/TransitionDenied.php:17,22`; `Pages/Inventory/Transfers/Show.vue:141-155` | Developer identifiers in everyday messages | Medium | S | Title-case statuses; map permissions to readable names and who holds them | C | Open |
| M-06 | Terminology | One thing, several names: Delivery note / challan; Goods receipt / GRN / Receive goods / Receipt (which also means customer money); Materials / Items; Material plan / MRP; Supplier bill / Supplier invoice; "Rate /M", "Rate / 1,000", "Quoted rate / M" | `resources/js/navigation.js:58,100,116`; `Pages/Dispatch/Challans/Index.vue:31-65`; `Pages/Procurement/Grns/Index.vue:34`; `Pages/MasterData/Items/Index.vue:58-91`; `Pages/Planning/Mrp.vue:57`; `Pages/Sales/Quotations/Form.vue:261` | Search and training confusion; "/M" reads as "per metre" in a ribbon factory | Medium | S | One term per thing, with the other in brackets on first use; "Rate per 1,000 pcs" | B, C | Open |
| M-07 | Terminology | Abbreviations never expanded where used: AQL, DHU, CAPA, NCR, CoC, RFQ, LC, BL/AWB, ETD/ETA, FIFO, MOQ, UoM, "WH", "eff"; LC kinds render as "Tt", "Da", "Dp" | `Pages/Quality/Inspections/Index.vue:23`; `Pages/Inventory/Stock/Index.vue:22`; `Pages/Trade/LettersOfCredit/Index.vue:73,91`; `Pages/MasterData/Items/Form.vue:25-47` | New staff guess meanings and units | Medium | S | Spell out in headers or add a tooltip; units in labels | C | Open |
| M-08 | Forms | Save buttons disabled without a stated reason, though `FormFooter` supports one; modals disable silently on hidden rules (reason shorter than 5 characters) | `Pages/Inventory/Issues/Form.vue:574`; `Pages/Sales/PriceLists/Form.vue:202-208`; `Pages/Manufacturing/JobCards/Show.vue:1336-1343`; `Pages/Quality/Inspections/Form.vue:349-355` | A dead button with no explanation | Medium | S | Pass `disabled-reason`; add a hint line in modals | C | Done (22d74bd) |
| M-09 | Forms | The save shortcut hint always reads "⌘S", while the sidebar correctly shows "Ctrl K" | `Ui/FormFooter.vue:107` | A meaningless glyph on every form for Windows users | Medium | S | Show "Ctrl S" unless on a Mac | B | Done (400e095) |
| M-10 | Forms | Line forms start with a blank row and post every row; an empty extra row fails the save | `Pages/Sales/Inquiries/Form.vue:60-61,74-78`; `Pages/Sales/SalesOrders/Form.vue:94,114-118` | "line 3 description is required" for a row the user never touched | Medium | S | Strip empty rows before submit | C | Done (53423fe, 9cc2f5b, dbb3bb9): quotation, inquiry and order forms; price list form still open |
| M-11 | Forms | Changing the customer leaves the previous customer's products on the lines | `Pages/Sales/Quotations/Form.vue:96-100`; `Pages/Sales/SalesOrders/Form.vue:63-67` | A document can be saved with another customer's product | Medium | S | Flag or clear mismatched lines | C | Done (53423fe, 9cc2f5b, dbb3bb9): quotation, inquiry and order forms; price list form still open |
| M-12 | Forms | Nine date fields use the native picker instead of `DateInput` | `Pages/Sales/SalesReturns/Form.vue:114`; `Pages/Inventory/Transfers/Form.vue:147`; `Pages/Inventory/Counts/Form.vue:75`; `Pages/Trade/LettersOfCredit/Show.vue:294-316`; `Pages/Manufacturing/JobCards/Show.vue:1381` | Two date pickers and formats in one app | Medium | S | Swap to `DateInput` (after H-02) | C | Partly done (471c968): 8 of 9 swapped; the job card date-and-time field stays native because the kit has no time picker |
| M-13 | Forms | The calendar ignores the "Week starts on" setting (always Monday), and other-month days are not dimmed because `text-ink-300` is not defined | `Ui/DateInput.vue:57,86-89,296`; `resources/css/app.css:41-46` | The picker's week differs from the wall calendar; 28–30 September look like October | Medium | S | Pass the setting through; define the token | B | Done (f522348) |
| M-14 | Forms | The unsaved-changes guard is a native browser confirm | `Ui/FormFooter.vue:72` | Out of style with every other dialog and untranslatable | Medium | S | Use the app's confirm dialog | B | Done (400e095) |
| M-15 | Forms | Quotation header puts five fields in one row at 1280 px; the date shows "05 Oct 202" and the customer "— select…" | `Pages/Sales/Quotations/Form.vue` (header card) | Values are cut off on the standard laptop width | Medium | S | Three columns below 1536 px | B | Done (53423fe) |
| M-16 | Forms | New job card: the order-line list is a short scroll box with no search; a product lacking approved artwork or routing is refused only after submit. "3,150 can be planned of 3,000 ordered" is unexplained | `Pages/Manufacturing/JobCards/Form.vue:172-184`; `app/Modules/Manufacturing/Http/Controllers/JobCardController.php:222-232` | The planner picks, submits, and is refused for something known at page load | Medium | M | Add search; flag unready lines with a link; say "includes 5% over-run allowance" | B, C | Open |
| M-17 | Forms | Inventory adjustment uses one signed quantity field with no resulting-balance preview | `Pages/Inventory/Adjustments/Form.vue:136-138,162` | Sign mistakes on a stock posting | Medium | M | In/Out toggle with a positive quantity and "balance after" | C | Done (bf4e81d) |
| M-18 | Forms | Count entry has no search, filter, progress or ordering | `Pages/Inventory/Counts/Form.vue:80-100` | 300 lots in one unstructured stack | Medium | M | Filter box, "uncounted only", sticky progress | C | Done (2b3cb02, 7217918) |
| M-19 | Forms | Machine and product each have three ways to switch a record off (status, Active checkbox, row action) | `Pages/MasterData/Machines/Form.vue:36-43`; `Pages/Product/Products/Form.vue:32-33` | "Available but inactive" combinations | Medium | M | Derive Active from status | C | Open |
| M-20 | Forms | Product form: brand list is not filtered by customer; the customer is described as permanent but editable | `Pages/Product/Products/Form.vue:18-19,30-31` | Wrong-customer brand on a product | Medium | S | Filter brands; lock customer on edit | C | Open |
| M-21 | Forms | BOM form is hand-rolled, cannot edit a draft, and always copies the active version rather than the newest | `Pages/Product/Boms/Form.vue:35-37,142-211` | Fixing a typo in a draft BOM means redoing the changes | Medium | M | Move to `LineItemsTable`; start from the newest version | C | Open |
| M-22 | Layout | Summary rails never render on the routing and customer-return forms (wrong slot name or nesting) | `Pages/Product/Routings/Form.vue:193-224`; `Pages/Sales/SalesReturns/Form.vue:194-211` | The running totals the page was designed with are missing | Medium | S | Use `#rail` at `FormLayout` level | C | Partly done (09ef498): return form rail fixed; routing form rail open |
| M-23 | Journey | Missing handoffs: Invoice has no "Record receipt"; Bill has no "Record payment"; Delivery note has no link to a trip; product setup ends with no "Create quotation"; MRP shortages have no "Create requisition"; job card has no link to the planning board | `Pages/Finance/Invoices/Show.vue:62-71`; `Pages/Procurement/Bills/Show.vue:64-90`; `Pages/Dispatch/Challans/Show.vue:70-90`; `Pages/Product/Products/Show.vue:100-108`; `Pages/Planning/Mrp.vue:44-70` | The user leaves the document, finds the next screen, and searches for the record again | Medium | M | Add the next-step button with the record preselected | C | Done (807d4ad) |
| M-24 | Journey | Order "not ready" banner lists missing spec/artwork with no links and ignores BOM; Confirm stays enabled | `Pages/Sales/SalesOrders/Show.vue:178-191` | The user knows what is missing but must find each product by hand | Medium | S | Link each line; disable Confirm with the reason | C | Done (b58d32d) |
| M-25 | Journey | Job card Release is enabled when the gate is red for a non-waivable reason | `Pages/Manufacturing/JobCards/Show.vue:1141-1165` | A guaranteed refusal, followed by H-04 | Medium | S | Disable with the reason inline | C | Done (807d4ad) |
| M-26 | Journey | A draft inquiry cannot be cancelled or deleted | `Pages/Sales/Inquiries/Show.vue:96-126` | Junk drafts accumulate | Medium | S | Add "Cancel inquiry" | C | Done (dbb3bb9, 62e2d67): a cancelled draft stays unnumbered, approved 5 Oct 2026 |
| M-27 | Planning | Board cells are not interactive; detail is in a hover tooltip only; no date navigation; the unscheduled list is capped at 50 with no notice; dates print as "10-05" | `Pages/Planning/Board.vue:57,129-136,147,176-198,218` | A planner cannot click a free slot, see beyond the window, or use the board on touch | Medium | M | Make cells buttons; add previous/next/today; show "50 of N" | B, C | Open |
| M-28 | Dispatch | "Add N cartons" sends N separate requests, each with its own toast, and stops silently on failure | `Pages/Dispatch/PackingLists/Show.vue:172-186` | Slow, noisy carton creation | Medium | M | One request with a count | C | Open |
| M-29 | Dispatch | Trip planning shows delivery notes as small chips with no address or zone; stops cannot be reordered; the driver's stop row has no address or phone | `Pages/Dispatch/Trips/Form.vue:73-93`; `Pages/Dispatch/Trips/Show.vue:87-106` | The dispatcher cannot build a route; the driver lacks what a driver needs | Medium | M | List with address and zone, up/down order, large Deliver buttons | C | Open |
| M-30 | Quality | Inspection defect counters are 28 px although described as the tablet control; a permanent REJECTED verdict has no confirm | `Pages/Quality/Inspections/Form.vue:225-246,349-355` | Mis-taps on a permanent record | Medium | S | 44 px targets; confirm with consequences on reject | C | Partly done (8bb1d68): reject confirm; 44 px counters in Phase 3 (M-47) |
| M-31 | Job card | The detail page loads everything eagerly (all employees, machines, 100 logs, eight modals) and puts Waste above Operations | `app/Modules/Manufacturing/Http/Controllers/JobCardController.php:321-454`; `Pages/Manufacturing/JobCards/Show.vue:768-890` | Slow open and a long scroll on the busiest production page | Medium | M | Defer secondary props; move Operations up; use tabs | C | Open |
| M-32 | Job card | Copy contradicts the UI: the Close dialog says "cannot be reopened" but a Reopen button exists | `Pages/Manufacturing/JobCards/Show.vue:590-597,1204,1211` | Users are over-warned | Medium | S | Correct the copy | C | Done (af905e4) |
| M-33 | Floor | The pending count shows only when the browser reports offline; with wifi up and the server down, the pill says ONLINE while records wait | `Pages/Floor/Queue.vue:110-115`; `resources/js/Composables/useOfflineQueue.js:146-169` | Unsent work is invisible | Medium | S | Always show "n waiting"; retry on a timer; add "Send now" | C | Done (34a0449) |
| M-34 | Floor | Queue cards hide status, progress and due date the API already sends; START is greyed when running with no label; the machine choice is not remembered | `Pages/Floor/Queue.vue:157-178`; `Pages/Floor/Operation.vue:186-192`; `Pages/Floor/Login.vue:90-95` | The operator cannot tell the running job from the next | Medium | S | A "RUNNING" pill, progress, due date; remember the machine | C | Open |
| M-35 | Tables | With a row link, every cell is its own link: 150 tab stops on a 25-row list, each 20 px tall | `Ui/DataTable.vue:266-281` | Keyboard users tab through every cell | Medium | S | Link the first cell only | B | Open |
| M-36 | Tables | Sort arrows appear on hover only; sort buttons are 16 px tall | `Ui/DataTable.vue:197-209` | On touch nothing shows which columns sort | Medium | S | Faint arrow at rest | B, C | Open |
| M-37 | Tables | On a phone, lists scroll sideways inside a height-capped box with no sticky first column and no cue | `Ui/DataTable.vue:173,269` | The document number scrolls out of view | Medium | M | Sticky first column; drop the height cap below `md` | B | Open |
| M-38 | Tables | Hand-rolled line tables without a scroll wrapper are clipped by the card on narrow screens | `Pages/Product/Boms/Form.vue:142-153`; `Pages/Product/Specs/Form.vue:268`; `Pages/Inventory/Issues/Form.vue:392,454`; `Pages/Procurement/Bills/Form.vue:96-136` | Right-hand columns, including the remove button, are unreachable below about 1000 px | Medium | S | Wrap in `overflow-x-auto` | C | Open |
| M-39 | Tables | List gaps: job cards and quotations have no text search beyond the number; customer returns has no row link, empty state or export; price lists have no filters; machines, routings and artwork have no export | `Pages/Manufacturing/JobCards/Index.vue`; `Pages/Sales/SalesReturns/Index.vue:40-50`; `Pages/Sales/PriceLists/Index.vue:39-63` | Slower lookup | Medium | M | Bring each up to the Inquiries list pattern | B, C | Open |
| M-40 | Tables | Export dialog shows raw query keys ("customer: 12", "sort: -total"); a failed column fetch shows an empty list | `Ui/ExportDialog.vue:36-57,103-105` | Users cannot confirm what they are exporting | Medium | S | Pass the filter chips' labels | C | Open |
| M-41 | Admin | Audit log filters by event only, shows "Model #id" and key names without values | `Pages/Admin/AuditLog.vue:30-50` | "Who changed this credit limit last week" cannot be answered | Medium | M | User, record and date filters; old → new values; link to the record | C | Open |
| M-42 | Detail pages | Customer and supplier detail pages omit contact details, tax numbers and terms; related orders are not links; lot page prints a PHP class name as the source and offers no actions | `Pages/MasterData/Customers/Show.vue:154-208`; `Pages/MasterData/Suppliers/Show.vue:59-90`; `Pages/Inventory/Lots/Show.vue:91-149` | Users open Edit to read a phone number | Medium | M | A Details card; row links; readable source with a link | C | Open |
| M-43 | Feedback | Toasts sit bottom-right over the docked Save bar and are wider than a 360 px screen | `Ui/Toasts.vue:44`; `Ui/FormFooter.vue:88,96` | An error toast can cover Save and Cancel (not reproduced) | Medium | S | Move to top-right under the header | C | Open |
| M-44 | Error pages | The error page drops the sidebar on 403/404 and gives no reference on 500; 413 (file too large) is unhandled | `Pages/Error.vue:6,37-40`; `bootstrap/app.php:73` | A mistyped URL loses navigation | Medium | S | Render inside the app shell when signed in; add a request id; handle 413 | B (404) | Open |
| M-45 | Accessibility | Row-action menus and the calendar are mouse-only: no focus move, no arrow keys, no day labels | `Ui/DropdownMenu.vue:24-34,99-141`; `Ui/DateInput.vue:231-236,285-304` | Edit and Delete on every row need a mouse | Medium | M | Focus the first item, roving index, return focus | C | Open |
| M-46 | Accessibility | Unlabelled controls: filter selects and dates, roles permission matrix checkboxes, settings selects, spec claim toggles (colour only), floor output inputs | `Ui/FilterBar.vue:120-131`; `Pages/Admin/Roles.vue:297-313`; `Pages/Admin/Settings.vue:326-361`; `Pages/Product/Specs/Form.vue:324-335`; `Pages/Floor/Operation.vue:199-210` | A screen reader hears "edit text" | Medium | S | Pass the field label as `aria-label`; `aria-pressed` on toggles | B (2 unlabelled on reports and settings), C | Open |
| M-47 | Accessibility | Targets under 24 px: toast dismiss, rule-hint icon (12–14 px), filter chips, switches (20 px), line remove (22 px, hidden until hover) | `Ui/Toasts.vue:65-71`; `Ui/RuleHint.vue:26-34`; `Ui/FilterBar.vue:143,151`; `Ui/LineItemsTable.vue:123-132` | Mis-taps on Android; no visible way to delete a line on touch (WCAG 2.5.8) | Medium | S | `min-h-6 min-w-6`; always show remove on coarse pointers | B, C | Open |
| M-48 | Touch | Every `SelectInput` focuses its search box on open, including two-option lists | `Ui/SelectInput.vue:40,130` | On Android every dropdown raises the keyboard | Medium | S | Skip autofocus on coarse pointers or below about 8 options | C | Open |
| M-49 | Performance | Filters, sort and paging refetch every prop (option lists included); no partial reloads or deferred props anywhere; the unused Ziggy route table rides on every response | `Ui/FilterBar.vue:42-46`; `Ui/DataTable.vue:89-93`; `app/Http/Middleware/HandleInertiaRequests.php:91-94` | Each typed character reloads lists the page already has | Medium | M | `only:` on list visits; defer option lists; remove Ziggy | C | Open |
| M-50 | Performance | Forms receive every active product or item and `SelectInput` renders all of them | `app/Modules/Sales/Http/Controllers/QuotationController.php:600-601`; `Ui/SelectInput.vue:270-292` | Long dropdowns and heavy pages as the catalogue grows | Medium | M | Scope by customer; cap rendered options; async search for items | C | Open |
| M-51 | Formatting | Number and money output that bypasses the formatter: `toFixed`, `toLocaleString()`, a hard-coded "৳" on On-hand while everything else says "BDT", returns shown without the invoice currency; lakh grouping only if an admin changes the default locale | `Pages/Inventory/Stock/Index.vue:109`; `Pages/Procurement/Bills/Form.vue:42,120,131`; `Pages/Sales/SalesReturns/Show.vue:87-118`; `resources/js/plugins/formatting.js:20` | Inconsistent figures; 12,34,567 vs 1,234,567 confusion | Medium | M | Route everything through the formatter; decide the default grouping (see §7) | C | Open |
| M-52 | Help | No help entry anywhere; 113 hints are hover-only `title` attributes; shortcuts (Ctrl K, `/`, Ctrl S, Ctrl B) have no list | `Ui/CommandPalette.vue:142,155`; `Pages/Dashboard.vue:123` | Users cannot self-serve; touch users never see hints | Medium | M | A "?" menu with shortcuts, a one-page guide per module and a support contact | C | Open |
| M-53 | Compliance | Reconciliation rows share a key across schemes; the close dialog asks the officer to confirm a review of transactions the page does not link to; the period cannot be reopened and the copy does not say so | `Pages/Compliance/Reconciliation.vue:87,105,134-149` | An irreversible lock on data that cannot be opened from the screen | Medium | M | Key by scheme and period; link to the transactions; say it is final | C | Open |
| M-54 | Trade | LC form says only two fields save but leaves all fields editable; edits are discarded silently | `Pages/Trade/LettersOfCredit/Form.vue:24-26` | Typed changes vanish | Medium | S | Disable the fields that do not save | C | Open |

### 3.4 Low

| ID | Area | Issue | Where | User impact | Sev | Effort | Recommended fix | Check | Status |
|---|---|---|---|---|---|---|---|---|---|
| L-01 | Kit | Two folders differ only by case: `resources/js/composables` and `resources/js/Composables` | directory listing | Breaks on case-insensitive checkouts | Low | S | Merge into one | C | Open |
| L-02 | Kit | `FormPage` section navigation is dead code; `Button size="xs"` is used but not defined | `Ui/FormPage.vue:20,32`; `Ui/Button.vue:28-32`; `Pages/Dispatch/PackingLists/Show.vue:389-394` | Long forms have no jump navigation; "xs" buttons render medium | Low | S | Forward `sections` or remove; add an `xs` size | C | Open |
| L-03 | Kit | 16 hand-rolled tables and 37 raw buttons bypass the kit; audit lists are hand-rolled where `ActivityTrail` exists | grep counts; `Pages/Inventory/Adjustments/Show.vue:178-192` | Inconsistent look and missing labels | Low | M | Migrate as the pages are touched | C | Open |
| L-04 | Kit | `echarts`, `vue-echarts` and Pinia are installed and unused | `package.json` | None today | Low | S | Remove, or use for report trends | C | Open |
| L-05 | Empty states | 52 of 97 tables use a plain string instead of `EmptyState`; several lists have an empty `<span />` in the actions column | `Ui/DataTable.vue:243-248`; `Pages/Admin/Users.vue:147`; `Pages/Finance/Invoices/Index.vue:52-57` | No next step on about half the secondary lists | Low | M | Default `DataTable` to `EmptyState` | C | Open |
| L-06 | Microcopy | Expense row actions are status nouns ("Approved", "Paid"); quotation header has "Rejected" and "Cancel" beside seven other buttons; "Send" only marks as sent; card titled "Header" | `Pages/Finance/Expenses/Index.vue:55`; `Pages/Sales/Quotations/Show.vue:69-114` | Wrong-button risk | Low | S | Verbs: "Approve", "Mark as sent", "Record rejection", "Cancel quotation"; group secondary actions in a menu | B, C | Open |
| L-07 | Microcopy | Statuses title-cased mechanically: "Qc Pending" | `Pages/Dashboard.vue` (status badges); `resources/js/plugins/formatting.js` (`titleCase`) | Looks unfinished | Low | S | A small exceptions map (QC, PO, LC) | B | Open |
| L-08 | Login | The login page hard-codes "Maheen Label · Label & garment-accessory manufacturing"; no show-password toggle | `Pages/Auth/Login.vue:32` | Wrong name after a rebrand | Low | S | Use the organisation profile | B | Done (e03be6c) |
| L-09 | Artwork | Each version prints its storage path and full checksum; the list filter is labelled "Gate" | `Pages/Product/Artworks/Show.vue:250-257,434-436`; `Pages/Product/Artworks/Index.vue:91` | Noise for merchandisers | Low | S | Show the filename; move the checksum behind "Details" | C | Open |
| L-10 | Robustness | `localStorage` is read unguarded when a table mounts | `Ui/DataTable.vue:129-131` | With storage blocked, every list fails | Low | S | try/catch | C | Open |
| L-11 | Admin | The roles page uses a unicode glyph as an icon; the users table shows raw "en"/"bn" | `Pages/Admin/Roles.vue:177`; `Pages/Admin/Users.vue:118` | A blank box on some Android devices | Low | S | Use the icon component; print the language name | C | Open |
| L-12 | Defaults | New quotation has no "Valid until" default while Duplicate sets +30 days; convert-to-order has no default delivery date | `Pages/Sales/Quotations/Form.vue:75`; `Pages/Sales/Quotations/Show.vue:335-343` | Extra typing | Low | S | Default both | C | Done (b58d32d, 53423fe) |

---

## 4. Journey walkthroughs

### 4.1 Quote to order (merchandiser)

1. **New inquiry.** No product can be attached to a line (H-09). A stray empty row fails the save (M-10). Typing a date as `20/10/2026` empties the field (H-02). The rule tooltip beside "Required by" is cut off by the card (H-46).
2. **Submit the draft.** The confirm reads "Move to open INQ-…? The state machine will still apply its own checks." (H-28). A mistaken draft cannot be cancelled (M-26).
3. **"Quote it".** Customer, currency and lines carry over, but each line needs its product picked. For a USD customer the rate stays 1, so figures are wrong and the save is refused (H-07).
4. **Pricing.** If the product lacks a spec, a costed BOM or a routing, the line cannot be priced and the quotation cannot be saved even as a draft; the message has no link (H-08). Each keystroke re-prices every line (H-10). At 1280 px the header fields are cut off (M-15).
5. **Quotation page.** Up to nine header buttons; "Send" only changes status (L-06). Terms typed on the form are not shown (H-11).
6. **Convert to order.** The order's "not ready" banner names missing spec or artwork with no links (M-24).
7. **Order typed by hand.** Cannot be saved at all (C-03).
8. **Return.** Cannot be saved (C-01, C-02).

### 4.2 Product setup (merchandiser, designer, engineer)

1. **New product.** The brand list shows other customers' brands (M-20). The redirect to the setup checklist works well.
2. **Specification.** About 25 fields; the colour row needs a name that is not marked required and whose error is not shown (H-12). "Make this the current specification" is unticked at the very bottom; "Make current" later has no confirm (H-29). A saved spec cannot be opened or edited.
3. **Artwork.** Upload has no limits, progress or inline error (H-14). "Submit to customer" is a status change with no confirm.
4. **BOM.** The table is clipped on narrow screens (M-38), shows no row errors (H-03), and a draft cannot be edited (M-21).
5. **Routing.** If none exists, "Create one" leaves the product for a form with unlabelled paired inputs and no reorder (H-15), and offers no way back.
6. **Finish.** The checklist ends on a trial price with no "Create quotation" (M-23). Tools cannot be created anywhere (H-13).

### 4.3 Buy to pay (purchase officer, accounts)

1. **Requisition.** Items are findable by code only (H-17).
2. **RFQ.** Quotes are keyed one at a time; selecting a winner above the threshold fails silently (H-20).
3. **Purchase order.** The exchange rate is corrected by trial and error (H-07); approval figures carry the wrong currency label (H-22); bulk approve has no confirm (H-29).
4. **Goods receipt.** Good from "Receive goods" on the PO; from "New GRN" nothing prefills (H-19). Posting is immediate and final (H-18), and errors on lot and certification fields are invisible (H-03).
5. **Supplier bill.** Prefills from the GRN, cannot be edited afterwards (H-21).
6. **Payment.** No button on the bill (M-23); one bill per payment (H-27); a foreign-currency bill cannot be paid (C-05).

### 4.4 Store work (store keeper)

1. **Landing.** The dashboard shows sales tiles (M-02).
2. **Receive.** No lot label can be printed (H-25).
3. **Issue.** Lot suggestion and FIFO explanation are good. Changing the store wipes the lines (H-23); server errors on lines are invisible (H-03).
4. **Transfer / adjust.** Lot list capped at 400 (H-24); one signed quantity field (M-17); the post confirm does not say it is final (H-28).
5. **Count.** "Start counting" blocks the warehouse without saying so; entry cannot be saved part-way (C-06); no search or progress (M-18); no way back from Reconciled (H-26).

### 4.5 Make (planner, supervisor, operator, QC)

1. **New job card.** No search across order lines; the artwork and routing gate is found only on submit (M-16).
2. **Plan.** The card has no link to the board (M-23). Each routing step is scheduled separately; cells are not clickable; no date navigation (M-27).
3. **Release.** The button is live when the gate is red (M-25); a refusal closes the dialog and erases the waiver text (H-04).
4. **Floor login.** Long guidance is English only (H-34); the machine is picked again every shift (M-34).
5. **Queue.** No running marker, due date or unit (M-34, H-35).
6. **Book output.** Double tap books twice (H-31); "Logged" shows even when only queued (H-32); FINISH has no confirm (H-29).
7. **Outage.** Pending count hidden unless the browser says offline (M-33); END SHIFT discards the queue (C-08); entries over four hours vanish (C-07); later rejections are an anonymous count (H-33).
8. **Inspection.** 28 px counters; a permanent reject has no confirm (M-30).
9. **NCR.** "Record action taken" and "Close" are one click (H-29).

### 4.6 Ship and collect (dispatch, driver, accounts)

1. **Packing list.** "Add 53 cartons" is 53 requests (M-28); removing a full carton is one click (H-29).
2. **Delivery note.** Called "challan" on some screens and "delivery note" on others (M-06); the mode is silently own fleet (H-37); Cancel and Mark delivered have no confirm (H-29).
3. **Trip.** No route from the note to a trip (M-23); notes are chips with no address; stops cannot be reordered (M-29).
4. **Proof of delivery.** A failed drop cannot be recorded (C-09) and, if forced, says "Stop delivered." (H-36).
5. **Invoice → receipt.** No "Record receipt" on the invoice (M-23); one invoice per receipt, no void (H-27).
6. **Credit note.** Apply and refund never appear (C-04).

### 4.7 First run (administrator, any new user)

1. **Login.** No reset path (H-43). Failed login message is clear.
2. **Dashboard on an empty system.** Zero tiles and no setup path (H-44).
3. **Configuration → Lists.** Has a completeness banner and search; this is the one place first-run guidance exists.
4. **Users and roles.** A stray backdrop click discards the form (H-06); the permission matrix is unlabelled (M-46).
5. **Language.** বাংলা does nothing on the desk and can only be chosen while changing the password (H-39, H-40).
6. **Help.** None (M-52).

---

## 5. Quick wins (high impact, small effort)

All fifteen were completed in Phase 1 (branch `ux/phase-1`).

1. **C-01** Customer return Save: use `FormFooter`. — **Done** (09ef498)
2. **C-03** Sales order: set `product_spec_id` from the product's current spec. — **Done** (4c7ea07)
3. **C-04** Credit note: rename the `money` prop. — **Done** (e382657)
4. **C-05** Payments: stop sending `exchange_rate: 1`. — **Done** (dc5f3cf)
5. **C-07** Floor queue: file expired entries as rejected instead of dropping them. — **Done** (ba255c0)
6. **C-09** Proof of delivery: `required_without:failure_reason`; correct the "Stop delivered." flash (H-36). — **Done** (2b9adcd)
7. **H-01** "Clear filters": fix once in `EmptyState`, repairs 36 lists. — **Done** (57152aa)
8. **H-17** Item pickers: add `hint-key="name"`. — **Done** (90efb0a)
9. **H-28** Status-change confirm: real titles, consequences and button labels. — **Done** (0c4e0c8)
10. **H-31 / H-32** Floor: lock buttons while sending; show "saved on this device" when queued. — **Done** (34a0449)
11. **H-35** Floor: print the unit beside every quantity. — **Done** (34a0449)
12. **H-40** Profile: give language its own card. — **Done** (bfab0be)
13. **H-45** Toasts: keep errors until dismissed and list every message. — **Done** (c4211a8)
14. **M-09** Save hint: "Ctrl S" on non-Mac. — **Done** (400e095)
15. **H-06** Overlays: confirm before closing a dirty form. — **Done** (b9c453a)

---

## 6. Prioritized roadmap

### Phase 1 — Unblock and stop silent failures

**Status: complete on branch `ux/phase-1`.** Every item below is Done unless marked.

- The nine Critical items (C-01 … C-09) — Done.
- The quick wins above — Done.
- Error visibility: H-03 (line errors), H-04 (refusals keep the dialog open), H-30 (`abort(422)`), M-08 (disabled reasons) — Done.
- Confirms on irreversible actions (H-29), GRN post (H-18), inspection reject (M-30), job card close wording (M-32) — Done. H-18 is the confirmation only; draft-then-post is Phase 2. M-30's larger counters are Phase 3.
- Date typing (H-02), week start and calendar dimming (M-13), native date inputs (M-12) — Done. One date-and-time field on the job card stays native because the kit has no time picker.
- Exchange-rate autofill (H-07) and purchase order approval labels (H-22) — Done.
- Rule tooltip clipping (H-46) — Done.
- Floor terminal: in-flight lock and stable keys (H-31), sent / saved-on-device states (H-32), units (H-35), waiting count and Send now (M-33) — Done.
- Language selector removed from the desk (H-40, and H-39 by decision), forgot-password help (H-43), login from the organisation profile (L-08), dashboard at 1280 px (M-01) — Done.
- Also done along the way: M-22 (return form rail only), M-18 (counted indicator only), M-09, M-14.
- New: **H-50** (missing number sequences on older databases) — needs a decision.

### Phase 2 — Core flow redesigns

- **Quotation:** draft with unpriced lines, product on inquiry lines, calmer pricing (H-08, H-09, H-10, H-11).
- **Next-step handoffs** across all journeys (M-23, M-24, M-25).
- **Store:** partial counts and recount (C-06 follow-through, H-26, M-18), lot search (H-24), label print and scan (H-25), issue across stores (H-23).
- **Money:** multi-invoice receipts and payments, void, detail pages (H-27); editable bills (H-21).
- **Floor terminal:** rejected-records screen, queue visibility, running marker, end-of-shift safety (C-08, H-33, M-33, M-34).
- **Dispatch:** trip planning and driver screen (M-29), delivery mode (H-37), cartons in one request (M-28).
- **Planning board:** clickable cells and date navigation (M-27); job card page weight and order (M-31).
- **First run:** setup checklist, seed-password banner, password help (H-43, H-44); role-aware dashboard (M-02).
- Reports export and print (H-41); import preview (H-42); session-expiry recovery (H-05).

### Phase 3 — System level

- **Language:** decide the Bangla scope (§7), then either remove the desk selector or add a translation layer, kit strings first (H-39). Bilingual floor dictionary and coded server errors (H-34). Plain-language pass over subtitles, hints, flashes and rule codes (M-03 … M-07).
- **Accessibility:** focus management in overlays (H-47), select and menu keyboard support (H-48, M-45), contrast token and 12 px floor (H-49), labels (M-46), target sizes (M-47), table row links (M-35).
- **Design system:** move hand-rolled tables onto `LineItemsTable` (M-38, L-03), one date picker (M-12, M-13), kit clean-up (L-01, L-02, L-04), default `EmptyState` (L-05).
- **Performance:** partial reloads and deferred props (M-49), scoped and capped option lists (M-50).
- **Help:** "?" menu, shortcut sheet, module guides (M-52).
- **Formatting:** one path for numbers and money, grouping decision (M-51).

---

## 7. Open questions

Answered on 5 October 2026 (recorded here as the decisions the implementation follows):

| # | Question | Decision |
|---|---|---|
| 2 | Bangla on the desk | No. The desk stays English and the language selector is removed. The floor terminal stays bilingual |
| 3 | Number grouping and currency label | Lakh grouping (12,34,567.00) with the "BDT" code, Latin digits |
| 4 | Typed date formats | Day first: dd/mm/yyyy, dd-mm-yyyy, dd.mm.yy, two-digit years, "20 Oct 2026", and ISO. Never month first |
| 5 | Goods receipt | Draft, then Post with a confirmation (Phase 2). Phase 1 adds the confirmation only |
| 6 | Floor records older than four hours | Not posted late; filed as rejected with reason "expired" for a supervisor to re-key |
| 7 | Lot labels and scanning | In scope for Phase 2 |
| 8 | One receipt or payment across several invoices | In scope for Phase 2, oldest first, with void and bounce |
| 9 | Tools | Create and edit in the UI (Phase 2) |
| 10 | Password reset | No email reset. The login page says to ask the administrator |
| — | Recount from Reconciled (H-26) | In scope for Phase 2 |
| — | Schema or calculation changes | Asked about each time before they are made |

Still open:

1. **Product name.** The brief says TrimFlow; the code says Octa ERP. Is a rename planned, and should user-facing copy change?
2. **H-50.** May a data migration add the number sequences that older databases lack (customer returns, refunds)?
3. **Devices.** Which desk roles really use Android phones or tablets?
4. **Known complaints.** None were supplied. Support tickets or user feedback would let the remaining roadmap be re-ordered by real frequency.
5. **Customer portal.** It is one placeholder page with developer copy. Is it reachable by customers today?
6. **Date and time entry.** The job card's "When was it made?" field needs a time as well as a date. Should the kit gain a time picker, or is the browser's own control acceptable there?
