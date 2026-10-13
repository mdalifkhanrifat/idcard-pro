<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cardholders\UploadCardholderImportRequest;
use App\Http\Resources\CardholderImportResource;
use App\Models\CardholderImport;
use App\Services\Cardholders\CardholderImportService;
use App\Support\ListPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CardholderImportController extends Controller
{
    public function __construct(private CardholderImportService $imports) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('cardholders.import');

        return CardholderImportResource::collection($this->imports->list(ListPage::clamp($request->integer('per_page', 10))));
    }

    /**
     * Upload and analyze a file; nothing is imported until the import is started.
     */
    public function store(UploadCardholderImportRequest $request): JsonResponse
    {
        $import = $this->imports->analyze($request->file('file'), $request->safe()->except('file'), $request->user());

        return (new CardholderImportResource($import))->response()->setStatusCode(201);
    }

    public function show(CardholderImport $import): CardholderImportResource
    {
        Gate::authorize('cardholders.import');

        return new CardholderImportResource($import->load('user'));
    }

    public function start(CardholderImport $import): CardholderImportResource
    {
        Gate::authorize('cardholders.import');

        return new CardholderImportResource($this->imports->start($import));
    }

    public function destroy(CardholderImport $import): JsonResponse
    {
        Gate::authorize('cardholders.import');

        $this->imports->discard($import);

        return response()->json(status: 204);
    }

    public function template(): Response
    {
        Gate::authorize('cardholders.import');

        return response($this->imports->template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="cardholders-template.csv"',
        ]);
    }
}
