<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Trade\Models\LetterOfCredit;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Export LC, step 5 — the export register: every export credit with drawn, realised and
 * available figures, its shipments and EXP forms; and the back-to-back link an import credit
 * names on its master.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->purchaser = User::query()->where('email', 'purchasemanager@octapussolution.com')->firstOrFail();
    $this->order = SalesOrder::query()->whereNotNull('number')->orderBy('id')->firstOrFail();

    $this->actingAs($this->merchandiser)->post('/export-lcs', [
        'lc_no' => 'REG-EXP-7', 'kind' => 'sight', 'customer_id' => $this->order->customer_id, 'currency_id' => $this->order->currency_id,
        'amount' => 10000, 'tolerance_pct' => 10, 'issued_on' => now()->toDateString(),
    ])->assertSessionHasNoErrors();
    $this->lc = LetterOfCredit::query()->latest('id')->firstOrFail();

    DB::table('sales_invoices')->insert([
        'number' => 'INV-REG-1', 'customer_id' => $this->order->customer_id, 'sales_order_id' => $this->order->id,
        'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(), 'currency_id' => $this->order->currency_id,
        'exchange_rate' => 1, 'subtotal' => 4000, 'tax_amount' => 0, 'total' => 4000, 'received_amount' => 0, 'status' => 'issued', 'lc_id' => $this->lc->id, 'lc_no' => 'REG-EXP-7', 'created_at' => now(),
    ]);
});

it('lists the credit with drawn, realised and available figures', function (): void {
    $this->actingAs($this->merchandiser)->get('/reports/export-register')->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $row = collect($page->toArray()['props']['rows']['data'])->firstWhere('id', $this->lc->id);

            expect($row)->not->toBeNull()
                ->and((float) $row['amount'])->toBe(10000.0)
                ->and((float) $row['drawn_amount'])->toBe(4000.0)
                ->and((float) $row['available_amount'])->toBe(7000.0)
                ->and((float) $row['realised_amount'])->toBe(0.0);
        });

    $this->get('/reports/export-register?unrealised=1')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('rows.data', fn ($rows) => collect($rows)->pluck('id')->doesntContain($this->lc->id)));
});

it('lets an import credit name the export credit it is opened back-to-back against, and the master lists it', function (): void {
    $supplier = DB::table('suppliers')->value('id');

    $this->actingAs($this->purchaser)->post('/letters-of-credit', [
        'kind' => 'back_to_back', 'supplier_id' => $supplier, 'currency_id' => $this->order->currency_id, 'amount' => 6000,
        'master_lc_id' => $this->lc->id, 'applied_on' => now()->toDateString(),
    ])->assertSessionHasNoErrors();

    $import = LetterOfCredit::query()->latest('id')->firstOrFail();
    expect($import->direction)->toBe('import')->and($import->master_lc_id)->toBe($this->lc->id);

    $this->actingAs($this->merchandiser)->get("/export-lcs/{$this->lc->id}")
        ->assertInertia(fn (AssertableInertia $page) => $page->where('backToBack.0.id', $import->id));

    // Only an export credit can be a master.
    $this->actingAs($this->purchaser)->post('/letters-of-credit', [
        'kind' => 'back_to_back', 'supplier_id' => $supplier, 'currency_id' => $this->order->currency_id, 'amount' => 1, 'master_lc_id' => $import->id,
    ])->assertSessionHasErrors('master_lc_id');
});
