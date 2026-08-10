<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>

    {{--
        Self-contained on purpose. A print view is opened, printed and closed; loading the
        application's stylesheet would drag in the whole design system for a page that needs
        eleven rules, and any of them failing would show up on a customer's desk.
    --}}
    <style>
        @page { margin: 14mm; }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font: 11pt/1.45 -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            color: #1e2530;
        }

        .sheet { max-width: 190mm; margin: 0 auto; padding: 8mm 0; }

        header { display: flex; justify-content: space-between; gap: 16mm; align-items: flex-start;
                 border-bottom: 2px solid #1e2530; padding-bottom: 4mm; }
        header .org h1 { margin: 0 0 1mm; font-size: 15pt; }
        header .org p, header .doc p { margin: 0; font-size: 9pt; color: #55607a; }
        header .doc { text-align: right; }
        header .doc h2 { margin: 0 0 1mm; font-size: 13pt; text-transform: uppercase; letter-spacing: .06em; }
        header .doc .number { font-size: 12pt; font-weight: 700; }
        header img { max-height: 18mm; max-width: 55mm; object-fit: contain; }

        .parties { display: flex; gap: 10mm; margin: 6mm 0; }
        .parties section { flex: 1; }
        .label { font-size: 7.5pt; letter-spacing: .08em; text-transform: uppercase; color: #7b869c; margin-bottom: 1mm; }
        .parties .name { font-weight: 600; }

        dl.meta { display: grid; grid-template-columns: auto auto; gap: .8mm 4mm; margin: 0; font-size: 9.5pt; }
        dl.meta dt { color: #55607a; }
        dl.meta dd { margin: 0; text-align: right; font-variant-numeric: tabular-nums; }

        table { width: 100%; border-collapse: collapse; margin-top: 4mm; font-size: 9.5pt; }
        thead th { background: #f1f3f7; text-align: left; padding: 2mm; font-size: 8.5pt;
                   text-transform: uppercase; letter-spacing: .04em; color: #55607a;
                   border-bottom: 1px solid #c9d0dc; }
        tbody td { padding: 2mm; border-bottom: 1px solid #e6e9f0; vertical-align: top; }
        tfoot td { padding: 2mm; font-weight: 600; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        tr { break-inside: avoid; }
        thead { display: table-header-group; }

        .totals { margin-left: auto; margin-top: 4mm; width: 70mm; }
        .totals .grand { border-top: 2px solid #1e2530; font-size: 11pt; }

        .terms { margin-top: 8mm; font-size: 9pt; color: #3d4658; white-space: pre-line; }
        .signatures { display: flex; gap: 14mm; margin-top: 16mm; }
        .signatures div { flex: 1; border-top: 1px solid #9aa3b5; padding-top: 2mm;
                          font-size: 8.5pt; color: #55607a; }

        footer.page-note { margin-top: 10mm; border-top: 1px solid #e6e9f0; padding-top: 2mm;
                           font-size: 8pt; color: #7b869c; display: flex; justify-content: space-between; }

        .toolbar { position: sticky; top: 0; background: #eef1f6; padding: 3mm; text-align: center;
                   font-size: 10pt; border-bottom: 1px solid #d7dce6; }
        .toolbar button { font: inherit; padding: 1.5mm 4mm; border: 1px solid #0071be; background: #0071be;
                          color: #fff; border-radius: 3px; cursor: pointer; }
        @media print { .toolbar { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">Print</button>
        <span style="margin-left:3mm;color:#55607a">Use your browser's “Save as PDF” to keep a copy.</span>
    </div>

    <div class="sheet">
        <header>
            <div class="org">
                @if ($organisation['logo_url'])
                    <img src="{{ $organisation['logo_url'] }}" alt="">
                @endif
                <h1>{{ $organisation['legal_name'] ?: $organisation['name'] }}</h1>
                @if ($organisation['address'])<p>{{ $organisation['address'] }}</p>@endif
                <p>
                    @if ($organisation['phone']) {{ $organisation['phone'] }} @endif
                    @if ($organisation['email']) · {{ $organisation['email'] }} @endif
                </p>
                @if ($organisation['tax_id'])<p>BIN {{ $organisation['tax_id'] }}</p>@endif
            </div>

            <div class="doc">
                <h2>{{ $documentTitle }}</h2>
                <p class="number">{{ $documentNumber }}</p>
                @yield('doc-meta')
            </div>
        </header>

        @yield('content')

        <footer class="page-note">
            <span>{{ $organisation['name'] }} · {{ $documentTitle }} {{ $documentNumber }}</span>
            <span>Printed {{ now($organisation['timezone'])->format($organisation['date_format'].' H:i') }}</span>
        </footer>
    </div>
</body>
</html>
