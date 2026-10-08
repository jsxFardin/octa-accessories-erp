<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * The buying lists as books of work, the way the sales lists are: every list is told how many
 * records stand at each stage whatever the filters say, and the one count that is a problem by
 * itself — late, overdue, expiring — is both a figure and a filter.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
});

it('gives every buying list its stage counts, under any filter and sort', function (string $url, string $component, string $table, ?string $alert): void {
    $byStatus = DB::table($table)->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');

    foreach (['', '?status=draft', '?q=zzz-nothing', '?sort=number', '?sort=-status', $alert ? "?{$alert}=1" : ''] as $query) {
        $this->actingAs($this->admin)->get($url.$query)
            ->assertOk()
            ->assertInertia(function (Inertia\Testing\AssertableInertia $page) use ($component, $byStatus, $alert): void {
                $page->component($component);

                foreach ($byStatus as $status => $count) {
                    $page->where("counts.{$status}", (int) $count);
                }

                if ($alert) {
                    $page->has("counts.{$alert}");
                }
            });
    }
})->with([
    'requisitions' => ['/purchase-requisitions', 'Procurement/Requisitions/Index', 'purchase_requisitions', 'overdue'],
    'RFQs' => ['/rfqs', 'Procurement/Rfqs/Index', 'supplier_rfqs', 'overdue'],
    'purchase orders' => ['/purchase-orders', 'Procurement/PurchaseOrders/Index', 'purchase_orders', 'late'],
    'goods receipts' => ['/grns', 'Procurement/Grns/Index', 'grns', null],
    'import shipments' => ['/import-shipments', 'Trade/Shipments/Index', 'import_shipments', 'overdue'],
    'letters of credit' => ['/letters-of-credit', 'Trade/LettersOfCredit/Index', 'letters_of_credit', 'expiring'],
]);

it('counts a purchase order as late only while it is placed, not all in, and past its date', function (): void {
    $order = fn (string $status, string $expected): int => (int) DB::table('purchase_orders')->insertGetId([
        'supplier_id' => DB::table('suppliers')->value('id'),
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'currency_id' => DB::table('currencies')->value('id'),
        'status' => $status,
        'expected_date' => $expected,
    ]);

    $before = (int) DB::table('purchase_orders')
        ->whereDate('expected_date', '<', now()->toDateString())
        ->whereIn('status', ['approved', 'sent', 'partially_received'])->count();

    $late = $order('sent', now()->subDays(3)->toDateString());
    $order('sent', now()->addDays(3)->toDateString());
    $order('received', now()->subDays(3)->toDateString());
    $order('draft', now()->subDays(3)->toDateString());

    $this->actingAs($this->admin)->get('/purchase-orders?late=1&per_page=200')
        ->assertOk()
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->where('counts.late', $before + 1)
            ->where('filters.late', '1')
            ->where('purchase_orders.data', fn ($rows) => collect($rows)->contains('id', $late)
                && collect($rows)->every(fn ($row) => in_array($row['status'], ['approved', 'sent', 'partially_received'], true))));
});

it('finds a purchase order by its supplier, and says how much of it is in', function (): void {
    $supplierId = (int) DB::table('suppliers')->insertGetId(['code' => 'SUP-FIND-T', 'name' => 'Findable Mills', 'is_approved' => true]);
    $poId = (int) DB::table('purchase_orders')->insertGetId([
        'supplier_id' => $supplierId,
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'currency_id' => DB::table('currencies')->value('id'),
        'status' => 'partially_received',
    ]);

    foreach ([[100, 100], [200, 50]] as $index => [$qty, $received]) {
        DB::table('purchase_order_lines')->insert([
            'po_id' => $poId, 'line_no' => $index + 1, 'item_id' => DB::table('items')->value('id'),
            'qty' => $qty, 'uom_id' => DB::table('uoms')->value('id'), 'rate' => 1, 'received_qty' => $received,
        ]);
    }

    $this->actingAs($this->admin)->get('/purchase-orders?q=Findable')
        ->assertOk()
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->has('purchase_orders.data', 1)
            ->where('purchase_orders.data.0.id', $poId)
            // One line complete, one a quarter in: 62.5% line by line, not 150 of 300.
            ->where('purchase_orders.data.0.received_pct', fn ($value) => abs((float) $value - 62.5) < 0.01));
});

it('counts suppliers by whether an order can go to them, and how many are out', function (): void {
    $this->actingAs($this->admin)->get('/suppliers?sort=name')
        ->assertOk()
        ->assertInertia(fn (Inertia\Testing\AssertableInertia $page) => $page
            ->where('counts.approved', DB::table('suppliers')->whereNull('deleted_at')->where('is_active', true)->where('is_approved', true)->count())
            ->where('counts.not_approved', DB::table('suppliers')->whereNull('deleted_at')->where('is_active', true)->where('is_approved', false)->count())
            ->where('counts.inactive', DB::table('suppliers')->whereNull('deleted_at')->where('is_active', false)->count())
            ->has('suppliers.data.0.open_orders'));
});
