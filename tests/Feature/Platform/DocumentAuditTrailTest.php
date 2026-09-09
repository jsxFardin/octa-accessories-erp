<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Dispatch\Models\FgReceipt;
use App\Modules\Dispatch\Models\PackingList;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\MaterialIssue;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Modules\Sales\Models\Inquiry;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\Audit\Auditable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * F-02 — the audit trail records that documents were *created*.
 *
 * `audit_logs` held 42 rows for sales orders and every one of them was a status change: the
 * commercial documents never opted into `Auditable`, so nothing recorded where an order came
 * from or who raised it. Conversion had no event of its own either — it is not an update to
 * the quotation and not a status change on it; a different document came into existence
 * because of it.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
});

function auditEvents(string $type, int $id): Illuminate\Support\Collection
{
    return DB::table('audit_logs')
        ->where('auditable_type', $type)
        ->where('auditable_id', $id)
        ->pluck('event');
}

it('records a creation event for every document the chain runs on', function (): void {
    // Inquiry.
    $this->actingAs($this->merchandiser)->post('/inquiries', [
        'customer_id' => App\Modules\MasterData\Models\Customer::query()->value('id'),
        'inquiry_date' => now()->toDateString(),
        'lines' => [['product_id' => null, 'description' => '20,000 woven labels', 'qty' => 20000]],
    ])->assertSessionHasNoErrors();

    $inquiry = Inquiry::query()->latest('id')->firstOrFail();

    expect(auditEvents(Inquiry::class, $inquiry->id))->toContain('created');

    // Quotation, and the sales order it becomes.
    $quotation = convertibleQuotationForAudit($this);

    expect(auditEvents(Quotation::class, $quotation->id))->toContain('created');

    $this->actingAs($this->merchandiser)
        ->post("/quotations/{$quotation->id}/convert", ['customer_po_no' => 'PO-AUDIT-1'])
        ->assertSessionHasNoErrors();

    $order = SalesOrder::query()->where('quotation_id', $quotation->id)->firstOrFail();

    expect(auditEvents(SalesOrder::class, $order->id))->toContain('created')
        // Conversion is its own event, on the document it happened to.
        ->and(auditEvents(Quotation::class, $quotation->id))->toContain('converted');
});

function convertibleQuotationForAudit(object $test): Quotation
{
    $test->actingAs($test->merchandiser)->post('/quotations', [
        'customer_id' => App\Modules\MasterData\Models\Customer::query()->value('id'),
        'quotation_date' => now()->toDateString(),
        'currency_id' => App\Modules\MasterData\Models\Currency::query()->where('is_base', true)->value('id'),
        'exchange_rate' => 1,
        'lines' => [[
            'product_id' => App\Modules\Product\Models\Product::query()->value('id'),
            'description' => '20,000 woven labels',
            'qty' => 20000,
            'rate_per_m' => 3.0,
        ]],
    ])->assertSessionHasNoErrors();

    $quotation = Quotation::query()->latest('id')->firstOrFail();

    $test->actingAs($test->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent']);
    $test->actingAs($test->merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'accepted']);

    return $quotation->fresh();
}

it('records who did it, when, and what changed', function (): void {
    $quotation = convertibleQuotationForAudit($this);

    $row = DB::table('audit_logs')
        ->where('auditable_type', Quotation::class)
        ->where('auditable_id', $quotation->id)
        ->where('event', 'created')
        ->first();

    expect($row->user_id)->toBe($this->merchandiser->id)
        ->and($row->created_at)->not->toBeNull()
        ->and($row->ip_address)->not->toBeNull();

    $values = json_decode((string) $row->new_values, true);

    expect($values['customer_id'])->toBe($quotation->customer_id)
        ->and($values)->not->toHaveKey('password');
});

it('distinguishes created, updated, status changed and converted on the same document', function (): void {
    $quotation = convertibleQuotationForAudit($this);

    $this->actingAs($this->merchandiser)
        ->post("/quotations/{$quotation->id}/convert", ['customer_po_no' => 'PO-AUDIT-2'])
        ->assertSessionHasNoErrors();

    $events = auditEvents(Quotation::class, $quotation->id)->unique()->values()->all();

    expect($events)->toContain('created')
        ->toContain('status_changed')
        ->toContain('converted');
});

