@php
    $fmtDate = fn (?string $value) => $value
        ? \Illuminate\Support\Carbon::parse($value)->format($organisation['date_format'])
        : '—';
@endphp

@extends('print.layout', [
    'title' => 'Physical count '.($count->number ?? ''),
    'documentTitle' => 'Physical count',
    'documentNumber' => $count->number ?? '(unnumbered)',
])

@section('doc-meta')
    <p>{{ $count->warehouse?->name }}</p>
    <p>Count date {{ $fmtDate($count->counted_on?->toDateString()) }}</p>
@endsection

@section('content')
    <p class="terms" style="margin-top:0">
        Blind count sheet — record the physical quantity found. Do not use system balances.
    </p>

    <table>
        <thead>
            <tr>
                <th style="width:8mm">#</th>
                <th>Lot</th>
                <th>Item</th>
                <th>Bin</th>
                <th class="num" style="width:28mm">Counted qty</th>
                <th style="width:40mm">Remarks</th>
                <th style="width:36mm">Counter / signature</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $index => $line)
                <tr>
                    <td>{{ $index + 1 }}</td>
                    <td>{{ $line['lot_no'] ?? '—' }}</td>
                    <td>{{ $line['item_code'] ?? '—' }}</td>
                    <td>{{ $line['bin_code'] ?? '—' }}</td>
                    <td class="num"></td>
                    <td>{{ $line['remarks'] ?? '' }}</td>
                    <td></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="signatures">
        <div>Counted by</div>
        <div>Verified by</div>
    </div>
@endsection
