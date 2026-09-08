{{--
    The invoice, and on an export order the commercial invoice a customs officer reads. So the
    identifiers that only matter outside this system — BIN, Mushak number, the LC it is drawn
    against, the challan the goods travelled on — are on the face of it rather than in a notes
    field, and the amounts come from the stored line values rather than being recomputed: an
    issued invoice is what it said when it was issued.
--}}
@extends('print.layout', [
    'title' => 'Invoice '.($document->number ?? ''),
    'documentTitle' => 'Invoice',
    'documentNumber' => $document->number ?? '(unnumbered)',
])

@section('doc-meta')
    <p>{{ $fmtDate($document->invoice_date) }}</p>
    @if ($document->due_date)<p>Due {{ $fmtDate($document->due_date) }}</p>@endif
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Invoice to</p>
            <p class="name">{{ $document->customer_name }}</p>
            @if ($document->customer_email)<p>{{ $document->customer_email }}</p>@endif
            @if ($document->customer_phone)<p>{{ $document->customer_phone }}</p>@endif
            @if ($document->customer_bin)<p>BIN {{ $document->customer_bin }}</p>@endif
            @if ($document->customer_tin)<p>TIN {{ $document->customer_tin }}</p>@endif
        </div>

        <div class="party">
            <table class="meta">
                <tr><th>Currency</th><td>{{ $document->currency }}</td></tr>
                @if ($document->payment_terms)
                    <tr><th>Payment terms</th><td>{{ $document->payment_terms }}</td></tr>
                @endif
                @if ($document->order_number)
                    <tr><th>Sales order</th><td>{{ $document->order_number }}</td></tr>
                @endif
                @if ($document->customer_po_no)
                    <tr><th>Your PO</th><td>{{ $document->customer_po_no }}</td></tr>
                @endif
                @if ($document->challan_number)
                    <tr><th>Challan</th><td>{{ $document->challan_number }}</td></tr>
                @endif
                @if ($document->lc_no)
                    <tr><th>LC no.</th><td>{{ $document->lc_no }}</td></tr>
                @endif
                @if ($document->mushak_no)
                    <tr><th>Mushak no.</th><td>{{ $document->mushak_no }}</td></tr>
                @endif
            </table>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:8mm">#</th>
                <th>Description</th>
                <th class="num" style="width:24mm">Quantity</th>
                <th class="num" style="width:26mm">Rate / 1,000</th>
                <th class="num" style="width:22mm">Tax</th>
                <th class="num" style="width:28mm">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line->line_no }}</td>
                    <td>
                        @if ($line->product_code)<strong>{{ $line->product_code }}</strong>@endif
                        {{ $line->description }}
                        @if ($line->tax_name)<br><span class="muted">{{ $line->tax_name }}</span>@endif
                    </td>
                    <td class="num">{{ $qty($line->qty) }}</td>
                    <td class="num">{{ number_format((float) $line->rate_per_m, 4) }}</td>
                    <td class="num">{{ $money($line->tax_amount) }}</td>
                    <td class="num">{{ $money($line->amount) }}</td>
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
        {{-- What is still owed, which is the number the customer's accounts department reads. --}}
        @if ((float) $document->received_amount > 0)
            <tr><td>Received</td><td class="num">{{ $money($document->received_amount) }}</td></tr>
            <tr class="grand"><td>Balance due</td><td class="num">{{ $document->currency }} {{ $money((float) $document->total - (float) $document->received_amount) }}</td></tr>
        @endif
    </table>

    @if ((float) $document->exchange_rate !== 1.0)
        <p class="muted" style="margin-top:3mm">
            Booked at {{ number_format((float) $document->exchange_rate, 4) }} per {{ $document->currency }} on the invoice date.
        </p>
    @endif

    @if ($document->remarks)
        <div class="terms">
            <p class="label">Remarks</p>
            <div class="body">{{ $document->remarks }}</div>
        </div>
    @endif

    <div class="signatures">
        <div>For {{ $organisation['name'] }}</div>
        <div>Received by</div>
    </div>
@endsection
