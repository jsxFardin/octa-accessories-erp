<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Refund;
use App\Modules\Finance\Services\RefundService;
use App\Modules\Sales\Models\SalesReturn;
use App\Modules\Sales\States\SalesReturnStateMachine;
use App\Support\States\TransitionDenied;
use Database\Seeders\PermissionSeeder;
use Illuminate\Support\Facades\DB;

/**
 * 06-rbac — who may do what to a customer return, and that the audit trail records it.
 *
 * The split the catalogue encodes: dispatch receive the goods, accounts agree the money. Those
 * are different jobs and neither should be able to do the other's, because a return that the
 * people receiving it can also approve is a return nobody signed for.
 */
beforeEach(function (): void {
    $this->customerId = (int) DB::table('sales_orders')->value('customer_id');
    $this->currencyId = (int) DB::table('currencies')->where('code', 'USD')->value('id');
    $this->productId = (int) DB::table('products')->value('id');
    $this->warehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');

    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->dispatchUser = User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail();
});

it('defines every permission the new documents reference', function (): void {
    $catalogue = PermissionSeeder::catalogue();

    expect($catalogue)->toContain(
        'sales_return.view_any', 'sales_return.view', 'sales_return.create',
        'sales_return.update', 'sales_return.delete', 'sales_return.export',
        'sales_return.approve', 'sales_return.post',
        'credit_note.apply', 'credit_note.refund',
        'refund.create', 'refund.post', 'refund.view_any',
    );

    // …and every one of them exists as a row, which is what the state machines look up.
    foreach (['sales_return.approve', 'sales_return.post', 'credit_note.refund', 'refund.post'] as $permission) {
        expect(DB::table('permissions')->where('name', $permission)->exists())->toBeTrue();
    }
});

it('lets accounts approve a return but not post the goods back', function (): void {
    $invoice = appPay($this, appInvoice($this, 10000, 375));
    $return = draftSalesReturn($this, $invoice, 1000);

    $states = app(SalesReturnStateMachine::class);

    $this->actingAs($this->accounts);
    $states->transition($return, SalesReturn::APPROVED);

    expect($return->refresh()->status)->toBe(SalesReturn::APPROVED)
        ->and($this->accounts->hasPermission('sales_return.post'))->toBeFalse();
});

it('lets dispatch post the goods back but not approve the return', function (): void {
    $invoice = appPay($this, appInvoice($this, 10000, 375));
    $return = draftSalesReturn($this, $invoice, 1000);

    $this->actingAs($this->dispatchUser);

    expect(fn () => app(SalesReturnStateMachine::class)->transition($return, SalesReturn::APPROVED))
        ->toThrow(TransitionDenied::class, 'sales_return.approve');

    expect($this->dispatchUser->hasPermission('sales_return.post'))->toBeTrue();
});

it('keeps refunds out of reach of anyone but accounts', function (): void {
    $invoice = appPay($this, appInvoice($this, 10000, 375));
    $note = returnCredit($this, $invoice, 2500);

    expect($this->accounts->hasPermission('credit_note.refund'))->toBeTrue()
        ->and($this->dispatchUser->hasPermission('credit_note.refund'))->toBeFalse()
        ->and($this->accounts->hasPermission('credit_note.apply'))->toBeTrue();

    expect($note->status)->toBe(CreditNote::APPROVED);
});

it('audits the return, the credit note and the refund with their reasons', function (): void {
    $invoice = appPay($this, appInvoice($this, 10000, 375));
    $note = returnCredit($this, $invoice, 2500);
    $return = SalesReturn::query()->findOrFail($note->sales_return_id);

    $this->actingAs($this->accounts);
    $refund = app(RefundService::class)->post($note, 937.50, ['reason' => 'Customer asked for the money back']);

    // The return walked draft → approved → posted.
    $returnTrail = DB::table('audit_logs')
        ->where('auditable_type', SalesReturn::class)->where('auditable_id', $return->id)
        ->where('event', 'status_changed')->orderBy('id')->pluck('new_values');

    expect($returnTrail)->toHaveCount(2)
        ->and($returnTrail[0])->toContain('approved')
        ->and($returnTrail[1])->toContain('posted');

    // The credit note was created and then approved and spent.
    expect(DB::table('audit_logs')->where('auditable_type', CreditNote::class)
        ->where('auditable_id', $note->id)->exists())->toBeTrue();

    $noteTrail = DB::table('audit_logs')->where('auditable_type', CreditNote::class)
        ->where('auditable_id', $note->id)->where('event', 'status_changed')
        ->orderByDesc('id')->value('new_values');

    expect($noteTrail)->toContain('refunded');

    // And the refund itself is on the record.
    expect(DB::table('audit_logs')->where('auditable_type', Refund::class)
        ->where('auditable_id', $refund->id)->exists())->toBeTrue();
});

it('writes one audit row per transition, not several', function (): void {
    $invoice = appPay($this, appInvoice($this, 10000, 375));
    $note = returnCredit($this, $invoice, 2500);
    $return = SalesReturn::query()->findOrFail($note->sales_return_id);

    $posted = DB::table('audit_logs')
        ->where('auditable_type', SalesReturn::class)->where('auditable_id', $return->id)
        ->where('event', 'status_changed')->where('new_values', 'like', '%posted%')->count();

    expect($posted)->toBe(1);
});
