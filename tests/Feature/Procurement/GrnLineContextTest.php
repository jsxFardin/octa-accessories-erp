<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Procurement\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * PO → GRN — the handoff carried the order and lost what was on it.
 *
 * `?po=` filled in the supplier and the order number and stopped there, so the storekeeper
 * retyped every item, quantity and rate from the paperwork on the bench. That is where a wrong
 * item code and a transposed rate come from, and `grn_lines.po_line_id` — the column that
 * records which order line a receipt answers — has been in the schema all along with nothing
 * ever writing it. With no link, `purchase_order_lines.received_qty` never moved either: every
 * line looked fully outstanding for ever, a second delivery offered the whole quantity again,
 * and `partially_received` was a status nothing could reach.
 *
 * The order's currency travels with it too (BR-50): a USD order priced its lines in dollars and
 * the receiving form rendered them against the factory's currency.
 */
beforeEach(function (): void {
    $this->store = User::query()->where('email', 'store@maheenlabel.test')->firstOrFail();
    $this->buyer = User::query()->where('email', 'purchase@maheenlabel.test')->firstOrFail();

    $supplier = DB::table('suppliers')->firstOrFail();
    DB::table('suppliers')->where('id', $supplier->id)->update(['is_approved' => true, 'is_active' => true]);

    $this->foreign = DB::table('currencies')->where('is_base', false)->firstOrFail();
    $this->items = DB::table('items')->whereNotNull('base_uom_id')->limit(2)->get();
    $unit = DB::table('factory_units')->where('is_active', true)->value('id');

    $this->order = PurchaseOrder::query()->create([
        'number' => 'PO-CTX-0001',
        'supplier_id' => $supplier->id,
        'factory_unit_id' => $unit,
        'order_date' => now()->toDateString(),
        'currency_id' => $this->foreign->id,
        'exchange_rate' => 122.5,
        'subtotal' => 3000,
        'total' => 3000,
        // Open to receiving, which is the set the picker offers.
        'status' => 'approved',
        'created_by' => $this->buyer->id,
    ]);

    $lineNo = 0;

    foreach ($this->items as $item) {
        DB::table('purchase_order_lines')->insert([
            'po_id' => $this->order->id,
            'line_no' => ++$lineNo,
            'item_id' => $item->id,
            'description' => 'Context line '.$lineNo,
            'qty' => 100 * $lineNo,
            'uom_id' => $item->base_uom_id,
            'rate' => 10 * $lineNo,
            'amount' => 1000 * $lineNo,
            'received_qty' => 0,
        ]);
    }

    $this->poLines = DB::table('purchase_order_lines')->where('po_id', $this->order->id)
        ->orderBy('line_no')->get();

    $this->warehouse = DB::table('warehouses')->where('is_active', true)->value('id');

    $this->receive = fn (array $lines) => $this->actingAs($this->store)->post('/grns', [
        'supplier_id' => $this->order->supplier_id,
        'po_id' => $this->order->id,
        'warehouse_id' => $this->warehouse,
        'received_on' => now()->toDateString(),
        'freight_amount' => 0,
        'duty_amount' => 0,
        'clearing_amount' => 0,
        'lines' => $lines,
    ]);
});

it('carries the order lines into the receiving form', function (): void {
    $this->actingAs($this->store)
        ->get("/grns/create?po={$this->order->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];

            expect($props['preselectPoId'])->toBe($this->order->id)
                ->and($props['poLines'])->toHaveCount($this->poLines->count());

            foreach ($props['poLines'] as $line) {
                expect($line)->toHaveKeys(['id', 'item_id', 'uom_id', 'qty', 'received_qty', 'rate', 'remaining_qty'])
                    ->and($line['item_code'])->not->toBeEmpty();
            }
        });
});

it('carries the order currency so its rates are not read as the factory currency', function (): void {
    $this->actingAs($this->store)
        ->get("/grns/create?po={$this->order->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $order = collect($page->toArray()['props']['purchaseOrders'])
                ->firstWhere('id', $this->order->id);

            expect($order['currency'])->toBe($this->foreign->code);
        });
});

it('records which order line each receipt answers', function (): void {
    $first = $this->poLines->first();

    ($this->receive)([[
        'po_line_id' => $first->id,
        'item_id' => $first->item_id,
        'uom_id' => $first->uom_id,
        'qty' => 40,
        'rate' => $first->rate,
    ]])->assertSessionHasNoErrors();

    $grnLine = DB::table('grn_lines')->orderByDesc('id')->first();

    expect((int) $grnLine->po_line_id)->toBe((int) $first->id);
});

