<?php

namespace App\Services\Cards;

use App\Data\Cards\CardPdfData;
use App\Data\Cards\PrintCardsData;
use App\Models\Card;
use App\Models\User;
use App\Support\Pdf\PdfOptions;
use App\Support\Pdf\PdfRenderer;
use App\Support\Pdf\PdfRenderException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * PDF of issued cards at their real size, drawn by the PDF engine from the same render page as browser
 * printing. Only cards that may be printed; making the PDF is logged as a print only when asked
 * (a download is not necessarily a printed card). API clients (e.g. an HR system) use it directly.
 */
class CardPdfService
{
    public function __construct(
        private CardPrintService $prints,
        private CardRenderService $render,
        private PdfRenderer $renderer,
    ) {}

    /**
     * @param  array<int, string>  $cardIds  public ids, in page order
     * @return array{content: string, filename: string}
     */
    public function make(array $cardIds, CardPdfData $data, User $actor): array
    {
        $cards = $this->prints->printableCards($cardIds);
        $ids = $cards->pluck('public_id')->all();

        try {
            $content = $this->renderer->renderUrl($this->render->link($ids, $data->sides), new PdfOptions(waitForExpression: CardRenderService::READY_EXPRESSION));
        } catch (PdfRenderException $exception) {
            report($exception);

            throw new ServiceUnavailableHttpException(30, __('cards.print.pdf_unavailable'), $exception);
        }

        if ($data->record) {
            $this->prints->printCards($ids, new PrintCardsData('pdf', 1, $data->note), $actor);
        }

        return ['content' => $content, 'filename' => $this->filename($cards->count() === 1 ? $cards->first() : null)];
    }

    private function filename(?Card $card): string
    {
        $name = $card ? 'card-'.$card->number : 'cards-'.now()->format('Y-m-d-His');

        return trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $name), '-').'.pdf';
    }
}
