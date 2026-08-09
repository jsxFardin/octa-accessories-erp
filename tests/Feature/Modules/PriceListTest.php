<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@maheenlabel.test')->firstOrFail();
    $this->product = DB::table('products')->first();
    $this->currency = DB::table('currencies')->where('is_base', true)->first();
});

function makePriceList(object $test, array $lines = []): int
{
    $test->actingAs($test->merchandiser)->post('/price-lists', [
        'code' => 'PL-TEST-01',
        'name' => 'Test contract pricing',
        'customer_id' => $test->product->customer_id,
        'currency_id' => $test->currency->id,
        'valid_from' => now()->toDateString(),
        'is_active' => true,
        'lines' => $lines !== [] ? $lines : [
            ['product_id' => $test->product->id, 'min_qty' => 0, 'rate_per_m' => 4.5],
            ['product_id' => $test->product->id, 'min_qty' => 50000, 'rate_per_m' => 3.9],
        ],
    ])->assertRedirect();

    return (int) DB::table('price_lists')->where('code', 'PL-TEST-01')->value('id');
}

it('stores a price list with its quantity breaks', function (): void {
    $id = makePriceList($this);

    $lines = DB::table('price_list_lines')->where('price_list_id', $id)->orderBy('min_qty')->get();

    expect($lines)->toHaveCount(2)
        ->and((float) $lines[0]->rate_per_m)->toBe(4.5)
        ->and((float) $lines[1]->min_qty)->toBe(50000.0);
});

it('replaces the lines rather than appending on edit', function (): void {
    $id = makePriceList($this);

    $this->actingAs($this->merchandiser)->put("/price-lists/{$id}", [
        'code' => 'PL-TEST-01',
        'name' => 'Test contract pricing',
        'customer_id' => $this->product->customer_id,
        'currency_id' => $this->currency->id,
        'valid_from' => now()->toDateString(),
        'is_active' => true,
        'lines' => [['product_id' => $this->product->id, 'min_qty' => 0, 'rate_per_m' => 4.25]],
    ])->assertRedirect();

    $lines = DB::table('price_list_lines')->where('price_list_id', $id)->get();

    expect($lines)->toHaveCount(1)
        ->and((float) $lines[0]->rate_per_m)->toBe(4.25);
});

it('deactivates rather than deletes, so an old quotation stays explicable', function (): void {
    $id = makePriceList($this);

    $this->actingAs($this->merchandiser)->delete("/price-lists/{$id}")->assertRedirect();

    $row = DB::table('price_lists')->where('id', $id)->first();

    expect($row)->not->toBeNull()
        ->and((bool) $row->is_active)->toBeFalse();
});

it('refuses a validity window that ends before it starts', function (): void {
    $this->actingAs($this->merchandiser)->post('/price-lists', [
        'code' => 'PL-TEST-02',
        'name' => 'Backwards',
        'customer_id' => $this->product->customer_id,
        'currency_id' => $this->currency->id,
        'valid_from' => now()->toDateString(),
        'valid_to' => now()->subDay()->toDateString(),
        'lines' => [['product_id' => $this->product->id, 'min_qty' => 0, 'rate_per_m' => 4]],
    ])->assertSessionHasErrors('valid_to');
});

it('needs at least one rate', function (): void {
    $this->actingAs($this->merchandiser)->post('/price-lists', [
        'code' => 'PL-TEST-03',
        'name' => 'Empty',
        'customer_id' => $this->product->customer_id,
        'currency_id' => $this->currency->id,
        'valid_from' => now()->toDateString(),
        'lines' => [],
    ])->assertSessionHasErrors('lines');
});
