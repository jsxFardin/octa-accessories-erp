<?php

declare(strict_types=1);

namespace App\Modules\Dispatch\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Dispatch\Models\Trip;
use App\Modules\Dispatch\Models\TripStop;
use App\Modules\Dispatch\States\DeliveryChallanStateMachine;
use App\Support\Http\ListsResources;
use App\Support\Numbering\NumberAllocator;
use App\Support\States\StateMachine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * DF-4 / DF-5 — trip planning, start, POD capture, completion.
 */
class TripController extends Controller
{
    use ListsResources;

    public function __construct(
        private readonly NumberAllocator $numbers,
        private readonly DeliveryChallanStateMachine $challanStates,
    ) {}

    public function index(Request $request): Response
    {
        $query = Trip::query()->with(['vehicle:id,registration_no', 'driver:id,name']);

        $user = $request->user();

        if (! $user->hasPermission('trip.view_any')) {
            $driverId = DB::table('drivers')
                ->where('employee_id', $user->employee?->id)
                ->value('id');

            $query->where('driver_id', $driverId);
        }

        $this->applyListing(
            $query,
            $request,
            searchable: ['number', 'route_zone'],
            filters: ['status' => 'status'],
            sortable: ['number', 'trip_date', 'status'],
            defaultSort: '-id',
        );

        return Inertia::render('Dispatch/Trips/Index', [
            'trips' => $query->paginate($this->perPage($request))->withQueryString()->through(
                fn (Trip $trip): array => [
                    ...$trip->only(['id', 'number', 'trip_date', 'route_zone', 'status']),
                    'vehicle' => $trip->vehicle?->registration_no,
                    'driver' => $trip->driver?->name,
                    'stops' => $trip->stops()->count(),
                ],
            ),
            'filters' => $this->listingFilters($request, ['status']),
        ]);
    }

    public function create(): Response
    {
        $vehicles = DB::table('vehicles')->where('is_active', true)->orderBy('registration_no')
            ->select(['id', 'registration_no', 'kind', 'capacity_kg'])->get();

        $drivers = DB::table('drivers')->where('is_active', true)->orderBy('name')
            ->select(['id', 'name', 'licence_no'])->get();

        /*
         * What a dispatcher routes by: where it is going, in which zone, and how much there is
         * to carry. The picker used to be a row of chips reading "DC-0042 — Customer", which is
         * enough to recognise a note and not enough to build a route from.
         *
         * Only notes going out on the factory's own vehicles are offered — a courier's parcel
         * and a customer's own pickup are not stops on our truck.
         */
        $waiting = DB::table('delivery_challans as dc')
            ->where('dc.status', 'issued')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('trip_stops')->whereColumn('delivery_challan_id', 'dc.id'));

        $otherModes = (clone $waiting)->where('dc.mode', '!=', 'own_fleet')->count();

        $challans = $waiting
            ->where('dc.mode', 'own_fleet')
            ->leftJoin('customers as c', 'c.id', '=', 'dc.customer_id')
            ->leftJoin('customer_addresses as a', 'a.id', '=', 'dc.delivery_address_id')
            ->orderBy('a.route_zone')
            ->orderBy('dc.id')
            ->select([
                'dc.id', 'dc.number', 'dc.challan_date', 'dc.total_cartons', 'dc.total_qty',
                'c.name as customer', 'a.label as address_label', 'a.line1', 'a.line2', 'a.city', 'a.route_zone',
            ])
            ->get()
            ->map(fn (object $row): array => [
                'id' => $row->id,
                'number' => $row->number,
                'challan_date' => $row->challan_date,
                'customer' => $row->customer,
                'address' => collect([$row->address_label, $row->line1, $row->line2, $row->city])->filter()->join(', ') ?: null,
                'route_zone' => $row->route_zone,
                'cartons' => (int) $row->total_cartons,
                'qty' => (float) $row->total_qty,
            ]);

