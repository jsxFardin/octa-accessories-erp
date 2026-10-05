# UX audit, Phase 1: unblock core tasks and stop silent failures

Branch `ux/phase-1`. Implements Phase 1 of `docs/UX_AUDIT.md`: 36 findings closed, 5 partly closed, and 1 (Bangla on the desk) settled by decision. One commit per finding or tight group, each named `ux(<ID>)`. Statuses are logged in the audit's findings tables; screenshots are in `docs/ux-evidence/`.

## What users will notice

**Tasks that could not be completed now can be**

- A customer return can be saved. Choosing the invoice no longer wipes the reason and date already typed.
- A sales order typed by hand can be saved, not only one converted from a quotation.
- An approved credit note offers "Apply credit" and "Refund".
- A supplier bill in a foreign currency can be paid.
- A physical count can be saved part-way and carried on later. The page shows "12 of 300 counted".
- A driver can record a delivery that failed without inventing a receiver, and is told the goods go back into stock.
- On the shop-floor terminal, ending a shift no longer discards records that have not been sent, and records older than four hours are kept for a supervisor instead of vanishing.

**The system says why it refused**

- An error on a line of a document now appears in that line. Before, many had nowhere to show.
- When a save is refused, every problem is listed next to the Save button and stays there until fixed. Error messages no longer disappear after ten seconds.
- When an action is refused inside a dialog, the dialog stays open with the message in it and what was typed is kept.
- A disabled Save button says what is missing.
- "Clear filters" on an empty list works (it was dead on 36 lists).

**Fewer accidents**

- Actions that cannot be undone ask first and say what will happen: posting a goods receipt, cancelling or completing a delivery note, closing a letter of credit, removing a packed carton, rejecting an inspection, FINISH on the floor terminal, bulk approve, and others.
- Every status change says what it does in plain words. "Start counting" says it freezes the warehouse.
- Buttons cannot be pressed twice while a request is in flight. A double tap on the floor terminal books output once.
- A half-filled dialog is not lost to a stray click outside it.

**Dates and money**

- Dates can be typed the usual way: `20/10/2026`, `20-10-26`, `20 Oct 2026`. Something that is not a date leaves the old value in place and says so.
- The calendar starts its week on the day set in organisation settings.
- Choosing a currency fills in the exchange rate on file. The rate field is hidden for BDT documents.
- Purchase order approval figures are labelled in the right currency.

**Shop-floor terminal**

- Every quantity shows its unit (metres or pieces), in Bangla and English.
- "Sent" and "Saved on this device, will send later" are different messages, and the totals include what is waiting.
- The number of records waiting is always visible, with a "Send now" button.

**Smaller things**

- Materials and products can be found by name, not only by code.
- The explanation behind the small (i) rule markers is no longer cut off.
- The save shortcut reads "Ctrl S" on Windows.
- The login page has "Forgot your password?" (ask your administrator), a show-password box, and takes its name and logo from the organisation profile.
- The language selector is removed from desk screens: the desk has no Bangla translation, so it did nothing. The floor terminal is unchanged.
- The dashboard is readable on a 1280 px laptop.

## Decisions this follows

Recorded in `docs/UX_AUDIT.md` §7: desk stays English; day-first dates; no email password reset; expired floor records go to a supervisor; goods receipt gets a confirmation now and a draft step in Phase 2.

## Needs a decision

- **H-50 (new).** A database seeded before customer returns and refunds existed has no number sequence for them, and approving a return fails with a server error. Found on the local demo database. The fix is a small data migration, but number sequences are outside what this work may change without approval.

## For reviewers

- **No schema changes.** No change to the floor terminal's device API, to number sequences, or to stock or accounting calculations.
- **Validation relaxed in two places, both with tests:** a physical count line may be saved without a quantity (reconciliation still refuses it); a trip stop needs a receiver only when no failure reason is given.
- **Behaviour change to know about:** for any non-GET Inertia visit, a page's `onSuccess` is skipped when the response carries a flash error (`resources/js/plugins/refusals.js`). This is what keeps dialogs open on a refusal; a call can pass `onRefused` to react.
- **Server refusals changed shape** in four desk controllers (packing lists, expenses, import shipments, letters of credit): `abort(422, …)` became a validation error via `App\Support\Http\RefusesActions`.
- **Shared components touched:** `LineItemsTable` (`errorKeys`, `errorPrefix`, `fixed`, `hideAdd`), `FormFooter` (error summary, in-app leave dialog), `Modal` / `SlideOver` (`dirty`, refusal shown inside), `EmptyState`, `Toasts`, `RuleHint`, `DateInput`, `BulkBar`, `ResourceForm`.
- **New composables:** `useDirtyClose`, `useGuardedAction`, `useBookedRate`; `useTransitionConfirm` now takes the document type.

## Checks

| Check | Before (clean `main`) | After |
|---|---|---|
| PHP tests (`php artisan test`) | 1417 passed | 1431 passed (14 new) |
| JS tests (`npx vitest run`) | 54 passed | 110 passed |
| Code style (`pint --test`) | passed | passed |
| Static analysis (`phpstan`) | 4 errors | 4 errors (the same four; none in files this branch changed) |
| Build (`npm run build`) | passed | passed |

Every finding was checked in a browser at 1280 px and 360 px on the local demo database. The Critical items were reproduced by saving real records there.

## Not in this branch

Phase 2 items noted while working and left alone: draft-then-post for goods receipts (H-18), search and filters on count entry (M-18), the routing form's summary rail (M-22), a time picker for the job card's date-and-time field (M-12), larger defect counters (M-30), a screen listing unsent floor records (H-33).

🤖 Generated with [Claude Code](https://claude.com/claude-code)
