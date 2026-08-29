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
    $this->admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();
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

    $this->orderIn = function (int $currencyId): int {
        return DB::table('purchase_orders')->insertGetId([
            'number' => 'PO-QA-'.substr(uniqid(), -8),
            'supplier_id' => $this->supplier->id,
            'factory_unit_id' => DB::table('factory_units')->value('id'),
            'order_date' => now()->toDateString(),
            'currency_id' => $currencyId,
            'exchange_rate' => $currencyId === (int) $this->usd->id ? 122.5 : 1,
            'subtotal' => 1000,
            'total' => 1000,
            'status' => 'approved',
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
