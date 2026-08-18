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
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $challans = DB::table('delivery_challans as dc')
            ->leftJoin('customers as c', 'c.id', '=', 'dc.customer_id')
            ->where('dc.status', 'issued')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('trip_stops')->whereColumn('delivery_challan_id', 'dc.id'))
            ->orderBy('dc.id')
            ->select(['dc.id', 'dc.number', 'c.name as customer', 'dc.mode'])
            ->get();

        return Inertia::render('Dispatch/Trips/Form', [
            'vehicles' => $vehicles,
            'drivers' => $drivers,
            'challans' => $challans,
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
            'stops.*.delivery_challan_id' => ['required', 'integer', 'exists:delivery_challans,id'],
        ]);

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
            ->get(['id', 'number', 'customer_id', 'status'])
            ->keyBy('id');

        $customerIds = $trip->stops->pluck('customer_id')->filter()->all();
        $customers = DB::table('customers')
            ->whereIn('id', $customerIds)
            ->get(['id', 'code', 'name'])
            ->keyBy('id');

        return Inertia::render('Dispatch/Trips/Show', [
            'trip' => [
                ...$trip->only(['id', 'number', 'trip_date', 'route_zone', 'status',
                    'started_at', 'completed_at', 'start_odometer', 'end_odometer', 'fuel_cost', 'remarks']),
                'vehicle' => $trip->vehicle?->registration_no,
                'driver' => $trip->driver?->name,
            ],
            'stops' => $trip->stops->map(function (TripStop $stop) use ($challans, $customers): array {
                $challan = $stop->delivery_challan_id ? $challans->get($stop->delivery_challan_id) : null;
                $customer = $stop->customer_id ? $customers->get($stop->customer_id) : null;

                return [
                    ...$stop->only(['id', 'sequence_no', 'status', 'arrived_at', 'departed_at',
                        'received_by_name', 'pod_captured_at', 'failure_reason']),
                    'challan_number' => $challan->number ?? null,
                    'challan_id' => $stop->delivery_challan_id,
                    'customer' => $customer->name ?? null,
                ];
            })->all(),
        ]);
    }

    /** DF-4 AC4 — start the trip; challans go in_transit. */
    public function start(Request $request, Trip $trip): RedirectResponse
    {
        if ($trip->status !== 'planned') {
            return back()->with('error', 'Only a planned trip can be started.');
        }

        DB::transaction(function () use ($trip): void {
            $trip->forceFill([
                'status' => 'in_transit',
                'started_at' => now(),
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
            'received_by_name' => ['required', 'string', 'max:150'],
            'failure_reason' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($stop, $data): void {
            if (isset($data['failure_reason']) && $data['failure_reason'] !== '') {
                $stop->forceFill([
                    'status' => 'failed',
                    'failure_reason' => $data['failure_reason'],
                    'departed_at' => now(),
                ])->save();

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

        return back()->with('success', 'Stop delivered.');
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
            'end_odometer' => ['nullable', 'numeric', 'min:0'],
            'fuel_cost' => ['nullable', 'numeric', 'min:0'],
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
