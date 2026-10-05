<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\PackingList;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * UX audit, dispatch workstream (H-37, M-28, M-29).
 *
 * The dispatcher could not build a route — delivery notes were chips with no address, in the
 * order they were clicked — and the driver's stop had a customer's name and nothing to act on.
 * Cartons were added one request at a time, and every note went out as "own fleet".
 */
beforeEach(function (): void {
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());

    $this->customerId = (int) DB::table('customers')->orderBy('id')->value('id');
    $this->addressId = (int) DB::table('customer_addresses')->where('customer_id', $this->customerId)->value('id');
    // The demo seed has no fleet.
    $this->vehicleId = (int) DB::table('vehicles')->insertGetId([
        'registration_no' => 'DHAKA-METRO-TA-11-0001', 'kind' => 'covered_van', 'is_owned' => true, 'is_active' => true,
    ]);

    /** An issued delivery note, ready for a trip. */
    $this->note = function (string $number, string $mode = 'own_fleet'): int {
        return (int) DB::table('delivery_challans')->insertGetId([
            'number' => $number,
            'customer_id' => $this->customerId,
            'delivery_address_id' => $this->addressId,
            'challan_date' => now()->toDateString(),
            'mode' => $mode,
            'total_cartons' => 3,
            'total_qty' => 3000,
            'status' => 'issued',
            'created_at' => now(),
        ]);
    };

    $this->plan = fn (array $noteIds, array $extra = []) => $this->post('/trips', [
        'vehicle_id' => $this->vehicleId,
        'trip_date' => now()->toDateString(),
        'stops' => array_map(fn (int $id): array => ['delivery_challan_id' => $id], $noteIds),
        ...$extra,
    ]);
});

it('offers the planner each waiting note with its address, zone and load', function (): void {
    $own = ($this->note)('DC-T-1');
    $courier = ($this->note)('DC-T-2', 'courier');

    $this->get('/trips/create')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Dispatch/Trips/Form')
        ->where('challans', function ($challans) use ($own, $courier): bool {
            $row = collect($challans)->firstWhere('id', $own);

            return $row !== null
                && filled($row['address'])
                && array_key_exists('route_zone', $row)
                && $row['cartons'] === 3
                && (float) $row['qty'] === 3000.0
                // A courier's parcel is not a stop on our vehicle.
                && collect($challans)->firstWhere('id', $courier) === null;
        })
        ->where('otherModes', fn ($count) => $count >= 1));
});

it('keeps the order the stops were placed in, and records where each one is', function (): void {
    $first = ($this->note)('DC-T-1');
    $second = ($this->note)('DC-T-2');

    ($this->plan)([$second, $first], ['start_odometer' => 41250])->assertSessionHasNoErrors();

    $tripId = (int) DB::table('trips')->max('id');
    $stops = DB::table('trip_stops')->where('trip_id', $tripId)->orderBy('sequence_no')->get();

    expect($stops->pluck('delivery_challan_id')->all())->toBe([$second, $first])
        ->and($stops->pluck('address_id')->unique()->all())->toBe([$this->addressId])
        ->and((float) DB::table('trips')->where('id', $tripId)->value('start_odometer'))->toBe(41250.0);
});

it('refuses a note that is already on a trip, twice on this one, or not issued', function (): void {
    $note = ($this->note)('DC-T-1');
    ($this->plan)([$note])->assertSessionHasNoErrors();

    ($this->plan)([$note])->assertSessionHasErrors('stops');

    $other = ($this->note)('DC-T-2');
    ($this->plan)([$other, $other])->assertSessionHasErrors();

    $draft = ($this->note)('DC-T-3');
    DB::table('delivery_challans')->where('id', $draft)->update(['status' => 'draft']);
    ($this->plan)([$draft])->assertSessionHasErrors('stops');

    expect(DB::table('trip_stops')->whereIn('delivery_challan_id', [$other, $draft])->count())->toBe(0);
});

it('gives the driver the address, a phone number and the load for each stop', function (): void {
    DB::table('customer_contacts')->insert([
        'customer_id' => $this->customerId, 'name' => 'Gate Store', 'phone' => '+880 1711 000000', 'is_primary' => true,
    ]);

    ($this->plan)([($this->note)('DC-T-1')])->assertSessionHasNoErrors();
    $tripId = (int) DB::table('trips')->max('id');

    $this->get("/trips/{$tripId}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Dispatch/Trips/Show')
        ->where('stops.0.challan_number', 'DC-T-1')
        ->where('stops.0.address', fn ($address) => filled($address))
        ->where('stops.0.phone', '+880 1711 000000')
        ->where('stops.0.contact_name', 'Gate Store')
        ->where('stops.0.cartons', 3));
});

