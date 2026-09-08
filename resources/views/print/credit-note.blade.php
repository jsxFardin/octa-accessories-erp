{{--
    A credit note is a small document that has to be unambiguous: which invoice it reduces, why,
    and who authorised it. The reason is a stored enum rather than free text, and a quality claim
    carries its NCR number, so the note is traceable back to the finding that caused it.
--}}
@extends('print.layout', [
    'title' => 'Credit note '.($document->number ?? ''),
    'documentTitle' => 'Credit note',
    'documentNumber' => $document->number ?? '(unnumbered)',
])

@section('doc-meta')
    <p>{{ $fmtDate($document->note_date) }}</p>
    <p>{{ ucfirst(str_replace('_', ' ', $document->status)) }}</p>
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Credited to</p>
            <p class="name">{{ $document->customer_name }}</p>
            @if ($document->customer_email)<p>{{ $document->customer_email }}</p>@endif
            @if ($document->customer_bin)<p>BIN {{ $document->customer_bin }}</p>@endif
        </div>

        <div class="party">
            <table class="meta">
                <tr><th>Reason</th><td>{{ ucfirst(str_replace('_', ' ', $document->reason)) }}</td></tr>
                @if ($document->invoice_number)
                    <tr><th>Against invoice</th><td>{{ $document->invoice_number }}</td></tr>
                    <tr><th>Invoice date</th><td>{{ $fmtDate($document->invoice_date) }}</td></tr>
                    <tr><th>Invoice total</th><td>{{ $document->currency }} {{ $money($document->invoice_total) }}</td></tr>
                @endif
                @if ($document->ncr_number)
                    <tr><th>NCR</th><td>{{ $document->ncr_number }}</td></tr>
                @endif
                @if ($document->approved_by_name)
                    <tr><th>Approved by</th><td>{{ $document->approved_by_name }}</td></tr>
                @endif
            </table>
        </div>
    </div>

    <table class="totals" style="margin-top:8mm">
        <tr class="grand">
            <td>Credit amount</td>
            <td class="num">{{ $document->currency }} {{ $money($document->amount) }}</td>
        </tr>
    </table>

    <div class="notice">
        This note reduces the balance of the invoice named above by the amount shown. It is not a
        refund instruction on its own.
    </div>

    @if ($document->remarks)
        <div class="terms">
            <p class="label">Remarks</p>
            <div class="body">{{ $document->remarks }}</div>
        </div>
    @endif

    <div class="signatures">
        <div>Authorised for {{ $organisation['name'] }}</div>
        <div>{{ $document->customer_name }}</div>
    </div>
@endsection
