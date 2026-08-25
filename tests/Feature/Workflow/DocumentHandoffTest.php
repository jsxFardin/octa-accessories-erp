<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\Inquiry;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * The handoffs between documents.
 *
 * Every one of these used to end with the user searching for the record they had open a
 * second earlier. What is asserted here is not that a page renders — the smoke test does that
 * — but that the context travelled with the click, and that it is withheld when it should be.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@maheenlabel.test')->firstOrFail();
    $this->planner = User::query()->where('email', 'planner@maheenlabel.test')->firstOrFail();
    $this->qc = User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail();
    $this->dispatch = User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail();
    $this->buyer = User::query()->where('email', 'purchase@maheenlabel.test')->firstOrFail();
    $this->purchaseManager = User::query()->where('email', 'purchasemanager@maheenlabel.test')->firstOrFail();
    $this->md = User::query()->where('email', 'md@maheenlabel.test')->firstOrFail();
    $this->store = User::query()->where('email', 'store@maheenlabel.test')->firstOrFail();
});

function makeHandoffInquiry(object $test, ?int $productId = null): Inquiry
{
    $customer = Customer::query()->active()->firstOrFail();

    $test->actingAs($test->merchandiser)->post('/inquiries', [
        'customer_id' => $customer->id,
        'inquiry_date' => now()->toDateString(),
        'lines' => [
            [
                'product_id' => $productId,
                'description' => '80,000 woven main labels',
                'qty' => 80000,
                'target_rate_per_m' => 3.10,
            ],
            [
                'product_id' => null,
                'description' => 'Something the customer has not specified yet',
                'qty' => 12000,
            ],
        ],
    ])->assertRedirect();

    return Inquiry::query()->latest('id')->firstOrFail();
}

// --- Inquiry → quotation ---------------------------------------------------------------

it('opens the quotation form with the inquiry it was raised from', function (): void {
    $product = Product::query()->firstOrFail();
    $inquiry = makeHandoffInquiry($this, $product->id);

    $this->actingAs($this->merchandiser)
        ->get("/quotations/create?inquiry={$inquiry->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Sales/Quotations/Form')
            ->where('inquiryId', $inquiry->id)
            ->where('inquiryPrefill.customer_id', $inquiry->customer_id)
            ->where('inquiryPrefill.number', $inquiry->number)
            ->has('inquiryPrefill.lines', 2)
            // The quantity travels; the rate deliberately does not — BR-20 computes it.
            ->where('inquiryPrefill.lines.0.product_id', $product->id)
            ->where('inquiryPrefill.lines.0.qty', '80000.000000')
            // A line the customer described without a product carries its wording across.
            ->where('inquiryPrefill.lines.1.product_id', null)
            ->where('inquiryPrefill.lines.1.description', 'Something the customer has not specified yet'),
        );
});

it('withholds the prefill when the id names no inquiry', function (): void {
    $this->actingAs($this->merchandiser)
        ->get('/quotations/create?inquiry=99999999')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('inquiryPrefill', null));
});

it('withholds the prefill from a user who may not read inquiries', function (): void {
    // The handoff must not become a way to read a document the role is not allowed to open.
    $inquiry = makeHandoffInquiry($this);

    $blind = User::query()->where('email', 'designer@maheenlabel.test')->firstOrFail();

    expect($blind->hasPermission('inquiry.view_any'))->toBeFalse();

    // Only a user who can create quotations reaches the screen at all; grant that alone.
    DB::table('role_permissions')->insertOrIgnore([
        'role_id' => $blind->roles->first()->id,
        'permission_id' => DB::table('permissions')->where('name', 'quotation.create')->value('id'),
    ]);
    cache()->flush();

    $this->actingAs($blind->fresh())
        ->get("/quotations/create?inquiry={$inquiry->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inquiryPrefill', null)
            ->where('inquiryId', $inquiry->id),
        );
});

it('leaves the quotation form blank when no inquiry is named', function (): void {
    $this->actingAs($this->merchandiser)
        ->get('/quotations/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inquiryId', null)
            ->where('inquiryPrefill', null),
        );
});

