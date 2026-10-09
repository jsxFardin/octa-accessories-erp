<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Text\RecordLink;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * M-42 — a lot's movement history names the document behind each movement, as a person
 * reads it, and leads to it. "Grn #7" was a PHP class name and a primary key.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
});

it('names the goods receipt behind a receipt movement and links to it', function (): void {
    $row = DB::table('stock_ledger')->where('source_type', 'like', '%Grn')->orderBy('id')->first();

    expect($row)->not->toBeNull();

    $number = DB::table('grns')->where('id', $row->source_id)->value('number');

    $this->actingAs($this->admin)
        ->get("/lots/{$row->lot_id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($row, $number): void {
            $ledger = collect($page->toArray()['props']['ledger']);
            $movement = $ledger->firstWhere('id', $row->id);

            expect($movement['source']['label'])->toBe("Goods receipt {$number}")
                ->and($movement['source']['href'])->toBe("/grns/{$row->source_id}");
        });
});

it('falls back to the id when a record has no number', function (): void {
    expect(RecordLink::describe('App\\Modules\\Sales\\Models\\SalesOrder', 12))
        ->toBe(['label' => 'Sales order #12', 'href' => '/sales-orders/12'])
        ->and(RecordLink::describe(null, null))->toBeNull()
        ->and(RecordLink::label('price_lists'))->toBe('price list')
        ->and(RecordLink::href('price_lists', 3))->toBe('/price-lists/3');
});
