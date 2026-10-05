{{--
    A report on paper.

    Self-contained, like the document print views: a page that is opened, printed and closed
    should not depend on the application's stylesheet arriving intact.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>

    <style>
        @page { size: A4 landscape; margin: 10mm; }

        * { box-sizing: border-box; }

        body { margin: 0; padding: 6mm; font: 9pt/1.4 -apple-system, "Segoe UI", Roboto, "Noto Sans Bengali", Arial, sans-serif; color: #1e2530; }

        header { border-bottom: 1.5px solid #1e2530; padding-bottom: 3mm; margin-bottom: 4mm; }
        header h1 { margin: 0 0 1mm; font-size: 14pt; }
        header p { margin: 0; font-size: 9pt; color: #3f4a5f; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 1.5mm 2mm; font-size: 8pt; color: #3f4a5f; background: #f1f3f7; border-bottom: 1px solid #9aa5b8; }
        td { padding: 1.5mm 2mm; border-bottom: 1px solid #dfe3ea; vertical-align: top; }
        th.num, td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
        tfoot td { font-weight: 700; border-top: 1.5px solid #1e2530; border-bottom: 0; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }

        .note { margin-top: 4mm; font-size: 8.5pt; color: #3f4a5f; }
        .warn { margin-top: 4mm; font-size: 9pt; color: #9a3412; }

        .bar { margin-bottom: 5mm; }
        .bar button { font: inherit; padding: 2mm 5mm; border: 1px solid #9aa5b8; border-radius: 4px; background: #fff; cursor: pointer; }

        @media print {
            body { padding: 0; }
            .bar { display: none; }
        }
    </style>
</head>
<body>
    <div class="bar">
        <button type="button" onclick="window.print()">Print</button>
        <button type="button" onclick="window.close()">Close</button>
    </div>

    <header>
        <h1>{{ $title }}</h1>
        <p>
            {{ $organisation }} · {{ number_format(count($rows)) }} {{ count($rows) === 1 ? 'row' : 'rows' }} · printed {{ $printedAt }}
        </p>
        @if ($filters !== [])
            <p>
                Only rows matching:
                @foreach ($filters as $filter)
                    {{ $filter['label'] }}: <strong>{{ $filter['value'] }}</strong>@if (! $loop->last); @endif
                @endforeach
            </p>
        @else
            <p>No filters applied.</p>
        @endif
    </header>

    <table>
        <thead>
            <tr>
                @foreach ($columns as $column)
                    <th @class(['num' => ($column['align'] ?? null) === 'right'])>{{ $column['label'] }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $index => $value)
                        <td @class(['num' => ($columns[$index]['align'] ?? null) === 'right'])>{{ $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($columns) }}">Nothing matches these filters.</td></tr>
            @endforelse
        </tbody>
        @if ($hasTotals && $rows !== [])
            <tfoot>
                <tr>
                    @foreach ($totals as $index => $total)
                        <td @class(['num' => ($columns[$index]['align'] ?? null) === 'right'])>
                            {{ $total ?? ($index === 0 ? 'Total' : '') }}
                        </td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>

    @if ($converted)
        <p class="note">
            These rows are in more than one currency. The totals are converted to {{ $base }} at the rate recorded on each document.
        </p>
    @endif

    @if ($truncated)
        <p class="warn">
            Only the first {{ number_format($limit) }} rows are printed. Narrow the filters, or download the report as a spreadsheet for the full list.
        </p>
    @endif

    <script>
        // Opened to be printed: offer the dialog straight away, once the table has been laid out.
        window.addEventListener('load', () => setTimeout(() => window.print(), 150));
    </script>
</body>
</html>
