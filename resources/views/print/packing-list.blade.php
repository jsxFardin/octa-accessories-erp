{{--
    Carton by carton, because that is how the consignment is checked at the far end: someone opens
    box 7 of 12 and expects the paper to say what is inside it, in which lot and which colourway.
    Weights and dimensions are here for the freight forwarder, and the certification claim is here
    because a GRS or OCS consignment is only claimable if the paperwork travelling with it says so.
--}}
@extends('print.layout', [
    'title' => 'Packing list '.($document->number ?? ''),
    'documentTitle' => 'Packing list',
    'documentNumber' => $document->number ?? '(unnumbered)',
])

@section('doc-meta')
    <p>Packed {{ $fmtDate($document->packed_on) }}</p>
    <p>{{ $document->total_cartons }} carton{{ (int) $document->total_cartons === 1 ? '' : 's' }}</p>
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Ship to</p>
            <p class="name">{{ $document->customer_name }}</p>
            @if ($document->ship_line1)<p>{{ $document->ship_line1 }}</p>@endif
            @if ($document->ship_line2)<p>{{ $document->ship_line2 }}</p>@endif
            <p>{{ collect([$document->ship_city, $document->ship_district, $document->ship_postcode])->filter()->implode(', ') }}</p>
            @if ($document->ship_country)<p>{{ $document->ship_country }}</p>@endif
        </div>

        <div class="party">
            <table class="meta">
                @if ($document->order_number)
                    <tr><th>Sales order</th><td>{{ $document->order_number }}</td></tr>
                @endif
                @if ($document->customer_po_no)
                    <tr><th>Your PO</th><td>{{ $document->customer_po_no }}</td></tr>
                @endif
                <tr><th>Total quantity</th><td>{{ $qty($document->total_qty) }} pcs</td></tr>
                @if ($document->gross_weight_kg !== null)
                    <tr><th>Gross weight</th><td>{{ number_format((float) $document->gross_weight_kg, 3) }} kg</td></tr>
                @endif
                @if ($document->net_weight_kg !== null)
                    <tr><th>Net weight</th><td>{{ number_format((float) $document->net_weight_kg, 3) }} kg</td></tr>
                @endif
                @if ($document->cert_claim_scheme)
                    <tr><th>Claim</th><td>{{ strtoupper($document->cert_claim_scheme) }} {{ rtrim(rtrim(number_format((float) $document->cert_claim_pct, 2), '0'), '.') }}%</td></tr>
                @endif
            </table>
        </div>
    </div>

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
            @forelse ($cartons as $carton)
                <tr>
                    <td>
                        <strong>{{ $carton->carton_no }}</strong>
                        @if ($carton->barcode)<br><span class="muted">{{ $carton->barcode }}</span>@endif
                    </td>
                    <td>
                        @forelse ($contents[$carton->id] ?? [] as $content)
                            <strong>{{ $content->product_code }}</strong> {{ $content->product_name }}
                            @if ($content->colourway) · {{ $content->colourway }}@endif
                            @if ($content->lot_no) · lot {{ $content->lot_no }}@endif
                            @if ($content->bundles) · {{ $content->bundles }} bundle{{ (int) $content->bundles === 1 ? '' : 's' }}@endif
                            <span class="muted">({{ $qty($content->qty) }} pcs)</span>
                            @if (! $loop->last)<br>@endif
                        @empty
                            <span class="empty">Empty carton</span>
                        @endforelse
                    </td>
                    <td class="num">{{ $qty(collect($contents[$carton->id] ?? [])->sum(fn ($content) => (float) $content->qty)) }}</td>
                    <td class="muted">
                        @if ($carton->gross_weight_kg !== null)
                            {{ number_format((float) $carton->gross_weight_kg, 3) }} kg gross
                        @endif
                        @if ($carton->net_weight_kg !== null)
                            <br>{{ number_format((float) $carton->net_weight_kg, 3) }} kg net
                        @endif
                        @if ($carton->length_cm !== null && $carton->width_cm !== null && $carton->height_cm !== null)
                            <br>{{ rtrim(rtrim(number_format((float) $carton->length_cm, 2), '0'), '.') }}
                            × {{ rtrim(rtrim(number_format((float) $carton->width_cm, 2), '0'), '.') }}
                            × {{ rtrim(rtrim(number_format((float) $carton->height_cm, 2), '0'), '.') }} cm
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">No cartons packed against this list.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td>{{ $cartons->count() }} carton{{ $cartons->count() === 1 ? '' : 's' }}</td>
                <td></td>
                <td class="num">{{ $qty($document->total_qty) }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    @if ($document->remarks)
        <div class="terms">
            <p class="label">Remarks</p>
            <div class="body">{{ $document->remarks }}</div>
        </div>
    @endif

    <div class="signatures">
        <div>Packed by</div>
        <div>Checked by</div>
        <div>Received by</div>
    </div>
@endsection
