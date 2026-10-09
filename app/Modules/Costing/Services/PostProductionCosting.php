<?php

declare(strict_types=1);

namespace App\Modules\Costing\Services;

use App\Modules\Costing\Models\CostSheet as CostSheetModel;
use App\Modules\Manufacturing\Models\JobCard;
use App\Support\Calculators\CostLine;
use App\Support\Calculators\CostSheet;
use App\Support\Settings\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Post-production costing (spec §4): what the job actually cost, from what was actually
 * issued, run and received.
 *
 * Material is what the store issued to the job, net of returns, at the lot's own cost.
 * Conversion is the minutes each operation actually ran, on the machine it actually ran on.
 * Waste is listed by cause beside the material it consumed. Overhead is applied at the same
 * rates the benchmark used, so a variance against the benchmark is a variance in usage,
 * price, yield or efficiency — not in method.
 */
class PostProductionCosting
{
    public function __construct(
        private readonly CostSheetService $sheets,
        private readonly Settings $settings,
    ) {}

    public function build(JobCard $jobCard): CostSheet
    {
        $jobCard->loadMissing(['product.item', 'operations.machine', 'operations.routingOperation', 'salesOrderLine']);

        $lines = [];
        $seq = 1;

        // --- Material actually issued, net of returns, at the lot's cost --------------------
        $issued = DB::table('material_issue_lines as l')
            ->join('material_issues as mi', 'mi.id', '=', 'l.material_issue_id')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'l.uom_id')
            ->where('mi.job_card_id', $jobCard->id)
            ->where('mi.status', 'posted')
            ->selectRaw("l.item_id, i.code, i.name, i.item_type, i.material_base, u.code as uom,
                SUM(CASE WHEN mi.issue_type = 'return' THEN -l.qty ELSE l.qty END) AS qty,
                SUM(CASE WHEN mi.issue_type = 'return' THEN -l.qty * l.unit_cost ELSE l.qty * l.unit_cost END) AS amount")
            ->groupBy('l.item_id', 'i.code', 'i.name', 'i.item_type', 'i.material_base', 'u.code')
            ->get();

        $materialCost = 0.0;

        foreach ($issued as $row) {
            $qty = (float) $row->qty;
            $amount = (float) $row->amount;
            $materialCost += $amount;
            $item = \App\Modules\MasterData\Models\Item::query()->with('category')->find($row->item_id);

            $lines[] = new CostLine(
                seq: $seq++,
                costType: $item === null ? 'material_other' : ProductCostEstimate::materialType($item),
                basis: (string) ($row->uom ?? 'unit'),
                qty: round($qty, 6),
                rate: $qty > 0 ? round($amount / $qty, 6) : 0.0,
                amount: round($amount, 4),
                formulaRef: 'CS-A',
                description: "{$row->code} {$row->name}",
            );
        }

        // --- Waste by cause: listed, not added — its cost is inside the material above -----
        $waste = DB::table('waste_logs')
            ->where('job_card_id', $jobCard->id)
            ->selectRaw('waste_type, SUM(qty) AS qty, SUM(value) AS value')
            ->groupBy('waste_type')
            ->get();

        foreach ($waste as $row) {
            $lines[] = new CostLine($seq++, 'wastage', 'unit', round((float) $row->qty, 6), 0.0, 0.0, 'G4', 'Waste: '.str_replace('_', ' ', (string) $row->waste_type));
        }

        // --- Conversion: minutes actually run, on the machine that ran them --------------
        $labourRate = $this->settings->decimal('labour_rate_per_hour', 80);
        $tariff = $this->settings->decimal('tariff_per_kwh', 12);
        $machineCost = $labourCost = $energyCost = $hours = 0.0;

        foreach ($jobCard->operations as $operation) {
            $stepHours = (float) $operation->actual_minutes / 60;

            if ($stepHours <= 0) {
                continue;
            }

            $hours += $stepHours;
            $machine = $operation->machine;
            $manning = (float) ($operation->routingOperation?->manning_level ?? 1);

            $machineAmount = $stepHours * (float) ($machine?->hourly_rate ?? 0);
            $labourAmount = $stepHours * $manning * $labourRate;
            $energyAmount = $stepHours * (float) ($machine?->kw_rating ?? 0) * $tariff;
            $machineCost += $machineAmount;
            $labourCost += $labourAmount;
            $energyCost += $energyAmount;

            $lines[] = new CostLine($seq++, 'machine', 'hour', round($stepHours, 4), (float) ($machine?->hourly_rate ?? 0), round($machineAmount, 4), 'CS-A', "{$operation->name}: machine".($machine ? " ({$machine->code})" : ''));
            $lines[] = new CostLine($seq++, 'labour', 'hour', round($stepHours, 4), round($manning * $labourRate, 4), round($labourAmount, 4), 'CS-A', "{$operation->name}: labour");

            if ($energyAmount > 0) {
                $lines[] = new CostLine($seq++, 'energy', 'kWh', round($stepHours * (float) $machine->kw_rating, 4), $tariff, round($energyAmount, 4), 'CS-A', "{$operation->name}: energy");
            }
        }

        // --- Overhead at the benchmark's rates; the output is the final operation's good qty --
        $direct = $materialCost + $machineCost + $labourCost + $energyCost;
        $overheadPct = $this->settings->decimal('overhead_pct', 12);
        $adminPct = $this->settings->decimal('admin_pct', 5);
        $factoryOverhead = $direct * $overheadPct / 100;
        $adminOverhead = ($direct + $factoryOverhead) * $adminPct / 100;
        $total = $direct + $factoryOverhead + $adminOverhead;

        $lines[] = new CostLine($seq++, 'overhead', '%', $overheadPct, round($direct, 4), round($factoryOverhead, 4), 'BR-19', 'Factory overhead');
        $lines[] = new CostLine($seq++, 'overhead', '%', $adminPct, round($direct + $factoryOverhead, 4), round($adminOverhead, 4), 'BR-19', 'Administrative overhead');

        $good = $jobCard->finalOperationOutput()['good'];
        $unitCost = $good > 0 ? $total / $good : 0.0;

        $revenue = $this->revenuePerUnit($jobCard);
        $marginPct = $revenue !== null && $revenue > 0 ? ($revenue - $unitCost) / $revenue * 100 : 0.0;

        return new CostSheet(
            lines: $lines,
            materialCost: round($materialCost, 4),
            toolingCost: 0.0,
            machineCost: round($machineCost, 4),
            labourCost: round($labourCost, 4),
            energyCost: round($energyCost, 4),
            directCost: round($direct, 4),
            factoryOverhead: round($factoryOverhead, 4),
            adminOverhead: round($adminOverhead, 4),
            subtotal: round($total, 4),
            minimumCharge: 0.0,
            totalCost: round($total, 4),
            unitCost: round($unitCost, 6),
            ratePerM: round($revenue ?? 0.0, 4),
            ratePerMInCurrency: round($revenue ?? 0.0, 4),
            marginPct: round($marginPct, 4),
            marginAmount: round((($revenue ?? 0.0) - $unitCost) * $good, 4),
            sellingValue: round(($revenue ?? 0.0) * $good, 4),
            currency: (string) $this->settings->get('base_currency', 'BDT'),
            exchangeRate: 1.0,
            belowMinimumOrderValue: false,
            totalMachineHours: round($hours, 4),
        );
    }

