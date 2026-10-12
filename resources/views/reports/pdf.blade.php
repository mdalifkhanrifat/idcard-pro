{{-- A report as a document (PdfExporter): drawn by the PDF engine, A4, landscape with many columns. --}}
@php
    /** @var \App\Data\Reports\ReportDocument $document */
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $document->color) ? $document->color : '#0f766e';
@endphp
<!doctype html>
<html lang="{{ $document->locale }}">
<head>
    <meta charset="utf-8">
    <title>{{ $document->title }} · {{ $document->organization }}</title>
    <style>
        {!! $fonts !!}
        @page { size: A4 {{ $landscape ? 'landscape' : 'portrait' }}; margin: 12mm 10mm 14mm 10mm; }
        * { box-sizing: border-box; }
        html { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body { margin: 0; font-family: 'Inter', 'Noto Sans Bengali', sans-serif; font-size: 8.5pt; line-height: 1.4; color: #1f2937; }
        .rule { height: 3px; background: {{ $accent }}; border-radius: 2px; margin-bottom: 10px; }
        .head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; }
        .org { display: flex; align-items: center; gap: 10px; min-width: 0; }
        .org img { height: 40px; max-width: 120px; object-fit: contain; }
        .org-name { font-size: 12pt; font-weight: 700; color: #111827; }
        .title { text-align: right; }
        .title h1 { margin: 0; font-size: 14pt; font-weight: 700; color: #111827; }
        .title p { margin: 2px 0 0; color: #6b7280; }
        .meta { margin-top: 4px; color: #6b7280; font-size: 7.5pt; }
        .criteria { margin: 10px 0 0; display: flex; flex-wrap: wrap; gap: 4px 6px; }
        .chip { border: 1px solid #e5e7eb; border-radius: 999px; padding: 1px 8px; background: #f9fafb; font-size: 7.5pt; }
        .chip b { font-weight: 700; }
        .totals { display: flex; flex-wrap: wrap; gap: 8px; margin: 10px 0 12px; }
        .total { min-width: 110px; border: 1px solid #e5e7eb; border-left: 3px solid {{ $accent }}; border-radius: 6px; padding: 5px 9px; }
        .total span { display: block; color: #6b7280; font-size: 7.5pt; }
        .total strong { font-size: 13pt; font-weight: 700; color: #111827; }
        table { width: 100%; border-collapse: collapse; }
        thead { display: table-header-group; }
        tr { break-inside: avoid; }
        th { text-align: left; font-size: 7.5pt; font-weight: 700; text-transform: uppercase; letter-spacing: .02em; color: #374151; background: #f3f4f6; border-bottom: 1.5px solid {{ $accent }}; padding: 5px 6px; }
        td { padding: 4px 6px; border-bottom: 1px solid #eef0f3; vertical-align: top; overflow-wrap: anywhere; }
        tbody tr:nth-child(even) td { background: #fafafa; }
        .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .day { white-space: nowrap; }
        .empty { padding: 24px; text-align: center; color: #6b7280; border: 1px dashed #d1d5db; border-radius: 6px; }
    </style>
</head>
<body>
    <div class="rule"></div>
    <div class="head">
        <div class="org">
            @if ($document->logo)
                <img src="{{ $document->logo }}" alt="">
            @endif
            <div class="org-name">{{ $document->organization }}</div>
        </div>
        <div class="title">
            <h1>{{ $document->title }}</h1>
            @if ($document->description !== '')
                <p>{{ $document->description }}</p>
            @endif
            <div class="meta">
                {{ __('reports.pdf.generated', ['when' => $text->digits($document->generatedAt->format('d/m/Y H:i')), 'who' => $document->author]) }}
                · {{ __('reports.pdf.rows', ['count' => $text->number(count($rows))]) }}
            </div>
        </div>
    </div>

    <div class="criteria">
        @forelse ($document->filters as $filter)
            <span class="chip">{{ $filter['label'] }}: <b>{{ $filter['value'] }}</b></span>
        @empty
            <span class="chip">{{ __('reports.pdf.no_filter') }}</span>
        @endforelse
    </div>

    @if ($document->totals !== [])
        <div class="totals">
            @foreach ($document->totals as $total)
                <div class="total"><span>{{ $total['label'] }}</span><strong>{{ $text->number($total['value']) }}</strong></div>
            @endforeach
        </div>
    @endif

    @if ($rows === [])
        <div class="empty">{{ __('reports.pdf.empty') }}</div>
    @else
        <table>
            <thead>
                <tr>
                    @foreach ($columns as $column)
                        <th @class(['num' => $column->type === 'number'])>{{ $column->label }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $cells)
                    <tr>
                        @foreach ($columns as $index => $column)
                            <td @class(['num' => $column->type === 'number', 'day' => in_array($column->type, ['date', 'datetime'], true)])>{{ $cells[$index] }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    {{-- Ready once the fonts are in: the PDF engine waits for this --}}
    <script>document.fonts.ready.then(function () { window.reportReady = true; });</script>
</body>
</html>
