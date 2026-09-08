<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Print\DocumentRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Every document view, rendered once, on both endpoints.
 *
 * DocumentPdfTest proves the registry cannot lie about permissions, views and statuses. It does
 * not prove that a view runs: a mistyped column on a Blade line renders as an exception the first
 * time somebody presses Print, which is on a day someone is waiting for the paper. So each
 * document gets the smallest row that makes it printable, and is then asked for as HTML and as a
 * PDF. Nothing here asserts wording — that belongs with the document — only that it renders and
 * that dompdf can lay it out.
 *
 * The walkthrough seed stops at production, so most of these rows are built here. Built rather
 * than skipped: a skipped test proves nothing, and a fixture that skips is how a view quietly
 * stops being covered.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->customer = DB::table('customers')->firstOrFail();
    $this->product = DB::table('products')->firstOrFail();
    $this->item = DB::table('items')->firstOrFail();
    $this->currency = (int) DB::table('currencies')->where('is_base', true)->value('id');
});

dataset('documents', [
    'inquiry' => ['inquiries', 'renderableInquiry'],
    'quotation' => ['quotations', 'renderableQuotation'],
    'order confirmation' => ['sales-orders', 'renderableSalesOrder'],
    'invoice' => ['invoices', 'renderableSalesInvoice'],
    'credit note' => ['credit-notes', 'renderableCreditNote'],
    'money receipt' => ['receipts', 'renderableReceipt'],
    'packing list' => ['packing-lists', 'renderablePackingList'],
    'delivery challan' => ['delivery-challans', 'renderableChallan'],
    'purchase order' => ['purchase-orders', 'renderablePurchaseOrder'],
    'request for quotation' => ['rfqs', 'renderableRfq'],
    'test report' => ['test-reports', 'renderableTestReport'],
    'job card' => ['job-cards', 'renderableJobCard'],
]);

it('renders as a printable page', function (string $key, string $factory): void {
    $id = $factory($this);

    $this->actingAs($this->admin)
        ->get('/'.DocumentRegistry::find($key)['segment']."/{$id}/print")
        ->assertOk()
        ->assertSee(DocumentRegistry::find($key)['label'], false);
})->with('documents');

it('renders as a PDF dompdf can lay out', function (string $key, string $factory): void {
    $id = $factory($this);

    $response = $this->actingAs($this->admin)
        ->get('/'.DocumentRegistry::find($key)['segment']."/{$id}/pdf");

    $response->assertOk();
    expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
    // A PDF that laid nothing out still starts with the magic number. A document with a
    // letterhead, a line table and a signature block does not fit in two kilobytes.
    expect(strlen($response->getContent()))->toBeGreaterThan(2048);
})->with('documents');

// --- Fixtures ------------------------------------------------------------------------------

function renderableInquiry(object $test): int
{
    $id = (int) DB::table('inquiries')->insertGetId([
        'number' => 'INQ-REN-1',
        'customer_id' => $test->customer->id,
        'brand_id' => DB::table('brands')->value('id'),
        'inquiry_date' => now()->toDateString(),
        'required_by' => now()->addDays(45)->toDateString(),
        'merchandiser_id' => DB::table('employees')->value('id'),
        'status' => 'open',
        'notes' => 'Repeat programme, two colourways.',
    ]);

    DB::table('inquiry_lines')->insert([
        'inquiry_id' => $id,
        'line_no' => 1,
        'product_id' => $test->product->id,
        'description' => '50,000 woven care labels',
        'product_type' => $test->product->product_type,
        'qty' => 50000,
        'target_rate_per_m' => 3.1,
        'notes' => 'Match last season.',
    ]);

    return $id;
}

function renderableQuotation(object $test): int
{
    $merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();

    $test->actingAs($merchandiser)->post('/quotations', [
        'customer_id' => $test->product->customer_id,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'currency_id' => $test->currency,
        'exchange_rate' => 1,
        'lines' => [[
            'product_id' => $test->product->id,
            'description' => '50,000 woven care labels',
            'qty' => 50000,
            'rate_per_m' => 3.25,
            'margin_pct' => 22,
        ]],
    ]);

    return (int) DB::table('quotations')->max('id');
}

