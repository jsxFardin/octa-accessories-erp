# Inventory

**Inventory** is a hub. Tabs: Stock enquiry, Lots, Material issues, Transfers, Adjustments, Physical counts, Items.

Stock never changes because someone edited a quantity field. It changes because a **document posted** (GRN, issue, transfer, adjustment, count, dispatch, FG receipt).

## Stock enquiry

On-hand by item / warehouse / lot, with a ledger that must reconcile to the lot. If enquiry and ledger disagree, that is a defect — tell implementers; do not “fix” it with an adjustment until you know why.

## Lots

Each receipt or FG receipt is a lot (`LOT-…`) with status (`available`, frozen during a count, consumed, quarantined…). Open a lot for genealogy (where it came from, where it went) and the movement ledger.

You do not create lots by typing. Receive a GRN or post an FG receipt.

Expiry on inks/chemicals: Settings `expiry_alert_days` flags them before they die (BR-39). Do not issue expired lots.

## Material issues

**Inventory → Material issues** (and returns).

1. Job card, warehouse, issue vs return.
2. Lines: item, lot, qty. The screen suggests lots (BR-37) — prefer what it suggests unless you have a reason.
3. Only **available** lots. Frozen (mid-count) and quarantined lots will refuse.
4. **Post issue** — stock leaves, job card sees the issue.

Returns reverse an issue onto the lot. You cannot return more than was issued.

Negative stock is refused (BR-38). If post fails, you do not have the qty — not “the server is slow”.

## Transfers

**Transfers** tab.

Draft: from warehouse → to warehouse, lines with lots and qty. Source and destination must differ; qty must be within free qty.

- **Dispatch** (from the document) posts into a **transit** warehouse.
- **Receive** posts a child lot into the destination.

Do not treat a transfer as an adjustment.

## Adjustments

Write-on / write-off with a **reason**. Draft posts nothing.

Approval band: store manager up to `adjustment_approval_band_manager`; above that, MD. Pending adjustments appear on their dashboard queue.

## Physical counts

**Physical counts** tab.

1. **Open count** on a warehouse — this **freezes** available lots in that warehouse. Issues against them will fail until you finish or cancel.
2. Print the **blind count sheet** (no system qty).
3. Enter counted qty per lot.
4. Reconcile, then **post** — variances become `count_variance` movements.
5. Frozen lots unfreeze.

Cancel an open/counting document if you opened it by mistake; do not leave a warehouse frozen over a weekend.

## Items

Item master: category, UoM, class (yarn, ink, packing, FG…). Technical attributes (yield, width) feed consumption. Prefer **deactivate** over delete.

## What you should not do

- Issue from a lot you can see on screen but that is frozen or quarantined.
- Use adjustments to hide a GRN mistake. Reverse or count.
- Start two physical counts on the same lots.
