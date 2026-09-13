<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Refund;
use App\Modules\Finance\Services\CreditNoteApplicationService;
use App\Modules\Finance\Services\RefundService;
use App\Modules\Finance\States\CreditNoteStateMachine;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Refunds — the other way a credit note's value leaves.
 *
 * The invariant the suite exists for:
 *
 *     applications + refunds <= credit note amount
 *
 * enforced by one calculation (`available()`) under the note's row lock, so a refund and an
 * application racing for the last of a note cannot both win. And, throughout, the paid invoice
 * the credit came from stays exactly as it was.
 */
beforeEach(function (): void {
    $this->customerId = (int) DB::table('sales_orders')->value('customer_id');
    $this->currencyId = (int) DB::table('currencies')->where('code', 'USD')->value('id');
    $this->productId = (int) DB::table('products')->value('id');
    $this->warehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');

    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->dispatchUser = User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail();

    $this->refunds = app(RefundService::class);
    $this->applications = app(CreditNoteApplicationService::class);
    $this->invoices = app(SalesInvoiceStateMachine::class);
});

it('pays a credit note back and records it as a refund', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $note = returnCredit($this, $paid, 2500);   // 937.50

    $this->actingAs($this->accounts);
    $refund = $this->refunds->post($note, 937.50, ['method' => 'bank_transfer', 'reason' => 'Customer asked for cash back']);

    expect($refund->status)->toBe(Refund::POSTED)
        ->and($refund->number)->toStartWith('REF-')
        ->and((float) $refund->amount)->toBeMoney(937.50)
        ->and((int) $refund->customer_id)->toBe((int) $note->customer_id)
        ->and((int) $refund->currency_id)->toBe((int) $note->currency_id)
        // Spent, and spent as money rather than against a receivable.
        ->and($note->refresh()->status)->toBe(CreditNote::REFUNDED)
        ->and($this->applications->available($note))->toBeMoney(0.0);
});

it('leaves the paid invoice the credit came from untouched', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));

    $received = (float) $paid->received_amount;
    $allocations = DB::table('receipt_allocations')->where('sales_invoice_id', $paid->id)
        ->orderBy('id')->pluck('amount', 'id')->map(fn ($a): float => (float) $a)->all();

    $note = returnCredit($this, $paid, 2500);

    $this->actingAs($this->accounts);
    $this->refunds->post($note, 937.50);

    $paid->refresh();

    expect($paid->status)->toBe('paid')
        ->and((float) $paid->received_amount)->toBeMoney($received)
        ->and($this->invoices->outstanding($paid))->toBeMoney(0.0)
        ->and($this->invoices->appliedCredits($paid))->toBeMoney(0.0)
        ->and(
            DB::table('receipt_allocations')->where('sales_invoice_id', $paid->id)
                ->orderBy('id')->pluck('amount', 'id')->map(fn ($a): float => (float) $a)->all(),
        )->toEqual($allocations);
});

it('refuses a refund larger than the credit is worth', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $note = returnCredit($this, $paid, 2500);   // 937.50

    $this->actingAs($this->accounts);

    expect(fn () => $this->refunds->post($note, 1000.00))
        ->toThrow(ValidationException::class, 'would refund more than it is worth');

    expect(Refund::query()->where('credit_note_id', $note->id)->count())->toBe(0)
        ->and($note->refresh()->status)->toBe(CreditNote::APPROVED);
});

it('lets a note be part applied and part refunded, and no more', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $open = appInvoice($this, 20000, 375);
    $note = returnCredit($this, $paid, 4000);   // 1,500.00

    $this->actingAs($this->accounts);

    $this->applications->apply($note, $open, 900.00);
    expect($this->applications->available($note->refresh()))->toBeMoney(600.00);

    $this->refunds->post($note->refresh(), 600.00);

    expect($this->applications->available($note->refresh()))->toBeMoney(0.0)
        ->and($note->refresh()->status)->toBe(CreditNote::REFUNDED)
        ->and($this->invoices->appliedCredits($open->refresh()))->toBeMoney(900.00);

    // Nothing left for either route.
    expect(fn () => $this->refunds->post($note->refresh(), 0.01))
        ->toThrow(ValidationException::class);
});

