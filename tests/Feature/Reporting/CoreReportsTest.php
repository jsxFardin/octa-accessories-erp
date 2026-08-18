<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\Models\SalesInvoiceLine;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\Services\FgReceiptService;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Modules\Quality\Models\Ncr;
use App\Modules\Reporting\Queries\FulfilmentReport;
use App\Modules\Reporting\Queries\NcrCapaReport;
use App\Modules\Reporting\Queries\ProductionReport;
use App\Modules\Reporting\Queries\ReceivableReport;
use App\Modules\Reporting\Queries\StockReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * P2-3 — the six operational reports. Read-only, same formulas as the transactional screens,
 * permission-gated, and reconcilable to source rows.
 */
beforeEach(function (): void {
    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());

    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->soLineId = (int) $this->jobCard->sales_order_line_id;
    $this->soId = (int) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('sales_order_id');
    $this->customerId = (int) DB::table('sales_orders')->where('id', $this->soId)->value('customer_id');
    $this->currencyId = (int) DB::table('currencies')->where('code', 'USD')->value('id');
});

function p23Rows(object $report, array $query = []): array
{
    $request = Request::create('/reports/'.$report->key(), 'GET', $query);

    return collect($report->paginate($request)->items())->map(fn ($row): array => (array) $row)->all();
}

function p23Invoice(object $test, float $total): SalesInvoice
{
    $test->actingAs(User::query()->where('email', 'accounts@maheenlabel.test')->firstOrFail());

    $invoice = SalesInvoice::query()->create([
        'customer_id' => $test->customerId,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $test->currencyId,
        'exchange_rate' => 1,
        'subtotal' => $total, 'tax_amount' => 0, 'total' => $total,
        'status' => 'draft',
    ]);

    SalesInvoiceLine::query()->create([
        'sales_invoice_id' => $invoice->id, 'line_no' => 1,
        'product_id' => (int) $test->jobCard->product_id ?: (int) DB::table('products')->value('id'),
        'description' => 'report fixture', 'qty' => 1000, 'rate_per_m' => $total, 'tax_amount' => 0, 'amount' => $total,
    ]);

    app(SalesInvoiceStateMachine::class)->transition($invoice, 'issued');

    return $invoice->refresh();
}

function p23Credit(object $test, SalesInvoice $invoice, float $amount): CreditNote
{
    $test->actingAs(User::query()->where('email', 'accounts@maheenlabel.test')->firstOrFail());

    $test->post('/credit-notes', [
        'sales_invoice_id' => $invoice->id, 'reason' => 'quality_claim', 'amount' => $amount,
    ]);
    $note = CreditNote::query()->latest('id')->firstOrFail();
    $test->post("/credit-notes/{$note->id}/transition", ['to' => 'approved']);
    $test->post("/credit-notes/{$note->id}/transition", ['to' => 'applied']);

    return $note->refresh();
}

it('refuses an operator and a driver at the route', function (): void {
    $this->actingAs(User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail())
        ->get('/reports')->assertForbidden();
    $this->actingAs(User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail())
        ->get('/reports/fulfilment')->assertForbidden();
    $this->actingAs(User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail())
        ->get('/reports')->assertForbidden();
    $this->actingAs(User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail())
        ->get('/reports/receivables')->assertForbidden();
});

it('lists the reports for an authorised reader', function (): void {
    $this->actingAs(User::query()->where('email', 'merchandiser@maheenlabel.test')->firstOrFail())
        ->get('/reports')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/Index')
            ->has('reports', 8)
            ->where('reports.0.key', 'fulfilment'));

    foreach (['fulfilment', 'production', 'stock', 'dispatch', 'receivables', 'payables', 'purchases', 'ncr-capa'] as $key) {
        $this->get("/reports/{$key}")->assertOk();
    }
});

it('matches fulfilment ordered qty to the sales-order lines', function (): void {
    $so = DB::table('sales_orders')->where('id', $this->soId)->first();
    $ordered = (float) DB::table('sales_order_lines')->where('sales_order_id', $this->soId)->sum('ordered_qty');
    $produced = (float) DB::table('sales_order_lines')->where('sales_order_id', $this->soId)->sum('produced_qty');
    $delivered = (float) DB::table('sales_order_lines')->where('sales_order_id', $this->soId)->sum('delivered_qty');
    $invoiced = (float) DB::table('sales_order_lines')->where('sales_order_id', $this->soId)->sum('invoiced_qty');

    $this->get('/reports/fulfilment')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/Show')
            ->where('report.key', 'fulfilment'));

    $row = collect(p23Rows(app(FulfilmentReport::class)))->firstWhere('id', $this->soId);

    expect($row)->not->toBeNull()
        ->and($row['number'])->toBe($so->number)
        ->and((float) $row['ordered_qty'])->toBeQty($ordered)
        ->and((float) $row['produced_qty'])->toBeQty($produced)
        ->and((float) $row['delivered_qty'])->toBeQty($delivered)
        ->and((float) $row['invoiced_qty'])->toBeQty($invoiced);
});

