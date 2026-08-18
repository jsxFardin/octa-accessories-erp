<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Dispatch\Models\DeliveryChallan;
use App\Modules\Dispatch\Models\PackingList;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\SalesInvoice;
use App\Modules\Finance\Models\SalesInvoiceLine;
use App\Modules\Finance\States\SalesInvoiceStateMachine;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\States\JobCardStateMachine;
use App\Modules\Quality\Models\Capa;
use App\Modules\Quality\Models\Ncr;
use App\Modules\Quality\Services\NcrService;
use App\Support\Notifications\Notifier;
use App\Support\Numbering\NumberAllocator;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * P2-4 — database notifications. Delivery is secondary: an NCR close, an invoice transition
 * or a credit-note draft must still commit if the inbox write later fails.
 */
beforeEach(function (): void {
    $this->qc = User::query()->where('email', 'qc@maheenlabel.test')->firstOrFail();
    $this->quality = User::query()->where('email', 'quality@maheenlabel.test')->firstOrFail();
    $this->operator = User::query()->where('email', 'operator@maheenlabel.test')->firstOrFail();
    $this->accounts = User::query()->where('email', 'accounts@maheenlabel.test')->firstOrFail();
    $this->md = User::query()->where('email', 'md@maheenlabel.test')->firstOrFail();
    $this->compliance = User::query()->where('email', 'compliance@maheenlabel.test')->firstOrFail();
    $this->supervisor = User::query()->where('email', 'supervisor@maheenlabel.test')->firstOrFail();
    $this->driver = User::query()->where('email', 'driver@maheenlabel.test')->firstOrFail();
    $this->lab = User::query()->where('email', 'lab@maheenlabel.test')->firstOrFail();
});

function p24Notes(User $user, ?string $action = null)
{
    return $user->notifications()->get()->filter(
        fn ($row): bool => $action === null || ($row->data['action'] ?? null) === $action,
    )->values();
}

function p24OpenNcr(int $raisedBy, ?int $ownerId = null): Ncr
{
    return DB::transaction(function () use ($raisedBy, $ownerId): Ncr {
        return Ncr::query()->create([
            'number' => app(NumberAllocator::class)->next('ncr'),
            'source' => 'audit',
            'raised_on' => now()->toDateString(),
            'description' => 'P2-4 notification fixture',
            'severity' => 'major',
            'status' => Ncr::OPEN,
            'raised_by' => $raisedBy,
            'owner_id' => $ownerId,
        ]);
    });
}

function p24WalkNcr(object $test, Ncr $ncr, string $until): Ncr
{
    $test->actingAs($test->qc);
    $service = app(NcrService::class);

    if ($ncr->owner_id === null) {
        $service->assign($ncr, $test->quality->id);
        $ncr->refresh();
    }

    if ($until === 'assigned') {
        return $ncr;
    }

    $service->investigate($ncr, [
        'root_cause' => 'Fixture root cause.',
        'action' => 'Fixture corrective action.',
        'due_date' => now()->addWeek()->toDateString(),
    ]);
    $ncr->refresh();

    if ($until === 'investigating') {
        return $ncr;
    }

    $service->disposition($ncr);
    $ncr->refresh();

    if ($until === 'action_taken') {
        return $ncr;
    }

    $service->verify($ncr, 'effective');
    $ncr->refresh();

    if ($until === 'verified') {
        return $ncr;
    }

    $service->close($ncr);

    return $ncr->refresh();
}

function p24Commercial(object $test): void
{
    if (isset($test->customerId)) {
        return;
    }

    $test->jobCard = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();
    $test->soLineId = (int) $test->jobCard->sales_order_line_id;
    $test->soId = (int) DB::table('sales_order_lines')->where('id', $test->soLineId)->value('sales_order_id');
    $test->customerId = (int) DB::table('sales_orders')->where('id', $test->soId)->value('customer_id');
    $test->currencyId = (int) DB::table('currencies')->where('code', 'USD')->value('id');
    $test->fgWarehouseId = (int) DB::table('warehouses')->where('kind', 'finished_goods')->value('id');
}

