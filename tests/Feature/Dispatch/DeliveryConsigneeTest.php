<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Dispatch\Models\PackingList;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Modules\MasterData\Models\Customer;
use App\Modules\MasterData\Models\CustomerAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * F-05 / D4 — a delivery note says where the goods went and to whom.
 *
 * Every row of the delivery-note list read "Customer —", and the detail page named no
 * consignee, no address and no sales order. Underneath the display problem was a real one:
 * nothing required a delivery address before the goods left the gate, and nothing checked
 * that the buyer on the note was the buyer on the order it fulfils.
 */
beforeEach(function (): void {
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $this->soLineId = (int) $this->jobCard->sales_order_line_id;
    $this->soId = (int) DB::table('sales_order_lines')->where('id', $this->soLineId)->value('sales_order_id');
    $this->customerId = (int) DB::table('sales_orders')->where('id', $this->soId)->value('customer_id');

    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $states = app(JobCardStateMachine::class);
    $states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'consignee walkthrough']);
    $states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);
    // BR-48 — a job that produced finished goods consumed material to do it. The store's
    // issue is the fixture; F-08 is why the FG receipt now insists on it.
    issueMaterialFor($this->jobCard->refresh(), 60000);

    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    $final->forceFill(['input_qty' => 10000, 'good_qty' => 10000])->save();

    $this->fgWarehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');

    $this->actingAs(User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail());
    $this->post('/qc-inspections', ['job_card_id' => $this->jobCard->id, 'stage' => 'final', 'lot_size' => 500, 'major_found' => 0]);

    $this->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
    $receipt = app(App\Modules\Manufacturing\Services\FgReceiptService::class)
        ->post($this->jobCard->refresh(), 5000, $this->fgWarehouseId, (string) Str::uuid());
    $this->lot = DB::table('stock_lots')->where('id', $receipt->lot_id)->first();

    DB::table('certifications')->where('scheme', 'GRS')->update([
        'issued_on' => now()->subYear()->toDateString(),
        'expires_on' => now()->addYear()->toDateString(),
    ]);
});

function draftChallanFor(object $test, float $qty = 3000): DeliveryChallan
{
    $test->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail());

    $test->post('/packing-lists', ['sales_order_id' => $test->soId])->assertSessionHasNoErrors();
    $list = PackingList::query()->latest('id')->firstOrFail();
    $test->post("/packing-lists/{$list->id}/cartons", []);
    $carton = DB::table('cartons')->where('packing_list_id', $list->id)->first();
    $test->post("/packing-lists/{$list->id}/cartons/{$carton->id}/contents", [
        'sales_order_line_id' => $test->soLineId, 'lot_id' => $test->lot->id, 'qty' => $qty,
    ]);
    $test->post("/packing-lists/{$list->id}/transition", ['to' => 'packed'])->assertSessionHasNoErrors();
    $test->post('/delivery-challans', ['packing_list_id' => $list->id, 'mode' => 'own_fleet'])
        ->assertSessionHasNoErrors();

    return DeliveryChallan::query()->latest('id')->firstOrFail();
}

/** A second buyer, so "addressed to the wrong customer" has somebody to be wrong about. */
function anotherCustomer(int $notThisOne): Customer
{
    $existing = Customer::query()->where('id', '!=', $notThisOne)->first();

    if ($existing !== null) {
        return $existing;
    }

    /** @var Customer $customer */
    $customer = Customer::query()->create([
        'code' => 'CUST-D4X',
        'name' => 'Someone Else Sourcing Ltd',
        'kind' => 'manufacturer',
        'currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
        'is_active' => true,
    ]);

    CustomerAddress::query()->create([
        'customer_id' => $customer->id,
        'label' => 'Their warehouse',
        'kind' => 'both',
        'line1' => '19 Someone Else Road',
        'city' => 'Chattogram',
        'country' => 'Bangladesh',
        'is_default' => true,
    ]);

    return $customer;
}

it('carries the customer and the destination down from the sales order', function (): void {
    $challan = draftChallanFor($this);

    $order = DB::table('sales_orders')->where('id', $this->soId)->first();

    expect($challan->customer_id)->toBe($this->customerId)
        ->and($challan->sales_order_id)->toBe($this->soId)
        ->and($challan->packing_list_id)->not->toBeNull()
        ->and($challan->delivery_address_id)->not->toBeNull();

    // The address is one of this customer's own, and the one the order named.
    $address = DB::table('customer_addresses')->where('id', $challan->delivery_address_id)->first();

    expect((int) $address->customer_id)->toBe($this->customerId);

    if ($order->delivery_address_id !== null) {
        expect($challan->delivery_address_id)->toBe((int) $order->delivery_address_id);
    }
});

it('names the customer on the delivery-note list', function (): void {
    $challan = draftChallanFor($this);

    $this->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail())
        ->get('/delivery-challans')
        ->assertInertia(function (AssertableInertia $page) use ($challan): void {
            $row = collect($page->toArray()['props']['delivery_challans']['data'])
                ->firstWhere('id', $challan->id);

            expect($row['customer']['id'])->toBe($this->customerId)
                ->and($row['customer']['name'])->not->toBeEmpty()
                ->and($row['destination'])->not->toBeNull()
                ->and($row['sales_order']['id'])->toBe($this->soId);
        });
});