function renderableSalesOrder(object $test): int
{
    // The seed leaves one order behind; only its status stands between it and a confirmation.
    $id = (int) DB::table('sales_orders')->min('id');

    DB::table('sales_orders')->where('id', $id)->update([
        'status' => 'confirmed',
        'confirmed_at' => now(),
    ]);

    $line = DB::table('sales_order_lines')->where('sales_order_id', $id)->first();

    // A schedule, because the schedule is the half of this document a quotation does not have.
    if ($line !== null && ! DB::table('so_delivery_schedules')->where('sales_order_line_id', $line->id)->exists()) {
        DB::table('so_delivery_schedules')->insert([
            ['sales_order_line_id' => $line->id, 'sequence_no' => 1, 'qty' => 20000, 'due_date' => now()->addDays(20)->toDateString()],
            ['sales_order_line_id' => $line->id, 'sequence_no' => 2, 'qty' => 30000, 'due_date' => now()->addDays(34)->toDateString()],
        ]);
    }

    return $id;
}

function renderableSalesInvoice(object $test): int
{
    $id = (int) DB::table('sales_invoices')->insertGetId([
        'number' => 'INV-REN-1',
        'customer_id' => $test->customer->id,
        'sales_order_id' => DB::table('sales_orders')->value('id'),
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $test->currency,
        'exchange_rate' => 1,
        'subtotal' => 162500,
        'tax_amount' => 0,
        'total' => 162500,
        'received_amount' => 50000,
        'status' => 'partially_paid',
        'mushak_no' => '6.3-REN-1',
    ]);

    DB::table('sales_invoice_lines')->insert([
        'sales_invoice_id' => $id,
        'line_no' => 1,
        'product_id' => $test->product->id,
        'description' => '50,000 woven care labels',
        'qty' => 50000,
        'rate_per_m' => 3.25,
        'tax_id' => DB::table('taxes')->value('id'),
        'tax_amount' => 0,
        'amount' => 162500,
    ]);

    return $id;
}

function renderableCreditNote(object $test): int
{
    return (int) DB::table('credit_notes')->insertGetId([
        'number' => 'CN-REN-1',
        'customer_id' => $test->customer->id,
        'sales_invoice_id' => renderableSalesInvoice($test),
        'note_date' => now()->toDateString(),
        'reason' => 'short_delivery',
        'currency_id' => $test->currency,
        'amount' => 3250,
        'status' => 'approved',
        'approved_by' => $test->admin->id,
        'remarks' => 'Agreed with the buyer.',
    ]);
}

function renderableReceipt(object $test): int
{
    $invoice = renderableSalesInvoice($test);

    $id = (int) DB::table('receipts')->insertGetId([
        'number' => 'RCP-REN-1',
        'customer_id' => $test->customer->id,
        'receipt_date' => now()->toDateString(),
        'method' => 'cheque',
        'reference_no' => '004512',
        'bank_name' => 'Test Bank Ltd.',
        'currency_id' => $test->currency,
        'exchange_rate' => 1,
        'amount' => 50000,
        'allocated_amount' => 50000,
        'status' => 'posted',
    ]);

    DB::table('receipt_allocations')->insert([
        'receipt_id' => $id,
        'sales_invoice_id' => $invoice,
        'amount' => 50000,
    ]);

    return $id;
}

function renderablePackingList(object $test): int
{
    $id = (int) DB::table('packing_lists')->insertGetId([
        'number' => 'PL-REN-1',
        'sales_order_id' => DB::table('sales_orders')->value('id'),
        'customer_id' => $test->customer->id,
        'delivery_address_id' => DB::table('customer_addresses')->value('id'),
        'packed_on' => now()->toDateString(),
        'total_cartons' => 2,
        'total_qty' => 50000,
        'gross_weight_kg' => 21.4,
        'net_weight_kg' => 19.8,
        'status' => 'packed',
        'cert_claim_scheme' => 'grs',
        'cert_claim_pct' => 60,
    ]);

    foreach ([['C-001', 30000], ['C-002', 20000]] as [$number, $qty]) {
        $carton = (int) DB::table('cartons')->insertGetId([
            'packing_list_id' => $id,
            'carton_no' => $number,
            'barcode' => 'CTN'.$number,
            'gross_weight_kg' => 10.7,
            'net_weight_kg' => 9.9,
            'length_cm' => 40,
            'width_cm' => 30,
            'height_cm' => 25,
        ]);

        DB::table('carton_contents')->insert([
            'carton_id' => $carton,
            'product_id' => $test->product->id,
            'lot_id' => DB::table('stock_lots')->where('kind', 'fg')->value('id'),
            'colourway' => 'Black / white',
            'qty' => $qty,
            'bundles' => 30,
        ]);
    }

    return $id;
}

