<?php

namespace App\Services\Cards;

use App\Http\Resources\TenantFontResource;
use App\Models\Card;
use App\Repositories\Contracts\CardRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Services\Fonts\TenantFontService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Issued cards drawn without a signed-in user, for the server PDF engine: a short-lived signed link to
 * the app's render page, and the data that page draws (the same layout, values and fonts as printing
 * in the browser, so the PDF matches). Authorization happens when the link is made.
 */
class CardRenderService
{
    /** Which sides to draw: both, or one pass for printers without duplex. */
    public const SIDES = ['both', 'front', 'back'];

    /** What the PDF engine waits for: the render page sets it when drawn (true) or failed (false). */
    public const READY_EXPRESSION = 'window.cardsReady !== undefined';

    public function __construct(
        private CardRepositoryInterface $cards,
        private TenantRepositoryInterface $tenants,
        private TenantContext $tenantContext,
        private CardLayoutPresenter $layout,
        private CardDataResolver $cardData,
        private CardPrintService $prints,
        private TenantFontService $fonts,
    ) {}

    /**
     * Address of the render page for these cards of the current organization, as the PDF engine reaches
     * the app (config pdf.app_url). The page forwards its signed query to `api.v1.card-render`.
     *
     * @param  array<int, string>  $cardPublicIds
     */
    public function link(array $cardPublicIds, string $sides = 'both'): string
    {
        $signed = URL::temporarySignedRoute(
            'api.v1.card-render',
            now()->addMinutes((int) config('pdf.link_minutes')),
            ['tenant' => $this->tenantContext->get()?->public_id, 'cards' => implode(',', $cardPublicIds), 'sides' => $sides],
            absolute: false,
        );

        return rtrim((string) config('pdf.app_url'), '/').'/render/cards?'.parse_url($signed, PHP_URL_QUERY);
    }

    /**
     * What the render page draws: each card (in the order given) with its layout and values, the
     * organization's fonts, and the sides.
     *
     * @param  array<int, string>  $cardPublicIds
     * @return array{cards: array<int, array<string, mixed>>, fonts: mixed, sides: string}
     */
    public function data(string $tenantPublicId, array $cardPublicIds, string $sides): array
    {
        $tenant = $this->tenants->findByPublicId($tenantPublicId);
        if ($tenant === null || ! $tenant->isActive()) {
            throw new NotFoundHttpException;
        }

        return $this->tenantContext->run($tenant, fn () => [
            'cards' => $this->cards->byPublicIds($cardPublicIds)->map(fn (Card $card) => [
                'id' => $card->public_id,
                'number' => $card->number,
                'printable' => $this->prints->printable($card),
                'layout' => $this->layout->present($card),
                'values' => $this->cardData->forCard($card),
            ])->all(),
            'fonts' => TenantFontResource::collection($this->fonts->list())->resolve(),
            'sides' => in_array($sides, self::SIDES, true) ? $sides : 'both',
        ]);
    }
}
