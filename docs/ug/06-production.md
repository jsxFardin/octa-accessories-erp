# Production

Planning, job cards, the material plan (MRP), and the shop-floor terminal.

## Two screens, not one word

"Floor" used to name three things at once. It names one now, and the operator's kiosk has its
own name. Which one you want depends on where you are standing:

| | **Production** (the sidebar group) | **Shop floor terminal** |
|---|---|---|
| What | Planning board, Job cards, Material plan, Machines | The kiosk beside the machine |
| Who | Planner, supervisor, merchandiser | Machine operator |
| Where | Your desk, inside the main application | A shared terminal on the floor |
| URL | `/planning`, `/job-cards`, `/mrp`, `/machines` | `/floor` |
| Sign in with | Email and password | Badge scan and PIN |
| Looks like | The dense desk grid | Four big buttons, high contrast, Bangla first |

They are separate applications. The terminal shares no navigation with the desk and has no way
back to it — that is deliberate, so an operator cannot wander into the order book. Open it from
**Production → Shop floor terminal** and it launches in its own tab.

One rule decides which you want: **a job card is planned at a desk and run on the floor.**

## Planning board

**Production → Planning board.**

Load by machine / day. Drag or assign job-card operations into capacity. An operation cannot be scheduled before its predecessor unless the routing allows parallel.

This is the planner’s morning screen. The board does not start the machine; the operator (or supervisor) does.

## Material plan

**Production → Material plan.**

Run MRP against confirmed demand. It proposes requisitions for shortages. Review, then raise PRs — MRP does not silently place POs.

## Job cards

**Production → Job cards.** The seeded demo card is **draft** against the Nordic Apparel order.

### Create

From planning or **New job card**: sales-order line, qty, due date, routing, BOM, **approved artwork version**. Artwork is mandatory.

### Release (J1)

**Release** is the second time Gate 1 and material availability are checked.

The release panel lists what is missing (artwork, BOM, material). If material is short, a supervisor may **waive** with a written reason (`job_card.waive_material`). That is auditable; it is not a silent skip.

Release assigns the job-card number and makes operations eligible to start.

### Run

Statuses typically: `draft` → `released` → `in_production` → `qc_pending` / `completed`, with `on_hold` and `cancelled`.

On the job card you will:

- See operations in sequence and their good / waste qty.
- **Issue material** (link to Inventory) against the BOM.
- Record **FG receipt** when finishing goods are produced — qty, warehouse, grade. Double-submit is ignored (client ref).
- Open **NCRs** if QC fails the lot.

A job does not complete if Settings require a **final QC** and none has been accepted.

### Hold / cancel

**On hold** needs a reason. Cancel only when policy allows; it does not delete ledger that already posted.

## Shop floor terminal

**URL:** `/floor`  
**Who:** the machine operator. A supervisor may work from the desk job card instead.

### Before an operator can sign in

Badge and PIN are issued by an administrator under **Configuration → Users**, on the user's own
record: **Badge number** (the number printed on the card they wear) and **Floor PIN**. Both are
needed — a user with no badge, or no PIN, cannot sign in at the terminal. The PIN is stored
hashed and is never shown back, so a forgotten one is reset, not looked up.

Do not set the PIN to the last digits of the badge number. The badge is worn where anyone can
read it.

### Signing in

Scan the badge, type the PIN, pick the machine, press **SIGN IN**. That is the whole login —
there is no separate password step, and the terminal keeps the operator signed in for the shift.

**Machine** filters the work queue to that machine, plus work its group has been given but
planning has not pinned to a specific machine yet. Leave it on **any** to see every machine in
the factory unit.

**END SHIFT**, top right of the queue, signs the operator out. Press it when handing the kiosk
over — otherwise the next person's output is booked under the previous operator's name.

A supervisor who is already signed in at their desk can press *"No badge? You are already signed
in as …"* at the bottom of the badge screen and continue under their own name, without a badge.

### Running an operation

Tap an operation in the queue:

| Action | Meaning |
|---|---|
| Start | Operation → running. Sequence guards still apply. |
| Output | Input received, good and waste. Beyond plan needs a written reason (J3). |
| Downtime | A reason from the list, plus minutes. |
| Finish | Closes the step. Finishing with nothing booked asks why first. |
| Queue | Back to the list without closing the step. |
| Offline | If wifi drops, the terminal queues the action for up to four hours and replays it. Do not reboot to “fix” a pending queue. |

Bangla labels are the default for operators. Output you type is the shop-floor truth; the job card on the desk updates from it.

### When the queue is empty

"Nothing to run" has three ordinary causes, and the terminal cannot tell them apart: nothing is
scheduled for that machine, the job cards are not **released** yet, or the step before this one
has not finished. All three are fixed at a desk, on the planning board — not at the terminal.

Demo: badge `BADGE-0009`, PIN `0009`.

## What you should not do

- Release a job card to “get it on the board” without approved artwork. The database will refuse it even if the UI were bypassed.
- Start operation 3 before operation 2 unless the routing says parallel.
- Receive finished goods into a raw-material warehouse.
- Share an operator badge. The card is the person for audit.
