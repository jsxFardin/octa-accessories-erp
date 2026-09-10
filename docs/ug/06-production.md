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

Load by machine / day. This is the planner's morning screen. The board does not start the
machine; the operator (or supervisor) does.

**Scheduling.** The board has two lists under the grid: **Unscheduled operations** (waiting for
a machine and a slot) and **Scheduled in this window**. Press **Schedule** on an unscheduled
row, pick a machine and a day, and the panel shows what that machine already has booked and
what it would have after this step. **Take off** returns a step to the unscheduled list and
frees the slot. To move a step, schedule it again — the new placement replaces the old one.

Four rules hold, and each names itself when it refuses:

| Rule | What it stops |
|---|---|
| Machine group | A step whose routing names *Weaving* cannot be put on a press. Only eligible machines are offered. |
| Retired machines | An inactive or retired machine cannot take work. |
| **BR-27** capacity | A day may not be booked past the machine's available minutes — shift minutes discounted by planned downtime *and* by the machine's own efficiency. |
| **J2** sequence | A step cannot be scheduled to run before the step that feeds it is scheduled to finish, unless the routing allows parallel. |

Capacity and holidays can both be overridden, but only with a written reason, and the reason is
kept on the audit trail against that operation. Nothing overrides the machine group or J2 —
those are plans that cannot run.

Scheduling is what fills the board's cells and what orders the operator's work queue at the
terminal. A step with no schedule reaches the floor in no particular order.

Needs `production_plan.update` — the planner role. Everyone else sees the board read-only.

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

### Installing it on the tablet

Open `/floor` in Chrome on the tablet and choose **Install app** (Chrome's ⋮ menu, or the prompt
it offers). The terminal then has its own icon on the home screen and opens full screen, with no
address bar for an operator to fall into. On an iPad it is *Share → Add to Home Screen*.

Do this once per kiosk, on the wifi, before the tablet goes to the floor. The install is also
what puts the terminal on the device: from then on it opens even when the network is down.

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
| Output | Input received, good and waste. Waste needs a cause; beyond plan needs a written reason (J3). |
| Downtime | A reason from the list, plus minutes. |
| Finish | Closes the step. Finishing with nothing booked asks why first. |
| Queue | Back to the list without closing the step. |
| Offline | If wifi drops, the terminal queues the action for up to four hours and replays it. Do not reboot to “fix” a pending queue. |

### When the wifi drops

Keep working. Everything booked is held on the tablet and sent when the link returns — the
badge at the top right shows **OFFLINE** and how many entries are waiting.

Reloading is safe: the terminal opens from the tablet rather than the network, and comes back
with the work list it had. It says so — *"Saved list from 14:20 — not live"* — because a job
card cancelled or added while the link was down will not be in it.

What does **not** work offline is opening a job card nobody has opened on that tablet yet; the
terminal says so rather than doing nothing. Open the card you are about to run while you still
have a connection, and you can work through the whole outage on it.

Ending a shift clears the saved screens, so the next operator never sees the last one's work.

Bangla labels are the default for operators. Output you type is the shop-floor truth; the job card on the desk updates from it.

### Waste

Waste is booked with a **cause**, not just a quantity. The terminal asks for one as soon as a
waste figure is entered, from a fixed list: setup, shade, weave defect, print defect, cutting,
edge trim, damaged, expired, other. A booking with no waste is still two numbers and SAVE.

The cause is what makes the figure actionable — a loom losing metres to setup and a loom losing
them to a weave defect are different problems with different fixes, and G4 ("wastage % per
machine trending down") cannot be worked on without knowing which one you have.

The job card's **Waste** panel lists them: when, which step, the cause, the quantity in that
step's own unit, the lot if the operator named one, and who reported it.

Waste value is not costed yet — the panel shows quantities. Costing WIP waste belongs to the
consumption and cost-sheet chain, and a number invented at the terminal would be worse than an
absent one.

### Booking output from the desk

Output normally reaches the system one way: an operator books it at the terminal, against the
job running in front of them. That is deliberate — it is the shop-floor truth, recorded where
and when the work happened.

When the terminal cannot take it — the kiosk is down, the tablet is dead, the shift ran without
one — a supervisor can key it from the job card instead: **Shift bookings → Book output
manually**. It is the same rules and a narrower door:

| | |
|---|---|
| Who | `operation.log` — production supervisor and operator |
| Needs | At least one employee record. Operators come from **Configuration → Lists → Employees**, not from Production — with no employees the button is disabled and says so. |
| Same limits | J3, J5, J7 and QC1 apply exactly as at the terminal. The guards live in one place, so neither door is the softer one. |
| When | You state the shift it belongs to. It is **not** stamped "now" — a night shift keyed the next morning would otherwise land on the wrong day and skew utilisation. |
| Who made it | You name the operator. The work belongs to whoever ran it, not whoever typed it. |
| Why | A reason is required and stays on the row. |

Manual bookings carry a **desk** badge in the Shift bookings list, and the reason is on the
badge. That is the point: an exception you cannot tell apart from the norm stops being an
exception, and an auditor asking "how do you know this is what was made" should get a different
answer for a figure that came off the machine and one that was typed from a paper sheet.

If you find yourself using this every day, the terminal is broken and that is the thing to fix.

### Correcting a booking

Output that was booked wrongly is **reversed, not edited** — the same rule inventory follows.
The original row stays exactly as the operator recorded it; a second row with negated
quantities cancels it, and the totals move back: the operation, the job card's running totals,
and — when the reversal is on the *final* operation — the sales order line's produced quantity.

On the job card, **Shift bookings** lists what the floor recorded, newest first: which step,
how much good and waste, which operator, machine and shift. Press **Reverse** on a row and give
a reason. The reason is required and is kept on the audit trail.

| | |
|---|---|
| Who | Production supervisor (`operation.update`). An operator books their own output; they cannot un-book it. |
| Once only | A booking can be reversed once, and a reversal cannot itself be reversed. To re-book, book again. |
| Not on a closed card | Reopen the job card first. |
| Waste goes too | Waste recorded by that booking is removed with it, so a reversed figure does not stand in the waste report. |

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
