<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Inventory\Models\StockLot;
use App\Modules\Inventory\Services\StockPostingService;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Manufacturing\Models\JobCardOperation;
use App\Modules\Manufacturing\Models\MaterialIssue;
use App\Support\Numbering\NumberAllocator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Repairs job cards seeded before J7 and BR-48 existed.
 *
 * The old volume seeder wrote production with one statement —
 * `operations()->update(['input_qty' => n, 'good_qty' => n])` — which left every step of a
 * routing holding the same figure while still marked `pending`, in units the step does not
 * count, against no material issue at all. The seeder was fixed, so a fresh database is clean
 * (`migrate:fresh --seed`); this is for the development databases that already exist and hold
 * QA records worth keeping, where a rebuild would throw those away.
 *
 * What it does NOT do is touch `stock_ledger`. The ledger is append-only (I1), so a finished
 * goods lot that was received at zero cost before BR-48 keeps that valuation: it is what the
 * receipt actually posted. Those lots stay as history, and no new one can be created.
 */
class RepairLegacySeedProductionCommand extends Command
{
    protected $signature = 'erp:repair-legacy-seed-production {--dry-run : Report what would change and write nothing}';

    protected $description = 'Bring job cards seeded before J7/BR-48 into a state those rules would allow';

    public function handle(StockPostingService $posting, NumberAllocator $numbers): int
    {
        $dryRun = (bool) $this->option('dry-run');

        // Two separate symptoms of the same seeder, and a card can have either: a chain that
        // was repaired on an earlier run still needs its material posting, so selecting on the
        // chain alone would drop it before the second half of the repair ran.
        $cards = JobCard::query()
            ->with('operations.routingOperation', 'bom')
            ->where(fn ($q) => $q
                ->whereHas('operations', fn ($op) => $op
                    ->whereIn('status', [JobCardOperation::PENDING, JobCardOperation::READY])
                    ->where('good_qty', '>', 0))
                ->orWhere(fn ($card) => $card
                    ->whereNotNull('bom_id')
                    ->whereExists(fn ($produced) => $produced
                        ->selectRaw('1')->from('v_job_card_output as o')
                        ->whereColumn('o.job_card_id', 'job_cards.id')
                        ->where('o.good_qty', '>', 0))
                    ->whereNotExists(fn ($issued) => $issued
                        ->selectRaw('1')->from('material_issues as mi')
                        ->whereColumn('mi.job_card_id', 'job_cards.id')
                        ->where('mi.status', 'posted'))))
            ->orderBy('id')
            ->get();

        if ($cards->isEmpty()) {
            $this->info('Nothing to repair: every produced job card has a closed chain and its material issue.');

            return self::SUCCESS;
        }

        $chains = 0;
        $issues = 0;

        foreach ($cards as $card) {
            $chains += $this->repairChain($card, $dryRun);
            $issues += $this->issueMissingMaterial($card, $posting, $numbers, $dryRun);
        }

        $this->info(sprintf(
            '%s %d job card(s): %d operation(s) closed, %d material issue(s) posted.',
            $dryRun ? 'Would repair' : 'Repaired',
            $cards->count(),
            $chains,
            $issues,
        ));

        return self::SUCCESS;
    }

    /**
     * Close the chain the way the floor would have: each step in the unit it counts, completed
     * in sequence. The *final* step keeps the figure it already has — finished-goods receipts
     * were posted against it, and lowering it would strand stock above its own ceiling.
     */
    private function repairChain(JobCard $card, bool $dryRun): int
    {
        $operations = $card->operations->sortBy('sequence_no')->values();
        $final = $operations->last();
        $changed = 0;

        foreach ($operations as $operation) {
            $isFinal = $final !== null && $operation->getKey() === $final->getKey();

            $consumesWeb = (bool) DB::table('routing_operations')
                ->where('id', $operation->routing_operation_id)
                ->value('consumes_web');

            $booked = match (true) {
                $isFinal => (float) $operation->good_qty,
                $consumesWeb => (float) $operation->planned_qty,
                default => $final === null ? 0.0 : (float) $final->good_qty,
            };

            $needsWork = $operation->status !== JobCardOperation::COMPLETED
                || abs((float) $operation->good_qty - $booked) > 0.000001;

            if (! $needsWork) {
                continue;
            }

            $changed++;

            if ($dryRun) {
                $this->line(sprintf(
                    '  %s step %d (%s): %s %s → completed, %s %s',
                    $card->number,
                    $operation->sequence_no,
                    $operation->name,
                    rtrim(rtrim(number_format((float) $operation->good_qty, 3, '.', ''), '0'), '.'),
                    $operation->unit(),
                    rtrim(rtrim(number_format($booked, 3, '.', ''), '0'), '.'),
                    $operation->unit(),
                ));

                continue;
            }

            $operation->forceFill([
                'input_qty' => max($booked, (float) $operation->input_qty),
                'good_qty' => $booked,
                'waste_qty' => 0,
                'status' => JobCardOperation::COMPLETED,
                'started_at' => $operation->started_at ?? $card->created_at,
                'finished_at' => $operation->finished_at ?? $card->created_at,
            ])->save();
        }

        if (! $dryRun && $final !== null) {
            // J6 — the card's running totals are the sum of its steps, whatever that means.
            $card->forceFill([
                'good_qty_running' => (float) $card->operations()->sum('good_qty'),
                'waste_qty_running' => (float) $card->operations()->sum('waste_qty'),
                'produced_qty_running' => (float) $card->operations()->sum('good_qty')
                    + (float) $card->operations()->sum('waste_qty'),
            ])->save();
        }

        return $changed;
    }

