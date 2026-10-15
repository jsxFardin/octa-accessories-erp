{{--
    The packing list for an export shipment (DF-6 AC1): carton by carton where the notes carry
    cartons, line by line where they do not; the container, seal and transport document on its
    face because the forwarder and the bank read them there.
--}}
@extends('print.layout', [
    'title' => 'Packing list '.($document->number ?? ''),
    'documentTitle' => 'Packing list',
    'documentNumber' => ($document->number ?? '(draft)').'/PL',
])

@section('doc-meta')
    <p>{{ $fmtDate($document->shipped_on ?? $document->etd ?? now()) }}</p>
    <p>{{ $document->total_cartons }} carton{{ (int) $document->total_cartons === 1 ? '' : 's' }} · {{ $qty($document->total_qty) }} pcs</p>
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Consignee</p>
            <p class="name">{{ $document->customer_name }}</p>
            @if ($document->ship_line1)<p>{{ $document->ship_line1 }}</p>@endif
            @if ($document->ship_line2)<p>{{ $document->ship_line2 }}</p>@endif
            <p>{{ collect([$document->ship_city, $document->ship_district, $document->ship_postcode, $document->ship_country])->filter()->implode(', ') }}</p>
        </div>
        <div class="party">
            <table class="meta">
                @if ($document->lc_no)<tr><th>LC no.</th><td>{{ $document->lc_no }}</td></tr>@endif
                @if ($document->order_numbers)<tr><th>Sales order</th><td>{{ $document->order_numbers }}</td></tr>@endif
                @if ($document->customer_po_numbers)<tr><th>Buyer PO</th><td>{{ $document->customer_po_numbers }}</td></tr>@endif
                @if ($document->challan_numbers)<tr><th>Delivery notes</th><td>{{ $document->challan_numbers }}</td></tr>@endif
                @if ($document->container_no)<tr><th>Container</th><td>{{ $document->container_no }}@if ($document->seal_no) · seal {{ $document->seal_no }}@endif</td></tr>@endif
                @if ($document->transport_doc_no)<tr><th>{{ $document->mode === 'air' ? 'AWB' : 'BL' }} no.</th><td>{{ $document->transport_doc_no }}</td></tr>@endif
                @if ($document->vessel_or_flight)<tr><th>{{ $document->mode === 'air' ? 'Flight' : 'Vessel' }}</th><td>{{ $document->vessel_or_flight }}</td></tr>@endif
                @if ($document->gross_weight_kg !== null)<tr><th>Gross weight</th><td>{{ number_format((float) $document->gross_weight_kg, 3) }} kg</td></tr>@endif
                @if ($document->net_weight_kg !== null)<tr><th>Net weight</th><td>{{ number_format((float) $document->net_weight_kg, 3) }} kg</td></tr>@endif
                @if ($document->cbm !== null)<tr><th>Volume</th><td>{{ number_format((float) $document->cbm, 3) }} CBM</td></tr>@endif
            </table>
        </div>
    </div>

    @if ($cartons->isNotEmpty())
        <table class="lines">
            <thead>
                <tr>
                    <th style="width:18mm">Carton</th>
                    <th>Contents</th>
                    <th class="num" style="width:22mm">Quantity</th>
                    <th style="width:36mm">Weight / size</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($cartons as $carton)
                    <tr>
                        <td><strong>{{ $carton->carton_no }}</strong>@if ($carton->barcode)<br><span class="muted">{{ $carton->barcode }}</span>@endif</td>
                        <td>
                            @forelse ($contents[$carton->id] ?? [] as $content)
                                <strong>{{ $content->product_code }}</strong> {{ $content->product_name }}@if ($content->colourway) · {{ $content->colourway }}@endif
                                @if ($content->lot_no)<span class="muted"> · lot {{ $content->lot_no }}</span>@endif<br>
                            @empty
                                <span class="muted">Empty</span>
                            @endforelse
                        </td>
                        <td class="num">{{ $qty(collect($contents[$carton->id] ?? [])->sum('qty')) }}</td>
                        <td>
                            @if ($carton->gross_weight_kg !== null){{ number_format((float) $carton->gross_weight_kg, 3) }} kg gross @endif
                            @if ($carton->net_weight_kg !== null)<br>{{ number_format((float) $carton->net_weight_kg, 3) }} kg net @endif
                            @if ($carton->length_cm !== null)<br>{{ (float) $carton->length_cm }} × {{ (float) $carton->width_cm }} × {{ (float) $carton->height_cm }} cm @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <table class="lines">
            <thead>
                <tr>
                    <th style="width:28mm">Delivery note</th>
                    <th>Goods</th>
                    <th class="num" style="width:22mm">Cartons</th>
                    <th class="num" style="width:24mm">Quantity</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($lines as $line)
                    <tr>
                        <td>{{ $line->challan_number }}</td>
                        <td><strong>{{ $line->product_code }}</strong> {{ $line->product_name }}</td>
                        <td class="num">{{ (int) $line->cartons ?: '—' }}</td>
                        <td class="num">{{ $qty($line->qty) }} pcs</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="muted">No delivery notes on this shipment yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    @endif

    <div class="signatures">
        <div>For {{ $organisation['name'] }}</div>
        <div>Authorised signatory</div>
    </div>
@endsection
