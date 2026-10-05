# UX audit, Phase 2: core flow redesigns

Branch `ux/phase-2`, stacked on `ux/phase-1` (merge that first). Implements Phase 2 of `docs/UX_AUDIT.md`: 47 findings closed across ten workstreams, plus three found on the way. One commit per finding or tight group, each named `ux(<ID>)`. Statuses and commits are in the audit's findings tables; screenshots are in `docs/ux-evidence/`.

## What users will notice

**First day**

- A new administrator sees a setup checklist on the dashboard: organisation, lists, users, customers, materials, products, first quotation.
- Anyone still using the starter password is told so on every page.
- The dashboard and the report links show only what that person may open.
- When a session runs out while a form is open, the form stays. A dialog asks the user to sign in again in another tab and press Save.

**Quote to order**

- A quotation can be saved as a draft before every line has a product or a price.
- An inquiry line can name a product. Changing the customer clears products that belong to someone else.
- Prices are worked out a moment after typing stops, per line, without freezing the form.
- The quotation and the sales order show terms, tooling, lead time and notes that used to be hidden.
- Each document offers the next step: record a receipt from an invoice, record a payment from a bill, plan a trip from a delivery note, create a quotation from a product, raise a requisition from the material plan.

**Store**

- A goods receipt can be saved as a draft, corrected, and posted when the goods have been checked. Roll length is recorded per line.
- Lots can be searched instead of scrolled, and scanned by barcode on transfers and adjustments. Lot labels can be printed from a goods receipt.
- A stock count has search, "not counted yet" and a progress figure, and can go back from Reconciled to Counting.
- A stock adjustment says whether stock goes in or out, and shows what will be on hand afterwards.

**Money**

- One receipt or payment can settle several invoices or bills. "Oldest first" fills the amounts.
- A bounced or wrong receipt or payment can be reversed, with a reason. The documents it settled go back to unpaid.
- A draft supplier bill can be edited, and its lines name a material.

**Shop-floor terminal**

- Everything on the terminal is in Bangla and English from one word list. Long explanations are in Bangla, with a button to switch them to English.
- When the server refuses a booking, the terminal says why in both languages, with the figures. No rule numbers.
- "2 records not sent" opens a list: job card, step, quantities with units, time, operator and reason. A supervisor removes a record after booking it at the desk; an operator cannot.
- Queue cards show running or not started, progress against plan, and the due date. A running step says "RUNNING" instead of showing a greyed-out START.
- The terminal remembers the machine chosen last time.
- Titles on the terminal are now readable (they were dark text on a dark background).

**Dispatch**

- Creating a delivery note asks how the goods will travel: own vehicle, courier, customer pickup or freight forwarder.
- "Add 53 cartons" is one action with one message.
- The trip planner lists delivery notes with address, zone, cartons and pieces. Stops can be moved up and down.
- The driver sees each stop as a card with the address, a number to call, the load, and large "Delivered" and "Could not deliver" buttons.
- Starting a trip asks for the odometer reading.

**Planning and production**

- Planning board cells can be selected to see minutes and steps, and to schedule a waiting step into that slot. The board can be moved to earlier and later days.
- The new job card form flags order lines whose product lacks approved artwork or a routing, with a link to fix it, and has a search box.
- The job card opens on its operations. Shift bookings, waste, materials, finished goods and NCRs are tabs. The page opens before its long lists have loaded.

**Product setup**

- Tools (plates, screens, dies) can be registered, edited and retired.
- Artwork upload says what it accepts (50 MB, listed formats), shows progress, and shows errors under the field. A wrong draft version can be withdrawn.
- The routing form has one labelled column per figure, says "m per hour" or "pcs per hour", and steps can be reordered.
- A deactivated price list can be reactivated.
- A product's brand list shows only that customer's brands. The customer of an existing product is locked.
- A draft bill of materials can be edited. A new version starts from the newest one.

**Data out and in**

