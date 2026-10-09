<?php

declare(strict_types=1);

namespace App\Modules\Costing\Services;

use App\Modules\Costing\Models\CostSheet as CostSheetModel;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesOrderLine;
use App\Support\Calculators\CostSheet;
use App\Support\Settings\Settings;

/**
 * Pre-production costing (spec §4): the order's cost at confirmation, with today's rates and
 * the confirmed quantity, locked as the benchmark production is measured against.
 *
 * Marketing costing is a guess at one piece before the order exists. This is the same sheet
 * re-run on the day the order is confirmed — the yarn rate that rose since the quote, the
 * quantity the buyer actually confirmed — set against the price that was agreed. The margin
 * it shows is the margin the factory is about to commit to; below the floor, confirming the
 * order is an approval, not a data entry.
 */
class PreProductionCosting
{
    public function __construct(
        private readonly CostSheetService $sheets,
        private readonly Settings $settings,
    ) {}

    /**
     * Every line's estimate at the confirmed quantity, against its agreed price.
     *
     * @return list<array{line: SalesOrderLine, sheet: CostSheet, revenue_per_unit: float, expected_margin_pct: ?float}>
     */
    public function forOrder(SalesOrder $order): array
    {
        $rows = [];

        foreach ($order->lines()->with(['product.item', 'product.activeBom.lines.item', 'product.routing.operations', 'spec'])->get() as $line) {
            if ($line->product === null || (float) $line->ordered_qty <= 0) {
                continue;
            }

            $sheet = $this->sheets->estimate($line->product, (float) $line->ordered_qty, [
                'marginPct' => $this->settings->decimal('default_margin_pct', 20),
                'exchangeRate' => (float) ($order->exchange_rate ?? 1),
            ], $line->spec);

            if ($sheet === null) {
                continue;
            }

            // What the buyer pays per unit, in base currency, tooling included: the figure the
            // margin is struck against.
            $revenue = ((float) $line->line_total * (float) ($order->exchange_rate ?? 1)) / (float) $line->ordered_qty;
            $margin = $revenue > 0 ? ($revenue - $sheet->unitCost) / $revenue * 100 : null;

            $rows[] = [
                'line' => $line,
                'sheet' => $sheet,
                'revenue_per_unit' => round($revenue, 6),
                'expected_margin_pct' => $margin === null ? null : round($margin, 4),
            ];
        }

        return $rows;
    }

    /**
     * The lines whose expected margin sits below the floor, as the words the refusal says.
     *
     * @return list<string>
     */
    public function belowFloor(SalesOrder $order): array
    {
        $floor = $this->settings->decimal('margin_floor_pct', 12);
        $short = [];

        foreach ($this->forOrder($order) as $row) {
            if ($row['expected_margin_pct'] !== null && $row['expected_margin_pct'] < $floor) {
                $short[] = sprintf(
                    'Line %d (%s): expected margin %s%% is below the %s%% floor (cost %s, price %s per unit).',
                    $row['line']->line_no,
                    $row['line']->product->code,
                    number_format($row['expected_margin_pct'], 1),
                    rtrim(rtrim(number_format($floor, 2, '.', ''), '0'), '.'),
                    number_format($row['sheet']->unitCost, 4),
                    number_format($row['revenue_per_unit'], 4),
                );
            }
        }

        return $short;
    }

    /** Lock the benchmark: one sheet per line, replacing any earlier pre-production sheet. */
    public function snapshot(SalesOrder $order): void
    {
        foreach ($this->forOrder($order) as $row) {
            CostSheetModel::query()
                ->where('sales_order_line_id', $row['line']->id)
                ->where('stage', CostSheetModel::PRE_PRODUCTION)
                ->delete();

            $this->sheets->persistEstimate($row['line']->product, $row['sheet'], (float) $row['line']->ordered_qty, [
                'stage' => CostSheetModel::PRE_PRODUCTION,
                'sales_order_line_id' => $row['line']->id,
                'product_spec_id' => $row['line']->product_spec_id,
                'revenue_per_unit' => $row['revenue_per_unit'],
                'is_locked' => true,
                'locked_at' => now(),
            ]);
        }
    }
}
