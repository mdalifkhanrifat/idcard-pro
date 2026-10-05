<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fonts\UploadTenantFontRequest;
use App\Http\Resources\TenantFontResource;
use App\Models\TenantFont;
use App\Services\Fonts\TenantFontService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TenantFontController extends Controller
{
    public function __construct(private TenantFontService $fonts) {}

    /**
     * Everyone who sees card templates needs the fonts to draw them.
     */
    public function index(): AnonymousResourceCollection
    {
        abort_unless(Gate::any(['fonts.view', 'templates.view']), 403);

        return TenantFontResource::collection($this->fonts->list());
    }

    public function store(UploadTenantFontRequest $request): JsonResponse
    {
        $font = $this->fonts->upload(
            $request->file('file'),
            $request->validated('family'),
            (int) ($request->validated('weight') ?? 400),
            $request->validated('style') ?? 'normal',
        );

        return (new TenantFontResource($font))->response()->setStatusCode(201);
    }

    public function destroy(TenantFont $font): JsonResponse
    {
        Gate::authorize('fonts.delete');

        $this->fonts->delete($font);

        return response()->json(status: 204);
    }

    /**
     * Font file behind a signed link.
     */
    public function file(Request $request, string $font): StreamedResponse
    {
        return $this->fonts->stream($font, (string) $request->query('v'));
    }
}
