<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Costing\Models\CostSheet;
use App\Modules\Costing\Services\PostProductionCosting;
use App\Modules\Costing\States\CostSheetStateMachine;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Sales\Models\SalesOrder;
use App\Support\Settings\Settings;
use App\Support\States\TransitionDenied;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * Spec §4 / CS-3 — the cost sheet as a document: header, three summaries, line detail with
 * each line's share of the price, the revisions it sits among, who signed it; submitted by
 * the clerk, approved by someone who may, reopened only with a reason.
 */
/**
 * The demo quotation is seeded without a sheet; one marketing sheet for its first line, as the
 * quotation form would have made it, at the quoted price.
 */
function marketingSheet(): CostSheet
{
    $line = DB::table('sales_order_lines')->orderBy('id')->firstOrFail();
    $order = DB::table('sales_orders')->where('id', $line->sales_order_id)->firstOrFail();
    $product = App\Modules\Product\Models\Product::query()->findOrFail($line->product_id);
    $service = app(App\Modules\Costing\Services\CostSheetService::class);
    $estimate = $service->estimate($product, (float) $line->ordered_qty);

    return $service->persistEstimate($product, $estimate, (float) $line->ordered_qty, [
        'stage' => CostSheet::MARKETING,
        'product_spec_id' => $product->currentSpec?->id,
        'revenue_per_unit' => round((float) $line->line_total * (float) $order->exchange_rate / (float) $line->ordered_qty, 6),
        'exchange_rate' => (float) $order->exchange_rate,
    ]);
}

beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->costing = User::query()->where('email', 'costing@octapussolution.com')->firstOrFail();
    $this->md = User::query()->where('email', 'md@octapussolution.com')->firstOrFail();
    $this->order = SalesOrder::query()->whereNotNull('number')->with('lines')->firstOrFail();
    $this->pre = CostSheet::query()->where('sales_order_line_id', $this->order->lines->first()->id)->where('stage', CostSheet::PRE_PRODUCTION)->firstOrFail();
});

it('reads a sheet as a document: summaries that foot, lines with their share of the price, and where it came from', function (): void {
    $this->actingAs($this->costing)
        ->get("/costing/sheets/{$this->pre->id}")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page): void {
            $props = $page->toArray()['props'];
            $prof = $props['summary']['profitability'];

            expect($props['sheet']['stage'])->toBe('pre_production')
                ->and($props['sheet']['status'])->toBe('approved')
                ->and($props['sheet']['currency'])->not->toBeNull()
                ->and($props['document']['kind'])->toBe('sales_order')
                ->and($props['buyer']['name'])->not->toBeEmpty()
                ->and(round($prof['material'] + $prof['other'], 2))->toBe(round($prof['total_cost'], 2))
                ->and($prof['margin_pct'])->not->toBeNull()
                ->and(round(array_sum(array_column($props['summary']['materials'], 'amount')), 2))->toBe(round($prof['material'], 2))
                // Where the cost goes adds up to the total cost, and the sheet opens with its verdict.
                ->and(round(array_sum(array_column($props['summary']['breakdown'], 'amount')), 2))->toBe(round($prof['total_cost'], 2))
                ->and(array_column($props['summary']['breakdown'], 'key'))->toContain('material', 'overhead')
                ->and($props['summary']['verdict']['headline'])->toStartWith('Makes ')
                ->and($props['summary']['verdict']['detail'])->toContain('margin')
                ->and($props['lines'])->not->toBeEmpty();

            $material = collect($props['lines'])->firstWhere('is_material', true);
            expect($material['per_unit'])->toBeGreaterThan(0)
                ->and($material['pct_of_price'])->toBeGreaterThan(0);
        });

    // Printable, as a proof copy.
    $this->actingAs($this->costing)->get("/costing/sheets/{$this->pre->id}/print")->assertOk()->assertSee('Cost sheet');
});

