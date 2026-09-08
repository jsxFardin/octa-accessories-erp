@extends('print.layout', [
    'title' => 'Quotation '.($document->number ?? 'draft'),
    'documentTitle' => 'Quotation',
    'documentNumber' => $document->number ?? '(unnumbered draft)',
])

@section('doc-meta')
    <p>{{ $fmtDate($document->quotation_date) }}</p>
    @if ($document->revision_no)<p>Revision {{ $document->revision_no }}</p>@endif
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Quotation for</p>
            <p class="name">{{ $document->customer_name }}</p>
            @if ($document->customer_email)<p>{{ $document->customer_email }}</p>@endif
            @if ($document->customer_phone)<p>{{ $document->customer_phone }}</p>@endif
        </div>

        <div class="party">
            <table class="meta">
                <tr><th>Valid until</th><td>{{ $fmtDate($document->valid_until) }}</td></tr>
                <tr><th>Currency</th><td>{{ $document->currency }}</td></tr>
                @if ($document->payment_terms)
                    <tr><th>Payment terms</th><td>{{ $document->payment_terms }}</td></tr>
                @endif
            </table>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:8mm">#</th>
                <th>Description</th>
                <th class="num" style="width:22mm">Quantity</th>
                <th class="num" style="width:24mm">Rate / 1,000</th>
                <th class="num" style="width:22mm">Tooling</th>
                <th class="num" style="width:26mm">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line->line_no }}</td>
                    <td>
                        <strong>{{ $line->product_code }}</strong>
                        {{ $line->description }}
                        @if ($line->lead_time_days)
                            <br><span class="muted">Lead time {{ $line->lead_time_days }} days</span>
                        @endif
                    </td>
                    <td class="num">{{ $qty($line->qty) }}</td>
                    {{-- Four decimals: the difference between 3.2500 and 3.2512 is real money at 500,000 pieces (BR-47). --}}
                    <td class="num">{{ number_format((float) $line->rate_per_m, 4) }}</td>
                    <td class="num">{{ $money($line->tooling_charge) }}</td>
                    <td class="num">{{ $money($line->line_total) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Subtotal</td><td class="num">{{ $document->currency }} {{ $money($document->subtotal) }}</td></tr>
        @if ((float) $document->tax_amount > 0)
            <tr><td>Tax</td><td class="num">{{ $money($document->tax_amount) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="num">{{ $document->currency }} {{ $money($document->total) }}</td></tr>
    </table>

    @if ($document->terms)
        <div class="terms">
            <p class="label">Terms</p>
            <div class="body">{{ $document->terms }}</div>
        </div>
    @endif

    <div class="signatures">
        <div>For {{ $organisation['name'] }}</div>
        <div>Accepted by {{ $document->customer_name }}</div>
    </div>
@endsection
