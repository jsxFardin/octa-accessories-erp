<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>

    {{--
        Self-contained on purpose. A document is opened, printed and closed; loading the
        application's stylesheet would drag in the whole design system for a page that needs
        thirty rules, and any of them failing would show up on a customer's desk.

        Written to what dompdf understands, because the same view is rendered twice: once for a
        browser and once into a PDF file. dompdf supports neither flexbox nor CSS grid, so every
        side-by-side arrangement here is `display: table`, which both renderers agree on. The
        alternative — a second stylesheet for the PDF — is how the file and the page start
        disagreeing, and the whole point is that they cannot.

        DejaVu Sans first because it is the font dompdf ships with full Latin coverage for; a
        Bengali-script letterhead needs a Bengali font registered with dompdf, which no
        organisation profile asks for yet.
    --}}
    <style>
        @page { margin: 14mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "DejaVu Sans", -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.45;
            color: #1e2530;
        }

        .sheet { width: 100%; max-width: 190mm; margin: 0 auto; }

        /* ---- Letterhead ---------------------------------------------------------------- */
        .letterhead { display: table; width: 100%; border-bottom: 2px solid #1e2530; padding-bottom: 3mm; }
        .letterhead .org { display: table-cell; width: 58%; vertical-align: top; }
        .letterhead .doc { display: table-cell; width: 42%; vertical-align: top; text-align: right; }
        .letterhead h1 { margin: 0 0 1mm; font-size: 14pt; }
        .letterhead p { margin: 0; font-size: 8.5pt; color: #55607a; }
        .letterhead .doc h2 { margin: 0 0 1mm; font-size: 12.5pt; text-transform: uppercase; letter-spacing: .06em; }
        .letterhead .doc .number { font-size: 11.5pt; font-weight: 700; color: #1e2530; }
        .letterhead img { max-height: 18mm; max-width: 55mm; margin-bottom: 1.5mm; }

        /* ---- Parties and metadata ------------------------------------------------------ */
        .parties { display: table; width: 100%; margin: 5mm 0 0; }
        .parties .party { display: table-cell; width: 50%; vertical-align: top; padding-right: 8mm; }
        .parties .party:last-child { padding-right: 0; }
        .parties p { margin: 0; font-size: 9pt; }
        .parties .name { font-weight: 700; font-size: 10pt; }

        .label { font-size: 7.5pt; letter-spacing: .08em; text-transform: uppercase;
                 color: #7b869c; margin: 0 0 1mm; }

        /* A key/value block. A real table rather than a `dl` with grid: dompdf cannot lay a
           grid out, and a browser renders the table identically. */
        table.meta { width: 100%; border-collapse: collapse; font-size: 9pt; margin: 0; }
        table.meta th { text-align: left; font-weight: 400; color: #55607a; padding: .6mm 4mm .6mm 0; white-space: nowrap; }
        table.meta td { text-align: right; padding: .6mm 0; }

        /* ---- Line tables --------------------------------------------------------------- */
        table.lines { width: 100%; border-collapse: collapse; margin-top: 5mm; font-size: 9pt; }
        table.lines thead th { background: #f1f3f7; text-align: left; padding: 2mm; font-size: 8pt;
                               text-transform: uppercase; letter-spacing: .04em; color: #55607a;
                               border-bottom: 1px solid #c9d0dc; }
        table.lines tbody td { padding: 2mm; border-bottom: 1px solid #e6e9f0; vertical-align: top; }
        table.lines tfoot td { padding: 2mm; font-weight: 700; }
        table.lines thead { display: table-header-group; }
        table.lines tr { page-break-inside: avoid; }
        .num { text-align: right; }
        .muted { color: #7b869c; font-size: 8pt; }
        .empty { color: #7b869c; font-style: italic; }

        /* ---- Totals -------------------------------------------------------------------- */
        table.totals { border-collapse: collapse; margin-top: 4mm; margin-left: auto; width: 72mm; font-size: 9.5pt; }
        table.totals td { padding: 1.4mm 2mm; }
        table.totals td.num { text-align: right; }
        table.totals tr.grand td { border-top: 2px solid #1e2530; font-size: 11pt; font-weight: 700; }

        /* ---- Closing matter ------------------------------------------------------------ */
        .terms { margin-top: 6mm; font-size: 8.5pt; color: #3d4658; }
        .terms .body { white-space: pre-line; }

        /* A certification claim on a line is part of the order, not a note: it makes the GRN's
           certification fields mandatory (Gate 2). */
        .cert { color: #1a7f5a; font-size: 8.5pt; }

        /* Gate 1 on paper — the artwork version this run is bound to. */
        .gate { border: 1px solid #1a7f5a; background: #f0faf5; padding: 3mm; margin-top: 4mm; font-size: 9.5pt; }

        .notice { margin-top: 5mm; padding: 2.5mm 3mm; background: #fdf6e7; border-left: 2px solid #b8860b;
                  font-size: 8.5pt; color: #6b4e0b; }

        .signatures { display: table; width: 100%; margin-top: 14mm; }
        .signatures div { display: table-cell; width: 33%; border-top: 1px solid #9aa3b5; padding: 2mm 6mm 0 0;
                          font-size: 8.5pt; color: #55607a; vertical-align: top; }

        .page-note { margin-top: 8mm; border-top: 1px solid #e6e9f0; padding-top: 2mm; font-size: 7.5pt; color: #7b869c; }
        .page-note table { width: 100%; border-collapse: collapse; }
        .page-note td { padding: 0; }
        .page-note td.right { text-align: right; }

        /* ---- Screen-only toolbar ------------------------------------------------------- */
        .toolbar { background: #eef1f6; padding: 3mm; text-align: center; font-size: 10pt;
                   border-bottom: 1px solid #d7dce6; margin-bottom: 6mm; }
        .toolbar a, .toolbar button { font: inherit; padding: 1.5mm 4mm; border: 1px solid #0071be;
                                      background: #0071be; color: #fff; border-radius: 3px;
                                      cursor: pointer; text-decoration: none; }
        .toolbar a.secondary { background: #fff; color: #0071be; }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
    {{--
        Not wrapped in `@media print` alone: dompdf renders as screen media by default, so a
        print-only rule would put the toolbar in the middle of the customer's PDF.
    --}}
    @unless ($pdf ?? false)
        <div class="toolbar">
            <button onclick="window.print()">Print</button>
            @isset($pdfUrl)
                <a class="secondary" href="{{ $pdfUrl }}">Download PDF</a>
            @endisset
        </div>
    @endunless

    <div class="sheet">
        <div class="letterhead">
            <div class="org">
                @if ($organisation['logo_url'])
                    <img src="{{ $organisation['logo_url'] }}" alt="">
                @endif
                <h1>{{ $organisation['legal_name'] ?: $organisation['name'] }}</h1>
                @if ($organisation['address'])<p>{{ $organisation['address'] }}</p>@endif
                <p>
                    @if ($organisation['phone']){{ $organisation['phone'] }}@endif
                    @if ($organisation['email']) · {{ $organisation['email'] }}@endif
                </p>
                @if ($organisation['tax_id'])<p>BIN {{ $organisation['tax_id'] }}</p>@endif
            </div>

            <div class="doc">
                <h2>{{ $documentTitle }}</h2>
                <p class="number">{{ $documentNumber }}</p>
                @yield('doc-meta')
            </div>
        </div>

        @yield('content')

        <div class="page-note">
            <table>
                <tr>
                    <td>{{ $organisation['name'] }} · {{ $documentTitle }} {{ $documentNumber }}</td>
                    <td class="right">Printed {{ now($organisation['timezone'])->format($organisation['date_format'].' H:i') }}</td>
                </tr>
            </table>
        </div>
    </div>
</body>
</html>
