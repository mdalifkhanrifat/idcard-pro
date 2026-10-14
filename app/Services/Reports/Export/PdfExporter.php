<?php

namespace App\Services\Reports\Export;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportDocument;
use App\Services\Reports\ReportValueText;
use App\Support\Pdf\DisabledPdfRenderer;
use App\Support\Pdf\PdfFonts;
use App\Support\Pdf\PdfOptions;
use App\Support\Pdf\PdfRenderer;
use App\Support\Pdf\PdfRenderException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * A report as a document to read, print or file: the organization's logo and name, what was asked for, the
 * totals and the rows, with page numbers; the paper, its orientation and the type size follow the number of
 * columns (config reports.pdf_layouts). Drawn by the local PDF engine from a
 * page of this app: the page is kept for one read behind a short signed link (the engine may only open the
 * app's own address), never stored as a file.
 */
class PdfExporter implements ReportExporter
{
    public function __construct(
        private PdfRenderer $renderer,
        private ReportValueText $text,
    ) {}

    public function available(): bool
    {
        return ! $this->renderer instanceof DisabledPdfRenderer;
    }

    public function maxRows(): ?int
    {
        return (int) config('reports.pdf_max_rows');
    }

    public function maxColumns(): ?int
    {
        $layouts = $this->layouts();

        return $layouts === [] ? null : (int) end($layouts)['max_columns'];
    }

    /**
     * The page for this many columns: the first layout that holds them (the last one past them all).
     *
     * @return array{paper: string, orientation: string, font_size: float}
     */
    public function layoutFor(int $columns): array
    {
        $layouts = $this->layouts();
        $layout = collect($layouts)->first(fn (array $layout) => $columns <= (int) $layout['max_columns']) ?? end($layouts) ?: [];

        return [
            'paper' => preg_match('/^[A-Za-z0-9]+$/', (string) ($layout['paper'] ?? '')) ? (string) $layout['paper'] : 'A4',
            'orientation' => ($layout['orientation'] ?? '') === 'portrait' ? 'portrait' : 'landscape',
            'font_size' => (float) ($layout['font_size'] ?? 8.5),
        ];
    }

    /**
     * @return array<int, array{max_columns: int, paper: string, orientation: string, font_size: float}>
     */
    private function layouts(): array
    {
        return collect((array) config('reports.pdf_layouts'))->sortBy('max_columns')->values()->all();
    }

    public function extension(): string
    {
        return 'pdf';
    }

    public function contentType(): string
    {
        return 'application/pdf';
    }

    public function write(string $path, ReportDocument $document, array $columns, iterable $rows): void
    {
        $cells = [];
        foreach ($rows as $row) {
            $cells[] = array_map(fn (ReportColumn $column) => $this->text->of($column, $column->valueOf($row)), $columns);
        }

        $html = view('reports.pdf', [
            'document' => $document,
            'columns' => $columns,
            'rows' => $cells,
            'layout' => $this->layoutFor(count($columns)),
            'fonts' => PdfFonts::css(),
            'text' => $this->text,
        ])->render();
        $footer = view('reports.pdf-footer', ['document' => $document])->render();

        $token = Str::random(48);
        $minutes = (int) config('pdf.link_minutes');
        Cache::put("report-render:{$token}", $html, now()->addMinutes($minutes));
        $link = URL::temporarySignedRoute('api.v1.report-render', now()->addMinutes($minutes), ['token' => $token], absolute: false);

        try {
            $pdf = $this->renderer->renderUrl(rtrim((string) config('pdf.app_url'), '/').$link, new PdfOptions(
                waitForExpression: 'window.reportReady === true',
                footerHtml: $footer,
                marginBottom: '14mm',
            ));
        } catch (PdfRenderException $exception) {
            report($exception);

            throw new ServiceUnavailableHttpException(30, __('reports.export.pdf_unavailable'), $exception);
        } finally {
            Cache::forget("report-render:{$token}");
        }

        file_put_contents($path, $pdf);
    }
}
