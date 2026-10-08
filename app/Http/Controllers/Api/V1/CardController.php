<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Cards\IssueCardData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cards\IssueCardRequest;
use App\Http\Requests\Cards\ListCardsRequest;
use App\Http\Resources\CardResource;
use App\Models\Card;
use App\Models\Cardholder;
use App\Services\Cards\CardDataResolver;
use App\Services\Cards\CardIssueService;
use App\Services\Cards\CardLayoutPresenter;
use App\Services\Cards\CardRenderService;
use App\Services\Cards\CardService;
use App\Services\Cards\CardWarnings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Cards of one cardholder (and their family members): history and issuing.
 */
class CardController extends Controller
{
    public function __construct(private CardIssueService $cards) {}

    /**
     * All issued cards (legacy card print history and expired card reports). Filters: filter[search|status|reason|
     * template|holder|issued_from|issued_to|validity|expiring_days|department|unit], sort, per_page.
     */
    public function list(ListCardsRequest $request, CardService $service): AnonymousResourceCollection
    {
        return CardResource::collection($service->list($request->toFilter()));
    }

    /**
     * Choices for card forms, from config/cards.php: reasons (with whether they need a note or allow a
     * document) and statuses, so new reasons need no frontend change.
     */
    public function options(): JsonResponse
    {
        abort_unless(Gate::any(['cards.view', 'cards.issue']), 403);

        return response()->json(['data' => [
            'reasons' => collect((array) config('cards.reasons'))
                ->map(fn (array $options, string $key) => ['key' => $key, 'note' => ! empty($options['note']), 'document' => ! empty($options['document'])])
                ->values(),
            'statuses' => config('cards.statuses'),
            'print' => [
                'methods' => config('cards.print.methods'),
                'max_copies' => (int) config('cards.print.max_copies'),
                'max_batch' => (int) config('cards.print.max_batch'),
                // Papers for several cards per sheet with crop marks, besides one card per page
                'sheets' => CardRenderService::sheets(),
                // Server PDFs are on (config/pdf.php); off, only the browser's own printing remains
                'pdf' => filled(config('pdf.driver')),
            ],
        ]]);
    }

    /**
     * Counts for the cards overview.
     */
    public function summary(Request $request, CardService $service): JsonResponse
    {
        Gate::authorize('cards.view');

        $days = (int) ($request->validate(['expiring_days' => ['sometimes', 'integer', 'between:1,365']])['expiring_days'] ?? 30);

        return response()->json(['data' => $service->summary($days)]);
    }

    public function index(Cardholder $cardholder): AnonymousResourceCollection
    {
        Gate::authorize('cards.view');

        return CardResource::collection($this->cards->history($cardholder));
    }

    /**
     * Issue a card; `meta.warnings` lists what will print blank (see show()), so a connected system knows too.
     */
    public function store(IssueCardRequest $request, Cardholder $cardholder, CardWarnings $warnings): JsonResponse
    {
        $card = $this->cards->issue($cardholder, IssueCardData::fromArray($request->validated(), $request->file('document')), $request->user());

        return (new CardResource($card))->additional(['meta' => ['warnings' => $warnings->forCard($card)]])->response()->setStatusCode(201);
    }

    /**
     * One card with what it prints: `meta.layout` (design snapshot), `meta.values` (text and images) and
     * `meta.warnings` (missing photos or signatures, barcodes that cannot be printed).
     */
    public function show(Card $card, CardLayoutPresenter $layout, CardDataResolver $cardData, CardWarnings $warnings): CardResource
    {
        Gate::authorize('cards.view');

        $values = $cardData->forCard($card);

        return (new CardResource($card->load(['template', 'familyMember', 'cardholder', 'issuer', 'replacedBy', 'document'])))
            ->additional(['meta' => ['layout' => $layout->present($card), 'values' => $values, 'warnings' => $warnings->check($card->layout['elements'] ?? [], $values)]]);
    }

    /**
     * RF card number, notes and expiry of an issued card.
     */
    public function update(Request $request, Card $card, CardService $service): CardResource
    {
        Gate::authorize('cards.update');

        $data = $request->validate([
            'rf_card_no' => ['sometimes', 'nullable', 'string', 'max:60'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'expiry_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        return new CardResource($service->update($card, $data)->load(['template', 'familyMember', 'issuer', 'replacedBy', 'document']));
    }

    /**
     * Cancel an active card; a reason is required (it is shown in the history and, later, on verification).
     */
    public function revoke(Request $request, Card $card, CardService $service): CardResource
    {
        Gate::authorize('cards.revoke');

        $note = $request->validate(['note' => ['required', 'string', 'max:255']])['note'];

        return new CardResource($service->revoke($card, $note)->load(['template', 'familyMember', 'issuer', 'replacedBy', 'document']));
    }

    /**
     * The number the next card would get (kept or new), without reserving it.
     */
    public function nextNumber(Request $request, Cardholder $cardholder): JsonResponse
    {
        Gate::authorize('cards.issue');

        $input = $request->validate([
            'template' => ['required', 'string', 'size:26'],
            'family_member' => ['nullable', 'string', 'size:26'],
        ]);

        return response()->json(['data' => $this->cards->nextNumber($cardholder, $input['template'], $input['family_member'] ?? null)]);
    }
}
