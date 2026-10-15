<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Models\User;
use App\Modules\Manufacturing\Models\JobCard;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Support\Calculators\MrpCalculator;
use Illuminate\Validation\ValidationException;

/**
 * PL-3 — generate the job cards an order needs, split by BR-28: one per dated shipment in the
 * line's delivery schedule, and none larger than the routing's maximum lot.
 *
 * A line that already has a live card is left alone and reported: the planner has started
 * placing that line by hand, and a generator that adds to it would double the work. Each card
 * is raised through JobCardFactory, so Gate 1, the consumption snapshot and the BR-49 ceiling
 * all apply exactly as they do to a card raised by hand.
 */
class JobCardGenerator
{
    public function __construct(
        private readonly JobCardFactory $factory,
        private readonly JobCardPlanningGuard $planning,
        private readonly MrpCalculator $mrp,
    ) {}

    /**
     * @param  list<int>|null  $lineIds  only these lines, or every open line of the order
     * @return array{created: list<JobCard>, skipped: list<array{line_no: int, reason: string}>}
     */
    public function generate(SalesOrder $order, int $factoryUnitId, User $actor, ?array $lineIds = null): array
    {
        $created = [];
        $skipped = [];

        /** @var SalesOrderLine $line */
        foreach ($order->lines()->with(['product.routing', 'schedules'])->get() as $line) {
            if ($lineIds !== null && ! in_array((int) $line->id, $lineIds, true)) {
                continue;
            }

            if ((float) $line->ordered_qty <= (float) $line->produced_qty) {
                $skipped[] = ['line_no' => $line->line_no, 'reason' => 'already produced in full'];

                continue;
            }

            $capacity = $this->planning->capacity($line);

            if ($capacity['live_cards'] > 0) {
                $skipped[] = ['line_no' => $line->line_no, 'reason' => "already has {$capacity['live_cards']} live job card(s); add to it by hand if more is needed"];

                continue;
            }

            $schedules = $line->schedules->map(fn ($row): array => [
                'qty' => (float) $row->qty,
                'date' => $row->due_date->toDateString(),
            ])->values()->all();

            $splits = $this->mrp->splitIntoJobCards(
                (float) $line->ordered_qty,
                $line->product?->routing?->max_lot_size === null ? null : (float) $line->product->routing->max_lot_size,
                $schedules,
            );

            try {
                foreach ($splits as $split) {
                    $created[] = $this->factory->create([
                        'factory_unit_id' => $factoryUnitId,
                        'planned_qty' => $split['qty'],
                        'due_date' => $split['due_date'] ?? $line->promised_date?->toDateString() ?? $order->delivery_date?->toDateString(),
                        'colourway' => $split['colourway'],
                    ], $actor, orderLine: $line, field: 'line');
                }
            } catch (ValidationException $e) {
                $skipped[] = ['line_no' => $line->line_no, 'reason' => implode(' ', array_map(fn (array $m): string => implode(' ', $m), $e->errors()))];
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
