# Production

Planning, job cards, MRP, and the shop-floor terminal. Desk users live under **Floor**. Operators live on `/floor`.

## Planning board

**Floor → Planning board.**

Load by machine / day. Drag or assign job-card operations into capacity. An operation cannot be scheduled before its predecessor unless the routing allows parallel.

This is the planner’s morning screen. The board does not start the machine; the operator (or supervisor) does.

## MRP

**Floor → MRP.**

Run MRP against confirmed demand. It proposes requisitions for shortages. Review, then raise PRs — MRP does not silently place POs.

## Job cards

**Floor → Job cards.** The seeded demo card is **draft** against the Nordic Apparel order.

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

## Shop-floor terminal

**URL:** `/floor`  
**Who:** operator (badge login). Supervisor may also work from the desk job card.

After sign-in you get a **queue** for your machine (or all machines). Tap an operation:

| Action | Meaning |
|---|---|
| Start | Operation → running. Sequence guards still apply. |
| Pause | With a downtime reason when asked. |
| Finish | Good qty (and waste if prompted). Predecessor must be complete. |
| Offline | If wifi drops, the terminal queues the action for up to four hours and replays it. Do not reboot to “fix” a pending queue. |

Bangla labels are the default for operators. Output you type is the shop-floor truth; the job card on the desk updates from it.

Demo: badge `BADGE-0009`, PIN `0009`.

## What you should not do

- Release a job card to “get it on the board” without approved artwork. The database will refuse it even if the UI were bypassed.
- Start operation 3 before operation 2 unless the routing says parallel.
- Receive finished goods into a raw-material warehouse.
- Share an operator badge. The card is the person for audit.
