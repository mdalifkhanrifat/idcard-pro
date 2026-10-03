<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Signatories\SaveSignatoryRequest;
use App\Http\Resources\SignatoryResource;
use App\Models\Signatory;
use App\Services\Signatories\SignatoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignatoryController extends Controller
{
    public function __construct(private SignatoryService $signatories) {}

    /**
     * Filters: filter[search], filter[is_active] (1/0).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('signatories.view');

        $filter = $request->validate([
            'filter' => ['sometimes', 'array:search,is_active'],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.is_active' => ['nullable', 'boolean'],
        ])['filter'] ?? [];

        return SignatoryResource::collection($this->signatories->list(
            $filter['search'] ?? null,
            isset($filter['is_active']) ? filter_var($filter['is_active'], FILTER_VALIDATE_BOOL) : null,
        ));
    }

    public function store(SaveSignatoryRequest $request): JsonResponse
    {
        return (new SignatoryResource($this->signatories->create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(Signatory $signatory): SignatoryResource
    {
        Gate::authorize('signatories.view');

        return new SignatoryResource($signatory->load('authorityType'));
    }

    public function update(SaveSignatoryRequest $request, Signatory $signatory): SignatoryResource
    {
        return new SignatoryResource($this->signatories->update($signatory, $request->validated()));
    }

    public function destroy(Signatory $signatory): JsonResponse
    {
        Gate::authorize('signatories.delete');

        $this->signatories->delete($signatory);

        return response()->json(status: 204);
    }

    public function uploadSignature(Request $request, Signatory $signatory): SignatoryResource
    {
        Gate::authorize('signatories.update');

        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048']]);

        return new SignatoryResource($this->signatories->uploadSignature($signatory, $request->file('file')));
    }

    public function removeSignature(Signatory $signatory): SignatoryResource
    {
        Gate::authorize('signatories.update');

        return new SignatoryResource($this->signatories->removeSignature($signatory));
    }

    /**
     * Signature image behind a short-lived signed link.
     */
    public function signature(Request $request, string $signatory): StreamedResponse
    {
        return $this->signatories->streamSignature($signatory, (string) $request->query('v'));
    }
}