it('records the production chain: job card, material issue, FG receipt, packing, dispatch', function (): void {
    $jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();

    expect(auditEvents(JobCard::class, $jobCard->id))->toContain('created');

    $this->actingAs($this->admin);
    $states = app(JobCardStateMachine::class);
    $states->transition($jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'audit walkthrough']);
    $states->transition($jobCard->refresh(), JobCard::IN_PRODUCTION);

    expect(auditEvents(JobCard::class, $jobCard->id))->toContain('status_changed');

    issueMaterialFor($jobCard->refresh(), 20000);

    $final = $jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    $final->forceFill(['input_qty' => 10000, 'good_qty' => 10000])->save();

    $this->actingAs(User::query()->where('email', 'qc@octapussolution.com')->firstOrFail());
    $this->post('/qc-inspections', ['job_card_id' => $jobCard->id, 'stage' => 'final', 'lot_size' => 500, 'major_found' => 0]);

    $this->actingAs($this->admin);
    $receipt = app(App\Modules\Manufacturing\Services\FgReceiptService::class)->post(
        $jobCard->refresh(),
        5000,
        (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id'),
        (string) Str::uuid(),
    );

    expect(auditEvents(FgReceipt::class, $receipt->id))->toContain('created');

    $soId = (int) DB::table('sales_order_lines')->where('id', $jobCard->sales_order_line_id)->value('sales_order_id');
    $soLineId = (int) $jobCard->sales_order_line_id;

    $dispatcher = User::query()->where('email', 'dispatch@octapussolution.com')->firstOrFail();
    $this->actingAs($dispatcher);
    $this->post('/packing-lists', ['sales_order_id' => $soId]);
    $list = PackingList::query()->latest('id')->firstOrFail();

    expect(auditEvents(PackingList::class, $list->id))->toContain('created');

    $this->post("/packing-lists/{$list->id}/cartons", []);
    $carton = DB::table('cartons')->where('packing_list_id', $list->id)->first();
    $this->post("/packing-lists/{$list->id}/cartons/{$carton->id}/contents", [
        'sales_order_line_id' => $soLineId, 'lot_id' => $receipt->lot_id, 'qty' => 2000,
    ]);
    $this->post("/packing-lists/{$list->id}/transition", ['to' => 'packed'])->assertSessionHasNoErrors();
    $this->post('/delivery-challans', ['packing_list_id' => $list->id, 'mode' => 'own_fleet'])
        ->assertSessionHasNoErrors();

    $challan = DeliveryChallan::query()->latest('id')->firstOrFail();

    expect(auditEvents(DeliveryChallan::class, $challan->id))->toContain('created');

    DB::table('certifications')->where('scheme', 'GRS')->update([
        'issued_on' => now()->subYear()->toDateString(),
        'expires_on' => now()->addYear()->toDateString(),
    ]);

    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued'])->assertSessionHas('success');

    // The dispatch itself: a status change on the challan, which is the document that moved.
    expect(auditEvents(DeliveryChallan::class, $challan->id))->toContain('status_changed');

    // And a material issue, written the way the store screen writes it — through the model,
    // which is what carries the trait. (The BR-48 fixture above inserts rows directly, so it
    // deliberately leaves no trail; this is the real path.)
    $issue = MaterialIssue::query()->create([
        'number' => 'MI-AUDIT-1',
        'job_card_id' => $jobCard->id,
        'warehouse_id' => (int) DB::table('warehouses')->where('kind', 'raw_material')->value('id'),
        'issued_on' => now()->toDateString(),
        'issue_type' => 'issue',
        'status' => 'draft',
    ]);

    expect(auditEvents(MaterialIssue::class, $issue->id))->toContain('created');
});

it('records production output against the operation that booked it', function (): void {
    $jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();

    $this->actingAs($this->admin);
    $states = app(JobCardStateMachine::class);
    $states->transition($jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'audit walkthrough']);
    $states->transition($jobCard->refresh(), JobCard::IN_PRODUCTION);

    $card = DB::table('employees')
        ->join('users', 'users.id', '=', 'employees.user_id')
        ->where('users.email', 'operator@octapussolution.com')
        ->value('card_no');

    $token = $this->postJson('/api/v1/device/session', [
        'card_no' => $card, 'pin' => substr((string) $card, -4),
    ])->json('token');

    $first = $jobCard->operations()->orderBy('sequence_no')->firstOrFail();

    $this->postJson("/api/v1/operations/{$first->id}/log", [
        'good_qty' => 300, 'waste_qty' => 4, 'input_qty' => 324.3, 'waste_type' => 'setup',
    ], ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => 'audit-log-1'])->assertOk();

    $row = DB::table('audit_logs')
        ->where('auditable_type', 'operation_logs')
        ->where('event', 'created')
        ->orderByDesc('id')
        ->first();

    expect($row)->not->toBeNull();

    $values = json_decode((string) $row->new_values, true);

    expect($values['job_card_operation_id'])->toBe($first->id)
        ->and((float) $values['good_qty'])->toBe(300.0)
        ->and($values['operation'])->toBe($first->name);
});

it('does not audit every harmless read', function (): void {
    $before = DB::table('audit_logs')->count();

    $this->actingAs($this->admin)->get('/job-cards')->assertOk();
    $this->actingAs($this->admin)->get('/sales-orders')->assertOk();
    $this->actingAs($this->admin)->get('/delivery-challans')->assertOk();
    $this->actingAs($this->admin)->get('/quotations')->assertOk();

    expect(DB::table('audit_logs')->count())->toBe($before);
});

it('keeps every audited document on one mechanism', function (): void {
    // A parallel audit system is how half the trail goes missing; these all use the trait.
    foreach ([
        Inquiry::class, Quotation::class, SalesOrder::class, JobCard::class,
        MaterialIssue::class, FgReceipt::class, PackingList::class, DeliveryChallan::class,
    ] as $model) {
        expect(in_array(Auditable::class, class_uses_recursive($model), true))
            ->toBeTrue("{$model} is not Auditable");
    }
});
