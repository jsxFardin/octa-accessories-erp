<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Item;
use App\Modules\MasterData\Models\Supplier;
use App\Modules\Procurement\Models\Grn;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\SupplierBill;
use Illuminate\Support\Facades\DB;

/**
 * FN-4 / FN-5 — supplier bills with three-way match and supplier payments.
 */
beforeEach(function (): void {
    $this->accounts = User::query()->where('email', 'accounts@maheenlabel.test')->firstOrFail();
    $this->buyer = User::query()->where('email', 'purchase@maheenlabel.test')->firstOrFail();
    $this->operator = User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail();
    $this->supplier = Supplier::query()->where('is_active', true)->firstOrFail();
    $this->item = Item::query()->where('is_active', true)->firstOrFail();
    $this->currencyId = (int) (DB::table('currencies')->where('is_base', true)->value('id')
        ?? DB::table('currencies')->value('id'));
});

function fn4CreateBill(object $test, ?int $poId = null, ?int $grnId = null, float $qty = 10, float $rate = 50): SupplierBill
{
    $test->actingAs($test->accounts)->post('/supplier-bills', [
        'supplier_id' => $test->supplier->id,
        'po_id' => $poId,
        'grn_id' => $grnId,
        'bill_no' => 'SUP-INV-'.random_int(1000, 9999),
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $test->currencyId,
        'exchange_rate' => 1,
        'lines' => [[
            'item_id' => $test->item->id,
            'description' => $test->item->name,
            'qty' => $qty,
            'rate' => $rate,
        ]],
    ])->assertRedirect();

    return SupplierBill::query()->latest('id')->firstOrFail();
}

// ── Index and creation ──────────────────────────────────────

it('lists supplier bills', function (): void {
    fn4CreateBill($this);

    $this->actingAs($this->accounts)
        ->get('/supplier-bills')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Procurement/Bills/Index')
            ->has('bills.data'));
});

it('creates a draft bill with correct totals', function (): void {
    $bill = fn4CreateBill($this, qty: 5, rate: 100);

    expect($bill->status)->toBe('draft');
    expect((float) $bill->subtotal)->toBe(500.0);
    expect((float) $bill->total)->toBe(500.0);
    expect($bill->lines()->count())->toBe(1);
});

it('shows a bill with its lines', function (): void {
    $bill = fn4CreateBill($this);

    $this->actingAs($this->accounts)
        ->get("/supplier-bills/{$bill->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Procurement/Bills/Show')
            ->has('lines', 1));
});

// ── Three-way match and approval ────────────────────────────

it('approves a standalone bill (no PO/GRN)', function (): void {
    $bill = fn4CreateBill($this);

    $this->actingAs($this->accounts)
        ->post("/supplier-bills/{$bill->id}/transition", ['to' => 'approved'])
        ->assertRedirect();

    expect($bill->refresh()->status)->toBe('approved');
    expect($bill->number)->not->toBeNull();
});

it('blocks approval when bill has no lines', function (): void {
    $bill = fn4CreateBill($this);
    $bill->lines()->delete();

    $this->actingAs($this->accounts)
        ->post("/supplier-bills/{$bill->id}/transition", ['to' => 'approved'])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect($bill->refresh()->status)->toBe('draft');
});

it('cancels a draft bill', function (): void {
    $bill = fn4CreateBill($this);

    $this->actingAs($this->accounts)
        ->post("/supplier-bills/{$bill->id}/transition", ['to' => 'cancelled'])
        ->assertRedirect();

    expect($bill->refresh()->status)->toBe('cancelled');
});

it('rejects bill creation from an unauthorized user', function (): void {
    $this->actingAs($this->operator)
        ->get('/supplier-bills')
        ->assertForbidden();
});

// ── Payment allocation ──────────────────────────────────────

it('pays a bill in full and moves it to paid', function (): void {
    $bill = fn4CreateBill($this, qty: 10, rate: 100);

    $this->actingAs($this->accounts)
        ->post("/supplier-bills/{$bill->id}/transition", ['to' => 'approved']);

    $bill->refresh();

    $this->actingAs($this->accounts)
        ->post('/payments', [
            'supplier_id' => $this->supplier->id,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'reference_no' => 'TXN-001',
            'currency_id' => $this->currencyId,
            'exchange_rate' => 1,
            'amount' => 1000,
            'allocations' => [[
                'supplier_bill_id' => $bill->id,
                'amount' => 1000,
            ]],
        ])
        ->assertRedirect();

    $bill->refresh();
    expect($bill->status)->toBe('paid');
    expect((float) $bill->paid_amount)->toBe(1000.0);
});

it('partially pays a bill and moves it to partially_paid', function (): void {
    $bill = fn4CreateBill($this, qty: 10, rate: 100);

    $this->actingAs($this->accounts)
        ->post("/supplier-bills/{$bill->id}/transition", ['to' => 'approved']);

    $this->actingAs($this->accounts)
        ->post('/payments', [
            'supplier_id' => $this->supplier->id,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'currency_id' => $this->currencyId,
            'amount' => 400,
            'allocations' => [[
                'supplier_bill_id' => $bill->id,
                'amount' => 400,
            ]],
        ])
        ->assertRedirect();

    expect($bill->refresh()->status)->toBe('partially_paid');
    expect((float) $bill->paid_amount)->toBe(400.0);
});

it('rejects allocation exceeding outstanding', function (): void {
    $bill = fn4CreateBill($this, qty: 5, rate: 20);

    $this->actingAs($this->accounts)
        ->post("/supplier-bills/{$bill->id}/transition", ['to' => 'approved']);

    $this->actingAs($this->accounts)
        ->post('/payments', [
            'supplier_id' => $this->supplier->id,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'currency_id' => $this->currencyId,
            'amount' => 200,
            'allocations' => [[
                'supplier_bill_id' => $bill->id,
                'amount' => 200,
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('allocations');
});

it('rejects payment against a draft bill', function (): void {
    $bill = fn4CreateBill($this);

    $this->actingAs($this->accounts)
        ->post('/payments', [
            'supplier_id' => $this->supplier->id,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'currency_id' => $this->currencyId,
            'amount' => 100,
            'allocations' => [[
                'supplier_bill_id' => $bill->id,
                'amount' => 100,
            ]],
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('allocations');
});

it('lists payments', function (): void {
    $bill = fn4CreateBill($this, qty: 10, rate: 10);
    $this->actingAs($this->accounts)->post("/supplier-bills/{$bill->id}/transition", ['to' => 'approved']);

    $this->actingAs($this->accounts)->post('/payments', [
        'supplier_id' => $this->supplier->id,
        'payment_date' => now()->toDateString(),
        'method' => 'cash',
        'currency_id' => $this->currencyId,
        'amount' => 100,
        'allocations' => [['supplier_bill_id' => $bill->id, 'amount' => 100]],
    ]);

    $this->actingAs($this->accounts)
        ->get('/payments')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Finance/Payments/Index')
            ->has('payments.data'));
});