function renderableChallan(object $test): int
{
    $id = (int) DB::table('delivery_challans')->insertGetId([
        'number' => 'DC-REN-1',
        'packing_list_id' => renderablePackingList($test),
        'sales_order_id' => DB::table('sales_orders')->value('id'),
        'customer_id' => $test->customer->id,
        'delivery_address_id' => DB::table('customer_addresses')->value('id'),
        'challan_date' => now()->toDateString(),
        'mode' => 'own_fleet',
        'total_cartons' => 2,
        'total_qty' => 50000,
        'status' => 'issued',
        'gate_pass_no' => 'GP-REN-1',
    ]);

    DB::table('delivery_challan_lines')->insert([
        'delivery_challan_id' => $id,
        'line_no' => 1,
        'sales_order_line_id' => DB::table('sales_order_lines')->value('id'),
        'product_id' => $test->product->id,
        'lot_id' => DB::table('stock_lots')->where('kind', 'fg')->value('id'),
        'qty' => 50000,
        'cartons' => 2,
    ]);

    return $id;
}

function renderablePurchaseOrder(object $test): int
{
    $id = (int) DB::table('purchase_orders')->insertGetId([
        'number' => 'PO-REN-1',
        'supplier_id' => DB::table('suppliers')->where('is_approved', true)->value('id'),
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'order_date' => now()->toDateString(),
        'expected_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $test->currency,
        'exchange_rate' => 1,
        'payment_term_id' => DB::table('payment_terms')->value('id'),
        'subtotal' => 250,
        'tax_amount' => 0,
        'freight_amount' => 25,
        'total' => 275,
        'status' => 'approved',
        'approved_by' => $test->admin->id,
        'approved_at' => now(),
    ]);

    DB::table('purchase_order_lines')->insert([
        'po_id' => $id,
        'line_no' => 1,
        'item_id' => $test->item->id,
        'uom_id' => $test->item->base_uom_id,
        'qty' => 10,
        'rate' => 25,
        'amount' => 250,
        'expected_date' => now()->addDays(30)->toDateString(),
        'cert_claim' => 'grs',
    ]);

    return $id;
}

function renderableRfq(object $test): int
{
    $id = (int) DB::table('supplier_rfqs')->insertGetId([
        'number' => 'RFQ-REN-1',
        'issued_on' => now()->toDateString(),
        'respond_by' => now()->addDays(7)->toDateString(),
        'status' => 'issued',
        'created_by' => $test->admin->id,
    ]);

    DB::table('supplier_rfq_lines')->insert([
        'rfq_id' => $id,
        'line_no' => 1,
        'item_id' => $test->item->id,
        'qty' => 500,
        'uom_id' => $test->item->base_uom_id,
    ]);

    return $id;
}

function renderableTestReport(object $test): int
{
    $id = (int) DB::table('test_reports')->insertGetId([
        'number' => 'TR-REN-1',
        'lot_id' => DB::table('stock_lots')->value('id'),
        'job_card_id' => DB::table('job_cards')->value('id'),
        'product_id' => $test->product->id,
        'customer_id' => $test->customer->id,
        'tested_on' => now()->toDateString(),
        'technician_id' => DB::table('employees')->value('id'),
        'overall_result' => 'pass',
        'status' => 'issued',
        'issued_at' => now(),
    ]);

    foreach (DB::table('lab_tests')->limit(3)->get() as $labTest) {
        DB::table('test_report_lines')->insert([
            'test_report_id' => $id,
            'lab_test_id' => $labTest->id,
            'result_value' => $labTest->default_pass_value ?? '4',
            'pass_value' => $labTest->default_pass_value,
            'result' => 'pass',
        ]);
    }

    return $id;
}

function renderableJobCard(object $test): int
{
    return (int) DB::table('job_cards')->value('id');
}
