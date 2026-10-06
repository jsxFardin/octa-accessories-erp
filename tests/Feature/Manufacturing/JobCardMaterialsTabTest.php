<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * The Materials tab says whether the job is covered: every bill-of-materials row carries what
 * has been issued, returns taken off, and what is still needed.
 */
beforeEach(function (): void {
    $this->admin = User::query()->where('email', 'admin@octapussolution.com')->firstOrFail();
    $this->card = JobCard::query()->whereNotNull('bom_id')->with('bom.lines')->orderBy('id')->firstOrFail();
});

it('carries issued and still-needed quantities on every material row', function (): void {
    $line = $this->card->bom->lines->first();
    $warehouse = (int) DB::table('warehouses')->where('is_active', true)->value('id');
    $lot = DB::table('stock_lots')->where('item_id', $line->item_id)->value('id');

    foreach ([['issue', 10], ['return', 4]] as [$type, $qtyMoved]) {
        $id = (int) DB::table('material_issues')->insertGetId([
            'number' => 'MI-T-'.strtoupper($type), 'job_card_id' => $this->card->id, 'warehouse_id' => $warehouse,
            'issued_on' => now()->toDateString(), 'issue_type' => $type, 'status' => 'posted',
            'issued_by' => $this->admin->id, 'created_at' => now(),
        ]);
        DB::table('material_issue_lines')->insert(['material_issue_id' => $id, 'line_no' => 1, 'item_id' => $line->item_id, 'lot_id' => $lot ?: null, 'uom_id' => $line->uom_id, 'qty' => $qtyMoved, 'unit_cost' => 1]);
    }

    $this->actingAs($this->admin)
        ->get("/job-cards/{$this->card->id}?tab=materials")
        ->assertOk()
        ->assertInertia(function (AssertableInertia $page) use ($line): void {
            $rows = $page->toArray()['props']['bomRequirement'];
            $row = collect($rows)->firstWhere('item.id', $line->item_id);

            expect($rows)->toHaveCount($this->card->bom->lines->count())
                ->and((float) $row['issued'])->toEqual(6.0)
                ->and((float) $row['remaining'])->toEqual(max(0.0, round((float) $row['required'] - 6.0, 6)))
                ->and($row)->toHaveKeys(['is_optional', 'formula_ref']);
        });
});

it('still opens the output panel for an old waste link', function (): void {
    $this->actingAs($this->admin)
        ->get("/job-cards/{$this->card->id}?tab=waste")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('tab', 'waste'));
});

it('opens on the section the status calls for', function (): void {
    $open = fn () => $this->actingAs($this->admin)->get("/job-cards/{$this->card->id}")->assertOk();

    foreach (['qc_pending' => 'ncrs', 'completed' => 'finished-goods', 'in_production' => 'bookings', 'released' => 'materials'] as $status => $tab) {
        DB::table('job_cards')->where('id', $this->card->id)->update(['status' => $status]);
        $open()->assertInertia(fn (AssertableInertia $page) => $page->where('tab', $tab));
    }
});