function p24IssuedInvoice(object $test, float $total): SalesInvoice
{
    p24Commercial($test);

    $test->actingAs($test->accounts);

    $invoice = SalesInvoice::query()->create([
        'customer_id' => $test->customerId,
        'invoice_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'currency_id' => $test->currencyId,
        'exchange_rate' => 1,
        'subtotal' => $total,
        'tax_amount' => 0,
        'total' => $total,
        'status' => 'draft',
    ]);

    SalesInvoiceLine::query()->create([
        'sales_invoice_id' => $invoice->id,
        'line_no' => 1,
        'product_id' => (int) $test->jobCard->product_id ?: (int) DB::table('products')->value('id'),
        'description' => 'P2-4 invoice fixture',
        'qty' => 1000,
        'rate_per_m' => $total,
        'tax_amount' => 0,
        'amount' => $total,
    ]);

    app(SalesInvoiceStateMachine::class)->transition($invoice, 'issued');

    return $invoice->refresh();
}

function p24FulfilChallan(object $test, float $qty): DeliveryChallan
{
    p24Commercial($test);

    $test->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());

    if ($test->jobCard->refresh()->status === JobCard::PLANNED) {
        $states = app(JobCardStateMachine::class);
        $states->transition($test->jobCard, JobCard::RELEASED, ['material_waiver_reason' => 'P2-4 notification walkthrough']);
        $states->transition($test->jobCard->refresh(), JobCard::IN_PRODUCTION);
        $test->jobCard->operations()->reorder('sequence_no', 'desc')->firstOrFail()
            ->forceFill(['input_qty' => 10000, 'good_qty' => 10000])->save();
        DB::table('sales_order_lines')->where('id', $test->soLineId)->increment('produced_qty', 10000);

        $test->actingAs($test->qc);
        $test->post('/qc-inspections', [
            'job_card_id' => $test->jobCard->id,
            'stage' => 'final',
            'lot_size' => 500,
            'major_found' => 0,
        ]);
        $test->actingAs(User::query()->where('email', 'admin@maheenlabel.test')->firstOrFail());
        $receipt = app(App\Modules\Manufacturing\Services\FgReceiptService::class)
            ->post($test->jobCard->refresh(), 10000, $test->fgWarehouseId, (string) Str::uuid());
        $test->lot = DB::table('stock_lots')->where('id', $receipt->lot_id)->first();
    }

    $test->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail());
    $test->post('/packing-lists', ['sales_order_id' => $test->soId]);
    $list = PackingList::query()->latest('id')->firstOrFail();
    $test->post("/packing-lists/{$list->id}/cartons", []);
    $carton = DB::table('cartons')->where('packing_list_id', $list->id)->first();
    $test->post("/packing-lists/{$list->id}/cartons/{$carton->id}/contents", [
        'sales_order_line_id' => $test->soLineId, 'lot_id' => $test->lot->id, 'qty' => $qty,
    ]);
    $test->post("/packing-lists/{$list->id}/transition", ['to' => 'packed']);
    $test->post('/delivery-challans', ['packing_list_id' => $list->id, 'mode' => 'own_fleet']);

    return DeliveryChallan::query()->latest('id')->firstOrFail();
}

it('creates the standard notifications table', function (): void {
    expect(Schema::hasTable('notifications'))->toBeTrue()
        ->and(Schema::hasColumns('notifications', [
            'id', 'type', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'created_at', 'updated_at',
        ]))->toBeTrue();
});

it('lets an authenticated user retrieve only their own notifications', function (): void {
    $ncr = p24OpenNcr($this->qc->id);
    $this->actingAs($this->qc);
    app(NcrService::class)->assign($ncr, $this->quality->id);

    $this->actingAs($this->quality)->getJson('/notifications')
        ->assertOk()
        ->assertJsonPath('unread', 1)
        ->assertJsonPath('notifications.0.action', 'assigned')
        ->assertJsonPath('notifications.0.href', '/ncrs/'.$ncr->id);

    $this->actingAs($this->operator)->getJson('/notifications')
        ->assertOk()
        ->assertJsonPath('unread', 0)
        ->assertJsonPath('notifications', []);
});

it('rejects an unauthenticated inbox request', function (): void {
    $this->get('/notifications')->assertRedirect('/login');
    $this->post('/notifications/read-all')->assertRedirect('/login');
});

it('refuses to mark another user\'s notification as read', function (): void {
    $ncr = p24OpenNcr($this->qc->id);
    $this->actingAs($this->qc);
    app(NcrService::class)->assign($ncr, $this->quality->id);

    $id = p24Notes($this->quality, 'assigned')->first()->id;

    $this->actingAs($this->operator)
        ->postJson("/notifications/{$id}/read")
        ->assertNotFound();

    expect(p24Notes($this->quality, 'assigned')->first()->read_at)->toBeNull();
});