    /** The agreed price per unit of the order line the job fills; a stock job has none. */
    public function revenuePerUnit(JobCard $jobCard): ?float
    {
        $line = $jobCard->salesOrderLine;

        if ($line === null || (float) $line->ordered_qty <= 0) {
            return null;
        }

        $rate = (float) ($line->salesOrder?->exchange_rate ?? 1);

        return round((float) $line->line_total * $rate / (float) $line->ordered_qty, 6);
    }

    /** Record the actuals against the job, replacing an earlier post-production sheet. */
    public function snapshot(JobCard $jobCard): CostSheetModel
    {
        $sheet = $this->build($jobCard);
        $good = max(0.000001, $jobCard->finalOperationOutput()['good']);

        CostSheetModel::query()->where('job_card_id', $jobCard->id)->where('stage', CostSheetModel::POST_PRODUCTION)->delete();

        return $this->sheets->persistEstimate($jobCard->product, $sheet, $good, [
            'stage' => CostSheetModel::POST_PRODUCTION,
            'job_card_id' => $jobCard->id,
            'sales_order_line_id' => $jobCard->sales_order_line_id,
            'product_spec_id' => $jobCard->product_spec_id,
            'revenue_per_unit' => $this->revenuePerUnit($jobCard),
            'is_locked' => true,
            'locked_at' => now(),
        ]);
    }
}
