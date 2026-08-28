<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\States\PurchaseOrderStateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * BR-51 — an approval band is a base-currency figure, so a document raised in another currency
 * has to be converted before it is measured against one.
 *
 * The band's own docblock said "a 400,000 **BDT** order", and the guard compared it against
 * `purchase_orders.total`, which is in whatever currency the order was raised in. At the
 * seeded rate of 122.5 a USD 1,000 order is BDT 122,500 — comfortably over a BDT 100,000
 * manager band — and it passed as the number `1000`. A purchase manager could approve, alone,
 * an order that needed the Managing Director. That is an authorisation bypass reached by
 * choosing a currency, not by holding a permission.
 *
 * The same shape sat on the credit-note band and on the work queue that decides whose list a
 * pending order appears in; all three now convert at the rate the document itself records
 * (BR-22).
 */
beforeEach(function (): void {
    $this->manager = User::query()->where('email', 'purchasemanager@maheenlabel.test')->firstOrFail();
    $this->md = User::query()->where('email', 'md@maheenlabel.test')->firstOrFail();
    $this->states = app(PurchaseOrderStateMachine::class);

    $this->band = 100000.0;
    DB::table('settings')->updateOrInsert(
        ['key' => 'po_approval_band_manager'],
        ['value' => (string) $this->band],
    );
    cache()->flush();

    $this->foreign = DB::table('currencies')->where('is_base', false)->firstOrFail();
    $this->base = DB::table('currencies')->where('is_base', true)->firstOrFail();

    $supplier = DB::table('suppliers')->firstOrFail();
    DB::table('suppliers')->where('id', $supplier->id)->update(['is_approved' => true]);
    $item = DB::table('items')->whereNotNull('base_uom_id')->firstOrFail();
    $unit = DB::table('factory_units')->where('is_active', true)->value('id');

    $this->order = function (int $currencyId, float $rate, float $total) use ($supplier, $item, $unit): PurchaseOrder {
        $order = PurchaseOrder::query()->create([
            'supplier_id' => $supplier->id,
            'factory_unit_id' => $unit,
            'order_date' => now()->toDateString(),
            'currency_id' => $currencyId,
            'exchange_rate' => $rate,
            'subtotal' => $total,
            'total' => $total,
            'status' => 'pending_approval',
            'created_by' => $this->manager->id,
        ]);

        DB::table('purchase_order_lines')->insert([
            'po_id' => $order->id, 'line_no' => 1, 'item_id' => $item->id,
            'description' => 'Band test', 'qty' => 1, 'uom_id' => $item->base_uom_id,
            'rate' => $total, 'amount' => $total,
        ]);

        return $order;
    };
});

it('br51: refuses a manager approval on a foreign-currency order worth more than the band', function (): void {
    // 1,000 at 122.5 is 122,500 base — over a 100,000 band. Raw, it read as 1,000.
    $order = ($this->order)((int) $this->foreign->id, 122.5, 1000.0);

    $this->actingAs($this->manager);

    expect(fn () => $this->states->transition($order, 'approved'))
        ->toThrow(TransitionDenied::class);

    expect($order->refresh()->status)->toBe('pending_approval');
});

it('br51: names the base-currency value in the refusal', function (): void {
    $order = ($this->order)((int) $this->foreign->id, 122.5, 1000.0);

    $this->actingAs($this->manager);

    try {
        $this->states->transition($order, 'approved');
        $this->fail('The approval should have been refused.');
    } catch (TransitionDenied $e) {
        // The manager is told what the order is actually worth, not the raw foreign figure.
        expect($e->getMessage())->toContain('122,500.00');
    }
});

it('br51: lets the MD approve the same order', function (): void {
    $order = ($this->order)((int) $this->foreign->id, 122.5, 1000.0);

    $this->actingAs($this->md);
    $this->states->transition($order, 'approved');

    expect($order->refresh()->status)->toBe('approved');
});

it('br51: still lets a manager approve a foreign order genuinely under the band', function (): void {
    // The rule tightens what was wrong; it must not start refusing what was right.
    // 100 at 122.5 is 12,250 base — well under.
    $order = ($this->order)((int) $this->foreign->id, 122.5, 100.0);

    $this->actingAs($this->manager);
    $this->states->transition($order, 'approved');

    expect($order->refresh()->status)->toBe('approved');
});

it('br51: is unchanged for base-currency orders', function (): void {
    $under = ($this->order)((int) $this->base->id, 1.0, 90000.0);
    $over = ($this->order)((int) $this->base->id, 1.0, 150000.0);

    $this->actingAs($this->manager);

    $this->states->transition($under, 'approved');
    expect($under->refresh()->status)->toBe('approved');

    expect(fn () => $this->states->transition($over, 'approved'))->toThrow(TransitionDenied::class);
});

it('br51: computes the base value from the rate the order itself recorded', function (): void {
    $order = ($this->order)((int) $this->foreign->id, 200.0, 10.0);

    expect($this->states->baseValue($order))->toBe(2000.0);
});

it('br51: keeps the approval queue and the guard in agreement', function (): void {
    // A count the manager cannot clear is worse than no count: the queue used to list an order
    // the guard would then refuse them.
    ($this->order)((int) $this->foreign->id, 122.5, 1000.0);

    $queue = collect(app(App\Support\Platform\WorkQueue::class)->for($this->manager))
        ->firstWhere('key', 'po_approval');

    $listed = DB::table('purchase_orders')
        ->where('status', 'pending_approval')
        ->whereRaw('total * COALESCE(exchange_rate, 1) <= ?', [$this->band])
        ->count();

    expect($queue['count'] ?? 0)->toBe($listed);
});
