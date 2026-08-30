<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Every contextual handoff, against every shape of id a URL can carry.
 *
 * A handoff parameter — `?inquiry=`, `?po=`, `?job_card=` — is chosen by whoever types the URL,
 * so each one is audited on three separate questions:
 *
 *  1. **Does the parameter name an id at all?** `$request->integer()` casts with PHP's rules,
 *     which are forgiving in a way a URL is not: `1.5`, `1 OR 1=1` and `1'` all became the
 *     integer `1`. No privilege was gained — the same user could pass `1` outright — but a
 *     request for a record that does not exist was being answered with a different record that
 *     does, which is a screen that no longer describes the request that produced it.
 *  2. **May this viewer read that record?** The source document's own permission decides.
 *  3. **Is the record in a state this handoff allows?** Resolved through the same rule the
 *     write path enforces, so the form cannot offer a workflow the save will refuse.
 *
 * The third is what `?pr_id=` on the RFQ form failed: `assertRequisition()` allows only an
 * approved requisition, while the prefill called a bare `find()` — so a draft or rejected
 * requisition's number, status and every line it carries were handed to the screen, and the
 * buyer discovered the refusal only after filling the form in.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
});

/**
 * Shapes that must never resolve to a record.
 *
 * Surrounding whitespace is deliberately absent: Laravel's global `TrimStrings` middleware
 * normalises `%201` to `1` before any controller runs, so asserting that a padded id resolves
 * to nothing would be asserting against the framework's own input handling rather than against
 * this application — and every other field in the app is trimmed the same way.
 */
dataset('hostile ids', [
    '0', '-1', '999999999', 'abc', '1%20OR%201=1', '1%27', '1.5', '%2B1', '01x', '1e2', '0x1',
]);

/** Every handoff: the form, its parameter, and the prop that must stay empty. */
dataset('handoffs', [
    'inquiry → quotation' => ['/quotations/create', 'inquiry', 'inquiryPrefill'],
    'order → job card' => ['/job-cards/create', 'sales_order', 'context'],
    'order line → job card' => ['/job-cards/create', 'sales_order_line', 'preselectLineId'],
    'job card → material issue' => ['/material-issues/create', 'job_card', 'preselectJobCardId'],
    'PO → GRN' => ['/grns/create', 'po', 'preselectPoId'],
    'customer → inquiry' => ['/inquiries/create', 'customer', 'preselectCustomerId'],
    'customer → product' => ['/products/create', 'customer', 'preselectedCustomer'],
    'supplier → PO' => ['/purchase-orders/create', 'supplier', 'preselectSupplierId'],
    'requisition → PO' => ['/purchase-orders/create', 'pr', 'fromRequisition'],
    'requisition → RFQ' => ['/rfqs/create', 'pr_id', 'requisition'],
    'order → packing list' => ['/packing-lists/create', 'sales_order', 'preselectOrderId'],
    'job card → QC' => ['/qc-inspections/create', 'job_card', 'preselect'],
]);

it('resolves a malformed contextual id to nothing', function (string $form, string $param, string $prop, string $bad): void {
    $this->actingAs($this->admin)
        ->get("{$form}?{$param}={$bad}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($prop, $param, $bad): void {
            $value = $page->toArray()['props'][$prop] ?? null;

            $empty = $value === null || $value === '' || $value === []
                || (is_array($value) && $value === []);

            expect($empty)->toBeTrue(
                "?{$param}={$bad} preselected ".json_encode($value),
            );
        });
})->with('handoffs')->with('hostile ids');

it('never returns a server error for a malformed contextual id', function (string $form, string $param, string $prop, string $bad): void {
    $response = $this->actingAs($this->admin)->get("{$form}?{$param}={$bad}");

    expect($response->status())->toBeLessThan(500);
})->with('handoffs')->with('hostile ids');

// --- the RFQ handoff, which had no state or permission rule at all ----------------------

it('withholds a requisition that is not approved from the RFQ form', function (): void {
    // `assertRequisition()` refuses to save an RFQ against anything but an approved
    // requisition. The prefill now asks the same question, so the form cannot offer a
    // workflow the save will refuse — and cannot read out a draft requisition's lines.
    $requisition = DB::table('purchase_requisitions')->first();

    if ($requisition === null) {
        $this->markTestSkipped('No purchase requisition in the walkthrough.');
    }

    DB::table('purchase_requisitions')->where('id', $requisition->id)->update(['status' => 'draft']);

    $this->actingAs($this->admin)
        ->get("/rfqs/create?pr_id={$requisition->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('requisition', null)
            ->where('lines', []),
        );
});

it('offers an approved requisition to the RFQ form', function (): void {
    $requisition = DB::table('purchase_requisitions')->first();

    if ($requisition === null) {
        $this->markTestSkipped('No purchase requisition in the walkthrough.');
    }

    DB::table('purchase_requisitions')->where('id', $requisition->id)->update(['status' => 'approved']);

    $this->actingAs($this->admin)
        ->get("/rfqs/create?pr_id={$requisition->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('requisition.id', (int) $requisition->id),
        );
});

