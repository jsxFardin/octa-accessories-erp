{{--
    The document that travels with the goods, and the one a gate guard and a receiving clerk both
    read. It carries no money: a challan proves what left and what arrived, and the invoice is a
    separate act. Vehicle, driver and gate pass are on it because that is what a checkpoint asks
    for, and the receiving signature block at the foot is the point of the whole sheet.
--}}
@extends('print.layout', [
    'title' => 'Delivery challan '.($document->number ?? ''),
    'documentTitle' => 'Delivery challan',
    'documentNumber' => $document->number ?? '(unnumbered)',
])

@section('doc-meta')
    <p>{{ $fmtDate($document->challan_date) }}</p>
    @if ($document->gate_pass_no)<p>Gate pass {{ $document->gate_pass_no }}</p>@endif
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Deliver to</p>
            <p class="name">{{ $document->customer_name }}</p>
            @if ($document->ship_line1)<p>{{ $document->ship_line1 }}</p>@endif
            @if ($document->ship_line2)<p>{{ $document->ship_line2 }}</p>@endif
            <p>{{ collect([$document->ship_city, $document->ship_district, $document->ship_postcode])->filter()->implode(', ') }}</p>
            @if ($document->ship_country)<p>{{ $document->ship_country }}</p>@endif
            @if ($document->customer_phone)<p>{{ $document->customer_phone }}</p>@endif
        </div>

        <div class="party">
            <table class="meta">
                <tr><th>Mode</th><td>{{ ucfirst(str_replace('_', ' ', $document->mode)) }}</td></tr>
                @if ($document->vehicle_no)
                    <tr><th>Vehicle</th><td>{{ $document->vehicle_no }}</td></tr>
                @endif
                @if ($document->driver_name)
                    <tr><th>Driver</th><td>{{ $document->driver_name }}@if ($document->driver_phone) · {{ $document->driver_phone }}@endif</td></tr>
                @endif
                @if ($document->courier_name)
                    <tr><th>Courier</th><td>{{ $document->courier_name }}</td></tr>
                @endif
                @if ($document->tracking_no)
                    <tr><th>Tracking</th><td>{{ $document->tracking_no }}</td></tr>
                @endif
                @if ($document->order_number)
                    <tr><th>Sales order</th><td>{{ $document->order_number }}</td></tr>
                @endif
                @if ($document->customer_po_no)
                    <tr><th>Your PO</th><td>{{ $document->customer_po_no }}</td></tr>
                @endif
                @if ($document->packing_list_number)
                    <tr><th>Packing list</th><td>{{ $document->packing_list_number }}</td></tr>
                @endif
            </table>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:8mm">#</th>
                <th>Product</th>
                <th style="width:30mm">Lot</th>
                <th class="num" style="width:24mm">Quantity</th>
                <th class="num" style="width:20mm">Cartons</th>
                <th style="width:26mm">Received</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line->line_no }}</td>
                    <td>
                        <strong>{{ $line->product_code }}</strong> {{ $line->order_description ?: $line->product_name }}
                        @if ($line->customer_style_ref)
                            <br><span class="muted">Your style {{ $line->customer_style_ref }}</span>
                        @endif
                    </td>
                    <td>{{ $line->lot_no ?? '—' }}</td>
                    <td class="num">{{ $qty($line->qty) }}</td>
                    <td class="num">{{ $line->cartons ?? '—' }}</td>
                    {{-- Left blank on purpose: the receiver writes what they actually counted. --}}
                    <td style="height:9mm"></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No lines on this challan.</td></tr>
            @endforelse
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3">Total</td>
                <td class="num">{{ $qty($document->total_qty) }}</td>
                <td class="num">{{ $document->total_cartons }}</td>
                <td></td>
            </tr>
        </tfoot>
    </table>

    <div class="notice">
        Not a tax invoice. Goods remain the property of {{ $organisation['legal_name'] ?: $organisation['name'] }}
        until paid for. Shortage or damage must be recorded on this challan at the time of receipt.
    </div>

    @if ($document->remarks)
        <div class="terms">
            <p class="label">Remarks</p>
            <div class="body">{{ $document->remarks }}</div>
        </div>
    @endif

    <div class="signatures">
        <div>Dispatched by</div>
        <div>Driver</div>
        <div>Received by (name, signature, date)</div>
    </div>
@endsection
