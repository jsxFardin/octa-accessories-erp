<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>

    {{--
        Lot labels. Self-contained like every other printout: opened, printed, closed.

        One label is 70 × 42 mm, three across on A4 — a common sheet of adhesive labels. On a
        roll-fed label printer, set the printer's paper to one label and each label breaks onto
        its own. The barcode is Code 128 of the lot's barcode value, which is what the scan
        fields on the transfer, adjustment and count screens look up.
    --}}
    <style>
        @page { margin: 8mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "DejaVu Sans", -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #111; }

        .toolbar { padding: 10px 12px; background: #f1f5f9; border-bottom: 1px solid #cbd5e1; font-size: 13px; }
        .toolbar button { font: inherit; padding: 6px 14px; border: 1px solid #0b5fa5; background: #0b5fa5; color: #fff; border-radius: 6px; cursor: pointer; }
        .toolbar span { margin-left: 12px; color: #334155; }

        .sheet { padding: 4mm; }
        .label {
            display: inline-block; vertical-align: top;
            width: 70mm; height: 42mm; margin: 0 2mm 2mm 0; padding: 2.5mm 3mm;
            border: 1px dashed #94a3b8; overflow: hidden; page-break-inside: avoid;
        }
        .lot { font-size: 13pt; font-weight: 700; letter-spacing: .02em; }
        .what { margin-top: .5mm; font-size: 8pt; line-height: 1.25; height: 7.5mm; overflow: hidden; }
        .what b { font-size: 9pt; }
        .bars { margin-top: 1mm; height: 12mm; }
        .bars svg { height: 12mm; width: 100%; }
        .facts { margin-top: 1mm; font-size: 7.5pt; line-height: 1.35; }
        .facts span { display: inline-block; margin-right: 3mm; white-space: nowrap; }
        .facts b { font-weight: 700; }

        @media print {
            .toolbar { display: none; }
            .sheet { padding: 0; }
            .label { border-color: transparent; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" onclick="window.print()">Print labels</button>
        <span>{{ $labels->count() }} {{ $labels->count() === 1 ? 'label' : 'labels' }} · 70 × 42 mm each, three across on A4</span>
    </div>

    <div class="sheet">
        @foreach ($labels as $label)
            <div class="label">
                <div class="lot">{{ $label['lot_no'] }}</div>
                <div class="what"><b>{{ $label['code'] }}</b> {{ $label['name'] }}</div>
                <div class="bars" role="img" aria-label="Barcode {{ $label['barcode_value'] }}">{!! $label['barcode_svg'] !!}</div>
                <div class="facts">
                    <span><b>{{ $label['qty'] }}</b> {{ $label['uom'] }}</span>
                    @if ($label['roll_length'])<span>Roll <b>{{ $label['roll_length'] }}</b></span>@endif
                    @if ($label['shade'])<span>Shade <b>{{ $label['shade'] }}</b></span>@endif
                    @if ($label['warehouse'])<span>Store <b>{{ $label['warehouse'] }}</b></span>@endif
                    @if ($label['received_on'])<span>Received <b>{{ $label['received_on'] }}</b></span>@endif
                    @if ($label['expiry_date'])<span>Use by <b>{{ $label['expiry_date'] }}</b></span>@endif
                    @if ($label['batch'])<span>Batch <b>{{ $label['batch'] }}</b></span>@endif
                    @if ($label['scheme'])<span><b>{{ $label['scheme'] }}</b></span>@endif
                </div>
            </div>
        @endforeach
    </div>
</body>
</html>
