<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\StockPostingService;
use App\Support\Periods\PeriodLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Period lock — a closed month takes no new entry, at every step.
 *
 * The chain-of-custody close locked one scheme's movements and nothing else. A receipt or a
 * stock movement dated into a month accounts had already reported could still be booked, so
 * the figure an auditor was shown and the figure the system held drifted apart.
 */
beforeEach(function (): void {
    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->lastMonth = now()->subMonthNoOverflow();
    $this->periods = app(PeriodLock::class);
});

it('closes a month and refuses anything dated into it', function (): void {
    $this->actingAs($this->accounts)
        ->post('/admin/accounting-periods/close', ['year' => $this->lastMonth->year, 'month' => $this->lastMonth->month, 'note' => 'Month-end'])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($this->periods->isClosed($this->lastMonth->year, $this->lastMonth->month))->toBeTrue()
        ->and(DB::table('audit_logs')->where('auditable_type', 'accounting_periods')->where('event', 'status_changed')->exists())->toBeTrue();

    $date = $this->lastMonth->format('Y-m-15');

    expect(fn () => $this->periods->assertOpen($date, 'a receipt'))
        ->toThrow(ValidationException::class, 'is closed');

    // Today is still open.
    $this->periods->assertOpen(null, 'a stock movement');
    expect(true)->toBeTrue();
});

it('refuses a receipt back-dated into a closed month on the date field', function (): void {
    $this->periods->close($this->lastMonth->year, $this->lastMonth->month, $this->accounts);

    // The demo seed stops before invoicing; one issued invoice is enough to receive against.
    $order = DB::table('sales_orders')->whereNotNull('number')->orderBy('id')->firstOrFail();
    $invoiceId = DB::table('sales_invoices')->insertGetId([
        'number' => 'INV-PERIOD-1',
        'customer_id' => $order->customer_id,
        'sales_order_id' => $order->id,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $order->currency_id,
        'exchange_rate' => 1,
        'subtotal' => 1000,
        'tax_amount' => 0,
        'total' => 1000,
        'status' => 'issued',
        'created_by' => $this->admin->id,
    ]);

    $this->actingAs($this->admin)
        ->from('/receipts/create')
        ->post('/receipts', [
            'customer_id' => $order->customer_id,
            'receipt_date' => $this->lastMonth->format('Y-m-10'),
            'method' => 'cash',
            'currency_id' => $order->currency_id,
            'exchange_rate' => 1,
            'amount' => 10,
            'allocations' => [['sales_invoice_id' => $invoiceId, 'amount' => 10]],
        ])
        ->assertRedirect('/receipts/create')
        ->assertSessionHasErrors('period');
});

it('refuses a stock movement while the current month is closed, and takes it again once reopened', function (): void {
    $lot = StockLot::query()->where('balance_qty', '>', 1)->where('status', 'available')->firstOrFail();
    $posting = app(StockPostingService::class);
    $source = $lot; // any model stands in as the source for the ledger row

    $this->periods->close((int) now()->format('Y'), (int) now()->format('n'), $this->accounts);

    expect(fn () => $posting->post($lot, 'adjustment_out', -1, $source, null, 'test'))
        ->toThrow(ValidationException::class, 'a stock movement');

    $this->actingAs($this->accounts)
        ->post('/admin/accounting-periods/reopen', ['year' => now()->year, 'month' => now()->month, 'note' => 'Late GRN from the port'])
        ->assertSessionHasNoErrors();

    expect(DB::table('audit_logs')->where('auditable_type', 'accounting_periods')->where('event', 'status_changed')->where('new_values', 'like', '%"open"%')->exists())->toBeTrue();

    $before = (float) $lot->fresh()->balance_qty;
    app(StockPostingService::class)->post($lot->fresh(), 'adjustment_out', -1, $source, null, 'test');

    expect((float) $lot->fresh()->balance_qty)->toBe($before - 1);
});

it('will not close a month that has not started, and asks for a reason to reopen', function (): void {
    $next = now()->addMonthNoOverflow();

    $this->actingAs($this->accounts)
        ->post('/admin/accounting-periods/close', ['year' => $next->year, 'month' => $next->month])
        ->assertSessionHasErrors('period');

    $this->periods->close($this->lastMonth->year, $this->lastMonth->month, $this->accounts);

    $this->actingAs($this->accounts)
        ->post('/admin/accounting-periods/reopen', ['year' => $this->lastMonth->year, 'month' => $this->lastMonth->month])
        ->assertSessionHasErrors('note');
});

it('lists every month since the first document, with the close shown', function (): void {
    $this->periods->close($this->lastMonth->year, $this->lastMonth->month, $this->accounts, 'Reported');

    $this->actingAs($this->accounts)
        ->get('/admin/accounting-periods')
        ->assertOk()
        ->assertInertia(function ($page): void {
            $months = collect($page->toArray()['props']['months']);
            $closed = $months->firstWhere('key', $this->lastMonth->format('Y-m'));

            expect($months->first()['is_current'])->toBeTrue()
                ->and($closed['is_closed'])->toBeTrue()
                ->and($closed['closed_by'])->toBe($this->accounts->name)
                ->and($closed['note'])->toBe('Reported');
        });
});

it('keeps the close away from anyone without the right', function (): void {
    $planner = User::query()->where('email', 'planner@octapussolution.com')->firstOrFail();

    $this->actingAs($planner)
        ->post('/admin/accounting-periods/close', ['year' => $this->lastMonth->year, 'month' => $this->lastMonth->month])
        ->assertForbidden();
});
