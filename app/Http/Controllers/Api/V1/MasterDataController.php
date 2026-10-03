<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\SaveMasterDataItemRequest;
use App\Http\Resources\MasterDataItemResource;
use App\Models\MasterDataItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class MasterDataController extends Controller
{
    /**
     * Registry of list types with item counts for the current tenant.
     */
    public function types(): JsonResponse
    {
        Gate::authorize('master_data.view');

        $counts = MasterDataItem::query()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');

        return response()->json([
            'data' => collect(config('master_data.types'))->map(fn (array $definition, string $type) => [
                'type' => $type,
                'parent' => $definition['parent'],
                'has_code' => $definition['has_code'],
                'count' => (int) ($counts[$type] ?? 0),
            ])->values(),
        ]);
    }

    /**
     * Filters: filter[search] (name, local name, code), filter[parent] (public id), filter[is_active] (1/0)
     * Sorts: sort_order (default), name, code, updated_at. `per_page=all` returns every item (for dropdowns).
     */
    public function index(Request $request, string $type): AnonymousResourceCollection
    {
        Gate::authorize('master_data.view');

        $query = QueryBuilder::for(MasterDataItem::class)
            ->allowedFilters(
                AllowedFilter::callback('search', fn (Builder $q, string $value) => $q->where(fn (Builder $w) => $w
                    ->where('name', 'like', "%{$value}%")
                    ->orWhere('name_local', 'like', "%{$value}%")
                    ->orWhere('code', 'like', "%{$value}%"))),
                AllowedFilter::callback('parent', fn (Builder $q, string $value) => $q->whereIn(
                    'parent_id',
                    MasterDataItem::query()->where('public_id', $value)->select('id'),
                )),
                AllowedFilter::callback('is_active', fn (Builder $q, mixed $value) => $q->where('is_active', filter_var($value, FILTER_VALIDATE_BOOL))),
            )
            ->allowedSorts('sort_order', 'name', 'code', 'updated_at')
            ->defaultSort('sort_order', 'name')
            ->where('type', $type)
            ->with('parent')
            ->withCount('children');

        if ($request->input('per_page') === 'all') {
            return MasterDataItemResource::collection($query->get());
        }

        return MasterDataItemResource::collection(
            $query->paginate(min((int) $request->integer('per_page', 25), 100))->withQueryString()
        );
    }

    public function store(SaveMasterDataItemRequest $request, string $type): JsonResponse
    {
        $item = MasterDataItem::create([...$request->attributesForSave(), 'type' => $type]);

        return (new MasterDataItemResource($item->load('parent')))->response()->setStatusCode(201);
    }

    public function update(SaveMasterDataItemRequest $request, string $type, MasterDataItem $item): MasterDataItemResource
    {
        abort_unless($item->type === $type, 404);

        $item->update($request->attributesForSave());

        return new MasterDataItemResource($item->load('parent'));
    }

    public function destroy(string $type, MasterDataItem $item): JsonResponse
    {
        Gate::authorize('master_data.delete');
        abort_unless($item->type === $type, 404);
        abort_if($item->children()->exists(), 422, __('master_data.has_children'));

        $item->delete();

        return response()->json(status: 204);
    }
}
