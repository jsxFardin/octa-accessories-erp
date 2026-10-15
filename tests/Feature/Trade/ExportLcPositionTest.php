<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Trade\Models\LetterOfCredit;
use App\Modules\Trade\Services\LcPosition;
use App\Modules\Trade\States\ExportLcStateMachine;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Export LC, step 2 — drawn and realised: an invoice under a credit draws on it and is refused
 * past the credit's tolerance; a receipt under it realises it; the credit page shows the position.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->accounts = User::query()->where('email', 'accounts@octapussolution.com')->firstOrFail();
    $this->order = SalesOrder::query()->whereNotNull('number')->orderBy('id')->firstOrFail();

    $this->actingAs($this->merchandiser)->post('/export-lcs', [
        'lc_no' => 'SCB-EXP-2200', 'kind' => 'sight', 'customer_id' => $this->order->customer_id, 'currency_id' => $this->order->currency_id,
        'amount' => 10000, 'tolerance_pct' => 5, 'issued_on' => now()->toDateString(),
        'last_shipment_date' => now()->addDays(30)->toDateString(), 'expiry_date' => now()->addDays(45)->toDateString(),
    ])->assertSessionHasNoErrors();
    $this->lc = LetterOfCredit::query()->latest('id')->firstOrFail();
    $this->post("/export-lcs/{$this->lc->id}/orders", ['sales_order_id' => $this->order->id])->assertSessionHasNoErrors();

    /** An issued invoice written straight to the table: the walkthrough to a real one is another test's job. */
    $this->invoice = function (float $total, string $status = 'issued', ?int $lcId = null): SalesInvoice {
        $id = DB::table('sales_invoices')->insertGetId([
            'number' => 'INV-LC-'.uniqid(),
            'customer_id' => $this->order->customer_id,
            'sales_order_id' => $this->order->id,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(),
            'currency_id' => $this->order->currency_id,
            'exchange_rate' => 1,
            'subtotal' => $total, 'tax_amount' => 0, 'total' => $total,
            'received_amount' => 0,
            'status' => $status,
            'lc_id' => $lcId ?? $this->lc->id,
            'lc_no' => $this->lc->lc_no,
            'created_at' => now(),
        ]);

        return SalesInvoice::query()->findOrFail($id);
    };
});

it('draws on the credit up to the face value plus tolerance and refuses the invoice past it', function (): void {
    $position = app(LcPosition::class);

    expect($position->limit($this->lc))->toBe(10500.0)->and($position->drawn($this->lc))->toBe(0.0);

    ($this->invoice)(6000);
    $draft = ($this->invoice)(4500, 'draft');
    $position->assertCanDraw($draft);

    $tooMuch = ($this->invoice)(4500.01, 'draft');
    expect(fn () => $position->assertCanDraw($tooMuch))->toThrow(TransitionDenied::class, 'can be drawn to 10,500.00');

    // A cancelled invoice no longer draws; another currency never may.
    DB::table('sales_invoices')->where('id', $tooMuch->id)->update(['status' => 'cancelled']);
    expect($position->drawn($this->lc))->toBe(6000.0);

    $foreign = DB::table('currencies')->where('id', '!=', $this->lc->currency_id)->value('id');
    DB::table('sales_invoices')->where('id', $draft->id)->update(['currency_id' => $foreign]);
    expect(fn () => $position->assertCanDraw($draft->refresh()))->toThrow(TransitionDenied::class, 'own currency');
});

it('refuses an invoice under a credit that is realised, closed or cancelled', function (): void {
    $this->actingAs($this->merchandiser)->post("/export-lcs/{$this->lc->id}/transition", ['status' => 'cancelled', 'reason' => 'Buyer re-issued'])->assertSessionHasNoErrors();

    $draft = ($this->invoice)(100, 'draft');
    expect(fn () => app(LcPosition::class)->assertCanDraw($draft))->toThrow(TransitionDenied::class, 'cancelled');
});