it('withholds the requisition from a user who may not read requisitions', function (): void {
    // The handoff must not become a way to read a document the role cannot open. No seeded
    // role currently holds `supplier_rfq.create` without requisition access, so the guard was
    // resting on a permission-assignment coincidence rather than on code.
    $requisition = DB::table('purchase_requisitions')->first();

    if ($requisition === null) {
        $this->markTestSkipped('No purchase requisition in the walkthrough.');
    }

    DB::table('purchase_requisitions')->where('id', $requisition->id)->update(['status' => 'approved']);

    $blind = User::query()->where('email', 'designer@octapussolution.com')->firstOrFail();

    expect($blind->hasPermission('purchase_requisition.view_any'))->toBeFalse();

    // Grant only the right to reach the screen, nothing about requisitions.
    DB::table('role_permissions')->insertOrIgnore([
        'role_id' => $blind->roles->first()->id,
        'permission_id' => DB::table('permissions')->where('name', 'supplier_rfq.create')->value('id'),
    ]);
    cache()->flush();

    $this->actingAs($blind->fresh())
        ->get("/rfqs/create?pr_id={$requisition->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('requisition', null)
            ->where('lines', []),
        );
});

// --- a real id still works, so the tightening did not break the handoffs ----------------

it('still carries a valid, permitted, in-state context', function (): void {
    $inquiry = DB::table('inquiries')->first();

    if ($inquiry === null) {
        $this->markTestSkipped('No inquiry in the walkthrough.');
    }

    $this->actingAs($this->admin)
        ->get("/quotations/create?inquiry={$inquiry->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('inquiryPrefill.id', (int) $inquiry->id),
        );
});

it('scopes the product customer preselect to the picker it renders', function (): void {
    // The raw parameter used to be echoed straight through, so an archived or unknown id left
    // the select showing a value it could not resolve.
    $archived = DB::table('customers')->where('is_active', false)->value('id');

    if ($archived === null) {
        $archived = DB::table('customers')->value('id');
        DB::table('customers')->where('id', $archived)->update(['is_active' => false]);
    }

    $this->actingAs($this->admin)
        ->get("/products/create?customer={$archived}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('preselectedCustomer', null));
});

/*
 * BR-54, second question — "May this viewer read that record?"
 *
 * The rule was documented as applying to every handoff and implemented on two of them. A QC
 * inspector holds `grn.create` and no purchase-order permission at all: `/purchase-orders/1`
 * refuses them outright, while `/grns/create?po=1` handed back that order's number, supplier,
 * currency, exchange rate and every line on it. A query parameter must not reach around a
 * permission the same user is refused at the front door.
 */
/** An order open to receiving, built here rather than hoped for in the walkthrough data. */
function br54OpenOrder(): int
{
    $supplier = DB::table('suppliers')->where('is_active', true)->firstOrFail();
    $item = DB::table('items')->whereNotNull('base_uom_id')->firstOrFail();

    $poId = DB::table('purchase_orders')->insertGetId([
        'number' => 'PO-BR54-'.substr(uniqid(), -6),
        'supplier_id' => $supplier->id,
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'order_date' => now()->toDateString(),
        'currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
        'exchange_rate' => 1,
        'subtotal' => 1000,
        'total' => 1000,
        'status' => 'approved',
    ]);

    DB::table('purchase_order_lines')->insert([
        'po_id' => $poId,
        'line_no' => 1,
        'item_id' => $item->id,
        'uom_id' => $item->base_uom_id,
        'qty' => 100,
        'rate' => 10,
        'amount' => 1000,
        'received_qty' => 0,
    ]);

    return $poId;
}

it('br54: does not hand a purchase order to a receiver who may not read purchase orders', function (): void {
    $inspector = User::query()->where('email', 'qc@octapussolution.com')->firstOrFail();
    $poId = br54OpenOrder();

    // The premise: this role can reach the receiving screen and cannot open the order.
    expect($inspector->hasPermission('grn.create'))->toBeTrue()
        ->and($inspector->hasPermission('purchase_order.view'))->toBeFalse()
        ->and($inspector->hasPermission('purchase_order.view_any'))->toBeFalse();

    $this->actingAs($inspector)->get("/purchase-orders/{$poId}")->assertForbidden();

    $this->actingAs($inspector)
        ->get("/grns/create?po={$poId}")
        // The screen still works — a receipt with no order behind it is legitimate…
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // …but it carries nothing about the order, through the parameter or the picker.
            ->where('preselectPoId', null)
            ->where('poLines', [])
            ->where('purchaseOrders', []),
        );
});

it('br54: still hands the order to a receiver who may read purchase orders', function (): void {
    $store = User::query()->where('email', 'store@octapussolution.com')->firstOrFail();
    $poId = br54OpenOrder();

    $this->actingAs($store)
        ->get("/grns/create?po={$poId}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('preselectPoId', $poId)
            ->has('poLines', 1),
        );
});

it('br54: withholds a sales order from a planner who may not read sales orders', function (): void {
    // A production supervisor raises job cards and holds no sales-order permission at all.
    $supervisor = User::query()->where('email', 'supervisor@octapussolution.com')->firstOrFail();
    $order = DB::table('sales_orders')->first();

    if ($order === null) {
        $this->markTestSkipped('No sales order in the walkthrough.');
    }

    expect($supervisor->hasPermission('job_card.create'))->toBeTrue()
        ->and($supervisor->hasPermission('sales_order.view_any'))->toBeFalse()
        ->and($supervisor->hasPermission('sales_order.view'))->toBeFalse();

    $this->actingAs($supervisor)
        ->get("/job-cards/create?sales_order={$order->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('context', null));
});

it('br54: withholds a customer from an engineer who may not read customers', function (): void {
    $engineer = User::query()->where('email', 'engineer@octapussolution.com')->firstOrFail();
    $customer = DB::table('customers')->where('is_active', true)->first();

    if ($customer === null) {
        $this->markTestSkipped('No active customer in the walkthrough.');
    }

    expect($engineer->hasPermission('product.create'))->toBeTrue()
        ->and($engineer->hasPermission('customer.view_any'))->toBeFalse();

    $this->actingAs($engineer)
        ->get("/products/create?customer={$customer->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('preselectedCustomer', null));
});
