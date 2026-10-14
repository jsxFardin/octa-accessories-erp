<?php

declare(strict_types=1);

use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\Scoping\PortalContext;
use Illuminate\Support\Facades\DB;

/**
 * GAP S-08 — NFR-21/31: the portal customer scope confines every commercial document to the
 * customer the request is bound to, and does nothing off the portal.
 */
beforeEach(function (): void {
    $this->customerId = (int) SalesOrder::query()->whereNotNull('customer_id')->value('customer_id');
    // A second customer, whether or not the seed has one.
    $this->otherId = (int) DB::table('customers')->insertGetId([
        'code' => 'CUST-PORTAL-2', 'name' => 'Other Buying House', 'kind' => 'buying_house', 'is_active' => true,
    ]);

    // A document for another customer, so there is something to be hidden.
    $this->foreignOrder = SalesOrder::query()->create([
        'customer_id' => $this->otherId,
        'order_date' => now()->toDateString(),
        'currency_id' => DB::table('currencies')->where('is_base', true)->value('id'),
        'exchange_rate' => 1,
        'status' => 'draft',
    ]);
});

it('sees everything off the portal', function (): void {
    expect(app(PortalContext::class)->isActive())->toBeFalse()
        ->and(SalesOrder::query()->whereKey($this->foreignOrder->id)->exists())->toBeTrue();
});

it('confines orders, quotations, invoices and delivery notes to the bound customer', function (): void {
    app(PortalContext::class)->bind($this->customerId);

    expect(SalesOrder::query()->whereKey($this->foreignOrder->id)->exists())->toBeFalse()
        ->and(SalesOrder::query()->where('customer_id', '!=', $this->customerId)->count())->toBe(0)
        ->and(SalesOrder::query()->where('customer_id', $this->customerId)->count())->toBeGreaterThan(0)
        ->and(Quotation::query()->where('customer_id', '!=', $this->customerId)->count())->toBe(0)
        ->and(SalesInvoice::query()->where('customer_id', '!=', $this->customerId)->count())->toBe(0)
        ->and(DeliveryChallan::query()->where('customer_id', '!=', $this->customerId)->count())->toBe(0)
        // A direct find by id is confined too — ids are sequential and guessable.
        ->and(SalesOrder::query()->find($this->foreignOrder->id))->toBeNull();
});
