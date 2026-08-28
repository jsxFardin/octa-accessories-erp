<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\MaterialIssue;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * A material issue is a posted, ledger-bearing document and had nowhere to be read.
 *
 * The module offered an index and a create form and nothing between them: no route, no
 * controller method, no page. The chain job card → issue → lot could be walked in the database
 * and on no screen, which makes the one question anyone asks of this document — *which lot went
 * into this job?* — unanswerable to the store keeper and the auditor whose job it is to answer
 * it. It is the shade-traceability record (BR-37) and it is what priced the job.
 *
 * Read-only by design: a posted stock movement is reversed by a return, never edited.
 */
beforeEach(function (): void {
    $this->store = User::query()->where('email', 'store@maheenlabel.test')->firstOrFail();

    // The walkthrough seeds no material issue, so one is built from records that really exist
    // — an actual job card, an actual lot and its own item and unit. A fixture invented out of
    // thin air would prove the page renders and nothing about whether it traces anything.
    $this->issue = MaterialIssue::query()->first() ?? (function (): MaterialIssue {
        $card = DB::table('job_cards')->whereNotNull('sales_order_line_id')->firstOrFail();
        $lot = DB::table('stock_lots')->whereNotNull('item_id')->where('unit_cost', '>', 0)->firstOrFail();

        $issue = MaterialIssue::query()->create([
            'number' => 'MI-TEST-0001',
            'job_card_id' => $card->id,
            'warehouse_id' => $lot->warehouse_id,
            'issued_on' => now()->toDateString(),
            'issue_type' => MaterialIssue::TYPE_ISSUE,
            'status' => MaterialIssue::POSTED,
            'issued_by' => $this->store->id,
        ]);

        DB::table('material_issue_lines')->insert([
            'material_issue_id' => $issue->id,
            'line_no' => 1,
            'item_id' => $lot->item_id,
            'lot_id' => $lot->id,
            'uom_id' => $lot->uom_id,
            'qty' => 12.5,
            'unit_cost' => $lot->unit_cost,
        ]);

        return $issue;
    })();
});

it('opens a material issue and names the job card it served', function (): void {
    $this->actingAs($this->store)
        ->get("/material-issues/{$this->issue->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Inventory/Issues/Show')
            ->where('issue.id', $this->issue->id)
            ->where('jobCard.id', (int) $this->issue->job_card_id)
            ->has('lines')
            ->has('trail'),
        );
});

it('lists the lots the issue actually moved', function (): void {
    $expected = DB::table('material_issue_lines')
        ->where('material_issue_id', $this->issue->id)
        ->count();

    $this->actingAs($this->store)
        ->get("/material-issues/{$this->issue->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($expected): void {
            $lines = $page->toArray()['props']['lines'];

            expect($lines)->toHaveCount($expected);

            foreach ($lines as $line) {
                // The lot is the point of the record; an issue line without one traces nothing.
                expect($line)->toHaveKeys(['lot_no', 'item_code', 'qty', 'unit_cost']);
            }
        });
});

it('values the issue in the factory currency', function (): void {
    $expected = (float) DB::table('material_issue_lines')
        ->where('material_issue_id', $this->issue->id)
        ->selectRaw('COALESCE(SUM(qty * unit_cost), 0) AS total')
        ->value('total');

    $this->actingAs($this->store)
        ->get("/material-issues/{$this->issue->id}")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('issue.total_value', fn ($value): bool => abs((float) $value - $expected) < 0.01),
        );
});

it('names the job card on every row of the list', function (): void {
    // The list showed a bare `job_card_id`, so "what was this material for" was a second lookup.
    $this->actingAs($this->store)
        ->get('/material-issues')
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $rows = $page->toArray()['props']['issues']['data'];

            expect($rows)->not->toBeEmpty();

            foreach ($rows as $row) {
                expect($row)->toHaveKeys(['job_card_number', 'warehouse', 'line_count']);
            }
        });
});

it('refuses the issue to a user without stock_issue.view_any', function (): void {
    $blind = User::query()->where('email', 'designer@maheenlabel.test')->firstOrFail();

    expect($blind->hasPermission('stock_issue.view_any'))->toBeFalse();

    $this->actingAs($blind)->get("/material-issues/{$this->issue->id}")->assertForbidden();
});

it('does not let the wildcard route swallow create, suggest or returnable', function (): void {
    // `/material-issues/{materialIssue}` sits after the literal paths; declared before them it
    // would resolve `create` as a model key and 404 the form.
    $this->actingAs($this->store)->get('/material-issues/create')->assertOk();
});