        return Inertia::render('Dispatch/Trips/Form', [
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'challans' => $challans,
            'otherModes' => $otherModes,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'trip_date' => ['required', 'date'],
            'route_zone' => ['nullable', 'string', 'max:60'],
            'start_odometer' => ['nullable', 'numeric', 'min:0'],
            'remarks' => ['nullable', 'string', 'max:255'],
            'stops' => ['required', 'array', 'min:1'],
            // `distinct`: the same note twice is the same goods delivered twice.
            'stops.*.delivery_challan_id' => ['required', 'integer', 'distinct', 'exists:delivery_challans,id'],
        ], [
            'stops.required' => 'Add at least one delivery note to the trip.',
            'stops.*.delivery_challan_id.distinct' => 'A delivery note can only be on the trip once.',
        ]);

        // A note already riding on another trip, or not yet issued, has no goods to put on this one.
        $ids = array_column($data['stops'], 'delivery_challan_id');
        $taken = DB::table('trip_stops as ts')
            ->join('delivery_challans as dc', 'dc.id', '=', 'ts.delivery_challan_id')
            ->whereIn('ts.delivery_challan_id', $ids)
            ->pluck('dc.number');
        $notIssued = DB::table('delivery_challans')->whereIn('id', $ids)->where('status', '!=', 'issued')->pluck('number');

        if ($taken->isNotEmpty() || $notIssued->isNotEmpty()) {
            throw ValidationException::withMessages(['stops' => trim(
                ($taken->isNotEmpty() ? 'Already on another trip: '.$taken->join(', ').'. ' : '')
                .($notIssued->isNotEmpty() ? 'Not issued, so there is nothing to deliver yet: '.$notIssued->map(fn ($n) => $n ?? 'a draft')->join(', ').'.' : ''),
            )]);
        }

        $trip = DB::transaction(function () use ($data): Trip {
            /** @var Trip $trip */
            $trip = new Trip;
            $trip->forceFill([
                'number' => $this->numbers->next('trip'),
                'vehicle_id' => $data['vehicle_id'],
                'driver_id' => $data['driver_id'] ?? null,
                'trip_date' => $data['trip_date'],
                'route_zone' => $data['route_zone'] ?? null,
                'start_odometer' => $data['start_odometer'] ?? null,
                'fuel_cost' => 0,
                'status' => 'planned',
                'remarks' => $data['remarks'] ?? null,
            ])->save();

            foreach ($data['stops'] as $index => $stop) {
                $challan = DeliveryChallan::query()->findOrFail($stop['delivery_challan_id']);

                $stopModel = new TripStop;
                $stopModel->forceFill([
                    'trip_id' => $trip->id,
                    'sequence_no' => $index + 1,
                    'delivery_challan_id' => $challan->id,
                    'customer_id' => $challan->customer_id,
                    // Where the stop is. The column was never filled, so the driver's screen
                    // had a customer's name and no address to drive to.
                    'address_id' => $challan->delivery_address_id,
                    'status' => 'pending',
                ])->save();
            }

            return $trip;
        });

