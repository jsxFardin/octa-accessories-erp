<?php

declare(strict_types=1);

namespace App\Modules\Costing\Services;

use App\Modules\Costing\Models\CostSheet;
use App\Support\Settings\Settings;
use App\Support\Text\RecordLink;
use Illuminate\Support\Facades\DB;

/**
 * A cost sheet shaped for reading (spec §4): the header, the three summaries — materials,
 * other costs, profitability — the line detail with each line's cost per unit and share of
 * the price, the revisions it sits among, and the hands it passed through. The screen and
 * the printed sheet both read this, so they cannot disagree.
 */
class CostSheetPage
{
    /** The cost line types that are material, grouped under one heading. */
    private const MATERIAL_TYPES = [
        'material_yarn' => 'Yarn', 'material_ribbon' => 'Tape & ribbon', 'material_ink' => 'Ink', 'material_chemical' => 'Chemical & consumable',
        'material_paper' => 'Paper & board', 'material_film' => 'Film', 'material_packing' => 'Packing material',
        'material_component' => 'Components', 'material_other' => 'Other material',
    ];

    /** Where the cost goes: every line lands in one of these, in the order a clerk reads them. */
    private const BREAKDOWN = [
        'material' => ['Material', 'bg-brand-600'],
        'conversion' => ['Conversion', 'bg-brand-400'],
        'tooling' => ['Tooling', 'bg-amber-400'],
        'packing' => ['Packing', 'bg-sky-400'],
        'other' => ['Other direct costs', 'bg-violet-400'],
        'overhead' => ['Overhead', 'bg-slate-400'],
    ];

    public function __construct(
        private readonly CostSheetPresenter $presenter,
        private readonly Settings $settings,
        private readonly CostStages $stages,
    ) {}

    /** @return array<string, mixed> */
    public function forSheet(CostSheet $sheet): array
    {
        $basis = max(0.000001, (float) $sheet->basis_qty);
        $per = max(0.000001, (float) $sheet->costing_per);
        $revenue = $sheet->revenue_per_unit === null ? null : (float) $sheet->revenue_per_unit;
        $value = $revenue === null ? null : $revenue * $basis;

        // Lines, each with what it costs per costing unit and what share of the price it takes.
        $lines = array_map(function (array $line) use ($basis, $per, $value): array {
            $amount = (float) $line['amount'];

            return [
                ...$line,
                'per_unit' => round($amount / $basis * $per, 6),
                'pct_of_price' => $value !== null && $value > 0 && $line['basis'] !== 'percentage' ? round($amount / $value * 100, 2) : null,
                'is_material' => isset(self::MATERIAL_TYPES[$line['cost_type']]),
                'is_waste' => in_array($line['cost_type'], ['wastage', 'scrap_credit'], true),
            ];
        }, $this->presenter->lines($sheet->lines));

        $materials = $this->group($lines, self::MATERIAL_TYPES, $value);

        $overhead = (float) $sheet->overhead_amount;
        $materialTotal = (float) $sheet->material_cost;
        $otherTotal = (float) $sheet->total_cost - $materialTotal;
        $unitCost = (float) $sheet->unit_cost * $per;
        $marginAmount = $value === null ? null : round($value - (float) $sheet->total_cost, 4);
        $floor = $this->settings->decimal('margin_floor_pct', 12);

        // A job's actuals read against its benchmark, line by line (spec §4).
        $comparison = $sheet->stage === CostSheet::POST_PRODUCTION && $sheet->jobCard !== null
            ? $this->stages->forJobCard($sheet->jobCard)
            : null;

        return [
            'sheet' => [
                'id' => $sheet->id,
                'number' => $sheet->number,
                'revision_no' => $sheet->revision_no,
                'stage' => $sheet->stage,
                'status' => $sheet->status,
                'is_locked' => (bool) $sheet->is_locked,
                'basis_qty' => (float) $sheet->basis_qty,
                'costing_per' => $per,
                'currency' => $sheet->currency?->code,
                'exchange_rate' => (float) $sheet->exchange_rate,
                'incoterm' => $sheet->incoterm,
                'overhead_pct' => (float) $sheet->overhead_pct,
                'admin_pct' => (float) $sheet->admin_pct,
                'margin_pct' => (float) $sheet->margin_pct,
                'total_wastage_pct' => (float) $sheet->total_wastage_pct,
                'gross_metres' => $sheet->gross_metres === null ? null : (float) $sheet->gross_metres,
                'variance_reason' => $sheet->variance_reason,
                'created_at' => $sheet->created_at,
                'created_by' => $sheet->creator?->name,
                'submitted_at' => $sheet->submitted_at,
                'submitted_by' => $sheet->submitter?->name,
                'approved_at' => $sheet->approved_at,
                'approved_by' => $sheet->approver?->name,
                'locked_at' => $sheet->locked_at,
            ],
            'product' => $sheet->product === null ? null : [
                'id' => $sheet->product->id,
                'code' => $sheet->product->code,
                'name' => $sheet->product->name,
                'product_type' => $sheet->product->product_type,
                'family' => $sheet->product->item?->family?->name,
                'style_ref' => $sheet->product->customer_style_ref,
                'spec_version' => $sheet->product_spec_id === null ? null : DB::table('product_specs')->where('id', $sheet->product_spec_id)->value('version_no'),
            ],
            'buyer' => $this->buyer($sheet),
            'document' => $this->document($sheet),
            'summary' => [
                'verdict' => $this->verdict($sheet, $marginAmount, $floor, $comparison),
                'breakdown' => $this->breakdown($lines, (float) $sheet->total_cost, $value),
                'materials' => $materials,
                'profitability' => [
                    'revenue_per_unit' => $revenue === null ? null : $revenue * $per,
                    'value' => $value,
                    'material' => $materialTotal,
                    'other' => $otherTotal,
                    'overhead' => $overhead,
                    'total_cost' => (float) $sheet->total_cost,
                    'unit_cost' => $unitCost,
                    'margin_per_unit' => $revenue === null ? null : round($revenue * $per - $unitCost, 6),
                    'margin_amount' => $marginAmount,
                    'margin_pct' => $sheet->marginPct(),
                    'margin_floor_pct' => $floor,
                    'material_pct' => $value !== null && $value > 0 ? round($materialTotal / $value * 100, 2) : null,
                    'other_pct' => $value !== null && $value > 0 ? round($otherTotal / $value * 100, 2) : null,
                ],
            ],
            'lines' => array_values($lines),
            'revisions' => $this->revisions($sheet),
            'history' => $this->history($sheet),
            'comparison' => $comparison,
        ];
    }

