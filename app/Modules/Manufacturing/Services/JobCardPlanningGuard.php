<?php

declare(strict_types=1);

namespace App\Modules\Manufacturing\Services;

use App\Modules\Sales\Models\SalesOrderLine;
use App\Support\Calculators\SalesToleranceCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * BR-49 — a job card may not plan more than the order line can still absorb.
 *
 * The form always showed the outstanding quantity as a hint and defaulted to it, but nothing
 * enforced it: `planned_qty` was validated as `numeric|gt:0` alone, so a line with 3,000
 * outstanding accepted a card for 5,000 — from the form, and just as happily from a POST that
 * never saw the form. Everything downstream then reconciles against a plan the order cannot
 * take delivery of.
 *
 * The ceiling is **not** the bare outstanding quantity. Over-production inside the customer's
 * agreed band is legitimate and already has a rule — BR-44, the same band the packing list
 * enforces before dispatch — so the allowance is computed by {@see SalesToleranceCalculator}
 * rather than restated here. Planning beyond what may be shipped is what is refused.
 *
 * Committed quantity counts every live card on the line, not just this one: two cards of
 * 3,000 against a 3,000 line is the same over-commitment as one card of 6,000, and only the
 * second was ever visible on screen.
 */
class JobCardPlanningGuard
{
    /** A card in one of these statuses no longer holds quantity against the line. */
    private const SPENT_CARD_STATUSES = ['cancelled'];

    public function __construct(private readonly SalesToleranceCalculator $tolerance) {}

    /**
     * What the line can still take, and the figures behind it.
     *
     * `headroom` is what a new card may plan. `committed` deliberately takes the greater of
     * planned and produced: a card that overran its own plan has consumed the larger of the
     * two, and adding them would count the same pieces twice.
     *
     * @param  int|null  $excludeJobCardId  the card being edited, so it does not block itself
     * @return array{
     *     ordered: float, produced: float, committed: float, allowance: float,
     *     headroom: float, over_tolerance_pct: float, outstanding: float, live_cards: int
     * }
     */
    public function capacity(SalesOrderLine $line, ?int $excludeJobCardId = null): array
    {
        $cards = DB::table('job_cards')
            ->where('sales_order_line_id', $line->getKey())
            ->whereNotIn('status', self::SPENT_CARD_STATUSES)
            ->when($excludeJobCardId !== null, fn ($query) => $query->where('id', '!=', $excludeJobCardId))
            ->selectRaw('COALESCE(SUM(planned_qty), 0) AS planned, COUNT(*) AS cards')
            ->first();

        return $this->buildCapacity(
            (float) $line->ordered_qty,
            (float) $line->produced_qty,
            (float) $line->over_tolerance_pct,
            (float) $line->under_tolerance_pct,
            (float) ($cards->planned ?? 0),
            (int) ($cards->cards ?? 0),
        );
    }

    /**
     * The same answer as {@see capacity()} for a whole list, in one aggregate query.
     *
     * The planning form lists every eligible order line in the factory; asking per line turned
     * one query into fifty-one. The arithmetic is not repeated here — each row is handed to
     * {@see buildCapacity()}, the same method the single-line path uses.
     *
     * @param  iterable<object>  $lines  rows carrying id, line_no, ordered_qty, produced_qty
     *                                   and both tolerance columns
     * @return array<int, array<string, float|int>> keyed by sales order line id
     */
    public function capacities(iterable $lines): array
    {
        $ids = [];

        foreach ($lines as $line) {
            $ids[] = (int) $line->id;
        }

        if ($ids === []) {
            return [];
        }

        $committed = DB::table('job_cards')
            ->whereIn('sales_order_line_id', $ids)
            ->whereNotIn('status', self::SPENT_CARD_STATUSES)
            ->groupBy('sales_order_line_id')
            ->selectRaw('sales_order_line_id, COALESCE(SUM(planned_qty), 0) AS planned, COUNT(*) AS cards')
            ->get()
            ->keyBy('sales_order_line_id');

        $result = [];

        foreach ($lines as $line) {
            $row = $committed->get((int) $line->id);

            $result[(int) $line->id] = $this->buildCapacity(
                (float) $line->ordered_qty,
                (float) $line->produced_qty,
                (float) $line->over_tolerance_pct,
                (float) $line->under_tolerance_pct,
                (float) ($row->planned ?? 0),
                (int) ($row->cards ?? 0),
            );
        }

        return $result;
    }