it('shows the position on the credit and the credit on the invoice', function (): void {
    ($this->invoice)(6000);

    $this->actingAs($this->merchandiser)->get("/export-lcs/{$this->lc->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('position.limit', fn ($v) => (float) $v === 10500.0)
        ->where('position.drawn', fn ($v) => (float) $v === 6000.0)
        ->where('position.available', fn ($v) => (float) $v === 4500.0)
        ->where('position.invoices', 1));

    $draft = ($this->invoice)(100, 'draft', null);
    DB::table('sales_invoices')->where('id', $draft->id)->update(['lc_id' => null, 'lc_no' => null]);

    // The references form offers the customer's live credits; choosing one copies the bank number.
    $this->actingAs($this->accounts)->get("/invoices/{$draft->id}")->assertInertia(fn (AssertableInertia $page) => $page
        ->where('exportLcs.0.id', $this->lc->id));
    $this->put("/invoices/{$draft->id}/references", ['lc_id' => $this->lc->id])->assertSessionHasNoErrors();
    expect($draft->refresh()->lc_id)->toBe($this->lc->id)->and($draft->lc_no)->toBe('SCB-EXP-2200');

    // Another customer's credit is refused.
    $copy = (array) DB::table('customers')->where('id', $this->order->customer_id)->first();
    unset($copy['id']);
    $copy['code'] = 'CUS-LC-OTHER';
    $copy['name'] = 'Other buyer (test)';
    $otherCustomer = DB::table('customers')->insertGetId($copy);
    $otherLc = DB::table('letters_of_credit')->insertGetId([
        'number' => 'ELC-TEST-OTHER', 'lc_no' => 'X', 'kind' => 'sight', 'direction' => 'export', 'customer_id' => $otherCustomer,
        'currency_id' => $this->order->currency_id, 'amount' => 1, 'status' => 'advised', 'created_at' => now(),
    ]);
    $this->put("/invoices/{$draft->id}/references", ['lc_id' => $otherLc])->assertSessionHasErrors('lc_id');
});

it('a receipt under the credit realises it with the bank reference, and counts as realised', function (): void {
    $invoice = ($this->invoice)(6000);
    $this->actingAs($this->merchandiser)->post("/export-lcs/{$this->lc->id}/transition", ['status' => 'accepted'])->assertSessionHasNoErrors();
    StateMachine::asSystem(fn () => app(ExportLcStateMachine::class)->transition($this->lc->refresh(), 'shipped', ['shipment' => 'ESH-TEST']));
    $this->post("/export-lcs/{$this->lc->id}/transition", ['status' => 'presented'])->assertSessionHasNoErrors();

    $this->actingAs($this->accounts)->post('/receipts', [
        'customer_id' => $this->order->customer_id,
        'receipt_date' => now()->toDateString(),
        'method' => 'lc',
        'lc_id' => $this->lc->id,
        'reference_no' => 'FDBC/2026/201',
        'currency_id' => $this->order->currency_id,
        'amount' => 6000,
        'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => 6000]],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $receipt = DB::table('receipts')->latest('id')->first();

    expect((int) $receipt->lc_id)->toBe($this->lc->id)
        ->and($this->lc->refresh()->status)->toBe('realised')
        ->and($this->lc->realisation_ref)->toBe('FDBC/2026/201')
        ->and(app(LcPosition::class)->for($this->lc)['realised'])->toBe(6000.0);

    // A receipt by LC names which credit; one without is refused.
    $this->post('/receipts', [
        'customer_id' => $this->order->customer_id, 'receipt_date' => now()->toDateString(), 'method' => 'lc',
        'currency_id' => $this->order->currency_id, 'amount' => 1, 'allocations' => [['sales_invoice_id' => $invoice->id, 'amount' => 1]],
    ])->assertSessionHasErrors('lc_id');
});
