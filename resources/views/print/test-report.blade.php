{{--
    Goes to a brand's technical department, or into an audit file, so it reads as a certificate:
    what was tested, against which method, what the requirement was, what was measured, and
    pass or fail per line. Values come from the stored rows and nothing is recalculated — QC3
    makes an issued report immutable, and a reprint has to reproduce what was issued.
--}}
@extends('print.layout', [
    'title' => 'Test report '.($document->number ?? ''),
    'documentTitle' => 'Test report',
    'documentNumber' => $document->number ?? '(unnumbered)',
])

@section('doc-meta')
    <p>Tested {{ $fmtDate($document->tested_on) }}</p>
    @if ($document->issued_at)<p>Issued {{ $fmtDateTime($document->issued_at) }}</p>@endif
@endsection

@section('content')
    <div class="parties">
        <div class="party">
            <p class="label">Reported to</p>
            <p class="name">{{ $document->customer_name ?? 'Internal' }}</p>
            @if ($document->product_code)
                <p style="margin-top:2mm">{{ $document->product_code }} — {{ $document->product_name }}</p>
            @endif
            @if ($document->customer_style_ref)<p class="muted">Style {{ $document->customer_style_ref }}</p>@endif
        </div>

        <div class="party">
            <table class="meta">
                @if ($document->lot_no)
                    <tr><th>Lot</th><td>{{ $document->lot_no }}</td></tr>
                @endif
                @if ($document->job_card_number)
                    <tr><th>Job card</th><td>{{ $document->job_card_number }}</td></tr>
                @endif
                @if ($document->technician_name)
                    <tr><th>Tested by</th><td>{{ $document->technician_name }}</td></tr>
                @endif
                <tr><th>Overall result</th><td><strong>{{ strtoupper($document->overall_result) }}</strong></td></tr>
            </table>
        </div>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th style="width:22mm">Test</th>
                <th>Description</th>
                <th style="width:30mm">Method</th>
                <th style="width:26mm">Requirement</th>
                <th style="width:26mm">Result</th>
                <th style="width:18mm">Verdict</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($lines as $line)
                <tr>
                    <td><strong>{{ $line->test_code }}</strong></td>
                    <td>
                        {{ $line->test_name }}
                        @if ($line->remarks)<br><span class="muted">{{ $line->remarks }}</span>@endif
                    </td>
                    <td>{{ $line->method ?? '—' }}</td>
                    <td>{{ $line->pass_value ?? '—' }}@if ($line->unit && $line->pass_value) {{ $line->unit }}@endif</td>
                    <td>{{ $line->result_value }}@if ($line->unit) {{ $line->unit }}@endif</td>
                    <td>{{ strtoupper($line->result) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="empty">No tests recorded on this report.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if ($document->status === 'issued')
        <div class="notice">
            Issued report. The values above are those recorded at issue and are not restated on
            reprint. Results relate only to the lot or job identified above.
        </div>
    @endif

    @if ($document->remarks)
        <div class="terms">
            <p class="label">Remarks</p>
            <div class="body">{{ $document->remarks }}</div>
        </div>
    @endif

    <div class="signatures">
        <div>
            {{ $document->technician_name ?? 'Laboratory technician' }}
            @if ($document->technician_designation)<br><span class="muted">{{ $document->technician_designation }}</span>@endif
        </div>
        <div>Approved for {{ $organisation['name'] }}</div>
    </div>
@endsection
