@extends('print.layout', [
    'title' => 'Cost sheet '.($sheet['number'] ?? 'draft'),
    'documentTitle' => 'Cost sheet',
    'documentNumber' => $sheet['number'] ?? '(unnumbered draft)',
])

@php
    $cur = $sheet['currency'] ?? '';
    $pctOrDash = fn ($v) => $v === null ? '—' : number_format((float) $v, 2).' %';
    $stageLabel = ['marketing' => 'Marketing (quotation)', 'pre_production' => 'Pre-production (benchmark)', 'post_production' => 'Post-production (actual)'][$sheet['stage']] ?? $sheet['stage'];
    $prof = $summary['profitability'];
@endphp

@section('doc-meta')
    <p>{{ $stageLabel }}</p>
    @if ($sheet['revision_no'])<p>Revision {{ $sheet['revision_no'] }}</p>@endif
    <p>{{ ucfirst($sheet['status']) }}{{ $sheet['is_locked'] ? ' · locked' : '' }}</p>
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Product</p>
            <p class="name">{{ $product['code'] ?? '—' }} · {{ $product['name'] ?? '' }}</p>
            @if ($product['family'] ?? null)<p>{{ $product['family'] }}</p>@endif
            @if ($product['style_ref'] ?? null)<p>Style {{ $product['style_ref'] }}</p>@endif
            @if ($buyer)<p>Buyer: {{ $buyer['name'] }}</p>@endif
            @if ($document ?? null)<p class="muted">{{ $document['label'] ?? '' }}{{ isset($document['line_no']) && $document['line_no'] ? ', line '.$document['line_no'] : '' }}</p>@endif
        </div>

        <div class="party">
            <table class="meta">
                <tr><th>Costed on</th><td>{{ $fmtDate($sheet['created_at']) }} {{ $sheet['created_by'] ? '· '.$sheet['created_by'] : '' }}</td></tr>
                <tr><th>Quantity</th><td>{{ $qty($sheet['basis_qty']) }}</td></tr>
                <tr><th>Costing per</th><td>{{ $qty($sheet['costing_per']) }} unit{{ (float) $sheet['costing_per'] == 1 ? '' : 's' }}</td></tr>
                <tr><th>Currency</th><td>{{ $cur ?: '—' }}@if ((float) $sheet['exchange_rate'] != 1) · rate {{ number_format((float) $sheet['exchange_rate'], 4) }}@endif</td></tr>
                @if ($sheet['incoterm'])<tr><th>Terms</th><td>{{ $sheet['incoterm'] }}</td></tr>@endif
                @if ($sheet['approved_at'])<tr><th>Approved</th><td>{{ $fmtDate($sheet['approved_at']) }} · {{ $sheet['approved_by'] }}</td></tr>@endif
            </table>
        </div>
    </div>

    @php $verdict = $summary['verdict']; @endphp
    <p style="margin:0 0 4mm;font-size:11pt"><strong>{{ $verdict['headline'] }}</strong>@if ($verdict['detail']) {{ $verdict['detail'] }}@endif</p>

    <table class="lines" style="margin-bottom:6mm">
        <thead>
            <tr>
                <th colspan="3">Materials</th>
                <th colspan="3">Where the cost goes</th>
                <th colspan="2">Profitability</th>
            </tr>
        </thead>
        <tbody>
            @php
                $rows = max(count($summary['materials']), count($summary['breakdown']), 7);
                $profRows = [
                    ['Value at price', $prof['value'] === null ? null : $money($prof['value'])],
                    ['Material', $money($prof['material']).($prof['material_pct'] !== null ? '  ('.number_format($prof['material_pct'], 1).' %)' : '')],
                    ['Other costs', $money($prof['other']).($prof['other_pct'] !== null ? '  ('.number_format($prof['other_pct'], 1).' %)' : '')],
                    ['Total cost', $money($prof['total_cost'])],
                    ['Cost per 1,000', $money($prof['unit_cost'] * 1000)],
                    ['Price per 1,000', $prof['revenue_per_unit'] === null ? '—' : $money($prof['revenue_per_unit'] * 1000)],
                    ['Margin', $prof['margin_pct'] === null ? '—' : $money($prof['margin_amount']).'  ('.number_format($prof['margin_pct'], 1).' %)'],
                ];
            @endphp
            @for ($i = 0; $i < $rows; $i++)
                @php $m = $summary['materials'][$i] ?? null; $o = $summary['breakdown'][$i] ?? null; $p = $profRows[$i] ?? null; @endphp
                <tr>
                    <td>{{ $m['label'] ?? '' }}</td>
                    <td class="num">{{ $m ? $money($m['amount']) : '' }}</td>
                    <td class="num">{{ $m ? $pctOrDash($m['pct_of_price']) : '' }}</td>
                    <td>{{ $o['label'] ?? '' }}</td>
                    <td class="num">{{ $o ? $money($o['amount']) : '' }}</td>
                    <td class="num">{{ $o ? $pctOrDash($o['pct_of_cost']).' of cost' : '' }}</td>
                    <td>{{ $p[0] ?? '' }}</td>
                    <td class="num">{{ $p[1] ?? '' }}</td>
                </tr>
            @endfor
            <tr class="grand">
                <td>Total material</td><td class="num">{{ $money($prof['material']) }}</td><td class="num">{{ $pctOrDash($prof['material_pct']) }}</td>
                <td>Total cost</td><td class="num">{{ $money($prof['total_cost']) }}</td><td class="num">100 %</td>
                <td>Margin per 1,000</td><td class="num">{{ $prof['margin_per_unit'] === null ? '—' : $money($prof['margin_per_unit'] * 1000) }}</td>
            </tr>
        </tbody>
    </table>

    <p class="label">Line detail</p>
    <table class="lines">
        <thead>
            <tr>
                <th>Line</th>
                <th>Element</th>
                <th class="num">Qty</th>
                <th>Unit</th>
                <th class="num">Rate</th>
                <th class="num">Amount</th>
                <th class="num">Per 1,000</th>
                <th class="num">% of price</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line['description'] }}</td>
                    <td class="muted">{{ ucfirst(str_replace('_', ' ', $line['cost_type'])) }}</td>
                    <td class="num">{{ $line['basis'] === 'percentage' ? number_format($line['qty'], 2).' %' : $qty($line['qty']) }}</td>
                    <td>{{ $line['basis'] === 'percentage' ? 'of '.$money($line['percentage_of']) : $line['basis_uom'] }}</td>
                    <td class="num">{{ $line['basis'] === 'percentage' ? '' : number_format($line['rate'], 4) }}</td>
                    <td class="num">{{ $money($line['amount']) }}</td>
                    <td class="num">{{ $line['basis'] === 'percentage' ? '' : $money($line['per_unit'] * 1000) }}</td>
                    <td class="num">{{ $pctOrDash($line['pct_of_price']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if (count($revisions) > 1)
        <p class="label" style="margin-top:6mm">Deviation summary</p>
        <table class="lines">
            <thead>
                <tr><th>Sheet</th><th>Stage</th><th class="num">Qty</th><th class="num">Material</th><th class="num">Conversion</th><th class="num">Overhead</th><th class="num">Total cost</th><th class="num">Cost / 1,000</th><th class="num">Price / 1,000</th><th class="num">Margin</th></tr>
            </thead>
            <tbody>
                @foreach ($revisions as $rev)
                    <tr>
                        <td>{{ $rev['number'] ?? '#'.$rev['id'] }}{{ $rev['revision_no'] ? ' R'.$rev['revision_no'] : '' }}</td>
                        <td>{{ ucfirst(str_replace('_', ' ', $rev['stage'])) }}</td>
                        <td class="num">{{ $qty($rev['basis_qty']) }}</td>
                        <td class="num">{{ $money($rev['material_cost']) }}</td>
                        <td class="num">{{ $money($rev['conversion_cost']) }}</td>
                        <td class="num">{{ $money($rev['overhead_amount']) }}</td>
                        <td class="num">{{ $money($rev['total_cost']) }}</td>
                        <td class="num">{{ $money($rev['unit_cost'] * 1000) }}</td>
                        <td class="num">{{ $rev['revenue_per_unit'] === null ? '—' : $money($rev['revenue_per_unit'] * 1000) }}</td>
                        <td class="num">{{ $pctOrDash($rev['margin_pct']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if (count($history))
        <p class="label" style="margin-top:6mm">Approval history</p>
        <table class="lines">
            <thead><tr><th>When</th><th>Who</th><th>What</th><th>Reason</th></tr></thead>
            <tbody>
                @foreach ($history as $event)
                    <tr>
                        <td>{{ $fmtDateTime($event['at']) }}</td>
                        <td>{{ $event['user'] ?? 'System' }}</td>
                        <td>{{ $event['to'] ? ucfirst($event['to']) : ucfirst(str_replace('_', ' ', $event['event'])) }}</td>
                        <td>{{ $event['reason'] ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <p class="page-note">Software-generated copy; no signature required. Rates are those of the day the sheet was costed.</p>
@endsection
