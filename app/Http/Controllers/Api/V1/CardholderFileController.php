<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cardholders\UploadCardholderFileRequest;
use App\Http\Resources\CardholderFileResource;
use App\Models\Cardholder;
use App\Models\CardholderFile;
use App\Services\Cardholders\CardholderFileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CardholderFileController extends Controller
{
    public function __construct(private CardholderFileService $files) {}

    /**
     * Current files of the cardholder; `collection` narrows to one collection, `include_replaced=1` adds
     * archived versions and `family_member` lists that family member's own files instead.
     */
    public function index(Request $request, Cardholder $cardholder): AnonymousResourceCollection
    {
        Gate::authorize('cardholders.view');

        $input = $request->validate([
            'collection' => ['sometimes', 'string', Rule::in(array_keys(config('cardholder_files.collections')))],
            'include_replaced' => ['sometimes', 'boolean'],
            'family_member' => ['sometimes', 'nullable', 'string', 'max:26'],
        ]);
        $member = $this->files->familyMemberOf($cardholder, $input['family_member'] ?? null);

        return CardholderFileResource::collection(
            $this->files->list($cardholder, $input['collection'] ?? null, (bool) ($input['include_replaced'] ?? false), $member),
        );
    }

    public function store(UploadCardholderFileRequest $request, Cardholder $cardholder): JsonResponse
    {
        $file = $this->files->upload(
            $cardholder,
            $request->validated('collection'),
            $request->file('file'),
            $request->validated('title'),
            $request->user(),
            $this->files->familyMemberOf($cardholder, $request->validated('family_member')),
        );

        return (new CardholderFileResource($file))->response()->setStatusCode(201);
    }

    public function destroy(Cardholder $cardholder, CardholderFile $file): JsonResponse
    {
        Gate::authorize('cardholders.update');

        $this->files->delete($file);

        return response()->json(status: 204);
    }

    /**
     * File content behind a short-lived signed link (usable in <img src> and new tabs).
     */
    public function content(string $file): StreamedResponse
    {
        return $this->files->stream($file);
    }
}
