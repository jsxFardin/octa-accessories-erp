@extends('print.layout', [
    'title' => 'Purchase order '.($document->number ?? ''),
    'documentTitle' => 'Purchase order',
    'documentNumber' => $document->number ?? '(unnumbered)',
])

@section('doc-meta')
    <p>{{ $fmtDate($document->order_date) }}</p>
    @if ($document->expected_date)<p>Expected {{ $fmtDate($document->expected_date) }}</p>@endif
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Supplier</p>
            <p class="name">{{ $document->supplier_name }}</p>
            @if ($document->supplier_address)<p>{{ $document->supplier_address }}</p>@endif
            @if ($document->supplier_country)<p>{{ $document->supplier_country }}</p>@endif
            @if ($document->supplier_email)<p>{{ $document->supplier_email }}</p>@endif
        </div>

        <div class="party">
            <p class="label">Deliver to</p>
            <p class="name">{{ $document->unit_name }}</p>
            @if ($document->unit_address)<p>{{ $document->unit_address }}</p>@endif

            <table class="meta" style="margin-top:3mm">
                <tr><th>Currency</th><td>{{ $document->currency }}</td></tr>
                @if ($document->payment_terms)
                    <tr><th>Payment terms</th><td>{{ $document->payment_terms }}</td></tr>
                @endif
                @if ($document->incoterm)
                    <tr><th>Incoterm</th><td>{{ $document->incoterm }}</td></tr>
                @endif
            </table>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:8mm">#</th>
                <th>Item</th>
                <th class="num" style="width:26mm">Quantity</th>
                <th class="num" style="width:24mm">Rate</th>
                <th style="width:24mm">Expected</th>
                <th class="num" style="width:28mm">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line->line_no }}</td>
                    <td>
                        <strong>{{ $line->item_code }}</strong> {{ $line->item_name }}
                        @if ($line->cert_claim)
                            {{-- The claim is part of the order, not a note: it makes the GRN's certification fields mandatory (Gate 2). --}}
                            <br><span class="cert">Must carry a {{ strtoupper($line->cert_claim) }} claim</span>
                        @endif
                    </td>
                    <td class="num">{{ $qty($line->qty) }} {{ $line->uom }}</td>
                    <td class="num">{{ number_format((float) $line->rate, 4) }}</td>
                    <td>{{ $fmtDate($line->expected_date) }}</td>
                    <td class="num">{{ $money($line->amount) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="totals">
        <tr><td>Goods</td><td class="num">{{ $document->currency }} {{ $money($document->subtotal) }}</td></tr>
        @if ((float) $document->freight_amount > 0)
            <tr><td>Freight</td><td class="num">{{ $money($document->freight_amount) }}</td></tr>
        @endif
        @if ((float) $document->tax_amount > 0)
            <tr><td>Tax</td><td class="num">{{ $money($document->tax_amount) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="num">{{ $document->currency }} {{ $money($document->total) }}</td></tr>
    </table>

    @if ($document->remarks)
        <div class="terms">
            <p class="label">Remarks</p>
            <div class="body">{{ $document->remarks }}</div>
        </div>
    @endif

    <div class="signatures">
        <div>Authorised for {{ $organisation['name'] }}</div>
        <div>Acknowledged by {{ $document->supplier_name }}</div>
    </div>
@endsection
