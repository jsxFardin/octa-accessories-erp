{{--
    A certificate of origin draft for an export shipment (DF-6 AC1): the exporter, the consignee,
    the transport and the goods, declared as of Bangladesh origin. The chamber's form is the
    legal document; this is what is typed onto it.
--}}
@extends('print.layout', [
    'title' => 'Certificate of origin '.($document->number ?? ''),
    'documentTitle' => 'Certificate of origin',
    'documentNumber' => ($document->number ?? '(draft)').'/COO',
])

@section('doc-meta')
    <p>{{ $fmtDate($document->shipped_on ?? $document->etd ?? now()) }}</p>
    <p class="muted">Draft for the chamber's form</p>
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Exporter</p>
            <p class="name">{{ $organisation['name'] }}</p>
            @if (!empty($organisation['address']))<p>{{ $organisation['address'] }}</p>@endif
            <p class="label" style="margin-top:3mm">Consignee</p>
            <p class="name">{{ $document->customer_name }}</p>
            @if ($document->ship_line1)<p>{{ $document->ship_line1 }}</p>@endif
            @if ($document->ship_line2)<p>{{ $document->ship_line2 }}</p>@endif
            <p>{{ collect([$document->ship_city, $document->ship_district, $document->ship_postcode, $document->ship_country])->filter()->implode(', ') }}</p>
        </div>
        <div class="party">
            <table class="meta">
                <tr><th>Country of origin</th><td>{{ $document->origin_country ?? 'Bangladesh' }}</td></tr>
                @if ($document->ship_country)<tr><th>Country of destination</th><td>{{ $document->ship_country }}</td></tr>@endif
                @if ($document->mode)<tr><th>Transport</th><td>{{ ucfirst($document->mode) }}@if ($document->vessel_or_flight) · {{ $document->vessel_or_flight }}@endif</td></tr>@endif
                @if ($document->port_of_loading)<tr><th>From</th><td>{{ $document->port_of_loading }}</td></tr>@endif
                @if ($document->port_of_discharge)<tr><th>To</th><td>{{ $document->port_of_discharge }}</td></tr>@endif
                @if ($document->transport_doc_no)<tr><th>{{ $document->mode === 'air' ? 'AWB' : 'BL' }} no.</th><td>{{ $document->transport_doc_no }}</td></tr>@endif
                @if ($document->lc_no)<tr><th>LC no.</th><td>{{ $document->lc_no }}</td></tr>@endif
                @if ($document->exp_form_no)<tr><th>EXP no.</th><td>{{ $document->exp_form_no }}</td></tr>@endif
                <tr><th>Invoice</th><td>{{ ($document->number ?? '(draft)').'/CI' }}</td></tr>
            </table>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:8mm">#</th>
                <th>Marks, numbers and description of goods</th>
                <th class="num" style="width:22mm">Cartons</th>
                <th class="num" style="width:26mm">Quantity</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line->line_no }}</td>
                    <td><strong>{{ $line->product_code }}</strong> {{ $line->description ?? $line->product_name }}</td>
                    <td class="num">{{ (int) $line->cartons ?: '—' }}</td>
                    <td class="num">{{ $qty($line->qty) }} pcs</td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">No delivery notes on this shipment yet.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="terms">
        <p class="label">Declaration by the exporter</p>
        <div class="body">The undersigned declares that the goods described above were produced in {{ $document->origin_country ?? 'Bangladesh' }} and that the particulars given are correct.</div>
    </div>

    <div class="signatures">
        <div>For {{ $organisation['name'] }}</div>
        <div>Certifying authority</div>
    </div>
@endsection