it('moves the outstanding quantity as goods arrive, and offers only what is left', function (): void {
    $first = $this->poLines->first();

    ($this->receive)([[
        'po_line_id' => $first->id,
        'item_id' => $first->item_id,
        'uom_id' => $first->uom_id,
        'qty' => 40,
        'rate' => $first->rate,
    ]])->assertSessionHasNoErrors();

    expect((float) DB::table('purchase_order_lines')->where('id', $first->id)->value('received_qty'))
        ->toBe(40.0);

    // A second delivery must be offered the remaining 60, not the original 100.
    $this->actingAs($this->store)
        ->get("/grns/create?po={$this->order->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($first): void {
            $line = collect($page->toArray()['props']['poLines'])->firstWhere('id', $first->id);

            expect((float) $line['remaining_qty'])->toBe(60.0);
        });
});

it('marks the order partially received while anything is outstanding', function (): void {
    $first = $this->poLines->first();

    ($this->receive)([[
        'po_line_id' => $first->id,
        'item_id' => $first->item_id,
        'uom_id' => $first->uom_id,
        'qty' => 40,
        'rate' => $first->rate,
    ]])->assertSessionHasNoErrors();

    expect($this->order->refresh()->status)->toBe('partially_received');
});

it('marks the order received once every line is complete', function (): void {
    ($this->receive)($this->poLines->map(fn ($l): array => [
        'po_line_id' => $l->id,
        'item_id' => $l->item_id,
        'uom_id' => $l->uom_id,
        'qty' => (float) $l->qty,
        'rate' => (float) $l->rate,
    ])->all())->assertSessionHasNoErrors();

    expect($this->order->refresh()->status)->toBe('received');
});

it('handles several lines on one receipt independently', function (): void {
    $lines = $this->poLines->values();

    ($this->receive)([
        ['po_line_id' => $lines[0]->id, 'item_id' => $lines[0]->item_id, 'uom_id' => $lines[0]->uom_id, 'qty' => 25, 'rate' => $lines[0]->rate],
        ['po_line_id' => $lines[1]->id, 'item_id' => $lines[1]->item_id, 'uom_id' => $lines[1]->uom_id, 'qty' => 60, 'rate' => $lines[1]->rate],
    ])->assertSessionHasNoErrors();

    expect((float) DB::table('purchase_order_lines')->where('id', $lines[0]->id)->value('received_qty'))->toBe(25.0)
        ->and((float) DB::table('purchase_order_lines')->where('id', $lines[1]->id)->value('received_qty'))->toBe(60.0);
});

it('records an over-receipt against the line rather than losing it', function (): void {
    // Suppliers do over-deliver, and the goods are physically on the bench: refusing the
    // receipt would leave stock in the yard and nothing in the system. It is recorded against
    // the line it belongs to, where it is visible, rather than silently unlinked.
    $first = $this->poLines->first();

    ($this->receive)([[
        'po_line_id' => $first->id,
        'item_id' => $first->item_id,
        'uom_id' => $first->uom_id,
        'qty' => (float) $first->qty + 25,
        'rate' => $first->rate,
    ]])->assertSessionHasNoErrors();

    expect((float) DB::table('purchase_order_lines')->where('id', $first->id)->value('received_qty'))
        ->toBe((float) $first->qty + 25);

    // Nothing outstanding is left to offer.
    $this->actingAs($this->store)
        ->get("/grns/create?po={$this->order->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($first): void {
            $line = collect($page->toArray()['props']['poLines'])->firstWhere('id', $first->id);

            expect((float) $line['remaining_qty'])->toBe(0.0);
        });
});

// --- the context is not a read-around ----------------------------------------------------

it('drops a line id belonging to a different purchase order', function (): void {
    // Pointing the field at another order's line would credit that order's receipt and move
    // its outstanding quantity. The goods are still received; the false provenance is not.
    $foreign = DB::table('purchase_order_lines')->where('po_id', '!=', $this->order->id)->first();

    if ($foreign === null) {
        $this->markTestSkipped('No second purchase order to borrow a line from.');
    }

    $before = (float) $foreign->received_qty;
    $first = $this->poLines->first();

    ($this->receive)([[
        'po_line_id' => $foreign->id,
        'item_id' => $first->item_id,
        'uom_id' => $first->uom_id,
        'qty' => 10,
        'rate' => $first->rate,
    ]])->assertSessionHasNoErrors();

    expect(DB::table('grn_lines')->orderByDesc('id')->value('po_line_id'))->toBeNull()
        ->and((float) DB::table('purchase_order_lines')->where('id', $foreign->id)->value('received_qty'))
        ->toBe($before);
});

