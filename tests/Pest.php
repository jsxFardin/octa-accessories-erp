<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\Models\SalesInvoiceLine;
use App\Modules\Finance\States\CreditNoteStateMachine;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\Models\SalesReturnLine;
use App\Modules\Sales\States\SalesReturnStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
 * Unit tests are pure calculators — no database, no framework. Feature tests get the real
 * MySQL schema, because the invariants they assert are enforced by the database.
 */
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/**
 * The vocabulary rows as `ReferenceDataSeeder` writes them, without a database.
 *
 * Product type and cut type behaviour is data now (docs/02a-schema.sql §1a), so a unit test of
 * a calculator has to state which behaviour it is testing. These are the seeded figures;
 * `VocabularySeedTest` asserts the rows in the database still match them, which is what fails
 * if someone edits the seeder and not the rules it encodes.
 *
 * @var array<string, array{0: bool, 1: bool, 2: float|null, 3: bool}>
 */
const PRODUCT_TYPE_FIXTURES = [
    //                      yarn,  sheets, ink g/m², tool per colour
    'woven' => [true, false, null, false],
    'flexo' => [false, false, 1.6, true],
    'screen' => [false, false, 8.0, true],
    'heat_transfer' => [false, false, 12.0, true],
    'offset_tag' => [false, true, 1.1, true],
    'thermal' => [false, false, null, false],
    'ribbon' => [false, false, null, false],
    'tape' => [false, false, null, false],
    'other' => [false, false, null, false],
];

/** @var array<string, array{0: float, 1: bool}> gap mm, needs a die */
const CUT_TYPE_FIXTURES = [
    'hot_cut' => [2.0, false],
    'ultrasonic' => [2.0, false],
    'laser' => [1.5, false],
    'die_cut' => [3.0, true],
    'straight_cut' => [1.0, false],
];

function productTypeRule(string $code): App\Support\Calculators\ProductTypeRule
{
    [$yarn, $sheets, $ink, $tool] = PRODUCT_TYPE_FIXTURES[$code];

    return new App\Support\Calculators\ProductTypeRule($code, $code, $yarn, $sheets, $ink, $tool);
}

function cutTypeRule(string $code): App\Support\Calculators\CutTypeRule
{
    [$gap, $tool] = CUT_TYPE_FIXTURES[$code];

    return new App\Support\Calculators\CutTypeRule($code, $code, $gap, $tool);
}

/**
 * `SpecInput::fromArray` with the two rules resolved from the fixtures above — what the
 * application does with the vocabulary tables, done from constants instead.
 *
 * @param  array<string, mixed>  $spec
 */
function fixtureSpec(array $spec): App\Support\Calculators\SpecInput
{
    return App\Support\Calculators\SpecInput::fromArray(
        $spec,
        productTypeRule((string) ($spec['product_type'] ?? 'woven')),
        cutTypeRule((string) ($spec['cut_type'] ?? 'hot_cut')),
    );
}

/**
 * Assert a decimal to the precision the schema actually stores (AD-7).
 */
expect()->extend('toBeMoney', function (float $expected) {
    return $this->toBeFloat()->and(round($this->value, 4))->toBe(round($expected, 4));
});

expect()->extend('toBeQty', function (float $expected) {
    return $this->toBeFloat()->and(round($this->value, 6))->toBe(round($expected, 6));
});

/**
 * Bring every operation before `$target` to `completed` with output booked (J2).
 *
 * A test about the packing step should not also be a test of weaving, slitting and folding —
 * but nor may it pretend those steps never ran, because since F-04 the floor API refuses
 * production on a step whose predecessor is still open. This is the fixture for "the job got
 * this far, legitimately"; `tests/Feature/Manufacturing/OperationChainTest.php` is where the
 * chain itself is walked through the real API.
 */
function completeOperationsBefore(
    App\Modules\Manufacturing\Models\JobCardOperation $target,
    float $good = 1000000.0,
): void {
    $earlier = DB::table('job_card_operations')
        ->where('job_card_id', $target->job_card_id)
        ->where('sequence_no', '<', $target->sequence_no);

    // QC1 — a step flagged `requires_qc` that completed was inspected and passed. Without
    // this the fixture describes a web that was cut before the inspector saw it, which the
    // API is right to refuse.
    foreach ((clone $earlier)->where('requires_qc', true)->get(['id']) as $inspected) {
        DB::table('qc_inspections')->insert([
            'stage' => 'in_process',
            'job_card_id' => $target->job_card_id,
            'job_card_operation_id' => $inspected->id,
            'inspected_on' => now()->toDateString(),
            'lot_size' => (int) $good,
            'result' => 'accepted',
            'created_at' => now(),
        ]);
    }

    $earlier->update([
        'input_qty' => $good,
        'good_qty' => $good,
        'waste_qty' => 0,
        'status' => App\Modules\Manufacturing\Models\JobCardOperation::COMPLETED,
        'started_at' => now()->subHour(),
        'finished_at' => now()->subMinutes(30),
    ]);
}