it('filters fulfilment by order date', function (): void {
    $orderDate = (string) DB::table('sales_orders')->where('id', $this->soId)->value('order_date');

    $inside = p23Rows(app(FulfilmentReport::class), ['from' => $orderDate, 'to' => $orderDate]);
    $outside = p23Rows(app(FulfilmentReport::class), ['from' => '1990-01-01', 'to' => '1990-01-02']);

    expect(collect($inside)->firstWhere('id', $this->soId))->not->toBeNull()
        ->and(collect($outside)->firstWhere('id', $this->soId))->toBeNull();

    $this->get('/reports/fulfilment?from=1990-01-01&to=1990-01-02')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('rows.data', 0));
});

it('returns 404 for an unknown report key', function (): void {
    $this->get('/reports/not-a-report')->assertNotFound();
});

it('reconciles stock report balances to the ledger view', function (): void {
    $this->get('/reports/stock')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Reports/Show')
            ->where('report.key', 'stock')
            ->where('extras.reconciliation.mismatched', []));

    $row = collect(p23Rows(app(StockReport::class)))->first();

    expect($row)->not->toBeNull()
        ->and((float) $row['balance_qty'])->toBeQty((float) $row['ledger_qty']);
});

it('matches production unreceived qty to FgReceiptService::positionFor', function (): void {
    $states = app(JobCardStateMachine::class);
    $states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'report walkthrough']);
    $states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);
    $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail()
        ->forceFill(['input_qty' => 2000, 'good_qty' => 2000, 'status' => JobCardOperation::COMPLETED])
        ->save();

    $position = app(FgReceiptService::class)->positionFor($this->jobCard->refresh());
    $row = collect(p23Rows(app(ProductionReport::class)))->firstWhere('id', $this->jobCard->id);

    expect($row)->not->toBeNull()
        ->and((float) $row['produced_qty'])->toBeQty($position['produced'])
        ->and((float) $row['fg_received_qty'])->toBeQty($position['received'])
        ->and((float) $row['unreceived_qty'])->toBeQty($position['remaining_receivable']);
});

it('matches receivable outstanding to total minus received minus applied credits', function (): void {
    $invoice = p23Invoice($this, 1000);
    p23Credit($this, $invoice, 250);

    $machine = app(SalesInvoiceStateMachine::class);
    $outstanding = $machine->outstanding($invoice->refresh());
    $credited = $machine->appliedCredits($invoice);

    $this->actingAs(User::query()->where('email', 'accounts@maheenlabel.test')->firstOrFail())
        ->get('/reports/receivables')
        ->assertOk();

    $row = collect(p23Rows(app(ReceivableReport::class)))->firstWhere('id', $invoice->id);

    expect($row)->not->toBeNull()
        ->and((float) $row['total'])->toBeMoney(1000)
        ->and((float) $row['received_amount'])->toBeMoney((float) $invoice->received_amount)
        ->and((float) $row['credited_amount'])->toBeMoney($credited)
        ->and((float) $row['outstanding_amount'])->toBeMoney($outstanding)
        ->and((float) $row['total'])->toBeMoney(
            (float) $row['received_amount'] + (float) $row['credited_amount'] + (float) $row['outstanding_amount'],
        );
});

it('lists an NCR after QC rejection and flags an overdue CAPA', function (): void {
    $states = app(JobCardStateMachine::class);
    $states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'ncr report']);
    $states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);
    $this->jobCard->operations()->update(['status' => JobCardOperation::COMPLETED, 'input_qty' => 1000, 'good_qty' => 1000]);
    $states->transition($this->jobCard->refresh(), JobCard::QC_PENDING);

    $this->qc = User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail();
    $this->quality = User::query()->where('email', 'quality@maheenlabel.test')->firstOrFail();

    $this->actingAs($this->qc);
    $this->post('/qc-inspections', [
        'job_card_id' => $this->jobCard->id, 'stage' => 'final', 'lot_size' => 500,
        'major_found' => 50, 'disposition' => 'rework',
    ])->assertSessionHasNoErrors();

    $ncr = Ncr::query()->orderByDesc('id')->firstOrFail();

    $open = collect(p23Rows(app(NcrCapaReport::class)))->firstWhere('id', $ncr->id);

    expect($open)->not->toBeNull()
        ->and($open['number'])->toBe($ncr->number)
        ->and($open['status'])->toBe(Ncr::OPEN)
        ->and($open['overdue'])->toBe('no');

    $this->actingAs($this->qc);
    $this->post("/ncrs/{$ncr->id}/assign", ['owner_id' => $this->quality->id])->assertSessionHasNoErrors();
    $this->post("/ncrs/{$ncr->id}/investigate", [
        'root_cause' => 'Registration drift on the press.',
        'action' => 'Reset the impression and reprint the lot.',
        'preventive_action' => 'Add a mid-shift registration check.',
        'due_date' => now()->subDay()->toDateString(),
    ])->assertSessionHasNoErrors();

    $this->actingAs(User::query()->where('email', 'quality@maheenlabel.test')->firstOrFail())
        ->get('/reports/ncr-capa')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Reports/Show')->where('report.key', 'ncr-capa'));

    $investigating = collect(p23Rows(app(NcrCapaReport::class)))->firstWhere('id', $ncr->id);

    expect($investigating['status'])->toBe(Ncr::INVESTIGATING)
        ->and($investigating['overdue'])->toBe('yes')
        ->and($investigating['corrective_action'])->toBe('Reset the impression and reprint the lot.')
        ->and($investigating['preventive_action'])->toBe('Add a mid-shift registration check.');
});
