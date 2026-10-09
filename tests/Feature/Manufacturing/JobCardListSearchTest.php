<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use Inertia\Testing\AssertableInertia;

/**
 * M-39 — a planner finds a job card by the product on it, or by the customer it is for,
 * not only by its number.
 */
beforeEach(function (): void {
    $this->planner = User::query()->where('email', 'planner@octapussolution.com')->firstOrFail();
    $this->jobCard = JobCard::query()->with('product.item', 'product.customer')->whereNotNull('product_id')->firstOrFail();
});

function listedJobCardIds(AssertableInertia $page): array
{
    return array_column($page->toArray()['props']['jobCards']['data'], 'id');
}

it('finds a job card by its product code', function (): void {
    $this->actingAs($this->planner)
        ->get('/job-cards?q='.urlencode($this->jobCard->product->item->code))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(listedJobCardIds($page))->toContain($this->jobCard->id));
});

it('finds a job card by its customer name', function (): void {
    $customer = $this->jobCard->product->customer;

    if ($customer === null) {
        $this->markTestSkipped('The job card is for a standard product with no customer.');
    }

    $this->actingAs($this->planner)
        ->get('/job-cards?q='.urlencode(substr($customer->name, 0, 6)))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(listedJobCardIds($page))->toContain($this->jobCard->id));
});

it('still finds nothing for a term no job card matches', function (): void {
    $this->actingAs($this->planner)
        ->get('/job-cards?q=zzz-no-such-thing')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(listedJobCardIds($page))->toBe([]));
});
