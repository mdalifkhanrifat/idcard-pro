<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ImageLibrary\SaveTenantImageRequest;
use App\Http\Resources\TenantImageResource;
use App\Models\TenantImage;
use App\Repositories\Contracts\TenantImageRepositoryInterface;
use App\Services\ImageLibrary\TenantImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class TenantImageController extends Controller
{
    public function __construct(private TenantImageService $images) {}

    /**
     * Filter: filter[search] (name). Template designers pick from the library, so templates.view may list it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless(Gate::any(['images.view', 'templates.view']), 403);

        $filter = $request->validate([
            'filter' => ['sometimes', 'array:search'],
            'filter.search' => ['nullable', 'string', 'max:100'],
        ])['filter'] ?? [];

        return TenantImageResource::collection($this->images->list($filter['search'] ?? null));
    }

    public function store(SaveTenantImageRequest $request): JsonResponse
    {
        return (new TenantImageResource($this->images->upload($request->file('file'), $request->validated('name'))))
            ->response()
            ->setStatusCode(201);
    }

    public function update(SaveTenantImageRequest $request, TenantImage $image): TenantImageResource
    {
        return new TenantImageResource($this->images->rename($image, $request->validated('name')));
    }

    public function destroy(TenantImage $image): JsonResponse
    {
        Gate::authorize('images.delete');

        $this->images->delete($image);

        return response()->json(status: 204);
    }

    /**
     * Put another library image in place of this one in every template (e.g. a new logo).
     */
    public function replace(Request $request, TenantImage $image, TenantImageRepositoryInterface $library): JsonResponse
    {
        abort_unless(Gate::check(['images.update', 'templates.update']), 403);

        $request->validate(['with' => ['required', 'string', 'size:26']]);
        $replacement = $library->findByPublicId($request->string('with')) ?? throw new NotFoundHttpException;

        return response()->json(['data' => ['templates' => $this->images->replaceEverywhere($image, $replacement)]]);
    }

    /**
     * Image file behind a signed link.
     */
    public function file(Request $request, string $image): StreamedResponse
    {
        return $this->images->stream($image, (string) $request->query('v'));
    }
}
