{{--
    A money receipt is the one document a customer keeps to prove they paid, so it states the
    instrument (cheque number, transfer reference, LC) as well as the amount, and it lists what
    the money was set against. Money on account with nothing allocated is a legitimate state —
    the allocation table is allowed to be empty and says so rather than looking broken.
--}}
@extends('print.layout', [
    'title' => 'Money receipt '.($document->number ?? ''),
    'documentTitle' => 'Money receipt',
    'documentNumber' => $document->number ?? '(unnumbered)',
])

@section('doc-meta')
    <p>{{ $fmtDate($document->receipt_date) }}</p>
    @if ($document->status === 'bounced')<p>Instrument returned unpaid</p>@endif
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Received from</p>
            <p class="name">{{ $document->customer_name }}</p>
            @if ($document->customer_email)<p>{{ $document->customer_email }}</p>@endif
            @if ($document->customer_phone)<p>{{ $document->customer_phone }}</p>@endif
        </div>

        <div class="party">
            <table class="meta">
                <tr><th>Method</th><td>{{ ucfirst(str_replace('_', ' ', $document->method)) }}</td></tr>
                @if ($document->reference_no)
                    <tr><th>Reference</th><td>{{ $document->reference_no }}</td></tr>
                @endif
                @if ($document->bank_name)
                    <tr><th>Bank</th><td>{{ $document->bank_name }}</td></tr>
                @endif
                <tr><th>Currency</th><td>{{ $document->currency }}</td></tr>
            </table>
        </div>
    </div>

    <table class="totals" style="margin-top:8mm">
        <tr class="grand">
            <td>Amount received</td>
            <td class="num">{{ $document->currency }} {{ $money($document->amount) }}</td>
        </tr>
    </table>

    <p class="label" style="margin-top:8mm">Set against</p>
    <table class="lines" style="margin-top:1mm">
        <thead>
            <tr>
                <th>Invoice</th>
                <th style="width:28mm">Invoice date</th>
                <th class="num" style="width:32mm">Invoice total</th>
                <th class="num" style="width:32mm">Allocated</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td>{{ $line->invoice_number }}</td>
                    <td>{{ $fmtDate($line->invoice_date) }}</td>
                    <td class="num">{{ $money($line->invoice_total) }}</td>
                    <td class="num">{{ $money($line->amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="empty">Held on account — not yet set against an invoice.</td></tr>
            @endforelse
        </tbody>
        @if ((float) $document->allocated_amount < (float) $document->amount)
            <tfoot>
                <tr>
                    <td colspan="3">Unallocated balance on account</td>
                    <td class="num">{{ $money((float) $document->amount - (float) $document->allocated_amount) }}</td>
                </tr>
            </tfoot>
        @endif
    </table>

    @if (in_array($document->method, ['cheque', 'bank_transfer'], true))
        <div class="notice">
            Subject to realisation of the instrument shown above.
        </div>
    @endif

    @if ($document->remarks)
        <div class="terms">
            <p class="label">Remarks</p>
            <div class="body">{{ $document->remarks }}</div>
        </div>
    @endif

    <div class="signatures">
        <div>Received for {{ $organisation['name'] }}</div>
    </div>
@endsection
