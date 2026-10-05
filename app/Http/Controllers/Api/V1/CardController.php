<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Cards\IssueCardData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cards\IssueCardRequest;
use App\Http\Resources\CardResource;
use App\Models\Card;
use App\Models\Cardholder;
use App\Services\Cards\CardDataResolver;
use App\Services\Cards\CardIssueService;
use App\Services\Cards\CardLayoutPresenter;
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

    public function index(Cardholder $cardholder): AnonymousResourceCollection
    {
        Gate::authorize('cards.view');

        return CardResource::collection($this->cards->history($cardholder));
    }

    public function store(IssueCardRequest $request, Cardholder $cardholder): JsonResponse
    {
        $card = $this->cards->issue($cardholder, IssueCardData::fromArray($request->validated(), $request->file('document')), $request->user());

        return (new CardResource($card))->response()->setStatusCode(201);
    }

    /**
     * One card with what it prints: `meta.layout` (design snapshot) and `meta.values` (text and images).
     */
    public function show(Card $card, CardLayoutPresenter $layout, CardDataResolver $cardData): CardResource
    {
        Gate::authorize('cards.view');

        return (new CardResource($card->load(['template', 'familyMember', 'cardholder', 'issuer', 'replacedBy', 'document'])))
            ->additional(['meta' => ['layout' => $layout->present($card), 'values' => $cardData->forCard($card)]]);
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
