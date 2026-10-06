<?php

namespace App\Support\Pdf;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gotenberg (a local Chromium container, compose service "gotenberg"). Nothing leaves the machine:
 * the container may only open the app's own address (its Chromium allow-list in compose.yaml).
 */
class GotenbergPdfRenderer implements PdfRenderer
{
    public function __construct(private string $endpoint, private int $timeout) {}

    public function renderUrl(string $url, PdfOptions $options = new PdfOptions): string
    {
        $fields = [
            'url' => $url,
            'preferCssPageSize' => 'true',
            'printBackground' => $options->printBackground ? 'true' : 'false',
            'marginTop' => '0',
            'marginBottom' => '0',
            'marginLeft' => '0',
            'marginRight' => '0',
            // A script error or a failed page load must not produce a half-drawn card
            'failOnConsoleExceptions' => 'true',
            'failOnHttpStatusCodes' => '[499,599]',
        ];
        if ($options->waitForExpression !== null) {
            $fields['waitForExpression'] = $options->waitForExpression;
        }

        try {
            $response = Http::timeout($this->timeout)
                ->asMultipart()
                ->post(rtrim($this->endpoint, '/').'/forms/chromium/convert/url', $fields);
        } catch (ConnectionException $exception) {
            throw new PdfRenderException('The PDF service cannot be reached.', previous: $exception);
        }

        if (! $response->successful() || ! str_starts_with($response->body(), '%PDF')) {
            throw new PdfRenderException('The PDF service could not render the page (HTTP '.$response->status().').');
        }

        return $response->body();
    }
}
