{{--
    Sent to a supplier who has to quote against it, so it carries no rates at all — a price the
    buyer already has in mind anchors the answer. It states what is wanted, how much, in which
    unit, and by when a reply is expected, and leaves columns for the supplier to write in.
--}}
@extends('print.layout', [
    'title' => 'Request for quotation '.($document->number ?? ''),
    'documentTitle' => 'Request for quotation',
    'documentNumber' => $document->number ?? '(unnumbered)',
])

@section('doc-meta')
    <p>Issued {{ $fmtDate($document->issued_on) }}</p>
    @if ($document->respond_by)<p>Reply by {{ $fmtDate($document->respond_by) }}</p>@endif
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Supplier</p>
            {{-- Deliberately blank: one RFQ goes to several suppliers, and naming one on the
                 sheet would mean printing it once per supplier. --}}
            <p class="name" style="border-bottom:1px solid #9aa3b5;padding-bottom:4mm">&nbsp;</p>
            <p class="muted" style="margin-top:1mm">Supplier name and contact</p>
        </div>

        <div class="party">
            <p class="label">Deliver to</p>
            <p class="name">{{ $document->unit_name ?? $organisation['name'] }}</p>
            @if ($document->unit_address)<p>{{ $document->unit_address }}</p>@endif

            <table class="meta" style="margin-top:3mm">
                @if ($document->requisition_number)
                    <tr><th>Our requisition</th><td>{{ $document->requisition_number }}</td></tr>
                @endif
                @if ($document->raised_by_name)
                    <tr><th>Buyer</th><td>{{ $document->raised_by_name }}</td></tr>
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
                <th class="num" style="width:24mm">Your rate</th>
                <th style="width:24mm">Lead time</th>
                <th style="width:26mm">Remarks</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line->line_no }}</td>
                    <td>
                        <strong>{{ $line->item_code }}</strong> {{ $line->item_name }}
                        @if ($line->item_description)<br><span class="muted">{{ $line->item_description }}</span>@endif
                        @if ($line->shade_code)<br><span class="muted">Shade {{ $line->shade_code }}</span>@endif
                    </td>
                    <td class="num">{{ $qty($line->qty) }} {{ $line->uom }}</td>
                    {{-- Three columns for the supplier to complete. --}}
                    <td style="height:9mm"></td>
                    <td></td>
                    <td></td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No lines on this request.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="notice">
        Please quote rate, lead time, minimum order quantity and validity against each line, and
        state any certification (GRS, OCS, OEKO-TEX) you can supply with documentation.
    </div>

    <div class="signatures">
        <div>Issued by {{ $organisation['name'] }}</div>
        <div>Supplier quotation signed by</div>
    </div>
@endsection