it('resolves a stale or unusable po parameter to nothing', function (): void {
    foreach (['999999', '0', '-1', 'abc'] as $bad) {
        $this->actingAs($this->store)
            ->get("/grns/create?po={$bad}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preselectPoId', null)
                ->where('poLines', []),
            );
    }
});

it('does not offer an order that is not open to receiving', function (): void {
    $this->order->forceFill(['status' => 'draft'])->save();

    $this->actingAs($this->store)
        ->get("/grns/create?po={$this->order->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preselectPoId', null)
            ->where('poLines', []),
        );
});

it('still allows a receipt with no purchase order behind it', function (): void {
    // Manual selection is preserved: not every delivery arrives against an order.
    $item = $this->items->first();

    $this->actingAs($this->store)->post('/grns', [
        'supplier_id' => $this->order->supplier_id,
        'warehouse_id' => $this->warehouse,
        'received_on' => now()->toDateString(),
        'freight_amount' => 0,
        'duty_amount' => 0,
        'clearing_amount' => 0,
        'lines' => [[
            'item_id' => $item->id,
            'uom_id' => $item->base_uom_id,
            'qty' => 5,
            'rate' => 1,
        ]],
    ])->assertSessionHasNoErrors();

    expect(DB::table('grn_lines')->orderByDesc('id')->value('po_line_id'))->toBeNull();
});

/**
 * BR-59 — the stock ledger is kept in the factory's currency, and every rate on a goods
 * receipt is in the purchase order's.
 *
 * Received against a USD order at the seeded rate of 122.5, a lot was valued at the dollar
 * figure and entered a taka ledger a hundred-and-twenty-two times too cheap. Everything
 * downstream inherits it in the same direction: the item's weighted average, the material cost
 * of the job it is issued to, the margin on the order that job was made for — and, at the far
 * end, a finished-goods lot indistinguishable from the zero-value ones BR-52 exists to stop.
 */
it('values a lot in the factory currency, not the order currency', function (): void {
    $line = $this->poLines->first();

    ($this->receive)([[
        'item_id' => $line->item_id,
        'uom_id' => $line->uom_id,
        'qty' => 10,
        'rate' => 10,
        'po_line_id' => $line->id,
    ]])->assertRedirect();

    $grnLine = DB::table('grn_lines')->latest('id')->firstOrFail();
    $lot = DB::table('stock_lots')->where('grn_line_id', $grnLine->id)->firstOrFail();

    // What the supplier charged stays in the supplier's money…
    expect((float) $grnLine->rate)->toBe(10.0)
        ->and((float) $grnLine->landed_rate)->toBe(10.0)
        // …and what the books hold is that, converted at the order's own snapshotted rate.
        ->and((float) $lot->unit_cost)->toBe(1225.0);
});

it('leaves a base-currency receipt valued exactly as it was charged', function (): void {
    $base = DB::table('currencies')->where('is_base', true)->firstOrFail();

    DB::table('purchase_orders')->where('id', $this->order->id)
        ->update(['currency_id' => $base->id, 'exchange_rate' => 1]);

    $line = $this->poLines->first();

    ($this->receive)([[
        'item_id' => $line->item_id,
        'uom_id' => $line->uom_id,
        'qty' => 10,
        'rate' => 10,
        'po_line_id' => $line->id,
    ]])->assertRedirect();

    $grnLine = DB::table('grn_lines')->latest('id')->firstOrFail();
    $lot = DB::table('stock_lots')->where('grn_line_id', $grnLine->id)->firstOrFail();

    expect((float) $lot->unit_cost)->toBe(10.0);
});

it('carries the landed cost into the conversion rather than around it', function (): void {
    $line = $this->poLines->first();

    $this->actingAs($this->store)->post('/grns', [
        'supplier_id' => $this->order->supplier_id,
        'po_id' => $this->order->id,
        'warehouse_id' => $this->warehouse,
        'received_on' => now()->toDateString(),
        // Charged in the order's currency, like every other figure on the receipt (BR-36).
        'freight_amount' => 100,
        'duty_amount' => 0,
        'clearing_amount' => 0,
        'lines' => [[
            'item_id' => $line->item_id,
            'uom_id' => $line->uom_id,
            'qty' => 10,
            'rate' => 10,
            'po_line_id' => $line->id,
        ]],
    ])->assertRedirect();

    $grnLine = DB::table('grn_lines')->latest('id')->firstOrFail();
    $lot = DB::table('stock_lots')->where('grn_line_id', $grnLine->id)->firstOrFail();

    // 10 + (100 ÷ 10) = 20 a unit charged, × 122.5 in the books.
    expect((float) $grnLine->landed_rate)->toBe(20.0)
        ->and((float) $lot->unit_cost)->toBe(2450.0);
});
