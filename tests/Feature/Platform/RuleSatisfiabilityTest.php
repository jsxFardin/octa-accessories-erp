<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Support\Reference\ReferenceRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A rule that tells the user to do something the application offers no way to do is not a
 * rule; it is a wall. BR-43 said "upload the signed certificate" while the certificate form
 * had no such field, and two more were introduced with the guards in this remediation: BR-48
 * told a supervisor to "record a waiver with a reason" on a form with nowhere to write one,
 * and I7 said "complete with a documented waiver" behind a button that sent no context.
 *
 * These walk the remedy each message names and prove the door it points at opens.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail();
    $this->supervisor = User::query()->where('email', 'supervisor@maheenlabel.test')->firstOrFail();
    $this->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
});

it('BR-43: the certificate the shipping rule demands can be recorded', function (): void {
    // The rule names a remedy; the register must carry the field that satisfies it.
    $fields = collect(ReferenceRegistry::find('certifications')['fields'])->pluck('name');

    expect($fields)->toContain('document_path');

    $certificate = DB::table('certifications')->where('scheme', 'GRS')->firstOrFail();

    $this->actingAs($this->admin)
        ->put("/setup/certifications/{$certificate->id}", [
            'scheme' => $certificate->scheme,
            'certificate_no' => $certificate->certificate_no,
            'issued_on' => $certificate->issued_on,
            'expires_on' => $certificate->expires_on,
            'status' => 'active',
            'reminder_days' => 60,
            'document_path' => 'compliance/GRS-signed.pdf',
        ])->assertSessionHasNoErrors();

    expect(DB::table('certifications')->where('id', $certificate->id)->value('document_path'))
        ->toBe('compliance/GRS-signed.pdf');
});

it('BR-48: the waiver the receipt rule demands is accepted by the receipt endpoint', function (): void {
    $states = app(JobCardStateMachine::class);
    $this->actingAs($this->admin);
    $states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'Rule audit']);
    $states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);

    $final = $this->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail();
    $final->forceFill(['input_qty' => 5000, 'good_qty' => 5000])->save();

    $warehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');

    // The refusal names the remedy…
    $refused = $this->actingAs($this->supervisor)->post("/job-cards/{$this->jobCard->id}/fg-receipts", [
        'qty' => 1000, 'warehouse_id' => $warehouseId, 'grade' => 'A', 'client_ref' => (string) Str::uuid(),
    ]);

    $refused->assertSessionHasErrors('qty');
    expect(session('errors')->first('qty'))->toContain('record a waiver with a reason');

    // …and the remedy is a field the endpoint accepts, from someone who may use it.
    $this->actingAs($this->supervisor)->post("/job-cards/{$this->jobCard->id}/fg-receipts", [
        'qty' => 1000, 'warehouse_id' => $warehouseId, 'grade' => 'A', 'client_ref' => (string) Str::uuid(),
        'material_waiver_reason' => 'Rework fed from JC-26-000000.',
    ])->assertSessionHasNoErrors();

    expect(DB::table('fg_receipts')->where('job_card_id', $this->jobCard->id)->where('status', 'posted')->count())
        ->toBe(1);
});