it('reorders the stops of a planned trip, and of no other', function (): void {
    ($this->plan)([($this->note)('DC-T-1'), ($this->note)('DC-T-2'), ($this->note)('DC-T-3')])->assertSessionHasNoErrors();
    $tripId = (int) DB::table('trips')->max('id');
    $ids = DB::table('trip_stops')->where('trip_id', $tripId)->orderBy('sequence_no')->pluck('id')->all();

    $this->post("/trips/{$tripId}/stops/order", ['stops' => [$ids[2], $ids[0], $ids[1]]])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');

    expect(DB::table('trip_stops')->where('trip_id', $tripId)->orderBy('sequence_no')->pluck('id')->all())
        ->toBe([$ids[2], $ids[0], $ids[1]])
        ->and(DB::table('trip_stops')->where('trip_id', $tripId)->orderBy('sequence_no')->pluck('sequence_no')->all())
        ->toBe([1, 2, 3]);

    // A list that is not exactly this trip's stops changes nothing.
    $this->post("/trips/{$tripId}/stops/order", ['stops' => [$ids[0], $ids[1]]])->assertSessionHas('error');

    // Once the vehicle has left, the order is history.
    DB::table('trips')->where('id', $tripId)->update(['status' => 'in_transit']);
    $this->post("/trips/{$tripId}/stops/order", ['stops' => $ids])->assertSessionHas('error');

    expect(DB::table('trip_stops')->where('trip_id', $tripId)->orderBy('sequence_no')->pluck('id')->all())
        ->toBe([$ids[2], $ids[0], $ids[1]]);
});

it('takes the odometer reading when the trip starts, and refuses an end reading below it', function (): void {
    ($this->plan)([($this->note)('DC-T-1')])->assertSessionHasNoErrors();
    $tripId = (int) DB::table('trips')->max('id');

    $this->post("/trips/{$tripId}/start", ['start_odometer' => 50100])->assertSessionHasNoErrors();

    $trip = DB::table('trips')->where('id', $tripId)->first();
    expect($trip->status)->toBe('in_transit')->and((float) $trip->start_odometer)->toBe(50100.0);

    DB::table('trip_stops')->where('trip_id', $tripId)->update(['status' => 'delivered']);

    $this->post("/trips/{$tripId}/complete", ['end_odometer' => 50000])->assertSessionHasErrors('end_odometer');
    $this->post("/trips/{$tripId}/complete", ['end_odometer' => 50180])->assertSessionHasNoErrors();

    expect(DB::table('trips')->where('id', $tripId)->value('status'))->toBe('completed');
});

it('adds many cartons in one request, numbered on from the last', function (): void {
    $list = PackingList::query()->create([
        'customer_id' => $this->customerId,
        'packed_on' => now()->toDateString(),
        'status' => 'draft',
    ]);

    $this->post("/packing-lists/{$list->id}/cartons", ['gross_weight_kg' => 12.5, 'count' => 53])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success', '53 cartons added (numbers 1 to 53).');

    $this->post("/packing-lists/{$list->id}/cartons", [])->assertSessionHas('success', 'Carton 54 added.');

    $cartons = DB::table('cartons')->where('packing_list_id', $list->id);

    expect($cartons->count())->toBe(54)
        ->and((clone $cartons)->distinct()->count('barcode'))->toBe(54)
        ->and((float) (clone $cartons)->where('carton_no', '53')->value('gross_weight_kg'))->toBe(12.5);

    $this->post("/packing-lists/{$list->id}/cartons", ['count' => 500])->assertSessionHasErrors('count');
    expect(DB::table('cartons')->where('packing_list_id', $list->id)->count())->toBe(54);
});

it('records how a delivery note travels, and who carries it', function (): void {
    $list = PackingList::query()->create([
        'customer_id' => $this->customerId,
        'packed_on' => now()->toDateString(),
        'status' => 'packed',
    ]);

    // No longer assumed: a note with no mode is refused.
    $this->post('/delivery-challans', ['packing_list_id' => $list->id])->assertSessionHasErrors('mode');

    $this->post('/delivery-challans', [
        'packing_list_id' => $list->id,
        'mode' => 'courier',
        'courier_name' => 'Sundarban Courier',
        'tracking_no' => 'SB-1234',
    ])->assertSessionHasNoErrors();

    $note = DB::table('delivery_challans')->where('packing_list_id', $list->id)->first();

    expect($note->mode)->toBe('courier')
        ->and($note->courier_name)->toBe('Sundarban Courier')
        ->and($note->tracking_no)->toBe('SB-1234');
});

/*
 * UX audit H-52. The driver's list showed their trips and every one of them opened to "403":
 * the page asked for a permission the driver role does not hold.
 */
it('lets a driver open and record their own trip, and nobody else\'s', function (): void {
    $driver = User::query()->where('email', 'driver@octapussolution.com')->firstOrFail();
    $mine = DB::table('drivers')->where('employee_id', $driver->employee?->id)->value('id')
        ?? DB::table('drivers')->insertGetId(['name' => 'Test Driver', 'employee_id' => $driver->employee?->id, 'is_active' => true]);
    $other = DB::table('drivers')->insertGetId(['name' => 'Someone Else', 'is_active' => true]);

    ($this->plan)([($this->note)('DC-T-1')], ['driver_id' => $mine])->assertSessionHasNoErrors();
    $own = (int) DB::table('trips')->max('id');
    ($this->plan)([($this->note)('DC-T-2')], ['driver_id' => $other])->assertSessionHasNoErrors();
    $theirs = (int) DB::table('trips')->max('id');

    DB::table('trips')->whereIn('id', [$own, $theirs])->update(['status' => 'in_transit']);

    $this->actingAs($driver);

    $this->get("/trips/{$own}")->assertOk();
    $this->get("/trips/{$theirs}")->assertForbidden();

    $stop = fn (int $tripId): int => (int) DB::table('trip_stops')->where('trip_id', $tripId)->value('id');

    $this->post("/trips/{$theirs}/stops/{$stop($theirs)}/deliver", ['received_by_name' => 'Gate'])->assertForbidden();
    expect(DB::table('trip_stops')->where('id', $stop($theirs))->value('status'))->toBe('pending');
});
