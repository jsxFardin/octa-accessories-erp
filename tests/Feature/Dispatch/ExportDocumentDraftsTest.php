<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\ExportShipment;
use App\Modules\Sales\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Export documents, step 4 (DF-6 AC1): the commercial invoice, packing list and certificate of
 * origin are drafted from the shipment's own data, filed, and listed as drafted documents that
 * satisfy the checklist; drafting again replaces the earlier draft.
 */
beforeEach(function (): void {
    Storage::fake();

    $this->dispatch = User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail();
    $this->lab = User::query()->where('email', 'lab@octapussolution.com')->firstOrFail();
    $this->order = SalesOrder::query()->whereNotNull('number')->orderBy('id')->firstOrFail();
    $line = DB::table('sales_order_lines')->where('sales_order_id', $this->order->id)->orderBy('line_no')->firstOrFail();

    $challanId = DB::table('delivery_challans')->insertGetId([
        'number' => 'DC-DOC-'.uniqid(), 'sales_order_id' => $this->order->id, 'customer_id' => $this->order->customer_id,
        'delivery_address_id' => DB::table('customer_addresses')->where('customer_id', $this->order->customer_id)->value('id'),
        'challan_date' => now()->toDateString(), 'mode' => 'freight_forwarder', 'total_cartons' => 4, 'total_qty' => 2000, 'status' => 'issued', 'created_at' => now(),
    ]);
    DB::table('delivery_challan_lines')->insert([
        'delivery_challan_id' => $challanId, 'line_no' => 1, 'sales_order_line_id' => $line->id, 'product_id' => $line->product_id, 'qty' => 2000, 'cartons' => 4,
    ]);

    $this->actingAs($this->dispatch)->post('/export-shipments', [
        'customer_id' => $this->order->customer_id, 'mode' => 'sea', 'currency_id' => $this->order->currency_id, 'incoterm' => 'FOB',
        'port_of_loading' => 'Chattogram', 'port_of_discharge' => 'Rotterdam', 'challan_id' => $challanId, 'container_no' => 'MSKU1234567', 'seal_no' => 'S-88',
    ])->assertSessionHasNoErrors();
    $this->shipment = ExportShipment::query()->latest('id')->firstOrFail();
});

it('drafts the three documents from the shipment, files them and satisfies the checklist', function (): void {
    foreach (['commercial_invoice', 'packing_list', 'coo'] as $type) {
        $this->actingAs($this->dispatch)->post("/export-shipments/{$this->shipment->id}/documents/generate", ['doc_type' => $type])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
    }

    $documents = DB::table('export_documents')->where('export_shipment_id', $this->shipment->id)->get();

    expect($documents)->toHaveCount(3)
        ->and($documents->pluck('status')->unique()->all())->toBe(['drafted'])
        ->and($documents->firstWhere('doc_type', 'commercial_invoice')->doc_no)->toEndWith('/CI');

    foreach ($documents as $document) {
        Storage::assertExists($document->file_path);
        expect(Storage::size($document->file_path))->toBeGreaterThan(1000);
    }

    expect(app(App\Modules\Dispatch\Services\ExportDocumentChecklist::class)->missing($this->shipment))->toBe(['exp_form', 'bl']);

    // The printable itself renders with the goods and the terms on it.
    $this->get("/export-shipments/commercial-invoices/{$this->shipment->id}/print")->assertOk()->assertSee('Commercial invoice')->assertSee('FOB')->assertSee('Rotterdam');
    $this->get("/export-shipments/packing-lists/{$this->shipment->id}/print")->assertOk()->assertSee('MSKU1234567');
    $this->get("/export-shipments/certificates-of-origin/{$this->shipment->id}/print")->assertOk()->assertSee('Bangladesh');
});

it('replaces an earlier draft rather than stacking one, and refuses a type it does not generate', function (): void {
    $this->actingAs($this->dispatch);
    $this->post("/export-shipments/{$this->shipment->id}/documents/generate", ['doc_type' => 'packing_list'])->assertSessionHasNoErrors();
    $first = DB::table('export_documents')->where('export_shipment_id', $this->shipment->id)->where('doc_type', 'packing_list')->first();

    $this->post("/export-shipments/{$this->shipment->id}/documents/generate", ['doc_type' => 'packing_list'])->assertSessionHasNoErrors();
    $rows = DB::table('export_documents')->where('export_shipment_id', $this->shipment->id)->where('doc_type', 'packing_list')->get();

    expect($rows)->toHaveCount(1)->and($rows->first()->id)->toBe($first->id);
    Storage::assertMissing($first->file_path);

    $this->post("/export-shipments/{$this->shipment->id}/documents/generate", ['doc_type' => 'bl'])->assertSessionHasErrors('doc_type');
});

it('is the dispatch desk\'s to draft', function (): void {
    $this->actingAs($this->lab)->post("/export-shipments/{$this->shipment->id}/documents/generate", ['doc_type' => 'coo'])->assertForbidden();
});
