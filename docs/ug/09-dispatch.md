# Dispatch

**Dispatch** sits as its own sidebar group: Packing lists, Delivery notes, Trips.

You invoice what left the gate, not what was produced. The chain is packing list → delivery challan → (own-fleet trip) → invoice.

## Packing lists

**Dispatch → Packing lists.**

1. New list against a **sales order**.
2. Add cartons, then carton contents: order line, **lot**, qty.
3. Pack from FG lots that QC has accepted — not from WIP.
4. **Packed** when the list is complete.
5. **Create challan** from the packed list.

Certification claim on the list (scheme + %) must be backed by the lots inside. Issuing the challan later writes CoC output.

## Delivery challans

**Dispatch → Delivery notes.**

Created from a packing list. Mode: own fleet, courier, customer pickup, freight forwarder.

| Status | What you press | What it does |
|---|---|---|
| `draft` | **Issue** | Posts **dispatch** stock movements, updates delivered qty on the order, writes CoC output if certified. This is the stock event. |
| `issued` | **In transit** | Courier name / tracking if needed. Own-fleet trips also move challans to in transit when the trip starts. |
| `in_transit` | **Delivered** | POD reference. On a trip, capture POD per stop instead. |
| | **Return** | Reason required. Reverses dispatch and restores stock. |
| `issued` / `in_transit` / `delivered` | **Create invoice** | Accounts (permission `sales_invoice.create`). One live invoice per challan. |

Issue is blocked if a line would take the order **over the over-delivery band** (BR-44) unless you give an override reason.

There is no “type an invoice” screen. If **Create invoice** is missing, you lack permission or the challan is still draft / cancelled.

## Trips (own fleet)

**Dispatch → Trips → Plan trip.**

1. Vehicle, optional driver, date, route zone.
2. Click issued challans to add them as **stops** (sequence is the list order).
3. **Plan trip** — needs at least one stop. Assigns `TRP-` number.
4. **Start trip** — trip `in_transit`, linked challans follow.
5. Per stop: **Capture POD** (receiver name). A failure reason marks the stop failed.
6. **Complete trip** — end odometer, fuel cost.

Drivers (`driver@maheenlabel.test`) land on the trip list and see **their** trips (`trip.view_own`), not the whole fleet.

Unassigned issued challans appear on the plan-trip form. Courier / pickup challans do not need a trip.

## What you should not do

- Issue a challan to get an invoice number without the cartons leaving. Issue posts stock.
- Pack lots that are still quarantined.
- Create a second invoice for the same challan. Cancel the first (if still allowed) or use a credit note.
