<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();
    $this->planner = User::query()->where('email', 'planner@maheenlabel.test')->firstOrFail();
    $this->purchaseManager = User::query()->where('email', 'purchasemanager@maheenlabel.test')->firstOrFail();
    $this->buyer = User::query()->where('email', 'purchase@maheenlabel.test')->firstOrFail();
});

/** The walkthrough seeds no commercial documents, so a print test builds its own. */
function printableQuotation(object $test): object
{
    $merchandiser = User::query()->where('email', 'merchandiser@maheenlabel.test')->firstOrFail();
    $product = DB::table('products')->first();

    $test->actingAs($merchandiser)->post('/quotations', [
        'customer_id' => $product->customer_id,
        'quotation_date' => now()->toDateString(),
        'valid_until' => now()->addDays(30)->toDateString(),
        'currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
        'exchange_rate' => 1,
        'lines' => [[
            'product_id' => $product->id,
            'description' => '50,000 woven care labels',
            'qty' => 50000,
            'rate_per_m' => 3.25,
            'margin_pct' => 22,
        ]],
    ]);

    return DB::table('quotations')->latest('id')->first();
}

function printablePurchaseOrder(object $test, string $status = 'draft'): object
{
    $item = DB::table('items')->first();

    $test->actingAs($test->buyer)->post('/purchase-orders', [
        'supplier_id' => DB::table('suppliers')->where('is_approved', true)->value('id'),
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'order_date' => now()->toDateString(),
        'currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
        'exchange_rate' => 1,
        'freight_amount' => 0,
        'lines' => [['item_id' => $item->id, 'uom_id' => $item->base_uom_id, 'qty' => 10, 'rate' => 25]],
    ]);

    $order = DB::table('purchase_orders')->latest('id')->first();

    DB::table('purchase_orders')->where('id', $order->id)->update(['status' => $status]);

    return DB::table('purchase_orders')->where('id', $order->id)->first();
}

// --- Printing -----------------------------------------------------------------------------

it('renders a printable quotation carrying the organisation identity', function (): void {
    $quotation = printableQuotation($this);

    $response = $this->actingAs($this->admin)->get("/quotations/{$quotation->id}/print");

    $response->assertOk();
    $response->assertSee($quotation->number ?? '(unnumbered draft)');
    // The letterhead comes from the organisation profile, not from config.
    $response->assertSee('Maheen Label', false);
});

it('renders a job card that names the artwork version bound to it', function (): void {
    $jobCard = DB::table('job_cards')->whereNotNull('artwork_version_id')->first();

    if ($jobCard === null) {
        $this->markTestSkipped('No job card in the walkthrough.');
    }

    // Gate 1 on paper: the floor holds the card, so the card states which version it may run.
    $this->actingAs($this->admin)->get("/job-cards/{$jobCard->id}/print")
        ->assertOk()
        ->assertSee('Approved artwork');
});

it('will not print a purchase order nobody has approved', function (): void {
    // A supplier holding an unapproved order ships against a price nobody signed off.
    $draft = printablePurchaseOrder($this, 'draft');

    $this->actingAs($this->admin)->get("/purchase-orders/{$draft->id}/print")->assertForbidden();
});

it('prints a purchase order once it has been approved', function (): void {
    $approved = printablePurchaseOrder($this, 'approved');

    $this->actingAs($this->admin)->get("/purchase-orders/{$approved->id}/print")
        ->assertOk()
        ->assertSee('Purchase order');
});

it('records a print in the audit log', function (): void {
    $quotation = printableQuotation($this);

    $this->actingAs($this->admin)->get("/quotations/{$quotation->id}/print");

    expect(DB::table('audit_logs')
        ->where('auditable_type', 'quotations')
        ->where('auditable_id', $quotation->id)
        ->where('event', 'printed')
        ->exists())->toBeTrue();
});

// --- Bulk transitions ---------------------------------------------------------------------

function makeSubmittedRequisitions(object $test, int $count): array
{
    $unit = DB::table('factory_units')->value('id');
    $item = DB::table('items')->first();
    $ids = [];

    for ($index = 0; $index < $count; $index++) {
        $test->actingAs($test->planner)->post('/purchase-requisitions', [
            'factory_unit_id' => $unit,
            'requested_on' => now()->toDateString(),
            'lines' => [['item_id' => $item->id, 'uom_id' => $item->base_uom_id, 'qty' => 10 + $index]],
        ]);

        $id = (int) DB::table('purchase_requisitions')->max('id');
        $test->actingAs($test->planner)->post("/purchase-requisitions/{$id}/transition", ['to' => 'submitted']);
        $ids[] = $id;
    }

    return $ids;
}

it('approves many requisitions in one action', function (): void {
    $ids = makeSubmittedRequisitions($this, 3);

    $this->actingAs($this->purchaseManager)
        ->post('/bulk/purchase-requisitions/transition', ['ids' => $ids, 'to' => 'approved'])
        ->assertRedirect();

    expect(DB::table('purchase_requisitions')->whereIn('id', $ids)->where('status', 'approved')->count())
        ->toBe(3);
});

it('applies the same guards in bulk as it does one at a time', function (): void {
    // A draft cannot go straight to approved; the bulk path must refuse it exactly as the
    // single-record path does, rather than becoming a way around the state machine.
    $ids = makeSubmittedRequisitions($this, 1);

    DB::table('purchase_requisitions')->where('id', $ids[0])->update(['status' => 'draft']);

    $this->actingAs($this->purchaseManager)
        ->post('/bulk/purchase-requisitions/transition', ['ids' => $ids, 'to' => 'approved'])
        ->assertSessionHas('error');

    expect(DB::table('purchase_requisitions')->where('id', $ids[0])->value('status'))->toBe('draft');
});

it('commits what succeeded and names what did not', function (): void {
    $good = makeSubmittedRequisitions($this, 2);
    $blocked = makeSubmittedRequisitions($this, 1);

    DB::table('purchase_requisitions')->where('id', $blocked[0])->update(['status' => 'cancelled']);

    $response = $this->actingAs($this->purchaseManager)->post('/bulk/purchase-requisitions/transition', [
        'ids' => [...$good, ...$blocked],
        'to' => 'approved',
    ]);

    // Partial success is the normal outcome: two approved, one reported by name.
    $response->assertSessionHas('warning');

    expect(DB::table('purchase_requisitions')->whereIn('id', $good)->where('status', 'approved')->count())->toBe(2)
        ->and(DB::table('purchase_requisitions')->where('id', $blocked[0])->value('status'))->toBe('cancelled');
});

it('refuses a bulk approval from someone without the permission', function (): void {
    $ids = makeSubmittedRequisitions($this, 1);

    $this->actingAs($this->planner)
        ->post('/bulk/purchase-requisitions/transition', ['ids' => $ids, 'to' => 'approved'])
        ->assertSessionHas('error');

    expect(DB::table('purchase_requisitions')->where('id', $ids[0])->value('status'))->toBe('submitted');
});

it('caps how much one action can move', function (): void {
    $this->actingAs($this->purchaseManager)->post('/bulk/purchase-requisitions/transition', [
        'ids' => range(1, 500),
        'to' => 'approved',
    ])->assertSessionHasErrors('ids');
});