    /**
     * BR-48 — the material this job consumed, posted through the stock service so the document
     * and the ledger say the same thing.
     */
    private function issueMissingMaterial(JobCard $card, StockPostingService $posting, NumberAllocator $numbers, bool $dryRun): int
    {
        $bom = $card->bom;

        if ($bom === null) {
            return 0;
        }

        $alreadyIssued = MaterialIssue::query()
            ->where('job_card_id', $card->getKey())
            ->where('status', 'posted')
            ->exists();

        if ($alreadyIssued) {
            return 0;
        }

        $produced = (float) DB::table('v_job_card_output')->where('job_card_id', $card->getKey())->value('good_qty');

        if ($produced <= 0) {
            return 0;
        }

        $lines = DB::table('bom_lines as bl')
            ->join('items as i', 'i.id', '=', 'bl.item_id')
            ->where('bl.bom_id', $bom->getKey())
            ->where('bl.is_optional', false)
            ->get(['bl.item_id', 'bl.uom_id', 'bl.qty_per_base', 'i.std_rate', 'i.code']);

        $byWarehouse = [];

        foreach ($lines as $line) {
            $required = max(0.000001, $bom->scaleTo((float) $line->qty_per_base, $produced));

            $lot = StockLot::query()
                ->where('item_id', $line->item_id)
                ->where('status', 'available')
                ->where('balance_qty', '>=', $required)
                ->orderBy('received_on')
                ->first();

            if ($lot === null) {
                $this->warn(sprintf(
                    '  %s: no lot of %s holds the %s needed — skipped rather than driving stock negative (BR-38).',
                    $card->number,
                    $line->code,
                    rtrim(rtrim(number_format($required, 6, '.', ''), '0'), '.'),
                ));

                return 0;
            }

            $byWarehouse[(int) $lot->warehouse_id][] = [
                'lot' => $lot,
                'item_id' => (int) $line->item_id,
                'uom_id' => (int) $line->uom_id,
                'qty' => $required,
                'unit_cost' => (float) ($line->std_rate ?: $lot->unit_cost),
            ];
        }

        if ($dryRun) {
            $this->line(sprintf('  %s: would post %d material issue(s).', $card->number, count($byWarehouse)));

            return count($byWarehouse);
        }

        foreach ($byWarehouse as $warehouseId => $picks) {
            DB::transaction(function () use ($card, $warehouseId, $picks, $posting, $numbers): void {
                /** @var MaterialIssue $issue */
                $issue = MaterialIssue::query()->create([
                    'number' => $numbers->next('material_issue'),
                    'job_card_id' => $card->getKey(),
                    'warehouse_id' => $warehouseId,
                    'issued_on' => now()->toDateString(),
                    'issue_type' => 'issue',
                    'status' => 'posted',
                    'remarks' => 'Recorded retrospectively: this job produced before BR-48 required the issue.',
                ]);

                $lineNo = 0;

                foreach ($picks as $pick) {
                    DB::table('material_issue_lines')->insert([
                        'material_issue_id' => $issue->getKey(),
                        'line_no' => ++$lineNo,
                        'item_id' => $pick['item_id'],
                        'lot_id' => $pick['lot']->getKey(),
                        'uom_id' => $pick['uom_id'],
                        'qty' => $pick['qty'],
                        'unit_cost' => $pick['unit_cost'],
                    ]);
                }

                $posting->issueToJob(
                    array_map(fn (array $pick): array => ['lot' => $pick['lot'], 'qty' => $pick['qty']], $picks),
                    $issue,
                );
            });
        }

        return count($byWarehouse);
    }
}
