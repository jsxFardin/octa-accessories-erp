@php
    $fmtDate = fn (?string $value) => $value
        ? \Illuminate\Support\Carbon::parse($value)->format($organisation['date_format'])
        : '—';
    $money = fn ($value) => number_format((float) $value, 2);
@endphp

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
        <section>
            <p class="label">Quotation for</p>
            <p class="name">{{ $document->customer_name }}</p>
            @if ($document->customer_email)<p>{{ $document->customer_email }}</p>@endif
            @if ($document->customer_phone)<p>{{ $document->customer_phone }}</p>@endif
        </section>

        <section>
            <dl class="meta">
                <dt>Valid until</dt><dd>{{ $fmtDate($document->valid_until) }}</dd>
                <dt>Currency</dt><dd>{{ $document->currency }}</dd>
                @if ($document->payment_terms)
                    <dt>Payment terms</dt><dd>{{ $document->payment_terms }}</dd>
                @endif
            </dl>
        </section>
    </div>

    <table>
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
                            <br><span style="color:#7b869c;font-size:8.5pt">Lead time {{ $line->lead_time_days }} days</span>
                        @endif
                    </td>
                    <td class="num">{{ number_format((float) $line->qty) }}</td>
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
        <div class="terms"><p class="label">Terms</p>{{ $document->terms }}</div>
    @endif

    <div class="signatures">
        <div>For {{ $organisation['name'] }}</div>
        <div>Accepted by {{ $document->customer_name }}</div>
    </div>
@endsection