it('notifies the new NCR owner when assignment changes', function (): void {
    $ncr = p24OpenNcr($this->qc->id);
    $this->actingAs($this->qc);
    app(NcrService::class)->assign($ncr, $this->quality->id);

    expect(p24Notes($this->quality, 'assigned'))->toHaveCount(1)
        ->and(p24Notes($this->quality, 'assigned')->first()->data['document_id'])->toBe($ncr->id)
        ->and(p24Notes($this->quality, 'assigned')->first()->data['dedupe_key'])->toBe('ncr:assigned:'.$ncr->id.':'.$this->quality->id)
        ->and(p24Notes($this->qc, 'assigned'))->toHaveCount(0);
});

it('does not notify again when the same owner is reassigned', function (): void {
    $ncr = p24OpenNcr($this->qc->id);
    $this->actingAs($this->qc);
    app(NcrService::class)->assign($ncr, $this->quality->id);
    app(NcrService::class)->assign($ncr->refresh(), $this->quality->id);

    expect(p24Notes($this->quality, 'assigned'))->toHaveCount(1);
});

it('does not notify an NCR owner who lacks NCR permission', function (): void {
    $ncr = p24OpenNcr($this->qc->id);
    $this->actingAs($this->qc);
    app(NcrService::class)->assign($ncr, $this->operator->id);

    expect($ncr->refresh()->owner_id)->toBe($this->operator->id)
        ->and(p24Notes($this->operator, 'assigned'))->toHaveCount(0)
        ->and(p24Notes($this->driver, 'assigned'))->toHaveCount(0);
});

it('notifies the owner of an overdue open CAPA without changing NCR status', function (): void {
    $ncr = p24OpenNcr($this->qc->id, $this->quality->id);
    $due = now()->subDay()->toDateString();

    Capa::query()->create([
        'ncr_id' => $ncr->id,
        'kind' => Capa::KIND_CORRECTIVE,
        'action' => 'Correct the registration.',
        'due_date' => $due,
        'status' => Capa::IN_PROGRESS,
    ]);

    $this->artisan('ncr:notify-overdue')->assertSuccessful();

    expect($ncr->refresh()->status)->toBe(Ncr::OPEN)
        ->and(p24Notes($this->quality, 'overdue'))->toHaveCount(1)
        ->and(p24Notes($this->quality, 'overdue')->first()->data['dedupe_key'])->toBe('ncr:overdue:'.$ncr->id.':'.$due);
});

it('does not duplicate an overdue notification for the same NCR and due date', function (): void {
    $ncr = p24OpenNcr($this->qc->id, $this->quality->id);

    Capa::query()->create([
        'ncr_id' => $ncr->id,
        'kind' => Capa::KIND_CORRECTIVE,
        'action' => 'Correct the registration.',
        'due_date' => now()->subDay()->toDateString(),
        'status' => Capa::IN_PROGRESS,
    ]);

    $this->artisan('ncr:notify-overdue')->assertSuccessful();
    $this->artisan('ncr:notify-overdue')->assertSuccessful();

    expect($ncr->refresh()->status)->toBe(Ncr::OPEN)
        ->and(p24Notes($this->quality, 'overdue'))->toHaveCount(1);
});

it('does not notify overdue when the CAPA is completed or verified', function (): void {
    $ncr = p24OpenNcr($this->qc->id, $this->quality->id);

    Capa::query()->create([
        'ncr_id' => $ncr->id,
        'kind' => Capa::KIND_CORRECTIVE,
        'action' => 'Done.',
        'due_date' => now()->subDay()->toDateString(),
        'status' => Capa::COMPLETED,
        'completed_on' => now()->toDateString(),
    ]);

    $this->artisan('ncr:notify-overdue')->assertSuccessful();

    expect(p24Notes($this->quality, 'overdue'))->toHaveCount(0);
});

it('does not notify overdue on a closed NCR', function (): void {
    $ncr = p24WalkNcr($this, p24OpenNcr($this->qc->id), 'closed');

    Capa::query()->where('ncr_id', $ncr->id)->update([
        'due_date' => now()->subDay()->toDateString(),
        'status' => Capa::IN_PROGRESS,
    ]);

    $this->artisan('ncr:notify-overdue')->assertSuccessful();

    expect($ncr->refresh()->status)->toBe(Ncr::CLOSED)
        ->and(p24Notes($this->quality, 'overdue'))->toHaveCount(0);
});

