<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\States\PurchaseOrderStateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * 05-workflows §7 — a purchase order may not be submitted to a supplier nobody has approved.
 *
 * The guard was written and never tested. It is the one thing standing between the factory and
 * committing money to an unvetted supplier — the compliance question a brand audit asks first —
 * and nothing in the suite would have noticed if a refactor dropped it.
 *
 * Approval is a state of the *supplier*, not of the order, so these tests move that flag and
 * assert the order's behaviour follows it in both directions.
 */
beforeEach(function (): void {
    $this->buyer = User::query()->where('email', 'purchase@octapussolution.com')->firstOrFail();
    $this->states = app(PurchaseOrderStateMachine::class);

    // The state machine reads the *authenticated* user for its permission check, so the buyer
    // has to actually be signed in. Without this every refusal below would be a permission
    // denial wearing the gate's clothes, and the test would pass without exercising it.
    $this->actingAs($this->buyer);

    expect($this->buyer->hasPermission('purchase_order.submit'))->toBeTrue();

    // The walkthrough seeds no purchase order, so a draft is built from records that really
    // exist — an actual supplier, item and unit — and given one line, because BR-25 refuses an
    // empty order before it ever looks at the supplier and this is not that test.
    $this->order = PurchaseOrder::query()->where('status', 'draft')->first() ?? (function (): PurchaseOrder {
        $supplier = DB::table('suppliers')->firstOrFail();
        $item = DB::table('items')->whereNotNull('base_uom_id')->firstOrFail();

        $order = PurchaseOrder::query()->create([
            'supplier_id' => $supplier->id,
            'factory_unit_id' => DB::table('factory_units')->where('is_active', true)->value('id'),
            'order_date' => now()->toDateString(),
            'currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
            'exchange_rate' => 1,
            'subtotal' => 5000,
            'total' => 5000,
            'status' => 'draft',
            'created_by' => $this->buyer->id,
        ]);

        DB::table('purchase_order_lines')->insert([
            'po_id' => $order->id,
            'line_no' => 1,
            'item_id' => $item->id,
            'description' => 'Test line',
            'qty' => 100,
            'uom_id' => $item->base_uom_id,
            'rate' => 50,
            'amount' => 5000,
        ]);

        return $order;
    })();

    $this->order->forceFill(['status' => 'draft'])->save();

    $this->setApproval = function (bool $approved): void {
        DB::table('suppliers')->where('id', $this->order->supplier_id)->update(['is_approved' => $approved]);
    };
});

it('refuses to submit a purchase order to an unapproved supplier', function (): void {
    ($this->setApproval)(false);

    expect(fn () => $this->states->transition($this->order, 'pending_approval'))
        ->toThrow(TransitionDenied::class);

    expect($this->order->refresh()->status)->toBe('draft');
});

it('names the supplier approval as the reason, not a generic refusal', function (): void {
    ($this->setApproval)(false);

    try {
        $this->states->transition($this->order, 'pending_approval');
        $this->fail('The transition should have been denied.');
    } catch (TransitionDenied $e) {
        expect($e->getMessage())->toContain('not approved')
            ->and($e->getMessage())->toContain('approve them');
    }
});

it('allows the submission once the supplier is approved', function (): void {
    ($this->setApproval)(true);

    $this->states->transition($this->order, 'pending_approval');

    expect($this->order->refresh()->status)->toBe('pending_approval');
});

it('refuses an empty order before it asks about the supplier', function (): void {
    // BR-25. An approved supplier does not make an order with nothing on it submittable.
    ($this->setApproval)(true);

    $order = PurchaseOrder::query()->create([
        'supplier_id' => $this->order->supplier_id,
        'factory_unit_id' => $this->order->factory_unit_id,
        'order_date' => now()->toDateString(),
        'currency_id' => $this->order->currency_id,
        'exchange_rate' => $this->order->exchange_rate,
        'status' => 'draft',
        'created_by' => $this->buyer->id,
    ]);

    try {
        $this->states->transition($order, 'pending_approval');
        $this->fail('The transition should have been denied.');
    } catch (TransitionDenied $e) {
        expect($e->getMessage())->toContain('no lines');
    }

    expect($order->refresh()->status)->toBe('draft');
});

it('holds the gate through the HTTP endpoint, not only the state machine', function (): void {
    ($this->setApproval)(false);

    $this->actingAs($this->buyer)
        ->post("/purchase-orders/{$this->order->id}/transition", ['to' => 'pending_approval']);

    // The controller reports the refusal rather than letting it through.
    expect($this->order->refresh()->status)->toBe('draft');
});
