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
        <div class="party">
            <p class="label">Product</p>
            <p class="name">{{ $document->product_code }} — {{ $document->product_name }}</p>
            <p>{{ $document->customer_name }}</p>
            @if ($document->colourway)<p>Colourway: {{ $document->colourway }}</p>@endif
        </div>

        <div class="party">
            <table class="meta">
                <tr><th>Planned quantity</th><td>{{ $qty($document->planned_qty) }} pcs</td></tr>
                <tr><th>Gross metres</th><td>{{ number_format((float) $document->gross_metres, 3) }}</td></tr>
                <tr><th>Labels per metre</th><td>{{ number_format((float) $document->labels_per_metre, 3) }}</td></tr>
                <tr><th>Priority</th><td>{{ $document->priority }}</td></tr>
            </table>
        </div>
    </div>

    {{--
        Gate 1 in print form. The version bound to this card is the only artwork the floor may
        run against, so it is stated on the paper the floor actually holds.
    --}}
    <div class="gate">
        <strong>Approved artwork:</strong>
        {{ $document->artwork_code ?? '—' }} v{{ $document->artwork_version ?? '—' }}
        @if ($document->artwork_approved_at)
            <span class="muted">· signed off {{ $fmtDate($document->artwork_approved_at) }}</span>
        @endif
        <br>
        <span class="muted">Run against no other version. If the artwork on the machine does not match this line, stop and ask.</span>
    </div>

    <table class="lines">
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
