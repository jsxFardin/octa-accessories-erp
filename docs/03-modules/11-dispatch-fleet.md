# Module 11 — Finished Goods, Packing, Dispatch & Fleet

**Purpose:** receive finished goods, pack them traceably, get them out of the gate, and prove they arrived.

**Actors:** Packing supervisor, FG store keeper, Dispatch officer, Driver, Commercial (export docs).

**Tables:** `fg_receipts`, `packing_lists`, `cartons`, `carton_contents`, `delivery_challans`, `delivery_challan_lines`, `vehicles`, `drivers`, `trips`, `trip_stops`, `export_documents`.

**Rules:** BR-12 (packing quantities), BR-44 (delivery tolerance), BR-45 (order closure), BR-43 (certificate validity).
**Invariants:** D1–D3.

---

## Flow

```
Job card completes QC
   → FG receipt         (creates a finished-goods lot)
   → Packing list       (bundles → cartons → carton contents, per lot)
   → Delivery challan   (what physically leaves)
   → Trip               (own fleet, multi-drop)  or  courier / forwarder
   → POD                (signature, photo, timestamp)
   → Invoice
```

---

## Packing structure

BR-12 drives the defaults from the product spec:

```
bundle       = spec.bundle_size            default 500 labels
polybag      = 1 per bundle
carton       = spec.bundles_per_carton     default 20 bundles = 10,000 labels
```

Each carton gets a barcode. `carton_contents` names the `lot_id` for every product inside it — so a complaint about "carton 14" resolves to a weaving lot, a yarn GRN and a supplier in one query (D1 requires those lots to have passed final QC).

Carton labels print with: customer, PO, style, colour, quantity, carton n of N, barcode, and the customer's own carton-marking requirements where specified.

---

## Screens

| Screen | Route | Notes |
|---|---|---|
| FG receipt | `/dispatch/fg-receipts` | From a completed job card, with grade |
| Packing list | `/dispatch/packing-lists/{id}` | Carton builder, scan-to-pack |
| Carton labels | `/dispatch/packing-lists/{id}/labels` | Batch PDF, 100×150 mm |
| Delivery challan | `/dispatch/challans/{id}` | Lines, mode, gate pass |
| Challan print | `/dispatch/challans/{id}/pdf` | The document that travels with the goods |
| Trip planner | `/fleet/trips` | Vehicle, driver, route zone, drop sequence |
| Driver screen | `/fleet/trips/{id}/drive` | Mobile: stop list, arrive, deliver, capture POD |
| Vehicles & drivers | `/fleet/vehicles` | Fitness, tax, licence expiry alerts |
| Export documents | `/commercial/export-docs` | CI, PL, COO, EXP, BL/AWB, UD, LC |
| Dispatch dashboard | `/dispatch/dashboard` | Ready to pack, packed, in transit, delivered today |

---

## Scan-to-pack

The packing bench workflow is scanner-first:

1. Scan the job card or FG lot barcode → the packing list line opens.
2. Scan or key the bundle count into a carton → carton contents accumulate.
3. Close the carton → barcode printed, weight captured.
4. Repeat. Totals roll up to the packing list automatically.

The screen refuses to add a lot that has not passed final QC (D1) and shows why.

---

## Own fleet

The factory delivers with its own vehicles on multiple routes, so this is a first-class module, not an afterthought.

- `trips` — vehicle, driver, date, route zone, odometer start/end, fuel cost.
- `trip_stops` — ordered drops, each linked to a delivery challan and a customer address, with planned/arrived/departed timestamps.
- **POD** — receiver name, signature image, photo, timestamp captured on the driver's phone. A stop cannot be `delivered` without a POD (configurable per customer).
- Route zones come from `customer_addresses.route_zone`, so the planner can build a Gazipur run or a Chattogram run in one click.

Vehicle fitness, tax token and driver licence expiry produce alerts 30 days out — a truck stopped at a checkpoint is a late delivery.

---

## User stories

**DF-1 — Post an FG receipt**
- AC1: Only from a job card whose final QC is accepted (or accepted with concession).
- AC2: Creates a finished-goods lot inheriting the job card's certification claim (BR-40) and a barcode.
- AC3: Grade `A`, `B` or `reject`; `B` grade lots are excluded from certified claims and flagged at packing.
- AC4: Posts an `fg_receipt` ledger movement into the finished-goods warehouse.

**DF-2 — Build a packing list**
- AC1: Lines come from FG lots against a sales order.
- AC2: Carton defaults follow BR-12 but every carton is individually editable.
- AC3: A lot that failed final QC cannot be packed (D1); the block message names the inspection.
- AC4: Totals (`total_cartons`, `total_qty`, weights) are computed, not typed.
- AC5: A certification claim on the packing list is validated against the lots inside it — the claim cannot exceed the diluted input claim (BR-40, BR-41).

**DF-3 — Issue a delivery challan**
- AC1: Requires a packed packing list with at least one carton (D3).
- AC2: Quantity is validated against the order's delivery tolerance (BR-44); outside the band needs an override with a reason.
- AC3: Mode is own fleet, courier, customer pickup or freight forwarder.
- AC4: Issuing posts a `dispatch` ledger movement and increments `delivered_qty` on the order line.
- AC5: If the shipment claims a certification whose certificate is expired on the challan date, issuing is blocked and the expired certificate is named (BR-43).
- AC6: A gate pass number is recorded.

**DF-4 — Plan a trip**
- AC1: The planner selects a vehicle, driver, date and route zone.
- AC2: Unassigned challans in that zone are listed; drag to sequence the drops.
- AC3: Total weight is checked against `vehicles.capacity_kg`; overload requires an override.
- AC4: Starting the trip sets challans to `in_transit`.

**DF-5 — Deliver and capture POD**
*As a Driver I confirm each delivery.*
- AC1: The stop list shows customer, address, contact, carton count.
- AC2: "Arrived" stamps a timestamp; "Delivered" requires receiver name and signature, optionally a photo.
- AC3: A failed delivery requires a failure reason and returns the challan to the dispatch queue.
- AC4: The screen works offline and syncs when back in coverage (queued POSTs, see [07-api-contracts](../07-api-contracts.md)).
- AC5: On delivery, the challan goes `delivered` and the order line's schedule row updates.

**DF-6 — Export documentation**
- AC1: For export shipments, the system generates commercial invoice, packing list and COO drafts from the shipment data.
- AC2: EXP form, UD, BL/AWB and LC references are recorded with document numbers and files.
- AC3: A checklist shows which documents are missing before the shipment can be treated as complete.

**DF-7 — Close the loop to the order**
- AC1: `delivered_qty` rolls up to the sales order line and the delivery schedule row.
- AC2: A line auto-closes when delivered ≥ ordered × (1 − under tolerance) (BR-45).
- AC3: The order closes when all lines are closed; remaining reservations are released.

---

## Reports

| Report | Content |
|---|---|
| Ready to dispatch | FG lots passed QC, not yet packed, by due date |
| Dispatch register | Challans by date, customer, mode, value |
| Delivery performance | Promised vs actual delivery date, on-time % by customer |
| Trip log | Trips, stops, distance, fuel cost, cost per delivery |
| POD exceptions | Delivered without POD, failed deliveries, by driver |
| Vehicle compliance | Fitness, tax, licence expiries |
| Carton traceability | Carton → lots → GRNs → suppliers |
| Export document status | Missing documents by shipment |
