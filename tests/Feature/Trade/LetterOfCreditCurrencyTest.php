<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Trade\Models\LetterOfCredit;
use Illuminate\Support\Facades\DB;

/**
 * BR-56 — a letter of credit is opened in one currency and can only be drawn on by purchase
 * orders payable in that currency.
 *
 * `covered` on the credit is the sum of `lc_purchase_orders.covered_amount` and is read
 * against the credit's face value. Attaching an order in another currency made that sum add
 * taka to dollars — the BR-50 defect, one document down.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->base = DB::table('currencies')->where('is_base', true)->firstOrFail();
    $this->usd = DB::table('currencies')->where('code', 'USD')->firstOrFail();
    $this->supplier = DB::table('suppliers')->where('is_active', true)->firstOrFail();

    $this->credit = LetterOfCredit::query()->create([
        'number' => 'LC-QA-BR56',
        'kind' => 'sight',
        'supplier_id' => $this->supplier->id,
        'currency_id' => $this->usd->id,
        'exchange_rate' => 122.5,
        'amount' => 100000,
        'status' => 'opened',
    ]);

    // `$status` matters: `update()` refuses a non-draft order as read-only, so a test about the
    // *currency* rule must use a draft order or it passes for the wrong reason.
    $this->orderIn = function (int $currencyId, string $status = 'approved'): int {
        return DB::table('purchase_orders')->insertGetId([
            'number' => 'PO-QA-'.substr(uniqid(), -8),
            'supplier_id' => $this->supplier->id,
            'factory_unit_id' => DB::table('factory_units')->value('id'),
            'order_date' => now()->toDateString(),
            'currency_id' => $currencyId,
            'exchange_rate' => $currencyId === (int) $this->usd->id ? 122.5 : 1,
            'subtotal' => 1000,
            'total' => 1000,
            'status' => $status,
        ]);
    };
});

it('refuses an order in another currency, posted directly', function (): void {
    // Not merely absent from the picker — the id arrives by POST.
    $orderId = ($this->orderIn)((int) $this->base->id);

    $this->actingAs($this->admin)
        ->post("/letters-of-credit/{$this->credit->id}/orders", ['po_id' => $orderId])
        ->assertStatus(422);

    expect(DB::table('lc_purchase_orders')->where('lc_id', $this->credit->id)->count())->toBe(0);
});

it('covers an order in its own currency', function (): void {
    $orderId = ($this->orderIn)((int) $this->usd->id);

    $this->actingAs($this->admin)
        ->post("/letters-of-credit/{$this->credit->id}/orders", ['po_id' => $orderId])
        ->assertRedirect();

    expect(DB::table('lc_purchase_orders')
        ->where('lc_id', $this->credit->id)->where('po_id', $orderId)->count())->toBe(1);
});

it('offers only orders payable under the credit', function (): void {
    $same = ($this->orderIn)((int) $this->usd->id);
    $other = ($this->orderIn)((int) $this->base->id);

    $response = $this->actingAs($this->admin)->get("/letters-of-credit/{$this->credit->id}");

    $available = collect($response->viewData('page')['props']['availablePurchaseOrders'])->pluck('id');

    expect($available)->toContain($same)->not->toContain($other);
});

it('names the credit currency on the screen rather than falling back to the factory', function (): void {
    $props = $this->actingAs($this->admin)
        ->get("/letters-of-credit/{$this->credit->id}")
        ->viewData('page')['props'];

    expect($props['letter']['currency']['code'])->toBe('USD');
});

/*
 * BR-56, held over time rather than only at the moment of attachment.
 *
 * `attachOrder()` refuses an order in another currency, but that check was made once and the
 * currency it checked against could still move underneath it: open a BDT draft, attach a BDT
 * order, switch the draft to USD, and a USD credit covers a BDT order — `covered` adds taka to
 * dollars again, and the invariant is false without any of the attach rules being broken.
 */
it('br56: refuses to redenominate a credit that already covers an order', function (): void {
    $bdtCredit = LetterOfCredit::query()->create([
        'number' => 'LC-QA-BR56-MUT',
        'kind' => 'sight',
        'supplier_id' => $this->supplier->id,
        'currency_id' => $this->base->id,
        'exchange_rate' => 1,
        'amount' => 100000,
        'status' => 'draft',
    ]);

    $orderId = ($this->orderIn)((int) $this->base->id);

    $this->actingAs($this->admin)
        ->post("/letters-of-credit/{$bdtCredit->id}/orders", ['po_id' => $orderId])
        ->assertRedirect();

    $this->actingAs($this->admin)->put("/letters-of-credit/{$bdtCredit->id}", [
        'kind' => 'sight',
        'supplier_id' => $this->supplier->id,
        'currency_id' => $this->usd->id,
        'amount' => 100000,
    ])->assertSessionHasErrors('currency_id');

    // Currency unchanged, attachment unchanged, coverage still in one currency.
    expect((int) $bdtCredit->fresh()->currency_id)->toBe((int) $this->base->id)
        ->and(DB::table('lc_purchase_orders')->where('lc_id', $bdtCredit->id)->count())->toBe(1)
        ->and((int) DB::table('purchase_orders')->where('id', $orderId)->value('currency_id'))
        ->toBe((int) $this->base->id);
});

