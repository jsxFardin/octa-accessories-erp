{{--
    The customer's copy of what this factory has committed to. Two things make it a confirmation
    rather than a restated quotation: the delivery schedule, because one quantity across three
    dates is three promises, and the tolerance band, because a short or over delivery inside it
    is contractually a complete delivery (BR-52) and the customer needs to have been told.
--}}
@extends('print.layout', [
    'title' => 'Order confirmation '.($document->number ?? ''),
    'documentTitle' => 'Order confirmation',
    'documentNumber' => $document->number ?? '(unnumbered)',
])

@section('doc-meta')
    <p>{{ $fmtDate($document->order_date) }}</p>
    @if ($document->revision_no)<p>Revision {{ $document->revision_no }}</p>@endif
    @if ($document->customer_po_no)<p>Your PO {{ $document->customer_po_no }}</p>@endif
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Confirmed to</p>
            <p class="name">{{ $document->customer_name }}</p>
            @if ($document->customer_email)<p>{{ $document->customer_email }}</p>@endif
            @if ($document->customer_phone)<p>{{ $document->customer_phone }}</p>@endif
            @if ($document->merchandiser_name)
                <p class="muted" style="margin-top:2mm">Your contact here: {{ $document->merchandiser_name }}</p>
            @endif
        </div>

        <div class="party">
            <table class="meta">
                <tr><th>Delivery date</th><td>{{ $fmtDate($document->delivery_date) }}</td></tr>
                <tr><th>Currency</th><td>{{ $document->currency }}</td></tr>
                @if ($document->payment_terms)
                    <tr><th>Payment terms</th><td>{{ $document->payment_terms }}</td></tr>
                @endif
                @if ($document->quotation_number)
                    <tr><th>Against quotation</th><td>{{ $document->quotation_number }}</td></tr>
                @endif
                @if ($document->priority_name)
                    <tr><th>Priority</th><td>{{ $document->priority_name }}</td></tr>
                @endif
                @if ($document->unit_name)
                    <tr><th>Producing unit</th><td>{{ $document->unit_name }}</td></tr>
                @endif
            </table>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:8mm">#</th>
                <th>Product</th>
                <th class="num" style="width:22mm">Quantity</th>
                <th class="num" style="width:24mm">Rate / 1,000</th>
                <th class="num" style="width:20mm">Tooling</th>
                <th style="width:22mm">Promised</th>
                <th class="num" style="width:26mm">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($lines as $line)
                <tr>
                    <td>{{ $line->line_no }}</td>
                    <td>
                        <strong>{{ $line->product_code }}</strong> {{ $line->description ?: $line->product_name }}
                        @if ($line->customer_style_ref)
                            <br><span class="muted">Your style {{ $line->customer_style_ref }}</span>
                        @endif
                        @if ($line->artwork_code)
                            <br><span class="muted">Artwork {{ $line->artwork_code }} v{{ $line->artwork_version }}</span>
                        @endif
                        {{-- Stated per line because the band is per line: BR-52 settles delivery against it. --}}
                        <br><span class="muted">Tolerance +{{ rtrim(rtrim(number_format((float) $line->over_tolerance_pct, 2), '0'), '.') }}% / −{{ rtrim(rtrim(number_format((float) $line->under_tolerance_pct, 2), '0'), '.') }}%</span>
                    </td>
                    <td class="num">{{ $qty($line->ordered_qty) }}</td>
                    <td class="num">{{ number_format((float) $line->rate_per_m, 4) }}</td>
                    <td class="num">{{ $money($line->tooling_charge) }}</td>
                    <td>{{ $fmtDate($line->promised_date) }}</td>
                    <td class="num">{{ $money($line->line_total) }}</td>
                </tr>

                {{-- The schedule sits under its line, because that is the line it splits. --}}
                @foreach ($schedules[$line->line_no] ?? [] as $slot)
                    <tr>
                        <td></td>
                        <td colspan="5" class="muted">
                            Shipment {{ $slot->sequence_no }} — {{ $qty($slot->qty) }} pcs due {{ $fmtDate($slot->due_date) }}
                        </td>
                        <td></td>
                    </tr>
                @endforeach
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

    <div class="notice">
        Delivered quantity inside the tolerance band shown against each line completes that line
        in full, and is invoiced at the quantity actually delivered.
    </div>

    @if ($document->notes)
        <div class="terms">
            <p class="label">Notes</p>
            <div class="body">{{ $document->notes }}</div>
        </div>
    @endif

    <div class="signatures">
        <div>Confirmed for {{ $organisation['name'] }}</div>
        <div>{{ $document->customer_name }}</div>
    </div>
@endsection
