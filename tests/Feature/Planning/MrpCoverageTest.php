<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Services\StockAvailability;
use App\Modules\Manufacturing\Models\JobCard;
use Illuminate\Support\Facades\DB;

/**
 * GAP P-06 — BR-24 and BR-26 as the plan applies them.
 */
beforeEach(function (): void {
    $this->actingAs(User::query()->where('email', 'admin@octapussolution.com')->firstOrFail());
    $this->jobCard = JobCard::query()->whereNotNull('bom_id')->whereIn('status', [JobCard::PLANNED, JobCard::DRAFT])->firstOrFail();
    $this->itemId = (int) DB::table('bom_lines')->where('bom_id', $this->jobCard->bom_id)->where('is_optional', false)->value('item_id');
    $this->supplierId = (int) DB::table('suppliers')->where('is_approved', true)->value('id');
});

it('counts an open order as cover only when it is due in time (BR-24)', function (): void {
    $poId = DB::table('purchase_orders')->insertGetId([
        'number' => 'PO-COVER-1', 'supplier_id' => $this->supplierId,
        'factory_unit_id' => DB::table('factory_units')->value('id'),
        'currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
        'status' => 'approved',
    ]);
    DB::table('purchase_order_lines')->insert([
        'po_id' => $poId, 'line_no' => 1, 'item_id' => $this->itemId,
        'uom_id' => DB::table('items')->where('id', $this->itemId)->value('base_uom_id'),
        'qty' => 500, 'rate' => 10, 'amount' => 5000, 'received_qty' => 0,
        'expected_date' => now()->addDays(40)->toDateString(),
    ]);

    $availability = app(StockAvailability::class);
    $before = $availability->onOrder($this->itemId, now()->addDays(10)->toDateString());

    expect($availability->onOrder($this->itemId) - $before)->toBeQty(500.0)
        ->and($availability->onOrder($this->itemId, now()->addDays(10)->toDateString()))->toBeQty($before)
        ->and($availability->onOrder($this->itemId, now()->addDays(45)->toDateString()) - $before)->toBeQty(500.0);
});

it('takes the lead time from the supplier-item agreement before the supplier default (BR-26)', function (): void {
    DB::table('items')->where('id', $this->itemId)->update(['default_supplier_id' => $this->supplierId]);
    DB::table('suppliers')->where('id', $this->supplierId)->update(['lead_time_days' => 7]);
    DB::table('supplier_items')->updateOrInsert(
        ['supplier_id' => $this->supplierId, 'item_id' => $this->itemId],
        ['lead_time_days' => 21],
    );

    $this->post('/mrp/run', ['horizon_days' => 90])->assertRedirect();

    $row = DB::table('material_requirements')->where('item_id', $this->itemId)->orderByDesc('id')->first();

    expect($row)->not->toBeNull()
        ->and(Illuminate\Support\Carbon::parse((string) $row->need_date)->diffInDays(Illuminate\Support\Carbon::parse((string) $row->po_place_by), false))
        ->toBe(-21.0);
});
