<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Procurement\Models\SupplierBill;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * UX audit H-21. A supplier bill could not be changed once saved, so one mistyped rate meant
 * cancelling it and entering the whole bill again; and a line typed by hand had no material on
 * it, so it could never be matched against the order and the goods receipt.
 */
beforeEach(function (): void {
    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->supplier = DB::table('suppliers')->where('is_active', true)->firstOrFail();
    $this->base = DB::table('currencies')->where('is_base', true)->firstOrFail();
    $this->item = DB::table('items')->where('is_active', true)->firstOrFail();

    $this->payload = fn (array $lines): array => [
        'supplier_id' => $this->supplier->id, 'bill_no' => 'QA-H21', 'bill_date' => now()->toDateString(),
        'currency_id' => $this->base->id, 'lines' => $lines,
    ];

    $this->actingAs($this->accounts)->post('/supplier-bills', ($this->payload)([
        ['item_id' => $this->item->id, 'description' => 'Yarn', 'qty' => 10, 'rate' => 100],
    ]))->assertSessionHasNoErrors();

    $this->bill = SupplierBill::query()->latest('id')->firstOrFail();
});

it('opens a draft bill for editing with its lines and the material list', function (): void {
    $this->actingAs($this->accounts)->get("/supplier-bills/{$this->bill->id}/edit")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Procurement/Bills/Form')
            ->where('bill.id', $this->bill->id)
            ->has('bill.lines', 1)
            ->where('bill.lines.0.item_id', $this->item->id)
            ->has('items.0.code'));
});

it('saves a corrected draft and recomputes its total', function (): void {
    $this->actingAs($this->accounts)->put("/supplier-bills/{$this->bill->id}", ($this->payload)([
        ['item_id' => $this->item->id, 'description' => 'Yarn', 'qty' => 10, 'rate' => 110],
        ['item_id' => null, 'description' => 'Courier', 'qty' => 1, 'rate' => 250],
    ]))->assertSessionHasNoErrors();

    $bill = $this->bill->refresh();

    expect($bill->status)->toBe('draft')
        ->and((float) $bill->total)->toBe(1350.0)
        ->and($bill->lines()->count())->toBe(2);
});

it('refuses to edit a bill that is no longer a draft', function (): void {
    DB::table('supplier_bills')->where('id', $this->bill->id)->update(['status' => 'approved']);

    $this->actingAs($this->accounts)->get("/supplier-bills/{$this->bill->id}/edit")
        ->assertRedirect("/supplier-bills/{$this->bill->id}");

    $this->actingAs($this->accounts)->put("/supplier-bills/{$this->bill->id}", ($this->payload)([
        ['item_id' => null, 'description' => 'Changed', 'qty' => 1, 'rate' => 1],
    ]))->assertSessionHasErrors('lines');

    expect((float) $this->bill->refresh()->total)->toBe(1000.0);
});
