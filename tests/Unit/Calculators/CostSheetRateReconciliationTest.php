<?php

declare(strict_types=1);

use App\Modules\Costing\Models\CostSheetLine;
use App\Modules\Costing\Services\CostSheetPresenter;
use App\Support\Calculators\ConsumptionCalculator;
use App\Support\Calculators\CostSheetCalculator;

/**
 * F-03 — a cost sheet row has to multiply out.
 *
 * The reported defect, on QTN-26-00049:
 *
 *     Machine   63.971 hours   Rate 0.0000   Amount BDT 6,612.37
 *     Labour    63.971 hours   Rate 0.0000   Amount BDT 5,149.23
 *
 * The rate was not merely mis-displayed — the calculator wrote a literal `0.0`. Each routing
 * step carries its own machine rate, manning level and power draw, so there is no single rate
 * across a multi-operation job, and a zero had been put where the blended one belonged. A
 * costing dispute over that sheet is unanswerable.
 *
 * Two halves are held here: the calculator now stores a rate that reconciles, and the
 * presenter recovers one for the sheets already snapshotted with a zero — because a sent
 * quotation is never rewritten (Q1).
 */
beforeEach(function (): void {
    $this->calc = new CostSheetCalculator(new ConsumptionCalculator);
    $this->presenter = new CostSheetPresenter;
});

/** Rows whose arithmetic is `qty × rate = amount`. Percentage rows are a different shape. */
const RATE_ROWS = [
    'material_yarn', 'material_ribbon', 'material_ink', 'material_chemical',
    'material_paper', 'material_film', 'tooling', 'machine', 'labour', 'energy', 'packing',
];

it('br16: prices the machine row at a rate that reconciles with its amount', function (): void {
    $sheet = $this->calc->build(costInput());

    $machine = collect($sheet->lines)->firstWhere('costType', 'machine');

    expect($machine)->not->toBeNull()
        ->and($machine->rate)->toBeGreaterThan(0.0)
        ->and(round($machine->qty * $machine->rate, 2))->toBe(round($machine->amount, 2));
});

it('br17: prices the labour row at a rate that reconciles with its amount', function (): void {
    $sheet = $this->calc->build(costInput());

    $labour = collect($sheet->lines)->firstWhere('costType', 'labour');

    expect($labour)->not->toBeNull()
        ->and($labour->rate)->toBeGreaterThan(0.0)
        ->and(round($labour->qty * $labour->rate, 2))->toBe(round($labour->amount, 2));
});

it('br18: books energy in kWh rather than pricing hours at a per-kWh tariff', function (): void {
    // The energy row carried machine *hours* under a `kWh` heading and priced them at the
    // tariff, so `hours × tariff` never equalled `hours × kW × tariff`. A 40 kW press and a
    // 4 kW folder do not draw the same power in the same hour.
    $sheet = $this->calc->build(costInput());

    $energy = collect($sheet->lines)->firstWhere('costType', 'energy');
    $machine = collect($sheet->lines)->firstWhere('costType', 'machine');

    expect($energy)->not->toBeNull()
        ->and($energy->basis)->toBe('kWh')
        // kWh is not hours, so the two rows no longer share a quantity.
        ->and($energy->qty)->not->toBe($machine->qty)
        ->and(round($energy->qty * $energy->rate, 2))->toBe(round($energy->amount, 2));
});

it('every rate row on a generated sheet multiplies out', function (): void {
    $sheet = $this->calc->build(costInput(['toolingCost' => 9000.0]));

    foreach ($sheet->lines as $line) {
        if (! in_array($line->costType, RATE_ROWS, true)) {
            continue;
        }

        expect(round($line->qty * $line->rate, 2))
            ->toBe(round($line->amount, 2), "{$line->costType} does not reconcile");
    }
});

it('blends the machine rate across operations rather than reporting any one of them', function (): void {
    // The three seeded steps run at 120, 180 and 90 per hour. The blended rate is what the job
    // paid on average and must sit inside that range — a single step's rate would be a
    // different and wrong claim.
    $sheet = $this->calc->build(costInput());

    $machine = collect($sheet->lines)->firstWhere('costType', 'machine');

    expect($machine->rate)->toBeGreaterThan(90.0)
        ->and($machine->rate)->toBeLessThan(180.0);
});

it('reads a persisted line model rather than its internal properties', function (): void {
    // F-01. `(array)` on an Eloquent model returns its internal properties under null-byte
    // keys, not its columns, so every lookup missed and every row rendered as a blank cost
    // type with a quantity and amount of zero — a screen full of zeros over untouched data.
    //
    // The assertions here are deliberately on *values*, not on arithmetic: a row of zeros
    // satisfies `qty × rate = amount` perfectly, which is how the original defect slipped past
    // a check written to catch a mis-stated rate.
    $model = new CostSheetLine;
    $model->forceFill([
        'sequence_no' => 1,
        'cost_type' => 'material_ink',
        'description' => 'Material ink',
        'basis_uom' => 'kg',
        'qty' => 2.7027,
        'rate' => 2200.0,
        'amount' => 5945.94,
        'formula_ref' => 'BR-10',
    ]);

    $row = $this->presenter->line($model);

    expect($row['cost_type'])->toBe('material_ink')
        ->and($row['basis_uom'])->toBe('kg')
        ->and($row['sequence_no'])->toBe(1)
        ->and($row['formula_ref'])->toBe('BR-10')
        ->and($row['qty'])->toBeGreaterThan(0.0)
        ->and($row['amount'])->toBeGreaterThan(0.0)
        ->and(round($row['qty'] * $row['rate'], 2))->toBe(5945.94);
});

