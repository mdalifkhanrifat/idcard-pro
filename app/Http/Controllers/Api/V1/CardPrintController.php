<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cards\ListCardsRequest;
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
     * Public ids of every printable card the card list filter finds (same filter[...] and sort as GET /cards),
     * at most one batch: `meta.total` printable cards in all, `meta.limit` the batch size.
     */
    public function printable(ListCardsRequest $request): JsonResponse
    {
        Gate::authorize('cards.print');

        $found = $this->prints->printableIds($request->toFilter());

        return response()->json(['data' => $found['ids'], 'meta' => ['total' => $found['total'], 'limit' => $found['limit']]]);
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