/**
 * Issue enough BOM material to a job card to account for `$coversQty` finished pieces (BR-48).
 *
 * Since F-08 the FG receipt refuses output the issued material cannot account for, which is
 * the whole point of the rule and not the subject of most of these tests. The rows are
 * written directly rather than through the issue screen: the fixture's job is to say "the
 * store issued what this run needed", not to re-test the issue workflow, and `tests/Feature/
 * Manufacturing/MaterialRequiredTest.php` is where the rule itself is exercised.
 *
 * Lots are picked from whatever the seed holds for the item; a lot the item has never had is
 * not a thing the store could have issued, so the line is skipped rather than invented.
 */
function issueMaterialFor(
    App\Modules\Manufacturing\Models\JobCard $jobCard,
    float $coversQty,
    float $unitCost = 100.0,
): ?int {
    $bom = $jobCard->bom;

    if ($bom === null) {
        return null;
    }

    $lines = DB::table('bom_lines')
        ->where('bom_id', $bom->getKey())
        ->where('is_optional', false)
        ->get(['item_id', 'uom_id', 'qty_per_base']);

    if ($lines->isEmpty()) {
        return null;
    }

    $warehouseId = (int) DB::table('warehouses')
        ->where('kind', 'raw_material')->value('id')
        ?: (int) DB::table('warehouses')->value('id');

    $issueId = DB::table('material_issues')->insertGetId([
        'number' => 'MI-FIX-'.Str::random(8),
        'job_card_id' => $jobCard->getKey(),
        'warehouse_id' => $warehouseId,
        'issued_on' => now()->toDateString(),
        'issue_type' => 'issue',
        'status' => 'posted',
        'created_at' => now(),
    ]);

    $lineNo = 0;

    foreach ($lines as $line) {
        $lot = DB::table('stock_lots')
            ->where('item_id', $line->item_id)
            ->orderBy('id')
            ->first(['id']);

        if ($lot === null) {
            continue;
        }

        DB::table('material_issue_lines')->insert([
            'material_issue_id' => $issueId,
            'line_no' => ++$lineNo,
            'item_id' => $line->item_id,
            'lot_id' => $lot->id,
            'uom_id' => $line->uom_id,
            'qty' => max(0.000001, $bom->scaleTo((float) $line->qty_per_base, $coversQty)),
            'unit_cost' => $unitCost,
        ]);
    }

    return $issueId;
}

/**
 * A posted, valued material issue against a job, independent of its BOM.
 *
 * `issueMaterialFor()` walks the job's mandatory BOM lines, so it issues nothing for a job
 * that has no BOM or only optional lines. Those jobs still consume *something* in reality —
 * and under BR-52 finished goods with no material value behind them need an authorised waiver
 * — so a fixture for them has to put a real, costed consumption on the job rather than leave
 * it producing stock worth nothing.
 */
function issueAnyMaterialFor(
    App\Modules\Manufacturing\Models\JobCard $jobCard,
    float $unitCost = 100.0,
    float $qty = 10.0,
): int {
    $lot = DB::table('stock_lots')
        ->whereNotNull('item_id')
        ->orderBy('id')
        ->first(['id', 'item_id', 'uom_id', 'warehouse_id']);

    $issueId = DB::table('material_issues')->insertGetId([
        'number' => 'MI-ANY-'.Str::random(8),
        'job_card_id' => $jobCard->getKey(),
        'warehouse_id' => $lot->warehouse_id,
        'issued_on' => now()->toDateString(),
        'issue_type' => 'issue',
        'status' => 'posted',
        'created_at' => now(),
    ]);

    DB::table('material_issue_lines')->insert([
        'material_issue_id' => $issueId,
        'line_no' => 1,
        'item_id' => $lot->item_id,
        'lot_id' => $lot->id,
        'uom_id' => $lot->uom_id,
        'qty' => $qty,
        'unit_cost' => $unitCost,
    ]);

    return $issueId;
}

/*
 * Sales-return fixtures, shared across the Finance and Sales suites.
 *
 * They live here rather than in one test file because three suites now build the same shape —
 * an invoice, money against it, goods coming back — and a helper that only exists while its own
 * file happens to be loaded is a helper that works until someone runs one test.
 */
function appInvoice(object $test, float $qty, float $rate, ?int $currencyId = null): SalesInvoice
{
    $total = round($qty / 1000 * $rate, 4);

    $test->actingAs($test->accounts);

    $invoice = SalesInvoice::query()->create([
        'customer_id' => $test->customerId,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $currencyId ?? $test->currencyId, 'exchange_rate' => 1,
        'subtotal' => $total, 'tax_amount' => 0, 'total' => $total, 'status' => 'draft',
    ]);

    SalesInvoiceLine::query()->create([
        'sales_invoice_id' => $invoice->id, 'line_no' => 1, 'product_id' => $test->productId,
        'description' => 'application fixture', 'qty' => $qty, 'rate_per_m' => $rate,
        'tax_amount' => 0, 'amount' => $total,
    ]);

    app(SalesInvoiceStateMachine::class)->transition($invoice, 'issued');

    return $invoice->refresh();
}

