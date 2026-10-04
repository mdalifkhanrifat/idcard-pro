<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CardTemplates\SaveCardTemplateElementsRequest;
use App\Http\Resources\CardTemplateElementResource;
use App\Models\CardTemplate;
use App\Services\CardTemplates\CardTemplateDesignService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Template designer layout. `meta` carries the template version and, on GET, the designer catalog.
 */
class CardTemplateElementController extends Controller
{
    public function __construct(private CardTemplateDesignService $design) {}

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
}
