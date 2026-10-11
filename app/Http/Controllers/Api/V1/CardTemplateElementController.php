<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CardTemplates\SaveCardTemplateElementsRequest;
use App\Http\Resources\CardTemplateElementResource;
use App\Models\CardTemplate;
use App\Services\Biometrics\CardBiometricImageService;
use App\Services\CardTemplates\CardTemplateDesignService;
use App\Services\CardTemplates\CardTemplateImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Template designer layout. `meta` carries the template version and, on GET, the designer catalog.
 */
class CardTemplateElementController extends Controller
{
    public function __construct(
        private CardTemplateDesignService $design,
        private CardTemplateImageService $images,
    ) {}

    public function index(CardTemplate $template): AnonymousResourceCollection
    {
        Gate::authorize('templates.view');

        return CardTemplateElementResource::collection($this->design->elements($template))
            ->additional(['meta' => ['version' => $template->version, 'catalog' => $this->design->catalog($template)]]);
    }

    public function update(SaveCardTemplateElementsRequest $request, CardTemplate $template): AnonymousResourceCollection
    {
        $elements = $this->design->save($template, $request->validated('elements'));

        return CardTemplateElementResource::collection($elements)
            ->additional(['meta' => ['version' => $template->version]]);
    }

    /**
     * Values of a real cardholder for the designer preview: ?cardholder=<id>[&family_member=<id>].
     */
    public function preview(Request $request, CardTemplate $template): JsonResponse
    {
        Gate::authorize('templates.view');
        Gate::authorize('cardholders.view');

        $input = $request->validate([
            'cardholder' => ['required', 'string', 'size:26'],
            'family_member' => ['nullable', 'string', 'size:26'],
        ]);

        $seesBiometrics = $request->user()->canAny(CardBiometricImageService::SEE_PERMISSIONS);

        return response()->json(['data' => $this->design->preview($template, $input['cardholder'], $input['family_member'] ?? null, $seesBiometrics)]);
    }

    /**
     * Upload a logo or background image; the returned name is then used in a layout save.
     */
    public function uploadImage(Request $request, CardTemplate $template): JsonResponse
    {
        Gate::authorize('templates.update');

        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);
        $name = $this->images->upload($template, $request->file('file'));

        return response()->json(['data' => [
            'image' => $name,
            'image_url' => $this->images->url($template, $this->images->path($template, $name)),
        ]], 201);
    }

    /**
     * Template image behind a short-lived signed link.
     */
    public function image(string $template, string $image): StreamedResponse
    {
        return $this->images->stream($template, $image);
    }
}
