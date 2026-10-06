<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Sales\Models\Quotation;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * The quotation list as a pipeline, and the nightly expiry that keeps "Sent" honest.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
});

/**
 * The test data has no sent quotation, so the state is set directly: sending for real needs
 * priced cost sheets, and what is under test here is what happens *after* sending.
 *
 * @return list<Quotation>
 */
function sentQuotations(object $test, int $count): array
{
    $customer = Customer::query()->firstOrFail();
    $currency = Currency::query()->where('is_base', true)->firstOrFail();
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $test->actingAs($test->merchandiser)->post('/quotations', [
            'customer_id' => $customer->id,
            'quotation_date' => now()->toDateString(),
            'currency_id' => $currency->id,
            'lines' => [['description' => 'Woven main label', 'product_id' => null, 'qty' => 5000, 'rate_per_m' => null]],
        ])->assertSessionHasNoErrors();

        $quotation = Quotation::query()->latest('id')->firstOrFail();

        DB::table('quotations')->where('id', $quotation->id)->update([
            'number' => sprintf('QTN-T-%05d', $quotation->id),
            'status' => 'sent',
            'sent_at' => now()->subDays(10),
            'decided_at' => null,
            'valid_until' => now()->addDays(20)->toDateString(),
        ]);

        $rows[] = $quotation->fresh();
    }

    return $rows;
}

it('expires a sent quotation once its valid-until date has passed, and leaves the rest alone', function (): void {
    [$stale, $live] = sentQuotations($this, 2);
    DB::table('quotations')->where('id', $stale->id)->update(['valid_until' => now()->subDay()->toDateString()]);
    DB::table('quotations')->where('id', $live->id)->update(['valid_until' => now()->addDays(3)->toDateString()]);
    $acceptedBefore = Quotation::query()->where('status', 'accepted')->count();

    $this->artisan('quotations:expire')->assertSuccessful();

    expect($stale->fresh()->status)->toBe('expired')
        ->and($live->fresh()->status)->toBe('sent')
        ->and(Quotation::query()->where('status', 'accepted')->count())->toBe($acceptedBefore);
});

it('records the expiry on the quotation trail as a system move', function (): void {
    [$stale] = sentQuotations($this, 1);
    DB::table('quotations')->where('id', $stale->id)->update(['valid_until' => now()->subDays(2)->toDateString()]);

    $this->artisan('quotations:expire')->assertSuccessful();

    $row = DB::table('audit_logs')
        ->where('auditable_id', $stale->id)
        ->where('auditable_type', 'like', '%Quotation%')
        ->where('event', 'status_changed')
        ->orderByDesc('id')
        ->first();

    expect($row)->not->toBeNull()
        ->and(json_decode((string) $row->new_values, true)['status'] ?? null)->toBe('expired');
});

it('does nothing on a dry run', function (): void {
    [$stale] = sentQuotations($this, 1);
    DB::table('quotations')->where('id', $stale->id)->update(['valid_until' => now()->subDay()->toDateString()]);

    $this->artisan('quotations:expire', ['--dry-run' => true])->assertSuccessful();

    expect($stale->fresh()->status)->toBe('sent');
});

it('counts every stage for the strip and says how long a sent offer has left', function (): void {
    [$sent] = sentQuotations($this, 1);
    DB::table('quotations')->where('id', $sent->id)->update(['valid_until' => now()->addDays(5)->toDateString()]);
    $expected = DB::table('quotations')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->all();

    $this->actingAs($this->merchandiser)
        ->get('/quotations?status=sent')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($expected, $sent): void {
            $props = $page->toArray()['props'];

            foreach ($expected as $status => $n) {
                expect((int) $props['counts'][$status])->toBe((int) $n);
            }

            $row = collect($props['quotations']['data'])->firstWhere('id', $sent->id);

            expect($row['days_to_expiry'])->toBe(5)
                ->and($row['merchandiser'])->not->toBeNull()
                ->and((float) $row['total_qty'])->toBeGreaterThan(0);
        });
});

it('finds a quotation by its customer name', function (): void {
    [$quotation] = sentQuotations($this, 1);
    $needle = substr($quotation->customer->name, 0, 5);

    $this->actingAs($this->merchandiser)
        ->get('/quotations?q='.urlencode($needle))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($quotation): void {
            expect(array_column($page->toArray()['props']['quotations']['data'], 'id'))->toContain($quotation->id);
        });
});

it('lists every revision under the number on the quotation page', function (): void {
    $revised = Quotation::query()->where('revision_no', '>', 0)->whereNotNull('number')->first();

    if ($revised === null) {
        $this->markTestSkipped('The data has no revised quotation to show.');
    }

    $this->actingAs($this->merchandiser)
        ->get("/quotations/{$revised->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($revised): void {
            $revisions = $page->toArray()['props']['revisions'];

            expect(count($revisions))->toBeGreaterThanOrEqual(2)
                ->and(collect($revisions)->firstWhere('current', true)['id'])->toBe($revised->id);
        });
});