it('br56: refuses the same redenomination the other way round', function (): void {
    $orderId = ($this->orderIn)((int) $this->usd->id);

    $this->actingAs($this->admin)
        ->post("/letters-of-credit/{$this->credit->id}/orders", ['po_id' => $orderId])
        ->assertRedirect();

    DB::table('letters_of_credit')->where('id', $this->credit->id)->update(['status' => 'draft']);

    $this->actingAs($this->admin)->put("/letters-of-credit/{$this->credit->id}", [
        'kind' => 'sight',
        'supplier_id' => $this->supplier->id,
        'currency_id' => $this->base->id,
        'amount' => 100000,
    ])->assertSessionHasErrors('currency_id');

    expect((int) $this->credit->fresh()->currency_id)->toBe((int) $this->usd->id);
});

it('br56: still lets a credit with no orders change currency', function (): void {
    // The rule is about protecting an attachment, not about freezing a draft nobody has used.
    DB::table('letters_of_credit')->where('id', $this->credit->id)->update(['status' => 'draft']);

    $this->actingAs($this->admin)->put("/letters-of-credit/{$this->credit->id}", [
        'kind' => 'sight',
        'supplier_id' => $this->supplier->id,
        'currency_id' => $this->base->id,
        'amount' => 100000,
    ])->assertSessionHasNoErrors();

    expect((int) $this->credit->fresh()->currency_id)->toBe((int) $this->base->id);
});

it('br56: holds the invariant across several attached orders', function (): void {
    $first = ($this->orderIn)((int) $this->usd->id);
    $second = ($this->orderIn)((int) $this->usd->id);

    foreach ([$first, $second] as $orderId) {
        $this->actingAs($this->admin)
            ->post("/letters-of-credit/{$this->credit->id}/orders", ['po_id' => $orderId])
            ->assertRedirect();
    }

    DB::table('letters_of_credit')->where('id', $this->credit->id)->update(['status' => 'draft']);

    $this->actingAs($this->admin)->put("/letters-of-credit/{$this->credit->id}", [
        'kind' => 'sight',
        'supplier_id' => $this->supplier->id,
        'currency_id' => $this->base->id,
        'amount' => 100000,
    ])->assertSessionHasErrors('currency_id');

    // Every attachment still matches the credit it hangs on.
    $mismatched = DB::table('lc_purchase_orders as lpo')
        ->join('letters_of_credit as lc', 'lc.id', '=', 'lpo.lc_id')
        ->join('purchase_orders as po', 'po.id', '=', 'lpo.po_id')
        ->whereColumn('po.currency_id', '!=', 'lc.currency_id')
        ->count();

    expect($mismatched)->toBe(0);
});

/*
 * BR-56 from the *other* side.
 *
 * The credit-side guard freezes an LC's currency while orders hang on it. Nothing froze the
 * order's. `attachOrder()` places no status restriction and a draft order is editable, so:
 * attach a USD order to a USD credit, edit the order to BDT, and the credit now covers an order
 * in a currency it cannot pay — `covered` adds taka to dollars again. The invariant held at
 * attach time and was falsified afterwards by an edit that broke none of its own rules.
 */
it('br56: refuses to redenominate an order that a credit already covers', function (): void {
    $orderId = ($this->orderIn)((int) $this->usd->id, 'draft');

    $this->actingAs($this->admin)
        ->post("/letters-of-credit/{$this->credit->id}/orders", ['po_id' => $orderId])
        ->assertRedirect();

    $item = DB::table('items')->whereNotNull('base_uom_id')->firstOrFail();

    $this->actingAs($this->admin)->put("/purchase-orders/{$orderId}", [
        'supplier_id' => $this->supplier->id,
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'order_date' => now()->toDateString(),
        // The bypass: the order walks out of the credit's currency.
        'currency_id' => $this->base->id,
        'lines' => [[
            'item_id' => $item->id,
            'uom_id' => $item->base_uom_id,
            'qty' => 1,
            'rate' => 1000,
        ]],
    ])->assertSessionHasErrors('currency_id');

    // Order untouched, attachment untouched, and the invariant still true across the whole set.
    expect((int) DB::table('purchase_orders')->where('id', $orderId)->value('currency_id'))
        ->toBe((int) $this->usd->id)
        ->and(DB::table('lc_purchase_orders')->where('po_id', $orderId)->count())->toBe(1);

    $mismatched = DB::table('lc_purchase_orders as lpo')
        ->join('letters_of_credit as lc', 'lc.id', '=', 'lpo.lc_id')
        ->join('purchase_orders as po', 'po.id', '=', 'lpo.po_id')
        ->whereColumn('po.currency_id', '!=', 'lc.currency_id')
        ->count();

    expect($mismatched)->toBe(0);
});

it('br56: still lets an unattached order change currency freely', function (): void {
    $orderId = ($this->orderIn)((int) $this->usd->id, 'draft');
    $item = DB::table('items')->whereNotNull('base_uom_id')->firstOrFail();

    $this->actingAs($this->admin)->put("/purchase-orders/{$orderId}", [
        'supplier_id' => $this->supplier->id,
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'order_date' => now()->toDateString(),
        'currency_id' => $this->base->id,
        'lines' => [[
            'item_id' => $item->id,
            'uom_id' => $item->base_uom_id,
            'qty' => 1,
            'rate' => 1000,
        ]],
    ])->assertSessionHasNoErrors();

    expect((int) DB::table('purchase_orders')->where('id', $orderId)->value('currency_id'))
        ->toBe((int) $this->base->id);
});