it('reads a query-builder row too', function (): void {
    $row = $this->presenter->line((object) [
        'sequence_no' => 2, 'cost_type' => 'machine', 'basis_uom' => 'hour',
        'qty' => 10.0, 'rate' => 120.0, 'amount' => 1200.0, 'formula_ref' => 'BR-16',
        'description' => null,
    ]);

    expect($row['cost_type'])->toBe('machine')->and($row['amount'])->toBe(1200.0);
});

it('recovers a quantity booked in the wrong unit so the row foots', function (): void {
    // Energy rows snapshotted before the calculator booked kWh hold machine *hours* under a
    // `kWh` heading, so `hours × tariff` never equalled the amount beside it. The amount was
    // summed into the sheet total and the tariff is a rate card, so the quantity is the figure
    // that cannot be trusted. Q1: the stored sheet is not rewritten.
    $row = $this->presenter->line([
        'sequence_no' => 4, 'cost_type' => 'energy', 'basis_uom' => 'kWh',
        'qty' => 63.97125,      // machine hours, mislabelled
        'rate' => 12.0,         // the tariff, correct
        'amount' => 673.8585,   // what was actually charged, correct
        'formula_ref' => 'BR-18', 'description' => null,
    ]);

    expect($row['qty_is_derived'])->toBeTrue()
        ->and($row['rate'])->toBe(12.0)
        ->and($row['amount'])->toBe(673.8585)
        ->and(round($row['qty'] * $row['rate'], 4))->toBe(673.8585);
});

it('leaves a row that already foots alone', function (): void {
    $row = $this->presenter->line([
        'sequence_no' => 1, 'cost_type' => 'material_yarn', 'basis_uom' => 'kg',
        'qty' => 11.09196, 'rate' => 1535.0, 'amount' => 17026.1586,
        'formula_ref' => 'BR-9', 'description' => null,
    ]);

    expect($row['qty_is_derived'])->toBeFalse()
        ->and($row['rate_is_derived'])->toBeFalse()
        ->and($row['qty'])->toBe(11.09196);
});

it('recovers a rate from a snapshotted row that stored none', function (): void {
    // A sent quotation's sheet is never rewritten (Q1), so the historical zero stays in the
    // database and the rate the job actually paid is recovered for display, flagged as derived.
    $row = $this->presenter->line([
        'sequence_no' => 8,
        'cost_type' => 'machine',
        'basis_uom' => 'hour',
        'qty' => 63.971,
        'rate' => 0.0,
        'amount' => 6612.37,
        'formula_ref' => 'BR-16',
        'description' => null,
    ]);

    expect($row['rate_is_derived'])->toBeTrue()
        ->and($row['basis'])->toBe('rate')
        ->and(round($row['qty'] * $row['rate'], 2))->toBe(6612.37);
});

it('leaves a stored rate alone and does not flag it as derived', function (): void {
    $row = $this->presenter->line([
        'sequence_no' => 2,
        'cost_type' => 'material_ribbon',
        'basis_uom' => 'metre',
        'qty' => 1000.0,
        'rate' => 2.5,
        'amount' => 2500.0,
        'formula_ref' => 'BR-7',
        'description' => null,
    ]);

    expect($row['rate_is_derived'])->toBeFalse()
        ->and($row['rate'])->toBe(2.5)
        ->and($row['qty'] * $row['rate'])->toBe($row['amount']);
});

it('marks overhead, admin and margin as percentage rows carrying their base', function (): void {
    // `qty` is the percentage and `rate` is the base it applies to. Printed under a Qty × Rate
    // heading, `12.75` beside `20,000` beside `2,550` invites the reader to multiply and get
    // 255,000, which is how a sheet becomes impossible to reconcile.
    foreach (['overhead', 'admin_overhead', 'margin'] as $costType) {
        $row = $this->presenter->line([
            'sequence_no' => 14,
            'cost_type' => $costType,
            'basis_uom' => '%',
            'qty' => 12.75,
            'rate' => 20000.0,
            'amount' => 2550.0,
            'formula_ref' => 'BR-19',
            'description' => null,
        ]);

        expect($row['basis'])->toBe('percentage')
            ->and($row['percentage_of'])->toBe(20000.0)
            ->and($row['rate_is_derived'])->toBeFalse();
    }
});

it('does not invent a rate for a zero-quantity row', function (): void {
    $row = $this->presenter->line([
        'sequence_no' => 5,
        'cost_type' => 'tooling',
        'basis_uom' => 'job',
        'qty' => 0.0,
        'rate' => 0.0,
        'amount' => 1200.0,
        'formula_ref' => 'BR-15',
        'description' => null,
    ]);

    expect($row['rate_is_derived'])->toBeFalse()
        ->and($row['rate'])->toBe(0.0);
});
