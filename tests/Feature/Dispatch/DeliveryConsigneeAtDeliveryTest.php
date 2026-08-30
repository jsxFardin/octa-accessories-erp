<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Dispatch\States\DeliveryChallanStateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * D4 at the point of delivery, not only at the point of issue.
 *
 * The reported symptom was a challan reading `Delivered` above the words "This challan cannot
 * be issued until the order names a delivery address (D4)". Both statements were true, which
 * is the defect: D4 was checked on `draft → issued` and on no other transition, so
 * `issued → delivered` and `in_transit → delivered` walked straight past it. Two challans in
 * the live database are `delivered` with no delivery address at all.
 *
 * Re-checking at delivery is not belt-and-braces. An address can be removed from the order
 * after the challan is issued, and a row can be created directly at `issued` without ever
 * meeting the issue guard — which is how the historical pair got there. Delivery is the last
 * moment the paperwork still means anything.
 */
beforeEach(function (): void {
    $this->dispatcher = User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail();
    $this->states = app(DeliveryChallanStateMachine::class);
    $this->actingAs($this->dispatcher);

    // The walkthrough seed stops short of dispatch, so a valid issued challan is built: a real
    // customer, a delivery address that is genuinely theirs, and the order it fulfils. Built
    // rather than found, because a skipped test proves nothing and a fixture that skips is how
    // a rule quietly stops being covered.
    $this->customer = DB::table('customers')->firstOrFail();

    $this->address = DB::table('customer_addresses')
        ->where('customer_id', $this->customer->id)->value('id')
        ?? DB::table('customer_addresses')->insertGetId([
            'customer_id' => $this->customer->id,
            'label' => 'QA delivery point',
            'kind' => 'delivery',
            'line1' => '1 Test Road',
            'city' => 'Dhaka',
            'country' => 'BD',
            'is_default' => 0,
        ]);

    $this->order = DB::table('sales_orders')->where('customer_id', $this->customer->id)->first()
        ?? DB::table('sales_orders')->first();

    // A dispatched packing list behind it, because delivery moves the list on too — the
    // challan is not a document that stands on its own.
    $this->packingList = DB::table('packing_lists')->insertGetId([
        'number' => 'PL-TEST-0001',
        'customer_id' => $this->customer->id,
        'delivery_address_id' => $this->address,
        'packed_on' => now()->toDateString(),
        'status' => 'dispatched',
        'created_by' => $this->dispatcher->id,
    ]);

    $this->challan = DeliveryChallan::query()->create([
        'number' => 'DC-TEST-0001',
        'customer_id' => $this->customer->id,
        'delivery_address_id' => $this->address,
        'packing_list_id' => $this->packingList,
        'sales_order_id' => $this->order?->customer_id === $this->customer->id ? $this->order->id : null,
        'challan_date' => now()->toDateString(),
        'status' => 'issued',
        'created_by' => $this->dispatcher->id,
    ]);
});

it('d4: refuses to mark a challan delivered when it has no delivery address', function (): void {
    $this->challan->forceFill(['delivery_address_id' => null])->save();

    expect(fn () => $this->states->transition($this->challan, 'delivered'))
        ->toThrow(TransitionDenied::class);

    expect($this->challan->refresh()->status)->not->toBe('delivered');
});

it('d4: says the challan cannot be marked delivered, not that it cannot be issued', function (): void {
    // The wording is the finding: a document that has already been issued was being told it
    // could not be issued.
    $this->challan->forceFill(['delivery_address_id' => null])->save();

    try {
        $this->states->transition($this->challan, 'delivered');
        $this->fail('The delivery should have been refused.');
    } catch (TransitionDenied $e) {
        expect($e->getMessage())->toContain('cannot be marked delivered')
            ->and($e->getMessage())->toContain('no delivery address');
    }
});

it('d4: refuses delivery when the address belongs to another customer', function (): void {
    $otherCustomerId = DB::table('customers')->where('id', '!=', $this->customer->id)->value('id')
        ?? DB::table('customers')->insertGetId([
            'code' => 'QA-OTHER',
            'name' => 'QA Other Buyer',
            'is_active' => 1,
        ]);

    $other = DB::table('customer_addresses')->insertGetId([
        'customer_id' => $otherCustomerId,
        'label' => 'Somebody else',
        'kind' => 'delivery',
        'line1' => '2 Other Road',
        'city' => 'Dhaka',
        'country' => 'BD',
        'is_default' => 0,
    ]);

    $this->challan->forceFill(['delivery_address_id' => $other])->save();

    expect(fn () => $this->states->transition($this->challan, 'delivered'))
        ->toThrow(TransitionDenied::class);
});

it('d4: allows delivery when the consignee is complete', function (): void {
    // The rule must not start refusing deliveries that were always valid.
    $this->states->transition($this->challan, 'delivered');

    expect($this->challan->refresh()->status)->toBe('delivered');
});

it('d4: cannot be bypassed by posting the transition directly', function (): void {
    // A disabled button is not the rule. This is the same request the UI makes, with no UI.
    $this->challan->forceFill(['delivery_address_id' => null])->save();

    $this->actingAs($this->dispatcher)
        ->post("/delivery-challans/{$this->challan->id}/transition", ['to' => 'delivered']);

    expect($this->challan->refresh()->status)->not->toBe('delivered');
});

it('d4: cannot be reached through in_transit either', function (): void {
    $this->challan->forceFill(['status' => 'in_transit', 'delivery_address_id' => null])->save();

    expect(fn () => $this->states->transition($this->challan, 'delivered'))
        ->toThrow(TransitionDenied::class);

    expect($this->challan->refresh()->status)->toBe('in_transit');
});

it('d4: leaves historical delivered challans exactly as they are', function (): void {
    // The two live records that predate this guard are dispatch history with stock movements
    // behind them. They are explained on screen, not rewritten.
    $historical = DeliveryChallan::query()
        ->where('status', 'delivered')
        ->whereNull('delivery_address_id')
        ->count();

    $this->states->transition($this->challan, 'delivered');

    expect(DeliveryChallan::query()->where('status', 'delivered')->whereNull('delivery_address_id')->count())
        ->toBe($historical);
});
