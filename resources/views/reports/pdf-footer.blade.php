{{-- Foot of every page of a report document (Chromium fills pageNumber and totalPages). --}}
<html>
<head>
    <style>
        body { margin: 0; -webkit-print-color-adjust: exact; }
        .foot { width: 100%; padding: 0 10mm 4mm; font-family: 'Inter', 'Noto Sans Bengali', sans-serif; font-size: 8px; color: #6b7280; display: flex; justify-content: space-between; }
    </style>
</head>
<body>
    <div class="foot">
        <span>{{ $document->organization }} · {{ $document->title }}</span>
        <span>{{ __('reports.pdf.page') }} <span class="pageNumber"></span> / <span class="totalPages"></span></span>
    </div>
</body>
</html>
