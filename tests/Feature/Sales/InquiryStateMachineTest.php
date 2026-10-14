<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\MasterData\Models\Currency;
use App\Modules\MasterData\Models\Customer;
use App\Modules\Product\Models\Product;
use App\Modules\Sales\Models\Inquiry;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\States\InquiryStateMachine;
use App\Support\States\StateMachine;
use Illuminate\Support\Facades\DB;

/**
 * GAP P-05 — inquiry and quotation status changes go through their state machines.
 *
 * The inquiry controller wrote whatever status the form sent, from any status to any other,
 * behind `inquiry.view`; a quotation retired to `revised` by a bare column write. Both now
 * take the table, the permission and the audit row every other document gets.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->auditor = User::query()->where('email', 'auditor@octapussolution.com')->firstOrFail();
    $this->customer = Customer::query()->firstOrFail();
    $this->product = Product::query()->firstOrFail();
    $this->currency = Currency::query()->where('is_base', true)->firstOrFail();

    $this->openInquiry = function (): Inquiry {
        $this->actingAs($this->merchandiser)->post('/inquiries', [
            'customer_id' => $this->customer->id,
            'inquiry_date' => now()->toDateString(),
            'lines' => [['product_id' => $this->product->id, 'description' => '20,000 woven labels', 'qty' => 20000]],
        ])->assertSessionHasNoErrors();

        $inquiry = Inquiry::query()->latest('id')->firstOrFail();
        $this->post("/inquiries/{$inquiry->id}/transition", ['status' => Inquiry::OPEN])->assertSessionHasNoErrors();

        return $inquiry->fresh();
    };

    $this->quote = function (Inquiry $inquiry): Quotation {
        $this->actingAs($this->merchandiser)->post('/quotations', [
            'inquiry_id' => $inquiry->id,
            'customer_id' => $this->customer->id,
            'quotation_date' => now()->toDateString(),
            'currency_id' => $this->currency->id,
            'exchange_rate' => 1,
            'lines' => [['product_id' => $this->product->id, 'description' => '20,000 woven labels', 'qty' => 20000, 'rate_per_m' => 3.0]],
        ])->assertSessionHasNoErrors();

        return Quotation::query()->latest('id')->firstOrFail();
    };
});

it('refuses a transition the table does not have', function (): void {
    $inquiry = ($this->openInquiry)();
    StateMachine::asSystem(fn () => app(InquiryStateMachine::class)->transition($inquiry, Inquiry::QUOTED));
    StateMachine::asSystem(fn () => app(InquiryStateMachine::class)->transition($inquiry->refresh(), Inquiry::WON));

    $this->actingAs($this->merchandiser)
        ->post("/inquiries/{$inquiry->id}/transition", ['status' => 'open'])
        ->assertSessionHas('error');

    expect($inquiry->fresh()->status)->toBe(Inquiry::WON);
});

it('charges the closing permission, not merely the right to view', function (): void {
    $open = ($this->openInquiry)();

    expect($this->auditor->hasPermission('inquiry.view'))->toBeTrue()
        ->and($this->auditor->hasPermission('inquiry.close'))->toBeFalse();

    $this->actingAs($this->auditor)
        ->post("/inquiries/{$open->id}/transition", ['status' => 'lost', 'lost_reason' => 'No bid'])
        ->assertSessionHas('error');

    expect($open->fresh()->status)->toBe(Inquiry::OPEN);
});

it('records a hand-made inquiry transition on the audit trail', function (): void {
    $open = ($this->openInquiry)();

    $this->actingAs($this->merchandiser)
        ->post("/inquiries/{$open->id}/transition", ['status' => 'lost', 'lost_reason' => 'Customer bought from another mill'])
        ->assertSessionHas('success');

    expect($open->fresh()->status)->toBe(Inquiry::LOST)
        ->and($open->fresh()->lost_reason)->toBe('Customer bought from another mill')
        ->and(DB::table('audit_logs')->where('auditable_type', Inquiry::class)->where('auditable_id', $open->id)
            ->where('event', 'status_changed')->exists())->toBeTrue();
});

it('revises only a sent or expired quotation, through the machine', function (): void {
    $inquiry = ($this->openInquiry)();
    $quotation = ($this->quote)($inquiry);

    expect($quotation->status)->toBe('draft');

    $this->actingAs($this->merchandiser)
        ->post("/quotations/{$quotation->id}/transition", ['to' => 'revised'])
        ->assertSessionHas('error');

    expect($quotation->fresh()->status)->toBe('draft')
        ->and(Quotation::query()->where('inquiry_id', $inquiry->id)->count())->toBe(1);

    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent'])->assertSessionHasNoErrors();
    $before = DB::table('audit_logs')->where('auditable_type', Quotation::class)->where('auditable_id', $quotation->id)
        ->where('event', 'status_changed')->count();

    $this->actingAs($this->merchandiser)
        ->post("/quotations/{$quotation->id}/transition", ['to' => 'revised'])
        ->assertSessionHas('success');

    expect($quotation->fresh()->status)->toBe('revised')
        ->and(Quotation::query()->where('inquiry_id', $inquiry->id)->count())->toBe(2)
        ->and(DB::table('audit_logs')->where('auditable_type', Quotation::class)->where('auditable_id', $quotation->id)
            ->where('event', 'status_changed')->count())->toBe($before + 1);
});

// GAP P-13 — SL-4 AC1: a revision carries its cost sheets, open, so it can be sent again.
it('copies each line\'s cost sheet onto the revision', function (): void {
    $inquiry = ($this->openInquiry)();
    $quotation = ($this->quote)($inquiry);
    $this->actingAs($this->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent'])->assertSessionHasNoErrors();

    $priorLine = $quotation->lines()->firstOrFail();
    $priorSheet = App\Modules\Costing\Models\CostSheet::query()->where('quotation_line_id', $priorLine->id)->firstOrFail();

    $this->post("/quotations/{$quotation->id}/transition", ['to' => 'revised'])->assertSessionHas('success');

    $revision = Quotation::query()->where('inquiry_id', $inquiry->id)->where('revision_no', 1)->firstOrFail();
    $newLine = $revision->lines()->firstOrFail();
    $sheet = App\Modules\Costing\Models\CostSheet::query()->where('quotation_line_id', $newLine->id)->first();

    expect($sheet)->not->toBeNull()
        ->and((bool) $sheet->is_locked)->toBeFalse()
        ->and($sheet->lines()->count())->toBe($priorSheet->lines()->count())
        // The prior revision keeps its own, locked.
        ->and((bool) $priorSheet->fresh()->is_locked)->toBeTrue();

    // And the revision can be sent without anyone re-saving it (Q1).
    $this->post("/quotations/{$revision->id}/transition", ['to' => 'sent'])->assertSessionHasNoErrors();
});