function appPay(object $test, SalesInvoice $invoice): SalesInvoice
{
    $test->actingAs($test->accounts);
    $test->post('/receipts', [
        'customer_id' => $test->customerId, 'receipt_date' => now()->toDateString(),
        'method' => 'bank_transfer', 'currency_id' => (int) $invoice->currency_id,
        'amount' => (float) $invoice->total,
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => (float) $invoice->total]],
    ])->assertSessionHas('success');

    return $invoice->refresh();
}

/**
 * P2-1 — where a credit note's value is consumed, now that it need not be the invoice the
 * credit came from.
 *
 * The distinction under test, in one line:
 *
 *   credit_notes.sales_invoice_id          → where did this credit come from
 *   credit_note_applications.sales_invoice_id → where was it consumed
 *
 * Merging those is what would drive a paid invoice negative, so the suite asserts both halves:
 * the return credit reaches a different, open invoice, and the paid invoice it came from is
 * untouched and still reconciles to zero.
 */
beforeEach(function (): void {
    $this->customerId = (int) DB::table('sales_orders')->value('customer_id');
    $this->currencyId = (int) DB::table('currencies')->where('code', 'USD')->value('id');
    $this->productId = (int) DB::table('products')->value('id');
    $this->warehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');

    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->dispatchUser = User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail();

    $this->applications = app(CreditNoteApplicationService::class);
    $this->invoices = app(SalesInvoiceStateMachine::class);
});

/** Post a return against $invoice and return the approved credit note it drafted. */
function returnCredit(object $test, SalesInvoice $invoice, float $qty): CreditNote
{
    $line = SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)->orderBy('line_no')->firstOrFail();

    $lot = (int) DB::table('stock_lots')->insertGetId([
        'lot_no' => 'L-AP-'.Str::random(10), 'product_id' => $test->productId,
        'kind' => 'finished_goods', 'warehouse_id' => $test->warehouseId,
        'uom_id' => (int) DB::table('uoms')->where('code', 'pcs')->value('id'),
        'received_qty' => 0, 'balance_qty' => 0, 'unit_cost' => 1.5,
        'received_on' => now()->toDateString(), 'status' => 'available', 'created_at' => now(),
    ]);

    $return = SalesReturn::query()->create([
        'sales_invoice_id' => $invoice->id, 'customer_id' => (int) $invoice->customer_id,
        'warehouse_id' => $test->warehouseId, 'returned_on' => now()->toDateString(),
        'reason' => 'Customer returned goods', 'status' => SalesReturn::DRAFT,
        'created_by' => $test->dispatchUser->id,
    ]);

    SalesReturnLine::query()->create([
        'sales_return_id' => $return->id, 'line_no' => 1,
        'sales_invoice_line_id' => $line->id, 'product_id' => $line->product_id,
        'lot_id' => $lot, 'qty' => $qty, 'rate_per_m' => $line->rate_per_m,
    ]);

    $states = app(SalesReturnStateMachine::class);
    $test->actingAs($test->accounts);
    $states->transition($return, SalesReturn::APPROVED);
    $test->actingAs($test->dispatchUser);
    $states->transition($return->refresh(), SalesReturn::POSTED);

    $note = CreditNote::query()->where('sales_return_id', $return->id)->firstOrFail();

    $test->actingAs($test->accounts);
    app(CreditNoteStateMachine::class)->transition($note, CreditNote::APPROVED);

    return $note->refresh();
}

/** A draft sales return of $qty against $invoice's first line, with a traceable lot. */
function draftSalesReturn(object $test, SalesInvoice $invoice, float $qty): SalesReturn
{
    $line = SalesInvoiceLine::query()->where('sales_invoice_id', $invoice->id)
        ->orderBy('line_no')->firstOrFail();

    $lot = (int) DB::table('stock_lots')->insertGetId([
        'lot_no' => 'L-SRH-'.Str::random(10), 'product_id' => $line->product_id,
        'kind' => 'finished_goods',
        'warehouse_id' => (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id'),
        'uom_id' => (int) DB::table('uoms')->where('code', 'pcs')->value('id'),
        'received_qty' => 0, 'balance_qty' => 0, 'unit_cost' => 1.5,
        'received_on' => now()->toDateString(), 'status' => 'available', 'created_at' => now(),
    ]);

    $return = SalesReturn::query()->create([
        'sales_invoice_id' => $invoice->id, 'customer_id' => (int) $invoice->customer_id,
        'warehouse_id' => (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id'),
        'returned_on' => now()->toDateString(), 'reason' => 'Customer returned goods',
        'status' => SalesReturn::DRAFT,
    ]);

    SalesReturnLine::query()->create([
        'sales_return_id' => $return->id, 'line_no' => 1,
        'sales_invoice_line_id' => $line->id, 'product_id' => $line->product_id,
        'lot_id' => $lot, 'qty' => $qty, 'rate_per_m' => $line->rate_per_m,
    ]);

    return $return->refresh();
}
