<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\ListMasterDataRequest;
use App\Http\Requests\MasterData\SaveMasterDataItemRequest;
use App\Http\Resources\MasterDataItemResource;
use App\Models\MasterDataItem;
use App\Services\MasterData\MasterDataService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class MasterDataController extends Controller
{
    public function __construct(private MasterDataService $masterData) {}

    /**
     * Registered list types with item counts.
     */
    public function types(): JsonResponse
    {
        Gate::authorize('master_data.view');

        return response()->json(['data' => $this->masterData->types()]);
    }

    /**
     * Filters: filter[search], filter[parent] (public id), filter[is_active] (1/0).
     * Sorts: sort_order (default), name, code, updated_at; prefix "-" for descending.
     * per_page=all returns every item (for dropdowns).
     */
    public function index(ListMasterDataRequest $request, string $type): AnonymousResourceCollection
    {
        return MasterDataItemResource::collection($this->masterData->list($type, $request->toFilter()));
    }

    public function store(SaveMasterDataItemRequest $request, string $type): JsonResponse
    {
        $item = $this->masterData->create($type, $request->validated());

        return (new MasterDataItemResource($item))->response()->setStatusCode(201);
    }

    public function update(SaveMasterDataItemRequest $request, string $type, MasterDataItem $item): MasterDataItemResource
    {
        return new MasterDataItemResource($this->masterData->update($type, $item, $request->validated()));
    }

    public function destroy(string $type, MasterDataItem $item): JsonResponse
    {
        Gate::authorize('master_data.delete');

        $this->masterData->delete($type, $item);

        return response()->json(status: 204);
    }
}
