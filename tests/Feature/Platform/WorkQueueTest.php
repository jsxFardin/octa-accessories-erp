<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\Platform\WorkQueue;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->queue = app(WorkQueue::class);
});

function queueKeys(object $test, string $email): array
{
    $user = User::query()->where('email', $email)->firstOrFail();

    return array_column($test->queue->for($user), 'key');
}

it('shows a purchase manager only the orders inside their band', function (): void {
    // A count you cannot clear is noise: an order above the band belongs to the MD's queue.
    $band = app(Settings::class)->decimal('po_approval_band_manager', 100000);
    $supplier = DB::table('suppliers')->where('is_approved', true)->value('id');
    $unit = DB::table('factory_units')->value('id');
    $currency = DB::table('currencies')->where('is_base', true)->value('id');

    foreach ([$band / 2, $band * 3] as $index => $total) {
        DB::table('purchase_orders')->insert([
            'number' => "PO-QUEUE-{$index}",
            'supplier_id' => $supplier,
            'factory_unit_id' => $unit,
            'currency_id' => $currency,
            'exchange_rate' => 1,
            'order_date' => now()->toDateString(),
            'subtotal' => $total,
            'total' => $total,
            'status' => 'pending_approval',
            'created_at' => now(),
        ]);
    }

    $manager = User::query()->where('email', 'purchasemanager@maheenlabel.test')->firstOrFail();
    $md = User::query()->where('email', 'md@maheenlabel.test')->firstOrFail();

    $entry = collect($this->queue->for($manager))->firstWhere('key', 'po_approval');
    $mdEntry = collect($this->queue->for($md))->firstWhere('key', 'po_approval');

    expect($entry['count'])->toBe(1)
        ->and($mdEntry['count'])->toBe(2);
});

it('offers no entry a user has no permission to act on', function (): void {
    // An operator holds nothing commercial; the queue must not become a status leak.
    $operator = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'operator'))->firstOrFail();

    expect($this->queue->for($operator))->toBe([]);
});

it('hides an entry with nothing in it', function (): void {
    // A row of zeros is a row to scan past, not reassurance.
    DB::table('purchase_requisitions')->where('status', 'submitted')->update(['status' => 'approved']);

    expect(queueKeys($this, 'purchasemanager@maheenlabel.test'))->not->toContain('pr_approval');
});

it('counts concessions with no customer evidence, not a state the schema forbids', function (): void {
    // The first version of this entry counted rejections with no disposition — which
    // `qc_inspections_rejected_chk` makes impossible, so it was a permanent zero dressed up as
    // a queue. What is really outstanding is a concession nobody can evidence.
    DB::table('qc_inspections')->insert([
        'number' => 'QC-QUEUE-1',
        'stage' => 'final',
        'inspected_on' => now()->toDateString(),
        'lot_size' => 1000,
        'sample_size' => 80,
        'accept_number' => 5,
        'reject_number' => 6,
        'critical_found' => 0,
        'major_found' => 2,
        'minor_found' => 0,
        'result' => 'accepted_with_concession',
        'disposition' => 'concession',
        'disposition_ref' => null,
        'created_at' => now(),
    ]);

    $entry = collect($this->queue->for(
        User::query()->where('email', 'quality@maheenlabel.test')->firstOrFail(),
    ))->firstWhere('key', 'concession_evidence');

    expect($entry['count'])->toBeGreaterThan(0);
});

it('serves the queue with the dashboard', function (): void {
    $this->actingAs(User::query()->where('email', 'md@maheenlabel.test')->firstOrFail())
        ->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Dashboard')->has('queue'));
});