it('saves the prefilled quotation without the merchandiser retyping the context', function (): void {
    // The whole point of the handoff: what the form opens with is a valid submission. If the
    // prefill produced something `store()` rejects, it has only moved the work around.
    $product = Product::query()->firstOrFail();
    $inquiry = makeHandoffInquiry($this, $product->id);

    $prefill = $this->actingAs($this->merchandiser)
        ->get("/quotations/create?inquiry={$inquiry->id}")
        ->viewData('page')['props'];

    $currency = DB::table('currencies')->where('is_base', true)->value('id');

    // Exactly what the form posts: the prefilled header and the one line that named a
    // product. The rate is the merchandiser's only genuinely new input — BR-20 computes it.
    $this->actingAs($this->merchandiser)->post('/quotations', [
        'inquiry_id' => $prefill['inquiryId'],
        'customer_id' => $prefill['inquiryPrefill']['customer_id'],
        'quotation_date' => now()->toDateString(),
        'currency_id' => $currency,
        'exchange_rate' => 1,
        'lines' => [[
            'product_id' => $prefill['inquiryPrefill']['lines'][0]['product_id'],
            'description' => $prefill['inquiryPrefill']['lines'][0]['description'],
            'qty' => $prefill['inquiryPrefill']['lines'][0]['qty'],
            'rate_per_m' => 3.40,
        ]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $quotation = DB::table('quotations')->latest('id')->first();

    expect((int) $quotation->inquiry_id)->toBe((int) $inquiry->id)
        ->and((int) $quotation->customer_id)->toBe((int) $inquiry->customer_id);

    expect(DB::table('quotation_lines')->where('quotation_id', $quotation->id)->count())->toBe(1);
});

// --- Quotation → order, both ends of the chain ------------------------------------------

it('names the inquiry a quotation answers and the order it became', function (): void {
    // Walk the chain rather than looking for one: inquiry → quotation → accepted → order.
    $product = Product::query()->firstOrFail();
    $inquiry = makeHandoffInquiry($this, $product->id);
    $currency = DB::table('currencies')->where('is_base', true)->value('id');

    $this->actingAs($this->merchandiser)->post('/quotations', [
        'inquiry_id' => $inquiry->id,
        'customer_id' => $inquiry->customer_id,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'currency_id' => $currency,
        'exchange_rate' => 1,
        'lines' => [[
            'product_id' => $product->id,
            'description' => '80,000 woven main labels',
            'qty' => 80000,
            'rate_per_m' => 3.25,
        ]],
    ])->assertRedirect();

    $quotation = DB::table('quotations')->latest('id')->first();

    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent']);
    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'accepted']);
    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/convert", [])->assertRedirect();

    $order = DB::table('sales_orders')->where('quotation_id', $quotation->id)->firstOrFail();

    $this->actingAs($this->merchandiser)
        ->get("/quotations/{$quotation->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inquiry.id', (int) $inquiry->id)
            ->where('inquiry.number', $inquiry->fresh()->number)
            ->has('orders', 1)
            ->where('orders.0.id', (int) $order->id),
        );
});

// --- Order → job card --------------------------------------------------------------------

it('preselects the order line the planner asked for', function (): void {
    $line = DB::table('sales_order_lines as sol')
        ->join('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
        ->whereIn('so.status', ['confirmed', 'in_production', 'partially_delivered'])
        ->whereColumn('sol.produced_qty', '<', 'sol.ordered_qty')
        ->first(['sol.id', 'sol.sales_order_id']);

    expect($line)->not->toBeNull();

    $this->actingAs($this->planner)
        ->get("/job-cards/create?sales_order={$line->sales_order_id}&sales_order_line={$line->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Manufacturing/JobCards/Form')
            ->where('preselectLineId', (int) $line->id)
            ->where('context.id', (int) $line->sales_order_id)
            // The order's own lines are listed first, so the one asked for is not below the fold.
            ->where('orderLines.0.sales_order_id', (int) $line->sales_order_id),
        );
});

it('saves the prefilled job card against the order line it was opened for', function (): void {
    // Gate 1 is structural, so use a line whose product has already carried a card — that is
    // the evidence its artwork is approved and it has a routing.
    $line = DB::table('sales_order_lines as sol')
        ->join('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
        ->join('job_cards as jc', 'jc.sales_order_line_id', '=', 'sol.id')
        ->whereIn('so.status', ['confirmed', 'in_production', 'partially_delivered'])
        ->whereColumn('sol.produced_qty', '<', 'sol.ordered_qty')
        ->first(['sol.id', 'sol.sales_order_id']);

    if ($line === null) {
        $this->markTestSkipped('No order line in the walkthrough has both an open quantity and a card.');
    }

    $props = $this->actingAs($this->planner)
        ->get("/job-cards/create?sales_order_line={$line->id}")
        ->viewData('page')['props'];

    $selected = collect($props['orderLines'])->firstWhere('id', $props['preselectLineId']);

    $before = DB::table('job_cards')->count();

    $this->actingAs($this->planner)->post('/job-cards', [
        'sales_order_line_id' => $props['preselectLineId'],
        'factory_unit_id' => collect($props['units'])->first()->id,
        // The quantity the form defaults to: what is left to make on the chosen line.
        'planned_qty' => (float) $selected->ordered_qty - (float) $selected->produced_qty,
        'due_date' => $selected->promised_date,
        'priority' => 50,
    ])->assertSessionHasNoErrors()->assertRedirect();

    expect(DB::table('job_cards')->count())->toBe($before + 1);

    $card = DB::table('job_cards')->latest('id')->first();

    expect((int) $card->sales_order_line_id)->toBe((int) $line->id)
        ->and($card->status)->toBe('draft');
});

it('refuses to preselect a line that is not eligible to be made', function (): void {
    // A stale link — the line has since been fully produced — must not tick an unmakeable row.
    $line = DB::table('sales_order_lines as sol')
        ->join('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
        ->whereIn('so.status', ['confirmed', 'in_production', 'partially_delivered'])
        ->whereColumn('sol.produced_qty', '<', 'sol.ordered_qty')
        ->first(['sol.id', 'sol.ordered_qty']);

    DB::table('sales_order_lines')->where('id', $line->id)->update(['produced_qty' => $line->ordered_qty]);

    $this->actingAs($this->planner)
        ->get("/job-cards/create?sales_order_line={$line->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('preselectLineId', null));
});

it('still offers every eligible line when no context is passed', function (): void {
    $this->actingAs($this->planner)
        ->get('/job-cards/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preselectLineId', null)
            ->where('context', null)
            ->has('orderLines'),
        );
});

it('lists the orders that are waiting for a job card', function (): void {
    $line = DB::table('sales_order_lines as sol')
        ->join('sales_orders as so', 'so.id', '=', 'sol.sales_order_id')
        ->whereIn('so.status', ['confirmed', 'in_production'])
        ->whereColumn('sol.produced_qty', '<', 'sol.ordered_qty')
        ->first(['sol.id', 'sol.sales_order_id']);

    expect($line)->not->toBeNull();

    // Nothing raised against it: it is in the queue.
    DB::table('job_cards')->where('sales_order_line_id', $line->id)->update(['status' => 'cancelled']);

    $ids = fn (): array => collect(
        $this->actingAs($this->planner)->get('/sales-orders?awaiting=job_card')
            ->viewData('page')['props']['orders']['data'],
    )->pluck('id')->all();

    expect($ids())->toContain((int) $line->sales_order_id);
});

// --- Job card → QC -----------------------------------------------------------------------

/** A card QC may inspect — put one into that state if the walkthrough left none there. */
function inspectableJobCard(): object
{
    $card = DB::table('job_cards')->whereIn('status', ['in_production', 'qc_pending'])->first();

    if ($card !== null) {
        return $card;
    }

    // Whatever the walkthrough left behind, put one card where QC can reach it. The
    // assertions here are about the handoff, not about how the card got to that status.
    $any = DB::table('job_cards')->orderBy('id')->firstOrFail();
    DB::table('job_cards')->where('id', $any->id)->update(['status' => 'in_production']);

    return DB::table('job_cards')->where('id', $any->id)->firstOrFail();
}

it('opens the inspection form on the job card it was raised from', function (): void {
    $card = inspectableJobCard();

    $operation = DB::table('job_card_operations')
        ->where('job_card_id', $card->id)->where('requires_qc', true)->first();

    $url = "/qc-inspections/create?job_card={$card->id}"
        .($operation ? "&operation={$operation->id}&stage=in_process" : '');

    $this->actingAs($this->qc)
        ->get($url)
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($card, $operation): void {
            $page->component('Quality/Inspections/Form')
                ->where('preselect.job_card_id', (int) $card->id)
                // The lot in front of the inspector is what the card has made, not a number
                // they retype off the screen they just left.
                ->where('preselect.lot_size', (int) $card->good_qty);

            if ($operation !== null) {
                $page->where('preselect.job_card_operation_id', (int) $operation->id)
                    ->where('preselect.stage', 'in_process');
            }
        });
});

it('ignores an operation that is not a QC step on the named card', function (): void {
    // QC1 — an in-process verdict releases exactly one operation. A stale or hand-edited link
    // must not attach one to a step that is not this card's, or is not inspected at all.
    $card = inspectableJobCard();

    $notThisCard = (int) DB::table('job_card_operations')->max('id') + 1000;

    $notInspected = DB::table('job_card_operations')
        ->where('job_card_id', $card->id)
        ->where('requires_qc', false)
        ->value('id');

    foreach (array_filter([$notThisCard, $notInspected]) as $operation) {
        $this->actingAs($this->qc)
            ->get("/qc-inspections/create?job_card={$card->id}&operation={$operation}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('preselect.job_card_id', (int) $card->id)
                ->where('preselect.job_card_operation_id', null),
            );
    }
});

// --- Requisition → purchase order → goods receipt ----------------------------------------

/**
 * The buying chain has no seeded documents, so it is walked here: raise, submit, approve.
 * Nothing is asserted about the walk itself — `ProcurementFormsTest` owns that — only that the
 * handoffs downstream of it carry their context.
 *
 * @return array{requisition: int, prLine: int}
 */
function approvedRequisition(object $test): array
{
    $unit = DB::table('factory_units')->value('id');
    $item = DB::table('items')->where('is_active', true)->first(['id', 'base_uom_id']);

    $test->actingAs($test->planner)->post('/purchase-requisitions', [
        'factory_unit_id' => $unit,
        'requested_on' => now()->toDateString(),
        'required_by' => now()->addDays(14)->toDateString(),
        'lines' => [['item_id' => $item->id, 'uom_id' => $item->base_uom_id, 'qty' => 500]],
    ])->assertRedirect();

    $id = (int) DB::table('purchase_requisitions')->latest('id')->value('id');

    $test->actingAs($test->planner)->post("/purchase-requisitions/{$id}/transition", ['to' => 'submitted']);
    $test->actingAs($test->purchaseManager)->post("/purchase-requisitions/{$id}/transition", ['to' => 'approved']);

    return [
        'requisition' => $id,
        'prLine' => (int) DB::table('purchase_requisition_lines')->where('pr_id', $id)->value('id'),
    ];
}

/** An order in a status the storekeeper can receive against. */
function approvedPurchaseOrder(object $test): int
{
    $supplier = DB::table('suppliers')->where('is_approved', true)->first(['id', 'currency_id']);
    $unit = DB::table('factory_units')->value('id');
    $item = DB::table('items')->where('is_active', true)->first(['id', 'base_uom_id']);

    $test->actingAs($test->buyer)->post('/purchase-orders', [
        'supplier_id' => $supplier->id,
        'factory_unit_id' => $unit,
        'order_date' => now()->toDateString(),
        'expected_date' => now()->addDays(10)->toDateString(),
        'currency_id' => $supplier->currency_id ?? DB::table('currencies')->where('is_base', true)->value('id'),
        'exchange_rate' => 1,
        'freight_amount' => 0,
        'lines' => [[
            'item_id' => $item->id, 'uom_id' => $item->base_uom_id, 'qty' => 100, 'rate' => 12.5,
        ]],
    ])->assertRedirect();

    $id = (int) DB::table('purchase_orders')->latest('id')->value('id');

    $test->actingAs($test->buyer)->post("/purchase-orders/{$id}/transition", ['to' => 'pending_approval']);
    $test->actingAs($test->md)->post("/purchase-orders/{$id}/transition", ['to' => 'approved']);

    return $id;
}

it('sorts the requisition the buyer came from to the top of the pull list', function (): void {
    ['requisition' => $prId] = approvedRequisition($this);

    $props = $this->actingAs($this->buyer)
        ->get("/purchase-orders/create?pr={$prId}")
        ->assertOk()
        ->viewData('page')['props'];

    expect((int) $props['fromRequisition']->id)->toBe($prId)
        // Its lines lead, so the buyer is not scrolling an all-factory list for the one they
        // arrived from. Everything else is still there — one order often covers several.
        ->and((int) collect($props['openRequisitionLines'])->first()->pr_id)->toBe($prId);
});

it('leaves the pull list unsorted when the buyer came from nowhere', function (): void {
    approvedRequisition($this);

    $this->actingAs($this->buyer)
        ->get('/purchase-orders/create')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('fromRequisition', null));
});

it('opens a purchase order on the supplier the buyer came from', function (): void {
    $supplier = DB::table('suppliers')->where('is_active', true)->where('is_approved', true)->firstOrFail();

    $this->actingAs($this->buyer)
        ->get("/purchase-orders/create?supplier={$supplier->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Procurement/PurchaseOrders/Form')
            ->where('preselectSupplierId', (int) $supplier->id),
        );
});

it('does not preselect a supplier the picker does not offer', function (): void {
    // The picker lists active suppliers only; an inactive id must resolve to nothing rather
    // than to a value the select cannot render and `store()` would reject.
    $supplier = DB::table('suppliers')->where('is_active', true)->first(['id']);

    DB::table('suppliers')->where('id', $supplier->id)->update(['is_active' => false]);

    $this->actingAs($this->buyer)
        ->get("/purchase-orders/create?supplier={$supplier->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('preselectSupplierId', null));
});

it('ignores a supplier id that names nothing', function (): void {
    $this->actingAs($this->buyer)
        ->get('/purchase-orders/create?supplier=99999999')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('preselectSupplierId', null));
});

it('opens a goods receipt on the order it is being received against', function (): void {
    $orderId = approvedPurchaseOrder($this);

    $this->actingAs($this->store)
        ->get("/grns/create?po={$orderId}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Procurement/Grns/Form')
            ->where('preselectPoId', $orderId),
        );
});

it('does not preselect a purchase order that is closed to receiving', function (): void {
    $orderId = approvedPurchaseOrder($this);

    DB::table('purchase_orders')->where('id', $orderId)->update(['status' => 'closed']);

    $this->actingAs($this->store)
        ->get("/grns/create?po={$orderId}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('preselectPoId', null));
});

// --- A new customer is usable straight away ----------------------------------------------

it('makes a newly created customer selectable on the documents that need it', function (): void {
    // The acceptance test behind the `Active` default: created, then found. A customer saved
    // inactive is created just as successfully and is then invisible to every picker, which
    // reads to the user as a save that did not happen.
    $admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();

    $this->actingAs($admin)->post('/customers', [
        'code' => 'CUST-HANDOFF-1',
        'name' => 'Handoff Test Buyer',
        'kind' => 'brand',
        'credit_limit' => 0,
        'min_order_value' => 0,
        'over_tolerance_pct' => 5,
        'under_tolerance_pct' => 5,
        'is_active' => true,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $customer = DB::table('customers')->where('code', 'CUST-HANDOFF-1')->firstOrFail();

    expect((bool) $customer->is_active)->toBeTrue();

    // The confirmation says which of the two things happened — read before the next request
    // consumes the flash.
    expect((string) session('success'))->toContain('available to quote and order against');

    // Not "the row exists" — the row is offered where someone would go looking for it.
    foreach (['/inquiries/create', '/quotations/create', '/sales-orders/create'] as $screen) {
        $customers = $this->actingAs($this->merchandiser)->get($screen)
            ->viewData('page')['props']['customers'];

        expect(collect($customers)->pluck('id'))->toContain((int) $customer->id);
    }
});

it('says so plainly when a customer is created inactive', function (): void {
    $admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();

    $this->actingAs($admin)->post('/customers', [
        'code' => 'CUST-HANDOFF-2',
        'name' => 'Dormant Buyer',
        'kind' => 'brand',
        'credit_limit' => 0,
        'min_order_value' => 0,
        'over_tolerance_pct' => 5,
        'under_tolerance_pct' => 5,
        'is_active' => false,
    ])->assertRedirect();

    $customer = DB::table('customers')->where('code', 'CUST-HANDOFF-2')->firstOrFail();

    expect((string) session('success'))->toContain('will not appear in order or quotation pickers');

    $customers = $this->actingAs($this->merchandiser)->get('/quotations/create')
        ->viewData('page')['props']['customers'];

    expect(collect($customers)->pluck('id'))->not->toContain((int) $customer->id);
});

// --- Job card → material issue -----------------------------------------------------------

it('opens a material issue on the job card the store was sent from', function (): void {
    // A card released but unfed is the single biggest reason the floor stands idle; clearing
    // it used to mean leaving the card and finding it again in a list of every open card.
    $card = inspectableJobCard();
    DB::table('job_cards')->where('id', $card->id)->update(['status' => 'released']);

    $this->actingAs($this->store)
        ->get("/material-issues/create?job_card={$card->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Inventory/Issues/Form')
            ->where('preselectJobCardId', (int) $card->id),
        );
});

it('names each store by kind so the source is a conscious choice', function (): void {
    /*
     * The form deliberately preselects no warehouse. A job card's BOM draws yarn, ink and
     * packing from different stores, so no single default is right — and the old default (the
     * alphabetically first nettable warehouse) was "Finished goods", which returned an empty
     * pick list for every raw-material issue. The ledger posts against the lot, so the wrong
     * choice never moved the wrong stock; it just silently offered nothing.
     */
    $props = $this->actingAs($this->store)
        ->get('/material-issues/create')
        ->assertOk()
        ->viewData('page')['props'];

    $warehouses = collect($props['warehouses']);

    expect($warehouses)->not->toBeEmpty()
        ->and($warehouses->every(fn ($w): bool => filled($w->kind)))->toBeTrue()
        ->and($warehouses->pluck('kind')->unique()->count())->toBeGreaterThan(1);
});

it('still refuses an issue that names no store', function (): void {
    $card = inspectableJobCard();
    DB::table('job_cards')->where('id', $card->id)->update(['status' => 'released']);

    $this->actingAs($this->store)
        ->post('/material-issues', [
            'job_card_id' => $card->id,
            'lines' => [],
        ])
        ->assertSessionHasErrors('warehouse_id');
});

it('does not preselect a job card material cannot move against', function (): void {
    $card = inspectableJobCard();
    DB::table('job_cards')->where('id', $card->id)->update(['status' => 'draft']);

    $this->actingAs($this->store)
        ->get("/material-issues/create?job_card={$card->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('preselectJobCardId', null));
});

// --- Order → packing list ----------------------------------------------------------------

it('preselects the order dispatch came from', function (): void {
    $order = DB::table('sales_orders')
        ->whereIn('status', ['confirmed', 'in_production', 'partially_delivered'])
        ->first(['id']);

    expect($order)->not->toBeNull();

    $this->actingAs($this->dispatch)
        ->get("/packing-lists/create?sales_order={$order->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Dispatch/PackingLists/Form')
            ->where('preselectOrderId', (int) $order->id),
        );
});

it('does not preselect an order that cannot be packed', function (): void {
    // A link kept from before the order was closed points at a row the picker no longer lists;
    // resolving it anyway would show dispatch an order they cannot pack against.
    $order = DB::table('sales_orders')
        ->whereIn('status', ['confirmed', 'in_production', 'partially_delivered'])
        ->first(['id']);

    DB::table('sales_orders')->where('id', $order->id)->update(['status' => 'closed']);

    $this->actingAs($this->dispatch)
        ->get("/packing-lists/create?sales_order={$order->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('preselectOrderId', null));
});
