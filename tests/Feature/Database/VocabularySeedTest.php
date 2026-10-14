<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * The unit tests state the product-type and cut-type behaviour as constants
 * (`PRODUCT_TYPE_FIXTURES`, `CUT_TYPE_FIXTURES` in tests/Pest.php). This holds the seeded rows
 * to those figures, so a change to the seeder that nobody carried into the rules — or the
 * other way round — fails here instead of silently costing every quotation differently.
 */
it('seeds the product types the calculators are tested against', function (): void {
    foreach (PRODUCT_TYPE_FIXTURES as $code => [$yarn, $sheets, $ink, $tool]) {
        $row = DB::table('product_types')->where('code', $code)->first();

        expect($row)->not->toBeNull($code)
            ->and((bool) $row->consumes_yarn)->toBe($yarn, "$code consumes_yarn")
            ->and((bool) $row->consumes_sheets)->toBe($sheets, "$code consumes_sheets")
            ->and($row->default_ink_lay_gsm === null ? null : (float) $row->default_ink_lay_gsm)->toBe($ink, "$code ink")
            ->and((bool) $row->requires_tool_per_colour)->toBe($tool, "$code tool per colour");
    }
});

it('seeds the cut types the calculators are tested against', function (): void {
    foreach (CUT_TYPE_FIXTURES as $code => [$gap, $needsDie]) {
        $row = DB::table('cut_types')->where('code', $code)->first();

        expect($row)->not->toBeNull($code)
            ->and((float) $row->default_cut_gap_mm)->toBe($gap, "$code gap")
            ->and((bool) $row->requires_tool)->toBe($needsDie, "$code requires tool");
    }
});
