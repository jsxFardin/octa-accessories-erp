<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Services\JobCardReleaseGate;
use App\Modules\Sampling\Models\SampleRequest;
use App\Modules\Sampling\Services\SampleProduction;
use App\Modules\Sampling\States\SampleRequestStateMachine;
use App\Support\States\StateMachine;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * GAP P-01 — the sampling workflow (AS-5 … AS-7): a merchandiser raises a request, the sample
 * room makes it on a sample job card, dispatches it with a tracking number, and the customer's
 * decision lands per line; a pre-production approval releases bulk for a customer who requires it.
 */
beforeEach(function (): void {
    $this->merchandiser = User::query()->where('email', 'merchandiser@octapussolution.com')->firstOrFail();
    $this->supervisor = User::query()->where('email', 'supervisor@octapussolution.com')->firstOrFail();
    $this->lab = User::query()->where('email', 'lab@octapussolution.com')->firstOrFail();

    // A product that can carry a card — one already has — and the customer it belongs to.
    $this->bulkCard = JobCard::query()->whereNotNull('sales_order_line_id')->with('salesOrderLine.salesOrder')->orderBy('id')->firstOrFail();
    $this->productId = (int) $this->bulkCard->product_id;
    $this->customerId = (int) $this->bulkCard->salesOrderLine->salesOrder->customer_id;
    $this->unit = (int) DB::table('factory_units')->where('is_active', true)->value('id');

    $this->raise = function (array $overrides = []): SampleRequest {
        $this->actingAs($this->merchandiser)->post('/sample-requests', [
            'customer_id' => $this->customerId,
            'sample_type' => 'pre_production',
            'requested_on' => now()->toDateString(),
            'required_by' => now()->addDays(10)->toDateString(),
            'is_chargeable' => true,
            'charge_amount' => 1500,
            'lines' => [
                ['product_id' => $this->productId, 'description' => 'Main label, navy ground', 'qty' => 50, 'colourway' => 'Navy / white'],
                ['description' => 'Hang tag mock-up', 'qty' => 10],
            ],
            ...$overrides,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return SampleRequest::query()->latest('id')->firstOrFail();
    };

    $this->makeReady = function (SampleRequest $request): SampleRequest {
        $this->actingAs($this->merchandiser)->post("/sample-requests/{$request->id}/transition", ['status' => 'ready'])->assertSessionHasNoErrors();

        return $request->refresh();
    };

    $this->dispatch = function (SampleRequest $request, string $tracking = 'DHL-4471'): SampleRequest {
        $this->actingAs($this->merchandiser)->post("/sample-requests/{$request->id}/dispatch", [
            'dispatched_on' => now()->toDateString(),
            'courier_name' => 'DHL',
            'tracking_no' => $tracking,
            'recipient' => 'Buyer QA desk',
        ])->assertSessionHasNoErrors();

        return $request->refresh();
    };
});

it('raises a numbered request with its lines, and charges are captured for later invoicing', function (): void {
    $request = ($this->raise)();

    expect($request->number)->toStartWith('SMP')
        ->and($request->status)->toBe('requested')
        ->and($request->is_chargeable)->toBeTrue()
        ->and((float) $request->charge_amount)->toBe(1500.0)
        ->and($request->lines)->toHaveCount(2)
        ->and($request->lines->first()->status)->toBe('pending');

    $this->get("/sample-requests/{$request->id}")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('Sales/SampleRequests/Show')->has('lines', 2));
});

it('needs a type, at least one line, and an amount when chargeable', function (): void {
    $this->actingAs($this->merchandiser)->post('/sample-requests', [
        'customer_id' => $this->customerId,
        'requested_on' => now()->toDateString(),
        'is_chargeable' => true,
        'lines' => [],
    ])->assertSessionHasErrors(['sample_type', 'lines', 'charge_amount']);
});

it('keeps the samples list from a role without the permission', function (): void {
    $this->actingAs($this->lab)->get('/sample-requests')->assertForbidden();
});

it('raises a sample job card from a line, so sample production is a real run', function (): void {
    $request = ($this->raise)();
    $line = $request->lines->firstWhere('product_id', $this->productId);

    $this->actingAs($this->supervisor)->post("/sample-requests/{$request->id}/lines/{$line->id}/job-card", [
        'factory_unit_id' => $this->unit,
        'planned_qty' => 50,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $card = JobCard::query()->where('sample_request_line_id', $line->id)->firstOrFail();

    expect($card->for_stock)->toBeFalse()
        ->and($card->sales_order_line_id)->toBeNull()
        ->and((float) $card->planned_qty)->toBe(50.0)
        ->and($card->colourway)->toBe('Navy / white')
        ->and($card->operations()->count())->toBeGreaterThan(0)
        ->and($request->refresh()->status)->toBe('in_production');

    // A line that is not yet a product has nothing to run.
    $other = $request->lines->firstWhere('product_id', null);
    $this->post("/sample-requests/{$request->id}/lines/{$other->id}/job-card", ['factory_unit_id' => $this->unit, 'planned_qty' => 10])
        ->assertSessionHasErrors('planned_qty');
});

it('marks the line produced when its card completes, and the request ready once every line has pieces', function (): void {
    $request = ($this->raise)();
    $line = $request->lines->firstWhere('product_id', $this->productId);

    $this->actingAs($this->supervisor)->post("/sample-requests/{$request->id}/lines/{$line->id}/job-card", ['factory_unit_id' => $this->unit, 'planned_qty' => 50])->assertSessionHasNoErrors();
    $card = JobCard::query()->where('sample_request_line_id', $line->id)->firstOrFail();

    app(SampleProduction::class)->onJobCardCompleted($card);

    expect($line->refresh()->status)->toBe('produced')
        ->and($request->refresh()->status)->toBe('in_production');

    // The mock-up line was made by hand; once it too has pieces the request is ready.
    $request->lines()->whereNull('product_id')->update(['status' => 'produced']);
    app(SampleProduction::class)->onJobCardCompleted($card);

    expect($request->refresh()->status)->toBe('ready');
});

it('dispatches only a ready request, records the parcel, and shows the tracking number on the list', function (): void {
    $request = ($this->raise)();

    $this->actingAs($this->merchandiser)->post("/sample-requests/{$request->id}/dispatch", [
        'dispatched_on' => now()->toDateString(), 'courier_name' => 'DHL', 'recipient' => 'Buyer QA desk',
    ])->assertSessionHas('error');

    expect($request->refresh()->status)->toBe('requested');

    ($this->makeReady)($request);
    ($this->dispatch)($request, 'DHL-4471');

    expect($request->status)->toBe('dispatched')
        ->and($request->dispatches)->toHaveCount(1)
        ->and($request->lines()->pluck('status')->unique()->all())->toBe(['dispatched']);

    $this->get('/sample-requests?status=dispatched')->assertInertia(fn (AssertableInertia $page) => $page
        ->where('requests.data.0.id', $request->id)
        ->where('requests.data.0.tracking_no', 'DHL-4471'));

    // AS-6 AC3 — delivered later, never before it was sent.
    $dispatch = $request->dispatches->first();
    $this->put("/sample-requests/{$request->id}/dispatches/{$dispatch->id}", ['delivered_on' => now()->subDay()->toDateString()])->assertSessionHasErrors('delivered_on');
    $this->put("/sample-requests/{$request->id}/dispatches/{$dispatch->id}", ['delivered_on' => now()->toDateString()])->assertSessionHasNoErrors();
    expect($dispatch->refresh()->delivered_on)->not->toBeNull();
});

it('cannot be marked dispatched by hand: the dispatch record is what moves it', function (): void {
    $request = ($this->makeReady)(($this->raise)());

    expect(fn () => StateMachine::asSystem(fn () => app(SampleRequestStateMachine::class)->transition($request, 'dispatched')))
        ->toThrow(TransitionDenied::class);
});

it('records the decision per line, needs comments unless plainly approved, and settles the request on the last line', function (): void {
    $request = ($this->dispatch)(($this->makeReady)(($this->raise)()));
    [$first, $second] = $request->lines->all();

    // Before dispatch there is nothing to decide on — covered by the status check.
    $this->actingAs($this->merchandiser)
        ->post("/sample-requests/{$request->id}/lines/{$first->id}/decision", ['decision' => 'rejected', 'decided_on' => now()->toDateString()])
        ->assertSessionHasErrors('comments');

    $this->post("/sample-requests/{$request->id}/lines/{$first->id}/decision", ['decision' => 'approved_with_comments', 'decided_on' => now()->toDateString(), 'comments' => 'Shift the logo 1 mm left.', 'customer_ref' => 'Mail 12 Oct'])
        ->assertSessionHasNoErrors();

    expect($first->refresh()->status)->toBe('approved')
        ->and($request->refresh()->status)->toBe('dispatched');

    // Deciding a line twice is refused.
    $this->post("/sample-requests/{$request->id}/lines/{$first->id}/decision", ['decision' => 'approved', 'decided_on' => now()->toDateString()])
        ->assertSessionHasErrors('decision');

    $this->post("/sample-requests/{$request->id}/lines/{$second->id}/decision", ['decision' => 'rejected', 'decided_on' => now()->toDateString(), 'comments' => 'Wrong board weight.'])
        ->assertSessionHasNoErrors();

    expect($second->refresh()->status)->toBe('rejected')
        ->and($request->refresh()->status)->toBe('rejected')
        ->and($first->approvals)->toHaveCount(1)
        ->and($first->approvals->first()->customer_ref)->toBe('Mail 12 Oct');
});

it('is approved when every line is', function (): void {
    $request = ($this->dispatch)(($this->makeReady)(($this->raise)()));

    foreach ($request->lines as $line) {
        $this->actingAs($this->merchandiser)
            ->post("/sample-requests/{$request->id}/lines/{$line->id}/decision", ['decision' => 'approved', 'decided_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();
    }

    expect($request->refresh()->status)->toBe('approved');
});

it('cancelling needs a reason and is refused once dispatched', function (): void {
    $request = ($this->raise)();

    $this->actingAs($this->merchandiser)->post("/sample-requests/{$request->id}/transition", ['status' => 'cancelled'])->assertSessionHasErrors('reason');
    $this->post("/sample-requests/{$request->id}/transition", ['status' => 'cancelled', 'reason' => 'Buyer withdrew the style'])->assertSessionHasNoErrors();
    expect($request->refresh()->status)->toBe('cancelled');

    $dispatched = ($this->dispatch)(($this->makeReady)(($this->raise)()));
    $this->post("/sample-requests/{$dispatched->id}/transition", ['status' => 'cancelled', 'reason' => 'late'])->assertSessionHas('error');
    expect($dispatched->refresh()->status)->toBe('dispatched');
});

// AS-7 AC3 — a customer who requires it gets no bulk release without an approved pre-production sample.
it('holds bulk release for a customer who requires an approved pre-production sample, until one exists', function (): void {
    DB::table('customers')->where('id', $this->customerId)->update(['requires_sample_approval' => true]);
    $gate = app(JobCardReleaseGate::class);

    $check = $gate->evaluate($this->bulkCard->fresh())['checks']['sample_approval'];
    expect($check['ok'])->toBeFalse()->and($check['detail'])->toContain('pre-production');

    $request = ($this->dispatch)(($this->makeReady)(($this->raise)()));

    foreach ($request->lines as $line) {
        $this->actingAs($this->merchandiser)
            ->post("/sample-requests/{$request->id}/lines/{$line->id}/decision", ['decision' => 'approved', 'decided_on' => now()->toDateString()])
            ->assertSessionHasNoErrors();
    }

    expect($gate->evaluate($this->bulkCard->fresh())['checks']['sample_approval']['ok'])->toBeTrue();

    // A sample card itself is never held by this rule.
    $line = $request->lines->firstWhere('product_id', $this->productId);
    $sampleCard = JobCard::query()->create([
        ...$this->bulkCard->only(['factory_unit_id', 'product_id', 'product_spec_id', 'artwork_version_id', 'bom_id', 'routing_id']),
        'sample_request_line_id' => $line->id,
        'planned_qty' => 10,
        'status' => JobCard::DRAFT,
    ]);
    DB::table('customers')->where('id', $this->customerId)->update(['requires_sample_approval' => true]);
    DB::table('sample_request_lines')->where('id', $line->id)->update(['status' => 'pending']);
    expect($gate->evaluate($sampleCard)['checks']['sample_approval']['ok'])->toBeTrue();

    DB::table('customers')->where('id', $this->customerId)->update(['requires_sample_approval' => false]);
});

it('a customer who does not require it is not held', function (): void {
    DB::table('customers')->where('id', $this->customerId)->update(['requires_sample_approval' => false]);

    $check = app(JobCardReleaseGate::class)->evaluate($this->bulkCard->fresh())['checks']['sample_approval'];
    expect($check['ok'])->toBeTrue();
});