    /**
     * The sheet's answer in one sentence, before any table: what it makes or loses on the
     * quantity, whether that clears the floor, and on a post-production sheet what moved it
     * against the benchmark. The screen and the printed sheet open with it.
     *
     * @param  array<string, mixed>|null  $comparison
     * @return array{tone: string, headline: string, detail: ?string}
     */
    private function verdict(CostSheet $sheet, ?float $marginAmount, float $floor, ?array $comparison): array
    {
        $cur = $sheet->currency?->code ?? '';
        $money = fn (float $v): string => trim($cur.' '.number_format(abs($v), abs($v) >= 100 ? 0 : 2));
        $qty = number_format((float) $sheet->basis_qty).' pcs';
        $past = $sheet->stage === CostSheet::POST_PRODUCTION;
        $margin = $sheet->marginPct();

        if ($marginAmount === null || $margin === null) {
            return ['tone' => 'none', 'headline' => 'No price on this sheet yet.', 'detail' => 'Cost '.$money((float) $sheet->total_cost).' for '.$qty.'.'];
        }

        $verb = $marginAmount >= 0 ? ($past ? 'Made' : 'Makes') : ($past ? 'Lost' : 'Loses');
        $headline = sprintf('%s %s on %s.', $verb, $money($marginAmount), $qty);
        $floorText = rtrim(rtrim(number_format($floor, 2, '.', ''), '0'), '.').'% floor';
        $detail = $margin < $floor
            ? sprintf('%s margin, below the %s.', number_format($margin, 1).'%', $floorText)
            : sprintf('%s margin, above the %s.', number_format($margin, 1).'%', $floorText);

        if ($comparison !== null && $comparison['pre'] !== null) {
            $total = collect($comparison['variance'])->firstWhere('key', 'total_cost')['total'] ?? null;

            if ($total !== null && abs($total) >= 0.005) {
                $drivers = collect($comparison['variance'])
                    ->filter(fn (array $row): bool => $row['key'] !== 'total_cost' && $row['total'] !== null && ($total > 0 ? $row['total'] > 0 : $row['total'] < 0))
                    ->sortByDesc(fn (array $row): float => abs((float) $row['total']))
                    ->take(2)
                    ->map(fn (array $row): string => sprintf('%s (%s%s)', $row['label'], $row['total'] > 0 ? '+' : '−', $money((float) $row['total'])))
                    ->all();
                $detail .= sprintf(' %s %s the benchmark%s.', $money((float) $total), $total > 0 ? 'over' : 'under', $drivers === [] ? '' : ', mostly '.implode(' and ', $drivers));
            }
        }

        return ['tone' => $marginAmount < 0 ? 'bad' : ($margin < $floor ? 'warn' : 'good'), 'headline' => $headline, 'detail' => $detail];
    }