it('shows the same customer on the detail page, with the chain it came from', function (): void {
    $challan = draftChallanFor($this);

    $this->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail())
        ->get("/delivery-challans/{$challan->id}")
        ->assertInertia(function (AssertableInertia $page) use ($challan): void {
            $data = $page->toArray()['props']['challan'];

            expect($data['customer']['id'])->toBe($this->customerId)
                ->and($data['consignee']['address'])->not->toBeEmpty()
                ->and($data['sales_order']['id'])->toBe($this->soId)
                ->and($data['packing_list']['id'])->toBe($challan->packing_list_id);
        });
});

it('refuses to issue a challan with no delivery address', function (): void {
    $challan = draftChallanFor($this);

    // The hole the audit found: a challan with nowhere to go, on its way out of the gate.
    DB::table('delivery_challans')->where('id', $challan->id)->update(['delivery_address_id' => null]);

    $ledgerBefore = DB::table('stock_ledger')->count();

    $this->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail())
        ->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])
        ->assertSessionHas('error');

    expect($challan->refresh()->status)->toBe('draft')
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and(session('error'))->toContain('no delivery address');
});

it('refuses to issue a challan addressed to a different customer from its order', function (): void {
    $challan = draftChallanFor($this);

    $other = anotherCustomer($this->customerId);

    DB::table('delivery_challans')->where('id', $challan->id)->update(['customer_id' => $other->id]);

    $ledgerBefore = DB::table('stock_ledger')->count();

    $this->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail())
        ->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])
        ->assertSessionHas('error');

    expect($challan->refresh()->status)->toBe('draft')
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore)
        ->and(session('error'))->toContain('different customer');
});

it('refuses a delivery address that belongs to somebody else', function (): void {
    $challan = draftChallanFor($this);

    $foreign = CustomerAddress::query()
        ->where('customer_id', anotherCustomer($this->customerId)->id)
        ->firstOrFail();

    DB::table('delivery_challans')->where('id', $challan->id)->update(['delivery_address_id' => $foreign->id]);

    $this->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail())
        ->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])
        ->assertSessionHas('error');

    expect($challan->refresh()->status)->toBe('draft')
        ->and(session('error'))->toContain('belongs to another customer');
});

it('falls back to the customer default address when the order names none', function (): void {
    DB::table('sales_orders')->where('id', $this->soId)->update(['delivery_address_id' => null]);

    $challan = draftChallanFor($this);

    $expected = CustomerAddress::defaultDeliveryFor($this->customerId);

    expect($expected)->not->toBeNull()
        ->and($challan->delivery_address_id)->toBe($expected->id);

    // And with a destination it can actually be issued.
    $this->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail())
        ->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])
        ->assertSessionHas('success');
});

it('gives a converted sales order the customer default delivery address', function (): void {
    $merchandiser = User::query()->where('email', 'merchandiser@maheenlabel.test')->firstOrFail();
    $customer = Customer::query()->findOrFail($this->customerId);
    $currency = App\Modules\MasterData\Models\Currency::query()->where('is_base', true)->firstOrFail();
    $product = App\Modules\Product\Models\Product::query()->where('customer_id', $customer->id)->firstOrFail();

    $this->actingAs($merchandiser)->post('/quotations', [
        'customer_id' => $customer->id,
        'quotation_date' => now()->toDateString(),
        'currency_id' => $currency->id,
        'exchange_rate' => 1,
        'lines' => [[
            'product_id' => $product->id,
            'description' => '10,000 labels',
            'qty' => 10000,
            'rate_per_m' => 3.0,
        ]],
    ])->assertSessionHasNoErrors();

    $quotation = App\Modules\Sales\Models\Quotation::query()->latest('id')->firstOrFail();
    $this->actingAs($merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent']);
    $this->actingAs($merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'accepted']);
    $this->actingAs($merchandiser)->post("/quotations/{$quotation->id}/convert", ['customer_po_no' => 'PO-ADDR-1'])
        ->assertSessionHasNoErrors();

    $order = App\Modules\Sales\Models\SalesOrder::query()->where('quotation_id', $quotation->id)->firstOrFail();

    expect($order->delivery_address_id)->toBe(CustomerAddress::defaultDeliveryFor($customer->id)?->id)
        ->and($order->delivery_address_id)->not->toBeNull();
});

it('does not let a user without the issue permission move the challan out of the gate', function (): void {
    $challan = draftChallanFor($this);

    $ledgerBefore = DB::table('stock_ledger')->count();

    $this->actingAs(User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail())
        ->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])
        ->assertForbidden();

    expect($challan->refresh()->status)->toBe('draft')
        ->and(DB::table('stock_ledger')->count())->toBe($ledgerBefore);
});
