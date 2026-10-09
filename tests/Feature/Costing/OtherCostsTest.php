<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Costing\CostComponents;
use App\Modules\Costing\Models\CostSheet;
use App\Modules\Costing\Services\CostSheetService;
use App\Modules\Costing\States\CostSheetStateMachine;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\States\QuotationStateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;

/**
 * Spec §4 — the other-cost components: commission and finance as a share of the price,
 * courier, inspection, certificate, development, freight as amounts, subcontract per unit.
 * Each is a line on the sheet, and a share of the price is resolved with the price.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->actingAs($this->admin);
    $this->product = Product::query()->with('currentSpec')->firstOrFail();
});

it('adds lump sums and per-unit amounts to the cost, and resolves a share of the price with the price', function (): void {
    $service = app(CostSheetService::class);
    $plain = $service->estimate($this->product, 50000, ['marginPct' => 20]);
    $with = $service->estimate($this->product, 50000, ['marginPct' => 20, 'extraCosts' => [
        ['key' => 'commission', 'value' => 5],            // 5 % of the price
        ['key' => 'inspection', 'value' => 1000],         // a lump sum
        ['key' => 'subcontract', 'value' => 0.01],        // per unit
    ]]);

    $types = collect($with->lines)->pluck('costType')->all();
    $commission = collect($with->lines)->first(fn ($l) => $l->costType === 'commission');
    $inspection = collect($with->lines)->first(fn ($l) => $l->description === 'Inspection');
    $subcontract = collect($with->lines)->first(fn ($l) => $l->costType === 'subcontract');

    expect($types)->toContain('commission', 'subcontract', 'other')
        ->and($inspection->amount)->toBe(1000.0)
        ->and($subcontract->amount)->toBe(500.0)
        // price = c / (1 − 0.20 − 0.05); commission = 5 % of that price; margin stays 20 % of price
        ->and(round($commission->amount, 2))->toBe(round($with->sellingValue * 0.05, 2))
        ->and(round($with->sellingValue - $with->totalCost, 2))->toBe(round($with->sellingValue * 0.20, 2))
        ->and($with->totalCost)->toBeGreaterThan($plain->totalCost + 1500 - 0.01)
        ->and($with->ratePerM)->toBeGreaterThan($plain->ratePerM);

    expect(fn () => $service->estimate($this->product, 50000, ['marginPct' => 60, 'extraCosts' => [['key' => 'commission', 'value' => 45]]]))
        ->toThrow(InvalidArgumentException::class, '100%');
});

it('persists the components with the quotation line and hands them back to the form', function (): void {
    $customer = DB::table('customers')->value('id');
    $currency = DB::table('currencies')->where('code', 'BDT')->value('id') ?? DB::table('currencies')->value('id');

    $this->post('/quotations', [
        'customer_id' => $customer,
        'currency_id' => $currency,
        'exchange_rate' => 1,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'lines' => [[
            'product_id' => $this->product->id,
            'product_spec_id' => $this->product->currentSpec?->id,
            'description' => 'Care label',
            'qty' => 10000,
            'margin_pct' => 20,
            'extra_costs' => [['key' => 'commission', 'value' => 3], ['key' => 'courier', 'value' => 250]],
        ]],
    ])->assertSessionHasNoErrors();

    $quotation = Quotation::query()->latest('id')->firstOrFail();
    $sheet = CostSheet::query()->where('quotation_line_id', $quotation->lines()->value('id'))->with('lines')->firstOrFail();
    $extras = $sheet->lines->where('formula_ref', CostComponents::FORMULA);

    expect($extras)->toHaveCount(2)
        ->and(CostComponents::fromLines($sheet->lines))->toEqualCanonicalizing([
            ['key' => 'courier', 'label' => 'Courier', 'basis' => 'amount', 'value' => 250.0],
            ['key' => 'commission', 'label' => 'Commission', 'basis' => 'pct_of_price', 'value' => 3.0],
        ]);

    $this->get("/quotations/{$quotation->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => expect($page->toArray()['props']['quotation']['lines'][0]['extra_costs'])->toHaveCount(2));
});

it('lets a line set its own overhead and admin % and say the buyer pays for the tool, and hands them back to the form', function (): void {
    $customer = DB::table('customers')->value('id');
    $currency = DB::table('currencies')->where('code', 'BDT')->value('id') ?? DB::table('currencies')->value('id');

    $this->post('/quotations', [
        'customer_id' => $customer, 'currency_id' => $currency, 'exchange_rate' => 1,
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
        'lines' => [[
            'product_id' => $this->product->id, 'product_spec_id' => $this->product->currentSpec?->id,
            'description' => 'Care label', 'qty' => 10000, 'margin_pct' => 20,
            'overhead_pct' => 15, 'admin_pct' => 3, 'customer_pays_tooling' => true,
        ]],
    ])->assertSessionHasNoErrors();

    $quotation = Quotation::query()->latest('id')->firstOrFail();
    $line = $quotation->lines()->firstOrFail();
    $sheet = CostSheet::query()->where('quotation_line_id', $line->id)->with('lines')->firstOrFail();

    expect($line->cost_overrides)->toEqualCanonicalizing(['overhead_pct' => 15, 'admin_pct' => 3, 'customer_pays_tooling' => true])
        ->and((float) $sheet->overhead_pct)->toBe(15.0)
        ->and((float) $sheet->admin_pct)->toBe(3.0)
        ->and((float) $sheet->tooling_cost)->toBe(0.0)
        ->and($sheet->lines->where('cost_type', 'tooling'))->toBeEmpty();

    $this->get("/quotations/{$quotation->id}/edit")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('quotation.lines.0.overhead_pct', 15)
            ->where('quotation.lines.0.admin_pct', 3)
            ->where('quotation.lines.0.customer_pays_tooling', true));
});

it('sends a quotation under the margin floor only on an approved sheet, or by someone who may accept the margin', function (): void {
    $customer = DB::table('customers')->value('id');
    $currency = DB::table('currencies')->where('code', 'BDT')->value('id') ?? DB::table('currencies')->value('id');

    $this->post('/quotations', [
        'customer_id' => $customer, 'currency_id' => $currency, 'exchange_rate' => 1,
        'quotation_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(),
        'lines' => [['product_id' => $this->product->id, 'product_spec_id' => $this->product->currentSpec?->id, 'description' => 'Care label', 'qty' => 10000, 'margin_pct' => 8, 'rate_per_m' => 350]],
    ])->assertSessionHasNoErrors();

    $quotation = Quotation::query()->latest('id')->firstOrFail();
    $sheet = CostSheet::query()->where('quotation_line_id', $quotation->lines()->value('id'))->firstOrFail();
    expect((float) $sheet->margin_pct)->toBe(8.0);

    // Costed at 8 % against a 12 % floor: sending needs the sheet approved.

    $merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->actingAs($merchandiser);
    expect(fn () => app(QuotationStateMachine::class)->transition($quotation->fresh(), 'sent'))
        ->toThrow(TransitionDenied::class, 'not approved');

    // Submitted by the clerk, approved by the Managing Director: now it goes.
    app(CostSheetStateMachine::class)->transition($sheet, 'submitted');
    $this->actingAs(User::query()->where('email', 'md@octapussolution.com')->firstOrFail());
    app(CostSheetStateMachine::class)->transition($sheet->fresh(), 'approved');

    $this->actingAs($merchandiser);
    app(QuotationStateMachine::class)->transition($quotation->fresh(), 'sent');
    expect($quotation->fresh()->status)->toBe('sent');
});