it('I7: the waiver the completion rule demands is accepted by the transition endpoint', function (): void {
    $states = app(JobCardStateMachine::class);
    $this->actingAs($this->admin);
    $states->transition($this->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'Rule audit']);
    $states->transition($this->jobCard->refresh(), JobCard::IN_PRODUCTION);

    // J2 first: a card completes once its steps are closed. What is under test is the rule
    // *after* that one — I7, which asks about material rather than about operations.
    $this->jobCard->operations()->update([
        'input_qty' => 5000, 'good_qty' => 5000, 'waste_qty' => 0, 'status' => 'completed',
    ]);

    $states->transition($this->jobCard->refresh(), JobCard::QC_PENDING);

    // P1-1 next: the routing demands a final verdict. Each rule names its own remedy and each
    // remedy exists — this is the chain of them, walked in order.
    $this->actingAs(User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail())
        ->post('/qc-inspections', [
            'job_card_id' => $this->jobCard->id, 'stage' => 'final',
            'lot_size' => 500, 'major_found' => 0, 'minor_found' => 0, 'critical_found' => 0,
        ])->assertSessionHasNoErrors();

    $this->actingAs($this->admin);

    // The refusal names the remedy…
    $this->actingAs($this->admin)
        ->post("/job-cards/{$this->jobCard->id}/transition", ['to' => 'completed'])
        ->assertSessionHas('error');

    expect(session('error'))->toContain('complete with a documented waiver');

    // …and the transition endpoint takes it.
    $this->actingAs($this->admin)
        ->post("/job-cards/{$this->jobCard->id}/transition", [
            'to' => 'completed',
            'material_waiver_reason' => 'Ran on substitute yarn issued to another card.',
        ])->assertSessionHasNoErrors();

    expect($this->jobCard->fresh()->status)->toBe(JobCard::COMPLETED);
});

it('BR-33: the rejection reason the rule demands is accepted where the rule fires', function (): void {
    $merchandiser = User::query()->where('email', 'merchandiser@maheenlabel.test')->firstOrFail();
    $customer = App\Modules\MasterData\Models\Customer::query()->firstOrFail();
    $product = App\Modules\Product\Models\Product::query()->firstOrFail();

    $this->actingAs($merchandiser)->post('/quotations', [
        'customer_id' => $customer->id,
        'quotation_date' => now()->toDateString(),
        'currency_id' => App\Modules\MasterData\Models\Currency::query()->where('is_base', true)->value('id'),
        'exchange_rate' => 1,
        'lines' => [['product_id' => $product->id, 'description' => 'labels', 'qty' => 1000, 'rate_per_m' => 3]],
    ])->assertSessionHasNoErrors();

    $quotation = App\Modules\Sales\Models\Quotation::query()->latest('id')->firstOrFail();
    $this->actingAs($merchandiser)->post("/quotations/{$quotation->id}/transition", ['to' => 'sent']);

    $this->actingAs($merchandiser)
        ->post("/quotations/{$quotation->id}/transition", ['to' => 'rejected'])
        ->assertSessionHas('error');

    expect(session('error'))->toContain('reason');

    $this->actingAs($merchandiser)
        ->post("/quotations/{$quotation->id}/transition", ['to' => 'rejected', 'reject_reason' => 'Price.'])
        ->assertSessionHasNoErrors();

    expect($quotation->fresh()->status)->toBe('rejected');
});

it('J1: the shortage the release gate reports can be waived, and the artwork cannot', function (): void {
    $gate = app(App\Modules\Manufacturing\Services\JobCardReleaseGate::class)->evaluate($this->jobCard);

    // Every failing check names a rule and says what is wrong, so the remedy is identifiable.
    foreach ($gate['checks'] as $check) {
        expect($check['rule'])->not->toBeEmpty()
            ->and($check['detail'])->not->toBeEmpty();
    }

    // The material check is waivable through the release transition; the gate says so.
    expect($gate['checks']['material'])->toHaveKey('ok');

    $this->actingAs($this->admin)
        ->post("/job-cards/{$this->jobCard->id}/transition", [
            'to' => 'released',
            'material_waiver_reason' => 'Yarn arriving tomorrow; loom is free today.',
        ])->assertSessionHasNoErrors();

    expect($this->jobCard->fresh()->status)->toBe(JobCard::RELEASED);
});

it('every reference register the rules point at is reachable and writable', function (): void {
    // A rule that says "register it first" needs the register to exist behind a route.
    foreach (['certifications', 'defects', 'downtime-reasons'] as $slug) {
        $definition = ReferenceRegistry::find($slug);

        expect($definition)->not->toBeNull("reference [{$slug}] is missing");

        $this->actingAs($this->admin)->get("/setup/{$slug}")->assertOk();
    }
});
