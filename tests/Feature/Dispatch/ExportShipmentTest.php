<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\ExportShipment;
use App\Modules\Dispatch\Services\ExportDocumentChecklist;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Trade\Models\LetterOfCredit;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Export shipments (plan step 3, DF-6): the consignment carries delivery notes, ships under the
 * customer's credit and moves it along, and is complete only once its document checklist is.
 */
beforeEach(function (): void {
    $this->dispatch = User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail();
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->lab = User::query()->where('email', 'lab@octapussolution.com')->firstOrFail();
    $this->order = SalesOrder::query()->whereNotNull('number')->orderBy('id')->firstOrFail();

    /** An issued delivery note written straight to the table; the dispatch walkthrough is DispatchTest's job. */
    $this->challan = function (array $overrides = []): object {
        $id = DB::table('delivery_challans')->insertGetId([
            'number' => 'DC-EXP-'.uniqid(),
            'sales_order_id' => $this->order->id,
            'customer_id' => $this->order->customer_id,
            'delivery_address_id' => DB::table('customer_addresses')->where('customer_id', $this->order->customer_id)->value('id'),
            'challan_date' => now()->toDateString(),
            'mode' => 'freight_forwarder',
            'total_cartons' => 10,
            'total_qty' => 5000,
            'status' => 'issued',
            'created_at' => now(),
            ...$overrides,
        ]);

        return DB::table('delivery_challans')->where('id', $id)->first();
    };

    $this->actingAs($this->merchandiser)->post('/export-lcs', [
        'lc_no' => 'EBL-EXP-5120', 'kind' => 'sight', 'customer_id' => $this->order->customer_id, 'currency_id' => $this->order->currency_id,
        'amount' => 20000, 'issued_on' => now()->toDateString(), 'last_shipment_date' => now()->addDays(20)->toDateString(), 'expiry_date' => now()->addDays(40)->toDateString(),
        'incoterm' => 'FOB', 'port_of_loading' => 'Chattogram', 'port_of_discharge' => 'Hamburg',
    ])->assertSessionHasNoErrors();
    $this->lc = LetterOfCredit::query()->latest('id')->firstOrFail();
    $this->post("/export-lcs/{$this->lc->id}/orders", ['sales_order_id' => $this->order->id]);
    $this->post("/export-lcs/{$this->lc->id}/transition", ['status' => 'accepted'])->assertSessionHasNoErrors();

    $this->open = function (?int $challanId = null, array $overrides = []): ExportShipment {
        $this->actingAs($this->dispatch)->post('/export-shipments', [
            'customer_id' => $this->order->customer_id,
            'lc_id' => $this->lc->id,
            'mode' => 'sea',
            'currency_id' => $this->order->currency_id,
            'incoterm' => 'FOB',
            'etd' => now()->addDays(5)->toDateString(),
            'eta' => now()->addDays(30)->toDateString(),
            'challan_id' => $challanId,
            ...$overrides,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return ExportShipment::query()->latest('id')->firstOrFail();
    };
});

it('opens from a delivery note with the order\'s credit and terms prefilled, and attaches it on save', function (): void {
    $challan = ($this->challan)();

    $this->actingAs($this->dispatch)->get("/export-shipments/create?challan={$challan->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Dispatch/ExportShipments/Form')
            ->where('preselect.customer_id', (int) $this->order->customer_id)
            ->where('preselect.lc_id', $this->lc->id)
            ->where('preselect.port_of_loading', 'Chattogram'));

    $shipment = ($this->open)((int) $challan->id);

    expect($shipment->status)->toBe('draft')->and($shipment->number)->toBeNull()
        ->and((int) DB::table('delivery_challans')->where('id', $challan->id)->value('export_shipment_id'))->toBe($shipment->id);

    $this->get("/delivery-challans/{$challan->id}")->assertInertia(fn (AssertableInertia $page) => $page->where('challan.export_shipment.id', $shipment->id));
});

it('carries only this customer\'s issued notes, each on one shipment', function (): void {
    $shipment = ($this->open)();
    $challan = ($this->challan)();

    $this->actingAs($this->dispatch)->post("/export-shipments/{$shipment->id}/challans", ['challan_id' => $challan->id])->assertSessionHasNoErrors();

    $second = ($this->open)();
    $this->post("/export-shipments/{$second->id}/challans", ['challan_id' => $challan->id])->assertSessionHasErrors('challan_id');

    $draft = ($this->challan)(['status' => 'draft']);
    $this->post("/export-shipments/{$shipment->id}/challans", ['challan_id' => $draft->id])->assertSessionHasErrors('challan_id');

    // Booking needs something on board; it is what gives the number.
    $this->post("/export-shipments/{$second->id}/transition", ['status' => 'booked'])->assertSessionHas('error');
    $this->post("/export-shipments/{$shipment->id}/transition", ['status' => 'booked'])->assertSessionHasNoErrors();
    expect($shipment->refresh()->status)->toBe('booked')->and($shipment->number)->toStartWith('ESH');
});

it('ships only within the credit\'s last shipment date, stamps the date and moves the credit along', function (): void {
    $shipment = ($this->open)((int) ($this->challan)()->id);
    $this->actingAs($this->dispatch)->post("/export-shipments/{$shipment->id}/transition", ['status' => 'booked']);

    $this->post("/export-shipments/{$shipment->id}/transition", ['status' => 'shipped', 'shipped_on' => now()->addDays(25)->toDateString()])->assertSessionHas('error');
    expect($shipment->refresh()->status)->toBe('booked')->and($this->lc->refresh()->status)->toBe('accepted');

    $this->post("/export-shipments/{$shipment->id}/transition", ['status' => 'shipped', 'shipped_on' => now()->addDays(3)->toDateString()])->assertSessionHasNoErrors();
    expect($shipment->refresh()->status)->toBe('shipped')
        ->and($shipment->shipped_on?->toDateString())->toBe(now()->addDays(3)->toDateString())
        ->and($this->lc->refresh()->status)->toBe('shipped');

    $this->post("/export-shipments/{$shipment->id}/transition", ['status' => 'presented', 'presented_on' => now()->addDays(10)->toDateString()])->assertSessionHasNoErrors();
    expect($shipment->refresh()->status)->toBe('presented')
        ->and($this->lc->refresh()->status)->toBe('presented')
        ->and($this->lc->presented_on?->toDateString())->toBe(now()->addDays(10)->toDateString());
});

it('derives the document checklist from mode, credit, buyer and terms, and refuses to close until it is complete', function (): void {
    DB::table('customers')->where('id', $this->order->customer_id)->update(['is_bonded' => true]);
    $shipment = ($this->open)((int) ($this->challan)()->id, ['incoterm' => 'CIF', 'mode' => 'air']);

    expect(app(ExportDocumentChecklist::class)->required($shipment))
        ->toBe(['commercial_invoice', 'packing_list', 'coo', 'exp_form', 'awb', 'lc_document', 'ud', 'insurance']);

    $this->actingAs($this->dispatch);
    $this->post("/export-shipments/{$shipment->id}/transition", ['status' => 'booked']);
    $this->post("/export-shipments/{$shipment->id}/transition", ['status' => 'shipped']);
    $this->post("/export-shipments/{$shipment->id}/transition", ['status' => 'delivered'])->assertSessionHasNoErrors();

    $this->post("/export-shipments/{$shipment->id}/transition", ['status' => 'closed'])->assertSessionHas('error');
    expect($shipment->refresh()->status)->toBe('delivered');

    foreach (['commercial_invoice' => 'CI-1', 'packing_list' => 'PL-1', 'coo' => 'COO-1', 'exp_form' => 'EXP-1', 'awb' => '125-55512345', 'lc_document' => 'EBL-EXP-5120', 'ud' => 'UD-9', 'insurance' => 'INS-2'] as $type => $no) {
        $this->post("/export-shipments/{$shipment->id}/documents", ['doc_type' => $type, 'doc_no' => $no, 'doc_date' => now()->toDateString()])->assertSessionHasNoErrors();
    }

    // The transport and customs numbers are mirrored onto the shipment.
    expect($shipment->refresh()->transport_doc_no)->toBe('125-55512345')->and($shipment->exp_form_no)->toBe('EXP-1')->and($shipment->ud_no)->toBe('UD-9')
        ->and(app(ExportDocumentChecklist::class)->missing($shipment))->toBe([]);

    $this->get("/export-shipments/{$shipment->id}")->assertInertia(fn (AssertableInertia $page) => $page->has('checklist', 8)->has('documents', 8));

    $this->post("/export-shipments/{$shipment->id}/transition", ['status' => 'closed'])->assertSessionHasNoErrors();
    expect($shipment->refresh()->status)->toBe('closed');
});

it('without a credit there is nothing to present, and cancelling needs a reason and frees the notes', function (): void {
    $challan = ($this->challan)();
    $shipment = ($this->open)((int) $challan->id, ['lc_id' => null]);

    $this->actingAs($this->dispatch);
    $this->post("/export-shipments/{$shipment->id}/transition", ['status' => 'booked']);
    $this->post("/export-shipments/{$shipment->id}/transition", ['status' => 'shipped'])->assertSessionHasNoErrors();
    $this->post("/export-shipments/{$shipment->id}/transition", ['status' => 'presented'])->assertSessionHas('error');

    $other = ($this->open)((int) ($this->challan)()->id, ['lc_id' => null]);
    $this->post("/export-shipments/{$other->id}/transition", ['status' => 'cancelled'])->assertSessionHas('error');
    $this->post("/export-shipments/{$other->id}/transition", ['status' => 'cancelled', 'reason' => 'Booking rolled'])->assertSessionHasNoErrors();
    expect($other->refresh()->status)->toBe('cancelled');
});

it('is kept from roles without the permission', function (): void {
    $this->actingAs($this->lab)->get('/export-shipments')->assertForbidden();
    $this->actingAs($this->merchandiser)->post('/export-shipments', [])->assertForbidden();
});