    /**
     * The rule itself, in one place, whether it was reached for one line or fifty.
     *
     * @return array{
     *     ordered: float, produced: float, committed: float, allowance: float,
     *     headroom: float, over_tolerance_pct: float, outstanding: float, live_cards: int
     * }
     */
    private function buildCapacity(
        float $ordered,
        float $produced,
        float $overPct,
        float $underPct,
        float $planned,
        int $cards,
    ): array {
        // BR-44 owns the band; this rule only asks it where the top is.
        $allowance = $this->tolerance->band($ordered, $underPct, $overPct)['max'];

        // A card that overran its own plan has consumed the larger of the two; adding them
        // would count the same pieces twice.
        $committed = max($planned, $produced);

        return [
            'ordered' => $ordered,
            'produced' => $produced,
            'committed' => $committed,
            'allowance' => $allowance,
            'headroom' => round(max(0.0, $allowance - $committed), 6),
            'over_tolerance_pct' => $overPct,
            'outstanding' => round(max(0.0, $ordered - $produced), 6),
            'live_cards' => $cards,
        ];
    }

    /**
     * BR-53 — a line whose live job cards commit more than the order now allows.
     *
     * BR-49 stops a *new* card being raised past the ceiling, but an order can be amended
     * downwards after its cards exist: a customer cuts 30,000 to 20,000 while a card for
     * 30,000 is already on the floor. The production is not undone — it happened, and S1 keeps
     * the order above what was made — but the conflict is real and somebody has to decide what
     * to do with the surplus. Left unsaid it is invisible on every screen.
     *
     * Returns null when there is no conflict.
     *
     * @return array{committed: float, allowance: float, excess: float, live_cards: int}|null
     */
    public function overAllocation(SalesOrderLine $line): ?array
    {
        $capacity = $this->capacity($line);

        $excess = round($capacity['committed'] - $capacity['allowance'], 6);

        if ($excess <= 0.000001) {
            return null;
        }

        return [
            'committed' => $capacity['committed'],
            'allowance' => $capacity['allowance'],
            'excess' => $excess,
            'live_cards' => $capacity['live_cards'],
        ];
    }

    /**
     * Why this quantity cannot be planned on this line, in words a planner can act on — or
     * null when it can. Read by the guard and by the screen, so the disabled button and the
     * server's refusal cannot disagree.
     */
    public function refusalReason(SalesOrderLine $line, float $plannedQty, ?int $excludeJobCardId = null): ?string
    {
        if ($plannedQty <= 0) {
            return 'A job card must plan a quantity greater than zero.';
        }

        $capacity = $this->capacity($line, $excludeJobCardId);

        // Floating point: 3150.0000001 against a 3150 ceiling is the same number typed twice.
        if ($plannedQty <= $capacity['headroom'] + 0.000001) {
            return null;
        }

        $fmt = static fn (float $value): string => rtrim(rtrim(number_format($value, 6, '.', ','), '0'), '.');

        if ($capacity['headroom'] <= 0.0) {
            return sprintf(
                'Order line %d is already fully covered: %s pcs ordered, %s pcs committed to live job cards, and nothing left to plan (BR-49). Cancel or reduce an existing card before raising another.',
                $line->line_no,
                $fmt($capacity['ordered']),
                $fmt($capacity['committed']),
            );
        }

        return sprintf(
            'You entered %s pcs, but order line %d can only take %s more (BR-49). It is for %s pcs, %s pcs are already committed to live job cards, and the customer accepts up to %s pcs including the %s%% over-delivery tolerance. Reduce the planned quantity to %s or less.',
            $fmt($plannedQty),
            $line->line_no,
            $fmt($capacity['headroom']),
            $fmt($capacity['ordered']),
            $fmt($capacity['committed']),
            $fmt($capacity['allowance']),
            $fmt($capacity['over_tolerance_pct']),
            $fmt($capacity['headroom']),
        );
    }

    /**
     * Refuse the quantity, or return silently.
     *
     * Thrown as a validation error against `planned_qty` so the message lands on the field the
     * planner has to change rather than in a generic banner.
     *
     * @throws ValidationException
     */
    public function assert(SalesOrderLine $line, float $plannedQty, ?int $excludeJobCardId = null): void
    {
        $refusal = $this->refusalReason($line, $plannedQty, $excludeJobCardId);

        if ($refusal !== null) {
            throw ValidationException::withMessages(['planned_qty' => $refusal]);
        }
    }
}
