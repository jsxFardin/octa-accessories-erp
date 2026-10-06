<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Sales\Models\Inquiry;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * The inquiry list as a pipeline and the inquiry page as a dossier: counts by stage, search
 * by customer, the value and the handler on every row, and the deadline read against today.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
});

function newInquiry(object $test, array $overrides = [], array $lines = []): Inquiry
{
    $test->actingAs($test->merchandiser)->post('/inquiries', [
        'customer_id' => $test->customer->id,
        'inquiry_date' => now()->subDays(5)->toDateString(),
        'required_by' => now()->addDays(20)->toDateString(),
        'lines' => $lines ?: [['description' => 'Centre-fold care label', 'qty' => 2000, 'target_rate_per_m' => 12.5]],
        ...$overrides,
    ])->assertSessionHasNoErrors();

    return Inquiry::query()->latest('id')->firstOrFail();
}

it('counts every stage for the strip, whatever the list is filtered to', function (): void {
    $expected = DB::table('inquiries')->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status')->all();

    $this->actingAs($this->merchandiser)
        ->get('/inquiries?status=lost')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($expected): void {
            $counts = $page->toArray()['props']['counts'];

            foreach ($expected as $status => $n) {
                expect((int) $counts[$status])->toBe((int) $n);
            }

            foreach ($page->toArray()['props']['inquiries']['data'] as $row) {
                expect($row['status'])->toBe('lost');
            }
        });
});

it('finds an inquiry by its customer name, not only its number', function (): void {
    $inquiry = newInquiry($this);
    $needle = substr($this->customer->name, 0, 5);

    $this->actingAs($this->merchandiser)
        ->get('/inquiries?q='.urlencode($needle))
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($inquiry): void {
            $ids = array_column($page->toArray()['props']['inquiries']['data'], 'id');

            expect($ids)->toContain($inquiry->id);
        });
});

it('puts the indicative value, the quantity and the handler on every row', function (): void {
    $inquiry = newInquiry($this, [], [
        ['description' => 'Care label', 'qty' => 2000, 'target_rate_per_m' => 12.5],
        ['description' => 'Hang tag', 'qty' => 1000],
    ]);

    $this->actingAs($this->merchandiser)
        ->get('/inquiries?status=draft')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($inquiry): void {
            $row = collect($page->toArray()['props']['inquiries']['data'])->firstWhere('id', $inquiry->id);

            expect($row)->not->toBeNull()
                ->and((float) $row['total_qty'])->toBe(3000.0)
                // 2000 ÷ 1000 × 12.5, and nothing for the line with no target.
                ->and((float) $row['value'])->toBe(25.0)
                ->and($row['merchandiser'])->toBe($this->merchandiser->name)
                ->and($row['age_days'])->toBe(5)
                ->and($row['overdue'])->toBeFalse();
        });
});

it('flags an open inquiry past its required-by date, but not a decided one', function (): void {
    $inquiry = newInquiry($this, ['required_by' => now()->addDay()->toDateString()]);
    DB::table('inquiries')->where('id', $inquiry->id)->update(['required_by' => now()->subDays(3)->toDateString()]);

    $row = fn (): array => collect($this->actingAs($this->merchandiser)->get('/inquiries?customer='.$this->customer->id)
        ->viewData('page')['props']['inquiries']['data'])->firstWhere('id', $inquiry->id);

    expect($row()['overdue'])->toBeTrue()
        ->and($row()['days_to_required'])->toBe(-3);

    DB::table('inquiries')->where('id', $inquiry->id)->update(['status' => 'won']);

    expect($row()['overdue'])->toBeFalse();
});

it('says what to do when no line is given, instead of naming a request key', function (): void {
    $response = $this->actingAs($this->merchandiser)->post('/inquiries', [
        'customer_id' => $this->customer->id,
        'inquiry_date' => now()->toDateString(),
        'lines' => [],
    ]);

    $response->assertSessionHasErrors('lines');

    expect(session('errors')->getBag('default')->first('lines'))->toBe('Add at least one line.');
});

it('records contact and brand when the form sends them, and shows them on the dossier', function (): void {
    $brandId = DB::table('brands')->where('customer_id', $this->customer->id)->value('id');
    $contactId = DB::table('customer_contacts')->where('customer_id', $this->customer->id)->value('id');

    $inquiry = newInquiry($this, array_filter([
        'brand_id' => $brandId,
        'customer_contact_id' => $contactId,
    ]));

    $this->actingAs($this->merchandiser)
        ->get("/inquiries/{$inquiry->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($brandId, $contactId): void {
            $props = $page->toArray()['props'];

            expect($props['inquiry']['merchandiser']['name'])->toBe($this->merchandiser->name)
                ->and((float) $props['inquiry']['total_qty'])->toBe(2000.0)
                ->and((float) $props['inquiry']['indicative_value'])->toBe(25.0)
                ->and($props['statusChanges'])->toBeArray();

            if ($brandId) {
                expect($props['inquiry']['brand']['id'])->toBe($brandId);
            }

            if ($contactId) {
                expect($props['inquiry']['contact']['id'])->toBe($contactId);
            }
        });
});

it('hands the form the contacts and brands to choose from', function (): void {
    $this->actingAs($this->merchandiser)
        ->get('/inquiries/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Sales/Inquiries/Form')
            ->has('contacts')
            ->has('brands'));
});

it('records when the inquiry moved stage, for the milestone strip', function (): void {
    $inquiry = newInquiry($this);

    $this->actingAs($this->merchandiser)
        ->post("/inquiries/{$inquiry->id}/transition", ['status' => 'open'])
        ->assertRedirect();

    $this->actingAs($this->merchandiser)
        ->get("/inquiries/{$inquiry->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $changes = $page->toArray()['props']['statusChanges'];

            expect(array_column($changes, 'status'))->toContain('open');
        });
});
