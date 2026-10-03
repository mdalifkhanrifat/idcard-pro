<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cardholders\ListCardholdersRequest;
use App\Http\Requests\Cardholders\SaveCardholderRequest;
use App\Http\Resources\CardholderResource;
use App\Models\Cardholder;
use App\Services\Cardholders\CardholderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CardholderController extends Controller
{
    public function __construct(private CardholderService $cardholders) {}

    /**
     * Filters: filter[search|status|employment_type|joined_from|joined_to] and
     * filter[department|unit|rank|designation|branch|blood_group] (master data public ids).
     * Sorts: full_name, employee_no, joining_date, created_at, updated_at (prefix "-" for descending).
     */
    public function index(ListCardholdersRequest $request): AnonymousResourceCollection
    {
        return CardholderResource::collection($this->cardholders->list($request->toFilter()));
    }

    public function store(SaveCardholderRequest $request): JsonResponse
    {
        $cardholder = $this->cardholders->create($request->validated(), $request->user());

        return (new CardholderResource($cardholder))->response()->setStatusCode(201);
    }

    public function show(Cardholder $cardholder): CardholderResource
    {
        Gate::authorize('cardholders.view');

        return new CardholderResource($this->cardholders->show($cardholder));
    }

    public function update(SaveCardholderRequest $request, Cardholder $cardholder): CardholderResource
    {
        return new CardholderResource($this->cardholders->update($cardholder, $request->validated()));
    }

    public function destroy(Request $request, Cardholder $cardholder): JsonResponse
    {
        Gate::authorize('cardholders.delete');

        $this->cardholders->delete($cardholder);

        return response()->json(status: 204);
    }
}
