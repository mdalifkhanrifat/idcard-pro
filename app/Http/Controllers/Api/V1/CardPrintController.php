<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cards\PrintCardsRequest;
use App\Http\Resources\CardPrintResource;
use App\Models\Card;
use App\Services\Cards\CardPrintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Print log of issued cards. The page that prints a card calls this once the printing is done.
 */
class CardPrintController extends Controller
{
    public function __construct(private CardPrintService $prints) {}

    public function index(Card $card): AnonymousResourceCollection
    {
        Gate::authorize('cards.view');

        return CardPrintResource::collection($this->prints->history($card));
    }

    /**
     * Log one printing of a card: method (browser, pdf), copies, note.
     */
    public function store(PrintCardsRequest $request, Card $card): JsonResponse
    {
        return (new CardPrintResource($this->prints->printCard($card, $request->toData(), $request->user())))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Log a batch: `cards` (public ids) printed together; all are logged or none.
     */
    public function batch(PrintCardsRequest $request): JsonResponse
    {
        return CardPrintResource::collection($this->prints->printCards($request->validated('cards'), $request->toData(), $request->user()))
            ->response()
            ->setStatusCode(201);
    }
}
