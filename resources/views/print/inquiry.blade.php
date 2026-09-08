{{--
    An acknowledgement, not a quotation. It says "we have your enquiry, here is what we recorded,
    here is who is handling it" — which is what a merchandiser is asked for on the day the
    enquiry arrives and a price is still weeks away. Target rates appear because the customer
    stated them; nothing here is an offer.
--}}
@extends('print.layout', [
    'title' => 'Inquiry '.($document->number ?? ''),
    'documentTitle' => 'Inquiry acknowledgement',
    'documentNumber' => $document->number ?? '(unnumbered)',
])

@section('doc-meta')
    <p>Received {{ $fmtDate($document->inquiry_date) }}</p>
    @if ($document->required_by)<p>Required by {{ $fmtDate($document->required_by) }}</p>@endif
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Enquiry from</p>
            <p class="name">{{ $document->customer_name }}</p>
            @if ($document->contact_name)
                <p>{{ $document->contact_name }}@if ($document->contact_designation), {{ $document->contact_designation }}@endif</p>
            @endif
            @if ($document->customer_email)<p>{{ $document->customer_email }}</p>@endif
            @if ($document->customer_phone)<p>{{ $document->customer_phone }}</p>@endif
        </div>

        <div class="party">
            <table class="meta">
                @if ($document->brand_name)
                    <tr><th>Brand</th><td>{{ $document->brand_name }}</td></tr>
                @endif
                @if ($document->source_name)
                    <tr><th>Received via</th><td>{{ $document->source_name }}</td></tr>
                @endif
                <tr><th>Handled by</th><td>{{ $document->merchandiser_name ?? 'To be assigned' }}</td></tr>
                <tr><th>Status</th><td>{{ ucfirst(str_replace('_', ' ', $document->status)) }}</td></tr>
            </table>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:8mm">#</th>
                <th>Description</th>
                <th style="width:34mm">Type</th>
                <th class="num" style="width:24mm">Quantity</th>
                <th class="num" style="width:28mm">Target rate / 1,000</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line->line_no }}</td>
                    <td>
                        @if ($line->product_code)<strong>{{ $line->product_code }}</strong>@endif
                        {{ $line->description }}
                        @if ($line->notes)<br><span class="muted">{{ $line->notes }}</span>@endif
                    </td>
                    <td>{{ $line->product_type_name ?? '—' }}</td>
                    <td class="num">{{ $qty($line->qty) }}</td>
                    {{-- The customer's number, not ours. Blank where they did not name one. --}}
                    <td class="num">{{ $line->target_rate_per_m === null ? '—' : number_format((float) $line->target_rate_per_m, 4) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="empty">No lines recorded against this enquiry.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="notice">
        Quantities and target rates are as received from you and are not a price offer. A costed
        quotation follows separately.
    </div>

    @if ($document->notes)
        <div class="terms">
            <p class="label">Notes</p>
            <div class="body">{{ $document->notes }}</div>
        </div>
    @endif

    <div class="signatures">
        <div>Acknowledged for {{ $organisation['name'] }}</div>
    </div>
@endsection
