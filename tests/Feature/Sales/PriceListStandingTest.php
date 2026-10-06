<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * A price list's standing today, derived from its dates and flag; the list's filters and
 * sort; and the refusal of two active lists on the same days for one customer.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->currency = Currency::query()->where('is_base', true)->firstOrFail();
    $this->product = DB::table('products')->where('customer_id', $this->customer->id)->first()
        ?? DB::table('products')->first();

    DB::table('price_lists')->where('customer_id', $this->customer->id)->update(['is_active' => false]);
});

function priceListPayload(object $test, array $overrides = []): array
{
    return [
        'code' => 'PL-T-'.substr(md5((string) microtime(true)), 0, 6),
        'name' => 'Test list',
        'customer_id' => $test->customer->id,
        'currency_id' => $test->currency->id,
        'valid_from' => now()->toDateString(),
        'valid_to' => null,
        'is_active' => true,
        'lines' => [['product_id' => $test->product->id, 'min_qty' => 0, 'rate_per_m' => 12.5]],
        ...$overrides,
    ];
}

it('derives the standing of every list from its dates and flag', function (): void {
    $cases = [
        'current' => ['valid_from' => now()->subDays(10)->toDateString(), 'valid_to' => null, 'is_active' => true],
        'ending' => ['valid_from' => now()->subDays(10)->toDateString(), 'valid_to' => now()->addDays(10)->toDateString(), 'is_active' => true],
        'upcoming' => ['valid_from' => now()->addDays(5)->toDateString(), 'valid_to' => null, 'is_active' => true],
        'lapsed' => ['valid_from' => now()->subDays(40)->toDateString(), 'valid_to' => now()->subDay()->toDateString(), 'is_active' => true],
        'inactive' => ['valid_from' => now()->subDays(10)->toDateString(), 'valid_to' => null, 'is_active' => false],
    ];
    $ids = [];

    foreach ($cases as $standing => $dates) {
        $ids[$standing] = DB::table('price_lists')->insertGetId([
            'customer_id' => $this->customer->id,
            'code' => 'PL-T-'.strtoupper(substr($standing, 0, 4)),
            'name' => ucfirst($standing),
            'currency_id' => $this->currency->id,
            ...$dates,
        ]);
    }

    $this->actingAs($this->admin)
        ->get('/price-lists?customer='.$this->customer->id)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($ids): void {
            $props = $page->toArray()['props'];
            $rows = collect($props['lists']['data'])->keyBy('id');

            foreach ($ids as $standing => $id) {
                expect($rows[$id]['standing'])->toBe($standing);
                expect((int) $props['counts'][$standing])->toBeGreaterThanOrEqual(1);
            }
        });

    $this->actingAs($this->admin)
        ->get('/price-lists?standing=lapsed')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(array_unique(array_column($page->toArray()['props']['lists']['data'], 'standing')))->toBe(['lapsed']));
});

it('sorts by code when asked, and finds a list by its customer', function (): void {
    $this->actingAs($this->admin)->post('/price-lists', priceListPayload($this, ['code' => 'PL-T-AAA', 'valid_from' => now()->addYears(2)->toDateString(), 'valid_to' => now()->addYears(2)->addDay()->toDateString()]))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/price-lists', priceListPayload($this, ['code' => 'PL-T-ZZZ', 'valid_from' => now()->addYears(3)->toDateString(), 'valid_to' => now()->addYears(3)->addDay()->toDateString()]))->assertSessionHasNoErrors();

    $this->actingAs($this->admin)
        ->get('/price-lists?sort=code')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $codes = array_column($page->toArray()['props']['lists']['data'], 'code');
            $sorted = $codes;
            sort($sorted, SORT_STRING);

            expect($codes)->toBe($sorted);
        });

    $this->actingAs($this->admin)
        ->get('/price-lists?q='.urlencode(substr($this->customer->name, 0, 5)))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => expect(array_column($page->toArray()['props']['lists']['data'], 'code'))->toContain('PL-T-AAA'));
});

it('refuses a second active list that overlaps another for the same customer, and names it', function (): void {
    $this->actingAs($this->admin)->post('/price-lists', priceListPayload($this, ['code' => 'PL-T-FIRST', 'valid_from' => now()->toDateString(), 'valid_to' => now()->addDays(60)->toDateString()]))->assertSessionHasNoErrors();

    $response = $this->actingAs($this->admin)->post('/price-lists', priceListPayload($this, ['code' => 'PL-T-SECOND', 'valid_from' => now()->addDays(30)->toDateString(), 'valid_to' => null]));

    $response->assertSessionHasErrors('valid_from');
    expect(session('errors')->getBag('default')->first('valid_from'))->toContain('PL-T-FIRST');

    // Starting the day after the first ends is fine.
    $this->actingAs($this->admin)->post('/price-lists', priceListPayload($this, ['code' => 'PL-T-NEXT', 'valid_from' => now()->addDays(61)->toDateString(), 'valid_to' => null]))->assertSessionHasNoErrors();

    // And an inactive one may overlap anything.
    $this->actingAs($this->admin)->post('/price-lists', priceListPayload($this, ['code' => 'PL-T-OFF', 'valid_from' => now()->toDateString(), 'valid_to' => null, 'is_active' => false]))->assertSessionHasNoErrors();
});

it('accepts a list that starts and ends on the same day', function (): void {
    $day = now()->addYears(5)->toDateString();

    $this->actingAs($this->admin)
        ->post('/price-lists', priceListPayload($this, ['valid_from' => $day, 'valid_to' => $day]))
        ->assertSessionHasNoErrors();
});

it('says what to do when no rate is given', function (): void {
    $response = $this->actingAs($this->admin)->post('/price-lists', priceListPayload($this, ['lines' => []]));

    $response->assertSessionHasErrors('lines');
    expect(session('errors')->getBag('default')->first('lines'))->toBe('Add at least one rate.');
});

it('preselects the customer a list is started from, and tells the page its standing and overlaps', function (): void {
    $this->actingAs($this->admin)
        ->get('/price-lists/create?customer='.$this->customer->id)
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('preselectCustomerId', $this->customer->id));

    $this->actingAs($this->admin)->post('/price-lists', priceListPayload($this, ['code' => 'PL-T-SHOW']))->assertSessionHasNoErrors();
    $id = (int) DB::table('price_lists')->where('code', 'PL-T-SHOW')->value('id');

    $this->actingAs($this->admin)
        ->get("/price-lists/{$id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('list.standing', 'current')
            ->has('overlapping'));
});
