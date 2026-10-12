<?php

use App\Support\Pdf\DisabledPdfRenderer;
use App\Support\Pdf\GotenbergPdfRenderer;
use App\Support\Pdf\PdfOptions;
use App\Support\Pdf\PdfRenderer;
use App\Support\Pdf\PdfRenderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['pdf.driver' => 'gotenberg', 'pdf.gotenberg.url' => 'http://gotenberg:3000']);
});

it('asks the local Gotenberg container for a PDF of the page at the CSS page size', function () {
    Http::fake(['gotenberg:3000/*' => Http::response('%PDF-1.7 fake', 200, ['Content-Type' => 'application/pdf'])]);

    $pdf = app(PdfRenderer::class)->renderUrl('http://web/render/cards?token=abc', new PdfOptions(waitForExpression: 'window.cardsReady === true'));

    expect($pdf)->toBe('%PDF-1.7 fake');
    Http::assertSent(function (Request $request) {
        $fields = collect($request->data())->mapWithKeys(fn (array $part) => [$part['name'] => $part['contents']]);

        return $request->url() === 'http://gotenberg:3000/forms/chromium/convert/url'
            && $request->isMultipart()
            && $fields['url'] === 'http://web/render/cards?token=abc'
            && $fields['preferCssPageSize'] === 'true'
            && $fields['printBackground'] === 'true'
            && $fields['marginTop'] === '0'
            && $fields['failOnConsoleExceptions'] === 'true'
            && $fields['waitForExpression'] === 'window.cardsReady === true';
    });
});

it('reports a clear error when the PDF service fails or cannot be reached', function () {
    Http::fake(['gotenberg:3000/*' => Http::response('Chromium failed', 503)]);
    expect(fn () => app(PdfRenderer::class)->renderUrl('http://web/x'))->toThrow(PdfRenderException::class, 'HTTP 503');

    Http::fake(['gotenberg:3000/*' => Http::response('<html>not a pdf</html>', 200)]);
    expect(fn () => app(PdfRenderer::class)->renderUrl('http://web/x'))->toThrow(PdfRenderException::class);

    Http::fake(['gotenberg:3000/*' => fn () => throw new ConnectionException('refused')]);
    expect(fn () => app(PdfRenderer::class)->renderUrl('http://web/x'))->toThrow(PdfRenderException::class, 'cannot be reached');
});

it('can be switched off in the configuration', function () {
    expect(app(PdfRenderer::class))->toBeInstanceOf(GotenbergPdfRenderer::class);

    config(['pdf.driver' => null]);
    Http::fake();

    expect(app(PdfRenderer::class))->toBeInstanceOf(DisabledPdfRenderer::class)
        ->and(fn () => app(PdfRenderer::class)->renderUrl('http://web/x'))->toThrow(PdfRenderException::class, 'not enabled');
    Http::assertNothingSent();
});

it('sends a footer for every page with the room it needs', function () {
    Http::fake(['gotenberg:3000/*' => Http::response('%PDF-1.7 fake', 200)]);

    app(PdfRenderer::class)->renderUrl('http://web/x', new PdfOptions(footerHtml: '<html><body><span class="pageNumber"></span></body></html>', marginBottom: '12mm'));

    Http::assertSent(function (Request $request) {
        $parts = collect($request->data());
        $footer = $parts->firstWhere('name', 'files');

        return $parts->firstWhere('name', 'marginBottom')['contents'] === '12mm'
            && $footer['filename'] === 'footer.html'
            && str_contains($footer['contents'], 'pageNumber');
    });
});