it('notifies ncr.close holders when action is taken, and not unauthorised roles', function (): void {
    $ncr = p24WalkNcr($this, p24OpenNcr($this->qc->id), 'action_taken');

    expect($ncr->status)->toBe(Ncr::ACTION_TAKEN)
        ->and(p24Notes($this->qc, 'action_taken'))->toHaveCount(1)
        ->and(p24Notes($this->quality, 'action_taken'))->toHaveCount(1)
        ->and(p24Notes($this->compliance, 'action_taken'))->toHaveCount(1)
        ->and(p24Notes($this->supervisor, 'action_taken'))->toHaveCount(1)
        ->and(p24Notes($this->operator, 'action_taken'))->toHaveCount(0)
        ->and(p24Notes($this->lab, 'action_taken'))->toHaveCount(0)
        ->and(p24Notes($this->accounts, 'action_taken'))->toHaveCount(0);
});

it('does not duplicate the verification notification on a same-status retry', function (): void {
    $ncr = p24WalkNcr($this, p24OpenNcr($this->qc->id), 'action_taken');

    $this->actingAs($this->qc);
    app(NcrService::class)->disposition($ncr->refresh());

    expect($ncr->refresh()->status)->toBe(Ncr::ACTION_TAKEN)
        ->and(p24Notes($this->quality, 'action_taken'))->toHaveCount(1);
});

it('notifies the owner and the raiser when an NCR closes, once each', function (): void {
    $ncr = p24WalkNcr($this, p24OpenNcr($this->qc->id), 'closed');

    expect($ncr->status)->toBe(Ncr::CLOSED)
        ->and(p24Notes($this->quality, 'closed'))->toHaveCount(1)
        ->and(p24Notes($this->qc, 'closed'))->toHaveCount(1)
        ->and(p24Notes($this->operator, 'closed'))->toHaveCount(0);
});

it('does not duplicate a closed notification when owner and raiser are the same user', function (): void {
    $ncr = p24OpenNcr($this->qc->id, $this->qc->id);
    p24WalkNcr($this, $ncr, 'closed');

    expect(p24Notes($this->qc, 'closed'))->toHaveCount(1);
});

it('does not notify an unauthorised owner that an NCR closed', function (): void {
    $ncr = p24OpenNcr($this->qc->id, $this->operator->id);
    p24WalkNcr($this, $ncr, 'closed');

    expect(p24Notes($this->operator, 'closed'))->toHaveCount(0)
        ->and(p24Notes($this->qc, 'closed'))->toHaveCount(1);
});

it('notifies sales_invoice.view holders when an invoice is transitioned to overdue', function (): void {
    $invoice = p24IssuedInvoice($this, 1500);

    $this->actingAs($this->accounts);
    app(SalesInvoiceStateMachine::class)->transition($invoice, 'overdue');

    expect($invoice->refresh()->status)->toBe('overdue')
        ->and(p24Notes($this->accounts, 'overdue'))->toHaveCount(1)
        ->and(p24Notes($this->accounts, 'overdue')->first()->data['href'])->toBe('/invoices/'.$invoice->id)
        ->and(p24Notes($this->md, 'overdue'))->toHaveCount(1)
        ->and(p24Notes($this->operator, 'overdue'))->toHaveCount(0)
        ->and(p24Notes($this->driver, 'overdue'))->toHaveCount(0);
});

it('keeps the invoice overdue transition when notification dispatch fails', function (): void {
    $invoice = p24IssuedInvoice($this, 1500);

    $this->mock(Notifier::class, function ($mock): void {
        $mock->shouldReceive('notifyInvoiceOverdue')->andThrow(new RuntimeException('notification store failed'));
    });

    $this->actingAs($this->accounts);
    app(SalesInvoiceStateMachine::class)->transition($invoice, 'overdue');

    expect($invoice->refresh()->status)->toBe('overdue');
});

it('notifies approvers when a draft credit note is created by hand', function (): void {
    $invoice = p24IssuedInvoice($this, 800);

    $this->actingAs($this->accounts)->post('/credit-notes', [
        'sales_invoice_id' => $invoice->id,
        'reason' => 'quality_claim',
        'amount' => 200,
    ])->assertSessionHasNoErrors();

    $note = CreditNote::query()->latest('id')->firstOrFail();

    expect($note->status)->toBe('draft')
        ->and(p24Notes($this->accounts, 'draft'))->toHaveCount(1)
        ->and(p24Notes($this->md, 'draft'))->toHaveCount(1);
});