it('lists the sheets of one order line across stages as its deviation summary', function (): void {
    $this->actingAs($this->admin);
    $job = JobCard::query()->whereNotNull('sales_order_line_id')->firstOrFail();

    foreach ($job->operations as $operation) {
        DB::table('job_card_operations')->where('id', $operation->id)->update(['actual_minutes' => 60, 'good_qty' => 50000, 'input_qty' => 50000, 'status' => 'completed']);
    }

    $post = app(PostProductionCosting::class)->snapshot($job->fresh());

    $this->actingAs($this->costing)
        ->get("/costing/sheets/{$post->id}")
        ->assertInertia(function (AssertableInertia $page) use ($post): void {
            $props = $page->toArray()['props'];

            expect(collect($props['revisions'])->pluck('stage')->sort()->values()->all())->toBe(['post_production', 'pre_production'])
                ->and(collect($props['revisions'])->firstWhere('id', $post->id)['is_current'])->toBeTrue()
                ->and($props['comparison']['pre'])->not->toBeNull();
        });
});

it('is submitted by the clerk, approved by someone who may, and reopened only with a reason on the record', function (): void {
    // A marketing sheet to walk through: the quotation's, still a draft.
    $this->actingAs($this->admin);
    $sheet = marketingSheet();
    expect($sheet->status)->toBe('draft')->and($sheet->number)->toBeNull();

    $this->actingAs($this->costing)
        ->post("/cost-sheets/{$sheet->id}/transition", ['to' => 'submitted'])
        ->assertSessionHasNoErrors();

    $sheet->refresh();
    expect($sheet->status)->toBe('submitted')
        ->and($sheet->number)->toStartWith('CS-')
        ->and($sheet->submitted_by)->toBe($this->costing->id);

    // The clerk may not approve; the merchandiser's wildcard does not reach approve either.
    expect(fn () => app(CostSheetStateMachine::class)->transition($sheet, 'approved'))->toThrow(TransitionDenied::class);

    $this->actingAs($this->md);
    app(CostSheetStateMachine::class)->transition($sheet->fresh(), 'approved');
    expect($sheet->fresh()->status)->toBe('approved')->and($sheet->fresh()->approved_by)->toBe($this->md->id);

    // Reopening needs a reason, and the reason is what the history shows.
    expect(fn () => app(CostSheetStateMachine::class)->transition($sheet->fresh(), 'draft'))->toThrow(TransitionDenied::class, 'reason');

    $this->actingAs($this->md)
        ->post("/cost-sheets/{$sheet->id}/transition", ['to' => 'draft', 'reason' => 'Yarn rate changed; recost.'])
        ->assertSessionHasNoErrors();

    expect($sheet->fresh()->status)->toBe('draft')->and($sheet->fresh()->approved_at)->toBeNull();

    $this->actingAs($this->costing)
        ->get("/costing/sheets/{$sheet->id}")
        ->assertInertia(function (AssertableInertia $page): void {
            $history = collect($page->toArray()['props']['history']);

            expect($history->pluck('to')->filter()->values()->all())->toBe(['submitted', 'approved', 'draft'])
                ->and($history->last()['reason'])->toBe('Yarn rate changed; recost.');
        });
});

it('refuses to approve a sheet under the margin floor without the override right', function (): void {
    $this->actingAs($this->admin);
    $sheet = marketingSheet();
    app(Settings::class)->set('margin_floor_pct', 95);
    app(Settings::class)->flush();

    $this->actingAs($this->costing);
    app(CostSheetStateMachine::class)->transition($sheet, 'submitted');

    // The MD holds approve but is also the one who may override the floor; a plain approver
    // (sales manager without override) is refused with the floor named.
    $salesManager = User::query()->where('email', 'sales@octapussolution.com')->firstOrFail();
    DB::table('role_permissions')->where('role_id', DB::table('roles')->where('name', 'sales_manager')->value('id'))
        ->where('permission_id', DB::table('permissions')->where('name', 'cost_sheet.override_margin')->value('id'))->delete();
    app('cache')->flush();

    $this->actingAs($salesManager);
    expect(fn () => app(CostSheetStateMachine::class)->transition($sheet->fresh(), 'approved'))->toThrow(TransitionDenied::class, 'below the 95% floor');

    $this->actingAs($this->md);
    app(CostSheetStateMachine::class)->transition($sheet->fresh(), 'approved');
    expect($sheet->fresh()->status)->toBe('approved');
});