    /**
     * Where the cost goes: six buckets that add up to the total cost, each with its share of
     * the cost. The margin line is not a cost and is left out; whatever the lines do not
     * account for (a minimum charge, rounding) lands in "Other direct costs" so it still foots.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{key: string, label: string, colour: string, amount: float, pct_of_cost: ?float, pct_of_price: ?float}>
     */
    private function breakdown(array $lines, float $total, ?float $value): array
    {
        $bucket = fn (string $type): ?string => match (true) {
            isset(self::MATERIAL_TYPES[$type]) => 'material',
            in_array($type, ['machine', 'labour', 'energy'], true) => 'conversion',
            $type === 'tooling' => 'tooling',
            $type === 'packing' => 'packing',
            $type === 'overhead' || $type === 'admin_overhead' => 'overhead',
            $type === 'margin' => null,
            default => 'other',
        };
        $sums = array_fill_keys(array_keys(self::BREAKDOWN), 0.0);

        foreach ($lines as $line) {
            $key = $bucket((string) $line['cost_type']);

            if ($key !== null) {
                $sums[$key] += (float) $line['amount'];
            }
        }

        $sums['other'] += $total - array_sum($sums);
        $rows = [];

        foreach (self::BREAKDOWN as $key => [$label, $colour]) {
            if (abs($sums[$key]) < 0.005) {
                continue;
            }

            $rows[] = [
                'key' => $key,
                'label' => $label,
                'colour' => $colour,
                'amount' => round($sums[$key], 4),
                'pct_of_cost' => $total > 0 ? round($sums[$key] / $total * 100, 1) : null,
                'pct_of_price' => $value !== null && $value > 0 ? round($sums[$key] / $value * 100, 1) : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @param  array<string, string>  $labels
     * @return list<array{key: string, label: string, amount: float, pct_of_price: ?float}>
     */
    private function group(array $lines, array $labels, ?float $value): array
    {
        $rows = [];

        foreach ($labels as $type => $label) {
            $amount = array_sum(array_map(fn (array $l): float => (float) $l['amount'], array_filter($lines, fn (array $l): bool => $l['cost_type'] === $type)));

            if ($amount == 0.0) {
                continue;
            }

            // Two keys share a label (subcontract, outsourcing): merge them into one row.
            $existing = array_search($label, array_column($rows, 'label'), true);

            if ($existing !== false) {
                $rows[$existing]['amount'] += $amount;
                $rows[$existing]['pct_of_price'] = $value !== null && $value > 0 ? round($rows[$existing]['amount'] / $value * 100, 2) : null;

                continue;
            }

            $rows[] = [
                'key' => $type,
                'label' => $label,
                'amount' => round($amount, 4),
                'pct_of_price' => $value !== null && $value > 0 ? round($amount / $value * 100, 2) : null,
            ];
        }

        return $rows;
    }

    /** @return array{id: int, name: string}|null */
    private function buyer(CostSheet $sheet): ?array
    {
        $customer = $sheet->quotationLine?->quotation?->customer
            ?? $sheet->salesOrderLine?->salesOrder?->customer
            ?? $sheet->product?->customer;

        return $customer === null ? null : ['id' => $customer->id, 'name' => $customer->name];
    }

    /** @return array{kind: string, label: string, href: string, line_no: ?int}|null */
    private function document(CostSheet $sheet): ?array
    {
        if ($sheet->jobCard !== null) {
            return ['kind' => 'job_card', 'label' => $sheet->jobCard->number ?? "Job card #{$sheet->jobCard->id}", 'href' => "/job-cards/{$sheet->jobCard->id}", 'line_no' => null];
        }

        if ($sheet->salesOrderLine !== null) {
            $order = $sheet->salesOrderLine->salesOrder;

            return ['kind' => 'sales_order', 'label' => $order?->number ?? "Order #{$order?->id}", 'href' => "/sales-orders/{$order?->id}", 'line_no' => $sheet->salesOrderLine->line_no];
        }

        if ($sheet->quotationLine !== null) {
            $quotation = $sheet->quotationLine->quotation;

            return ['kind' => 'quotation', 'label' => ($quotation?->number ?? "Quotation #{$quotation?->id}").($quotation?->revision_no ? " R{$quotation->revision_no}" : ''), 'href' => "/quotations/{$quotation?->id}", 'line_no' => $sheet->quotationLine->line_no];
        }

        return null;
    }

    /**
     * The deviation summary: every sheet of this product on the same quotation across its
     * revisions, or on the same order line across its stages, so a reader sees what moved
     * between one costing and the next.
     *
     * @return list<array<string, mixed>>
     */
    private function revisions(CostSheet $sheet): array
    {
        $query = CostSheet::query()->with(['quotationLine.quotation', 'approver:id,name']);

        if ($sheet->sales_order_line_id !== null) {
            $query->where('sales_order_line_id', $sheet->sales_order_line_id);
        } elseif ($sheet->quotationLine?->quotation !== null) {
            $quotation = $sheet->quotationLine->quotation;
            $query->whereHas('quotationLine.quotation', fn ($q) => $q->where('number', $quotation->number)->where('customer_id', $quotation->customer_id))
                ->where('product_id', $sheet->product_id);
        } else {
            $query->whereKey($sheet->id);
        }

        return $query->orderByDesc('id')->get()->map(fn (CostSheet $row): array => [
            'id' => $row->id,
            'number' => $row->number,
            'stage' => $row->stage,
            'status' => $row->status,
            'revision_no' => $row->quotationLine?->quotation?->revision_no ?? $row->revision_no,
            'basis_qty' => (float) $row->basis_qty,
            'material_cost' => (float) $row->material_cost,
            'conversion_cost' => (float) $row->machine_cost + (float) $row->labour_cost + (float) $row->energy_cost,
            'other_cost' => (float) $row->tooling_cost + (float) $row->packing_cost + (float) $row->other_cost,
            'overhead_amount' => (float) $row->overhead_amount,
            'total_cost' => (float) $row->total_cost,
            'unit_cost' => (float) $row->unit_cost * (float) $row->costing_per,
            'revenue_per_unit' => $row->revenue_per_unit === null ? null : (float) $row->revenue_per_unit * (float) $row->costing_per,
            'margin_pct' => $row->marginPct(),
            'created_at' => $row->created_at,
            'approved_by' => $row->approver?->name,
            'is_current' => $row->id === $sheet->id,
        ])->all();
    }

    /**
     * Who did what to the sheet, from the audit log: made, submitted, approved, rejected,
     * reopened, printed — with the reason where one was given.
     *
     * @return list<array<string, mixed>>
     */
    private function history(CostSheet $sheet): array
    {
        return DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->whereIn('a.auditable_type', [CostSheet::class, 'cost_sheets'])
            ->where('a.auditable_id', $sheet->id)
            // The model's own `updated` rows are the bookkeeping behind a transition, not
            // an act of their own; the history is the acts.
            ->whereIn('a.event', ['created', 'status_changed', 'printed'])
            ->orderBy('a.id')
            ->get(['a.id', 'a.event', 'a.old_values', 'a.new_values', 'a.created_at', 'u.name as user'])
            ->map(function ($row): array {
                $new = json_decode((string) $row->new_values, true) ?: [];
                $old = json_decode((string) $row->old_values, true) ?: [];

                return [
                    'id' => $row->id,
                    'event' => $row->event,
                    'user' => $row->user,
                    'at' => $row->created_at,
                    'from' => $row->event === 'status_changed' ? ($old['status'] ?? null) : null,
                    'to' => $row->event === 'status_changed' ? ($new['status'] ?? null) : null,
                    'reason' => $new['reason'] ?? $new['context']['reason'] ?? null,
                ];
            })
            ->all();
    }

    /** The sheet's own page. */
    public static function href(CostSheet|int $sheet): string
    {
        return RecordLink::PATHS['CostSheet'].'/'.($sheet instanceof CostSheet ? $sheet->id : $sheet);
    }
}