        return redirect()
            ->route('trips.show', $trip)
            ->with('success', "Trip {$trip->number} planned.");
    }

    public function show(Trip $trip): Response
    {
        $trip->load(['vehicle', 'driver', 'stops']);

        $challanIds = $trip->stops->pluck('delivery_challan_id')->filter()->all();
        $challans = DB::table('delivery_challans')
            ->whereIn('id', $challanIds)
            ->get(['id', 'number', 'customer_id', 'delivery_address_id', 'status', 'total_cartons', 'total_qty'])
            ->keyBy('id');

        $customerIds = $trip->stops->pluck('customer_id')->filter()->all();
        $customers = DB::table('customers')
            ->whereIn('id', $customerIds)
            ->get(['id', 'code', 'name', 'phone'])
            ->keyBy('id');

        // Who to ring at the gate: the customer's primary contact, else the first one with a number.
        $contacts = DB::table('customer_contacts')
            ->whereIn('customer_id', $customerIds)
            ->whereNotNull('phone')
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get(['customer_id', 'name', 'phone'])
            ->unique('customer_id')
            ->keyBy('customer_id');

        // The stop's own address, or — for trips planned before stops recorded one — the note's.
        $addressIds = $trip->stops->pluck('address_id')
            ->merge($challans->pluck('delivery_address_id'))
            ->filter()->unique()->all();
        $addresses = DB::table('customer_addresses')
            ->whereIn('id', $addressIds)
            ->get(['id', 'label', 'line1', 'line2', 'city', 'route_zone'])
            ->keyBy('id');

        return Inertia::render('Dispatch/Trips/Show', [
            'trip' => [
                ...$trip->only(['id', 'number', 'trip_date', 'route_zone', 'status',
                    'started_at', 'completed_at', 'start_odometer', 'end_odometer', 'fuel_cost', 'remarks']),
                'vehicle' => $trip->vehicle?->registration_no,
                'driver' => $trip->driver?->name,
            ],
            'stops' => $trip->stops->sortBy('sequence_no')->values()->map(function (TripStop $stop) use ($challans, $customers, $contacts, $addresses): array {
                $challan = $stop->delivery_challan_id ? $challans->get($stop->delivery_challan_id) : null;
                $customer = $stop->customer_id ? $customers->get($stop->customer_id) : null;
                $contact = $stop->customer_id ? $contacts->get($stop->customer_id) : null;
                $address = $addresses->get($stop->address_id ?? $challan?->delivery_address_id);

                return [
                    ...$stop->only(['id', 'sequence_no', 'status', 'arrived_at', 'departed_at',
                        'received_by_name', 'pod_captured_at', 'failure_reason']),
                    'challan_number' => $challan->number ?? null,
                    'challan_id' => $stop->delivery_challan_id,
                    'customer' => $customer->name ?? null,
                    'address' => $address === null ? null : collect([$address->label, $address->line1, $address->line2, $address->city])->filter()->join(', '),
                    'route_zone' => $address->route_zone ?? null,
                    'contact_name' => $contact->name ?? null,
                    'phone' => $contact->phone ?? $customer->phone ?? null,
                    'cartons' => (int) ($challan->total_cartons ?? 0),
                    'qty' => (float) ($challan->total_qty ?? 0),
                ];
            })->all(),
        ]);
    }

    /**
     * Put a planned trip's stops in a new order.
     *
     * The order was fixed by the order the notes were clicked in, and there is no edit screen
     * for a trip — so a route planned in the wrong order stayed wrong. Only while the trip is
     * still planned: once the vehicle has left, the order is history.
     */
    public function reorder(Request $request, Trip $trip): RedirectResponse
    {
        if ($trip->status !== 'planned') {
            return back()->with('error', 'The order of stops can only be changed before the trip starts.');
        }

        $request->validate([
            'stops' => ['required', 'array', 'min:1'],
            'stops.*' => ['required', 'integer', 'distinct'],
        ]);

        $existing = $trip->stops()->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
        /** @var list<int> $asked */
        $asked = array_map(intval(...), array_values($request->array('stops')));
        $sorted = $asked;
        sort($sorted);

        if ($sorted !== $existing) {
            return back()->with('error', 'The stops on this trip have changed. Reload the page and try again.');
        }

        DB::transaction(function () use ($asked, $trip): void {
            // (trip, sequence) is unique, so two stops cannot hold the same place even for a
            // moment: everything is moved out of the way first, then set down in its new place.
            TripStop::query()->where('trip_id', $trip->id)->update(['sequence_no' => DB::raw('sequence_no + 10000')]);

            foreach ($asked as $index => $stopId) {
                TripStop::query()->whereKey($stopId)->update(['sequence_no' => $index + 1]);
            }
        });

        return back()->with('success', 'Stop order saved.');
    }

    /** DF-4 AC4 — start the trip; challans go in_transit. */
    public function start(Request $request, Trip $trip): RedirectResponse
    {
        if ($trip->status !== 'planned') {
            return back()->with('error', 'Only a planned trip can be started.');
        }

        // Read off the dashboard as the vehicle leaves — the only moment the figure is known.
        // The column existed and the completion dialog asks for the end reading, but nothing
        // ever asked for the start, so distance per trip could not be worked out.
        $data = $request->validate([
            'start_odometer' => ['nullable', 'numeric', 'min:0'],
        ]);

        DB::transaction(function () use ($trip, $data): void {
            $trip->forceFill([
                'status' => 'in_transit',
                'started_at' => now(),
                'start_odometer' => $data['start_odometer'] ?? $trip->start_odometer,
            ])->save();

            foreach ($trip->stops()->get() as $stop) {
                if ($stop->delivery_challan_id !== null) {
                    $challan = DeliveryChallan::query()->find($stop->delivery_challan_id);

                    if ($challan !== null && $this->challanStates->can($challan, 'in_transit')) {
                        $this->challanStates->transition($challan, 'in_transit');
                    }
                }
            }
        });

        return back()->with('success', 'Trip started.');
    }

    /** DF-5 — deliver a stop with POD. */
    public function deliver(Request $request, Trip $trip, TripStop $stop): RedirectResponse
    {
        if ((int) $stop->trip_id !== (int) $trip->id) {
            abort(404);
        }

        if ($stop->status !== 'pending' && $stop->status !== 'arrived') {
            return back()->with('error', "Stop is already {$stop->status}.");
        }

        $data = $request->validate([
            // A failed drop has no receiver to name. Requiring one regardless meant a failure
            // could only be recorded by inventing somebody.
            'received_by_name' => ['nullable', 'required_without:failure_reason', 'string', 'max:150'],
            'failure_reason' => ['nullable', 'string', 'max:255'],
        ], [
            'received_by_name.required_without' => 'Enter the name of the person who received the goods.',
        ]);

        $failed = isset($data['failure_reason']) && $data['failure_reason'] !== '';

        DB::transaction(function () use ($stop, $data, $failed): void {
            if ($failed) {
                $stop->forceFill([
                    'status' => 'failed',
                    'failure_reason' => $data['failure_reason'],
                    'departed_at' => now(),
                ])->save();

                // The goods are back on the truck, so the challan is a return: stock comes
                // back to the lot, delivered_qty falls, the certified claim is withdrawn and
                // a credit note is drafted if it was already invoiced. Recording a failed
                // drop while the sales order still read "delivered" was a lie with the stock
                // already gone. The driver holds three permissions and `delivery_challan.return`
                // is not among them — the return is the system's consequence of their POD.
                if ($stop->delivery_challan_id !== null) {
                    $challan = DeliveryChallan::query()->find($stop->delivery_challan_id);

                    if ($challan !== null && in_array($challan->status, ['issued', 'in_transit'], true)) {
                        StateMachine::asSystem(fn () => $this->challanStates->transition(
                            $challan,
                            'returned',
                            ['return_reason' => "Delivery failed at stop {$stop->sequence_no}: {$data['failure_reason']}"],
                        ));
                    }
                }

                return;
            }

            $stop->forceFill([
                'status' => 'delivered',
                'received_by_name' => $data['received_by_name'],
                'arrived_at' => $stop->arrived_at ?? now(),
                'departed_at' => now(),
                'pod_captured_at' => now(),
            ])->save();

            if ($stop->delivery_challan_id !== null) {
                $challan = DeliveryChallan::query()->find($stop->delivery_challan_id);

                if ($challan !== null && $this->challanStates->can($challan, 'delivered')) {
                    $this->challanStates->transition($challan, 'delivered');
                }
            }
        });

        return back()->with('success', $failed
            ? 'Stop marked as not delivered. The goods on its delivery note are returned to stock.'
            : 'Stop delivered.');
    }

    /** Complete the trip when all stops are done. */
    public function complete(Request $request, Trip $trip): RedirectResponse
    {
        if ($trip->status !== 'in_transit') {
            return back()->with('error', 'Only an in-transit trip can be completed.');
        }

        $pending = $trip->stops()->whereNotIn('status', ['delivered', 'failed'])->count();

        if ($pending > 0) {
            return back()->with('error', "{$pending} stop(s) still pending.");
        }

        $data = $request->validate([
            'end_odometer' => ['nullable', 'numeric', 'min:0', ...($trip->start_odometer !== null ? ['gte:'.(float) $trip->start_odometer] : [])],
            'fuel_cost' => ['nullable', 'numeric', 'min:0'],
        ], [
            'end_odometer.gte' => 'The end reading cannot be less than the start reading ('.(float) $trip->start_odometer.' km).',
        ]);

        $trip->forceFill([
            'status' => 'completed',
            'completed_at' => now(),
            'end_odometer' => $data['end_odometer'] ?? null,
            'fuel_cost' => $data['fuel_cost'] ?? $trip->fuel_cost,
        ])->save();

        return back()->with('success', 'Trip completed.');
    }
}
