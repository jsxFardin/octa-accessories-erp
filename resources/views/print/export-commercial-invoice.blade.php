{{--
    The commercial invoice for an export shipment, drafted from the delivery notes on it (DF-6
    AC1). A draft: the merchandiser checks it against the credit's wording before it goes to the
    bank. The goods are totalled by what was sold, not by carton; the LC, the EXP and the
    transport document are on its face because those are what the bank reads it against.
--}}
@extends('print.layout', [
    'title' => 'Commercial invoice '.($document->number ?? ''),
    'documentTitle' => 'Commercial invoice',
    'documentNumber' => ($document->number ?? '(draft)').'/CI',
])

@section('doc-meta')
    <p>{{ $fmtDate($document->shipped_on ?? $document->etd ?? now()) }}</p>
    <p class="muted">Draft from shipment data</p>
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Buyer / applicant</p>
            <p class="name">{{ $document->customer_name }}</p>
            @if ($document->customer_email)<p>{{ $document->customer_email }}</p>@endif
            @if ($document->customer_phone)<p>{{ $document->customer_phone }}</p>@endif
            <p class="label" style="margin-top:3mm">Consignee</p>
            @if ($document->ship_line1)<p>{{ $document->ship_line1 }}</p>@endif
            @if ($document->ship_line2)<p>{{ $document->ship_line2 }}</p>@endif
            <p>{{ collect([$document->ship_city, $document->ship_district, $document->ship_postcode, $document->ship_country])->filter()->implode(', ') }}</p>
        </div>

        <div class="party">
            <table class="meta">
                <tr><th>Currency</th><td>{{ $document->currency }}</td></tr>
                @if ($document->incoterm)<tr><th>Terms</th><td>{{ $document->incoterm }} {{ $document->port_of_loading }}</td></tr>@endif
                @if ($document->lc_no)<tr><th>LC no.</th><td>{{ $document->lc_no }}@if ($document->lc_date) dated {{ $fmtDate($document->lc_date) }}@endif</td></tr>@endif
                @if ($document->issuing_bank)<tr><th>Issuing bank</th><td>{{ $document->issuing_bank }}</td></tr>@endif
                @if ($document->order_numbers)<tr><th>Sales order</th><td>{{ $document->order_numbers }}</td></tr>@endif
                @if ($document->customer_po_numbers)<tr><th>Buyer PO</th><td>{{ $document->customer_po_numbers }}</td></tr>@endif
                @if ($document->exp_form_no)<tr><th>EXP no.</th><td>{{ $document->exp_form_no }}</td></tr>@endif
                @if ($document->transport_doc_no)<tr><th>{{ $document->mode === 'air' ? 'AWB' : 'BL' }} no.</th><td>{{ $document->transport_doc_no }}</td></tr>@endif
                @if ($document->vessel_or_flight)<tr><th>{{ $document->mode === 'air' ? 'Flight' : 'Vessel' }}</th><td>{{ $document->vessel_or_flight }}</td></tr>@endif
                @if ($document->port_of_loading)<tr><th>From</th><td>{{ $document->port_of_loading }}</td></tr>@endif
                @if ($document->port_of_discharge)<tr><th>To</th><td>{{ $document->port_of_discharge }}@if ($document->final_destination) · {{ $document->final_destination }}@endif</td></tr>@endif
            </table>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:8mm">#</th>
                <th>Description of goods</th>
                <th class="num" style="width:22mm">Cartons</th>
                <th class="num" style="width:24mm">Quantity</th>
                <th class="num" style="width:34mm; white-space:nowrap">Rate per 1,000 pcs</th>
                <th class="num" style="width:28mm">Amount</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line->line_no }}</td>
                    <td>
                        <strong>{{ $line->product_code }}</strong> {{ $line->description ?? $line->product_name }}
                        @if ($line->customer_style_ref)<br><span class="muted">Style {{ $line->customer_style_ref }}</span>@endif
                    </td>
                    <td class="num">{{ (int) $line->cartons ?: '—' }}</td>
                    <td class="num">{{ $qty($line->qty) }} pcs</td>
                    <td class="num">{{ $line->rate_per_m === null ? '—' : number_format((float) $line->rate_per_m, (int) ($organisation['decimal_places'] ?? 2)) }}</td>
                    <td class="num">{{ $money($line->amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No delivery notes on this shipment yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="totals">
        <tr class="grand"><td>Total {{ $document->incoterm ?? '' }}</td><td class="num">{{ $document->currency }} {{ $money($document->total) }}</td></tr>
    </table>

    @if ($invoices->isNotEmpty())
        <p class="muted" style="margin-top:3mm">Tax invoice{{ $invoices->count() === 1 ? '' : 's' }}: {{ $invoices->map(fn ($i) => $i->number)->filter()->implode(', ') }}</p>
    @endif

    <div class="terms">
        <p class="label">Declaration</p>
        <div class="body">We declare that this invoice shows the actual price of the goods described, that all particulars are true and correct, and that the goods are of {{ $document->origin_country ?? 'Bangladesh' }} origin.</div>
    </div>

    <div class="signatures">
        <div>For {{ $organisation['name'] }}</div>
        <div>Authorised signatory</div>
    </div>
@endsection
