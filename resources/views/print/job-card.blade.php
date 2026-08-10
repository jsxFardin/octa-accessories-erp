@php
    $fmtDate = fn (?string $value) => $value
        ? \Illuminate\Support\Carbon::parse($value)->format($organisation['date_format'])
        : '—';
@endphp

@extends('print.layout', [
    'title' => 'Job card '.($document->number ?? ''),
    'documentTitle' => 'Job card',
    'documentNumber' => $document->number ?? '(unnumbered)',
])

@section('doc-meta')
    <p>{{ $document->unit_name }}</p>
    @if ($document->due_date)<p>Due {{ $fmtDate($document->due_date) }}</p>@endif
@endsection

@section('content')
    <div class="parties">
        <section>
            <p class="label">Product</p>
            <p class="name">{{ $document->product_code }} — {{ $document->product_name }}</p>
            <p>{{ $document->customer_name }}</p>
            @if ($document->colourway)<p>Colourway: {{ $document->colourway }}</p>@endif
        </section>

        <section>
            <dl class="meta">
                <dt>Planned quantity</dt><dd>{{ number_format((float) $document->planned_qty) }} pcs</dd>
                <dt>Gross metres</dt><dd>{{ number_format((float) $document->gross_metres, 3) }}</dd>
                <dt>Labels per metre</dt><dd>{{ number_format((float) $document->labels_per_metre, 3) }}</dd>
                <dt>Priority</dt><dd>{{ $document->priority }}</dd>
            </dl>
        </section>
    </div>

    {{--
        Gate 1 in print form. The version bound to this card is the only artwork the floor may
        run against, so it is stated on the paper the floor actually holds.
    --}}
    <div style="border:1px solid #1a7f5a;background:#f0faf5;padding:3mm;margin-top:4mm;font-size:9.5pt">
        <strong>Approved artwork:</strong>
        {{ $document->artwork_code ?? '—' }} v{{ $document->artwork_version ?? '—' }}
        @if ($document->artwork_approved_at)
            <span style="color:#55607a">· signed off {{ $fmtDate($document->artwork_approved_at) }}</span>
        @endif
        <br>
        <span style="color:#55607a;font-size:8.5pt">Run against no other version. If the artwork on the machine does not match this line, stop and ask.</span>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width:8mm">#</th>
                <th>Operation</th>
                <th>Machine group</th>
                <th class="num" style="width:26mm">Planned minutes</th>
                <th style="width:28mm">Operator / time</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($operations as $operation)
                <tr>
                    <td>{{ $operation->sequence_no }}</td>
                    <td><strong>{{ $operation->code }}</strong> {{ $operation->name }}</td>
                    <td>{{ $operation->machine_group ?? '—' }}</td>
                    <td class="num">{{ number_format((float) $operation->planned_minutes) }}</td>
                    {{-- Deliberately blank: the paper card is filled in by hand and keyed later. --}}
                    <td style="height:9mm"></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="signatures">
        <div>Issued by</div>
        <div>Supervisor</div>
        <div>QC</div>
    </div>
@endsection
