<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cards\CardPdfRequest;
use App\Models\Card;
use App\Services\Cards\CardPdfService;
use Illuminate\Http\Response;

/**
 * Issued cards as a PDF at their real size, one page per side.
 */
class CardPdfController extends Controller
{
    public function __construct(private CardPdfService $pdf) {}

    /**
     * One card: `GET /cards/{card}/pdf?sides=both&record=1&note=`.
     */
    public function show(CardPdfRequest $request, Card $card): Response
    {
        return $this->download($this->pdf->make([$card->public_id], $request->toData(), $request->user()));
    }

    /**
     * Several cards in one PDF, in the order given: `POST /cards/pdf` {cards: [...], sides, record, note}.
     */
    public function batch(CardPdfRequest $request): Response
    {
        return $this->download($this->pdf->make($request->validated('cards'), $request->toData(), $request->user()));
    }

    /**
     * @param  array{content: string, filename: string}  $pdf
     */
    private function download(array $pdf): Response
    {
        return response($pdf['content'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$pdf['filename'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
