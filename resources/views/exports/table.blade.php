{{--
    The PDF face of an export.

    Self-contained, like the print views: dompdf gets eleven rules rather than the design
    system, because anything it cannot parse turns up as a misaligned table on somebody's desk.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>

    <style>
        @page { margin: 12mm; }

        body { margin: 0; font: 9pt/1.4 "DejaVu Sans", sans-serif; color: #1e2530; }

        header { border-bottom: 1.5px solid #1e2530; padding-bottom: 3mm; margin-bottom: 4mm; }
        header h1 { margin: 0 0 1mm; font-size: 13pt; }
        header p { margin: 0; font-size: 8pt; color: #55607a; }

        table { width: 100%; border-collapse: collapse; }
        thead th { background: #f1f3f7; text-align: left; padding: 1.6mm 2mm; font-size: 7.5pt;
                   text-transform: uppercase; letter-spacing: .04em; color: #55607a;
                   border-bottom: 1px solid #c9d0dc; }
        tbody td { padding: 1.6mm 2mm; border-bottom: 1px solid #e6e9f0; vertical-align: top;
                   word-wrap: break-word; }
        tbody tr:nth-child(even) td { background: #fafbfc; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }

        .note { margin-top: 4mm; font-size: 8pt; color: #7b869c; }
        .warn { margin-top: 4mm; font-size: 8pt; color: #9a3412; }
    </style>
</head>
<body>
    <header>
        <h1>{{ $title }}</h1>
        <p>
            {{ count($rows) }} row{{ count($rows) === 1 ? '' : 's' }} · printed {{ $printedAt }}
            @if ($filters !== [])
                · filtered by
                @foreach ($filters as $key => $value)
                    {{ $key }}: {{ $value }}@if (! $loop->last), @endif
                @endforeach
            @endif
        </p>
    </header>

    <table>
        <thead>
            <tr>
                @foreach ($headings as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    @foreach ($row as $value)
                        <td>{{ $value }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headings) }}">Nothing matched these filters.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($truncated)
        {{-- Said on the page, not swallowed: a PDF that silently stops at 2 000 rows is read
             as the whole list by whoever it is handed to. --}}
        <p class="warn">
            Stopped at the first {{ number_format($limit) }} rows. Export as CSV or XLSX for the full list.
        </p>
    @endif
</body>
</html>