- Every report has Print, Download Excel and CSV, with all matching rows and totals.
- Importing a spreadsheet checks it first and shows "N will be created, M updated, K skipped" with reasons. Nothing changes until "Import N rows" is pressed.
- The export dialog says what the export is filtered by in plain words.

## Decisions this follows

Recorded in `docs/UX_AUDIT.md` §7. Approved during the work on 5 Oct 2026: goods receipt as draft then post; a data migration for missing number sequences; cancelling an unnumbered draft inquiry without giving it a number; the reversal calculation for receipts and payments; adding `code` and `params` to floor API refusals.

## For reviewers

**Schema**

- `2026_10_05_000100_add_missing_number_sequences` (data only: adds sequence rows that older databases lack).
- `2026_10_05_000200_add_roll_length_to_grn_lines` (one nullable column).
- No other schema change. Several features use columns that already existed and were never filled: `trip_stops.address_id`, `delivery_challans.courier_name` / `tracking_no`, `trips.start_odometer`, and the whole `tools` table.

**Calculations and state machines**

- Sales invoice and supplier bill state machines gained the backward steps a reversal needs (paid → partly paid → issued / approved). `SettlementReversal` holds the logic; `SettlementReversalTest` covers multi-document, partial and once-only cases.
- Physical count: Reconciled → Counting.
- Goods receipt: posting a draft produces the same lots and costs as posting in one step (`GrnDraftTest`).

**Floor API**

- A refused booking still answers 422 with `message`; it now also carries `code` and `params`. English messages are unchanged.
- `GET /api/v1/floor/queue` adds `unit` to each step. Additive.
- Queue entries on the device keep a `meta` object (job, step, unit, operator). It is never posted.

**Access changes to check**

- **H-52:** a driver can open a trip they are the driver of (they could list it but got 403), and can record a stop only on their own trip. One existing test fixture now links its trip to the driver user.
- Removing a record from the terminal's "Not sent" list needs `job_card.update`.
- Report downloads need `report.export`; print needs `report.view`.
- Tools use the existing `tool.create` / `tool.update`. BOM editing uses `bom.update`. Withdrawing an artwork draft uses `artwork.create`.

**Behaviour changes to know about**

- Delivery note creation now requires a mode; it used to default to own fleet.
- The trip planner offers only own-vehicle delivery notes, and refuses a note that is already on a trip or not issued.
- The job card page sends `operationLogs`, `wasteLogs`, `ncrs`, `operators` and `machines` as deferred props. Two tests were updated to read them that way.
- The product form refuses a brand that belongs to another customer, and a change of customer on an existing product.
- `ResourceForm` fields accept `options` as a function of the form, and `disabled`.

## Checks

| Check | Phase 1 end | Now |
|---|---|---|
| PHP tests (`php artisan test`) | 1431 passed | 1496 passed |
| JS tests (`npx vitest run`) | 110 passed | 142 passed |
| Code style (`pint --test`) | passed | passed |
| Static analysis (`phpstan`) | 4 errors | 4 errors (the same four, none in files this branch changed) |
| Build (`npm run build`) | passed | passed |

Each journey was walked in a browser at 1280 px and 360 px on the local demo database, signed in as the role that uses it.

## Please check by hand

- The floor terminal on a real tablet with the network switched off: book output, reload, reconnect, confirm it is sent once. It was tested here by making the requests fail, not by cutting the network.
- Artwork upload with a large file, to see the progress bar move.
- "Call" links on the driver's screen (the demo data has no contact phone numbers).
- Printing a lot label on the label printer in use.

## Not in this branch

- H-20 (RFQ winner refused silently) and H-38 (lab form) were not in any phase brief. They are the first items of Phase 3.
- M-10 and M-11 on the price list form; barcode scanning on the material issue form; a time picker for the job card's date-and-time field (M-12).
- Rewording the server's English refusal messages (they still carry rule prefixes for API clients) is part of the Phase 3 copy pass.
- A map and drag-and-drop on the trip planner; a driver starting their own trip.

🤖 Generated with [Claude Code](https://claude.com/claude-code)