it('stops a fully applied note being refunded', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $open = appInvoice($this, 2500, 375);       // 937.50
    $note = returnCredit($this, $paid, 2500);   // 937.50

    $this->actingAs($this->accounts);
    $this->applications->apply($note, $open, 937.50);

    expect($note->refresh()->status)->toBe(CreditNote::APPLIED);

    expect(fn () => $this->refunds->post($note->refresh(), 100.00))
        ->toThrow(ValidationException::class, 'would refund more than it is worth');
});

it('stops a fully refunded note being applied', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $open = appInvoice($this, 20000, 375);
    $note = returnCredit($this, $paid, 2500);

    $this->actingAs($this->accounts);
    $this->refunds->post($note, 937.50);

    expect(fn () => $this->applications->apply($note->refresh(), $open, 100.00))
        ->toThrow(ValidationException::class, 'only an approved note can be applied');
});

it('will not let two refunds race past the credit note amount', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $note = returnCredit($this, $paid, 2500);   // 937.50

    $this->actingAs($this->accounts);
    $this->refunds->post($note, 900.00);

    // 37.50 left; the second asks for the figure it read before the first landed.
    expect(fn () => $this->refunds->post($note->refresh(), 900.00))
        ->toThrow(ValidationException::class, 'would refund more than it is worth');

    expect((float) DB::table('refunds')->where('credit_note_id', $note->id)
        ->where('status', 'posted')->sum('amount'))->toBeMoney(900.00);
});

it('refuses to refund a draft or cancelled note', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));

    $draft = CreditNote::query()->create([
        'customer_id' => $this->customerId, 'sales_invoice_id' => $paid->id,
        'note_date' => now()->toDateString(), 'reason' => 'return',
        'currency_id' => (int) $paid->currency_id, 'amount' => 500, 'status' => 'draft',
    ]);

    $this->actingAs($this->accounts);

    expect(fn () => $this->refunds->post($draft, 100.00))
        ->toThrow(ValidationException::class, 'only an approved note can be refunded');

    $cancelled = CreditNote::query()->create([
        'customer_id' => $this->customerId, 'sales_invoice_id' => $paid->id,
        'note_date' => now()->toDateString(), 'reason' => 'return',
        'currency_id' => (int) $paid->currency_id, 'amount' => 500, 'status' => 'cancelled',
    ]);

    expect(fn () => $this->refunds->post($cancelled, 100.00))
        ->toThrow(ValidationException::class, 'only an approved note can be refunded');
});

it('refuses a refund of nothing', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $note = returnCredit($this, $paid, 2500);

    $this->actingAs($this->accounts);

    expect(fn () => $this->refunds->post($note, 0.0))
        ->toThrow(ValidationException::class, 'A refund of nothing is not a refund');
});

it('keeps the credit note out of reach without the refund permission', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $note = returnCredit($this, $paid, 2500);

    // The dispatch officer holds no credit_note.refund.
    $this->actingAs($this->dispatchUser);

    expect(fn () => app(CreditNoteStateMachine::class)->transition($note, CreditNote::REFUNDED))
        ->toThrow(App\Support\States\TransitionDenied::class, 'credit_note.refund');
});

it('audits the refund and the note transition', function (): void {
    $paid = appPay($this, appInvoice($this, 10000, 375));
    $note = returnCredit($this, $paid, 2500);

    $this->actingAs($this->accounts);
    $refund = $this->refunds->post($note, 937.50);

    expect(DB::table('audit_logs')->where('auditable_type', Refund::class)
        ->where('auditable_id', $refund->id)->exists())->toBeTrue();

    $trail = DB::table('audit_logs')
        ->where('auditable_type', CreditNote::class)->where('auditable_id', $note->id)
        ->where('event', 'status_changed')->orderByDesc('id')->first(['new_values']);

    expect($trail->new_values)->toContain('refunded');
});