it('notifies approvers when a return after invoicing drafts a credit note', function (): void {
    $challan = p24FulfilChallan($this, 2000);
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'issued']);

    $this->actingAs($this->accounts);
    $this->post('/invoices', ['delivery_challan_id' => $challan->id]);
    $invoice = SalesInvoice::query()->latest('id')->firstOrFail();
    $this->post("/invoices/{$invoice->id}/transition", ['to' => 'issued']);

    $this->actingAs(User::query()->where('email', 'dispatch@maheenlabel.test')->firstOrFail());
    $this->post("/delivery-challans/{$challan->id}/transition", ['to' => 'returned', 'return_reason' => 'refused at gate']);

    $note = CreditNote::query()->where('sales_invoice_id', $invoice->id)->firstOrFail();

    expect($note->status)->toBe('draft')
        ->and(p24Notes($this->accounts, 'draft'))->toHaveCount(1)
        ->and(p24Notes($this->md, 'draft'))->toHaveCount(1);
});

it('routes above-band credit notes to the MD only', function (): void {
    $invoice = p24IssuedInvoice($this, 80000);

    $this->actingAs($this->accounts)->post('/credit-notes', [
        'sales_invoice_id' => $invoice->id,
        'reason' => 'rate_difference',
        'amount' => 60000,
    ])->assertSessionHasNoErrors();

    expect(p24Notes($this->md, 'draft'))->toHaveCount(1)
        ->and(p24Notes($this->accounts, 'draft'))->toHaveCount(0);
});

it('stores only identity fields on a credit-note notification', function (): void {
    $invoice = p24IssuedInvoice($this, 800);

    $this->actingAs($this->accounts)->post('/credit-notes', [
        'sales_invoice_id' => $invoice->id,
        'reason' => 'quality_claim',
        'amount' => 150,
    ])->assertSessionHasNoErrors();

    $note = CreditNote::query()->latest('id')->firstOrFail();
    $data = p24Notes($this->accounts, 'draft')->first()->data;

    expect($data['document_id'])->toBe($note->id)
        ->and($data['href'])->toBe('/credit-notes/'.$note->id)
        ->and(array_key_exists('document_number', $data))->toBeTrue()
        ->and($data)->not->toHaveKeys([
            'amount', 'total', 'outstanding', 'received_amount', 'subtotal', 'tax_amount',
            'invoice_total', 'invoice_balance', 'customer_outstanding',
        ]);
});

it('does not duplicate a credit-note approval notification', function (): void {
    $invoice = p24IssuedInvoice($this, 800);

    $this->actingAs($this->accounts)->post('/credit-notes', [
        'sales_invoice_id' => $invoice->id,
        'reason' => 'other',
        'amount' => 50,
    ])->assertSessionHasNoErrors();

    $note = CreditNote::query()->latest('id')->firstOrFail();
    app(Notifier::class)->notifyCreditNoteApproval($note);

    expect(p24Notes($this->accounts, 'draft'))->toHaveCount(1)
        ->and(p24Notes($this->md, 'draft'))->toHaveCount(1);
});

it('reports the unread count and marks only the caller\'s notifications as read', function (): void {
    $first = p24OpenNcr($this->qc->id);
    $second = p24OpenNcr($this->qc->id);
    $this->actingAs($this->qc);
    app(NcrService::class)->assign($first, $this->quality->id);
    app(NcrService::class)->assign($second, $this->quality->id);

    $this->actingAs($this->quality)->getJson('/notifications')
        ->assertOk()
        ->assertJsonPath('unread', 2);

    $id = p24Notes($this->quality, 'assigned')->first()->id;

    $this->actingAs($this->quality)->postJson("/notifications/{$id}/read")
        ->assertOk()
        ->assertJsonPath('unread', 1);

    $this->actingAs($this->operator)->postJson('/notifications/read-all')->assertOk();

    expect($this->quality->unreadNotifications()->count())->toBe(1);

    $this->actingAs($this->quality)->postJson('/notifications/read-all')
        ->assertOk()
        ->assertJsonPath('unread', 0);

    expect($this->quality->unreadNotifications()->count())->toBe(0);
});

it('schedules the NCR overdue command daily and does not schedule invoice ageing', function (): void {
    $events = collect(app(Schedule::class)->events());

    expect($events->contains(
        fn ($event): bool => str_contains((string) $event->command, 'ncr:notify-overdue'),
    ))->toBeTrue()
        ->and($events->contains(
            fn ($event): bool => str_contains((string) $event->command, 'invoice')
                && str_contains((string) $event->command, 'overdue'),
        ))->toBeFalse();
});
