<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\CardTemplates\CardTemplateFilter;
use App\Enums\TemplateAudience;
use App\Http\Controllers\Controller;
use App\Http\Requests\CardTemplates\SaveCardTemplateRequest;
use App\Http\Resources\CardTemplateResource;
use App\Models\CardTemplate;
use App\Services\CardTemplates\CardTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;

class CardTemplateController extends Controller
{
    public function __construct(private CardTemplateService $templates) {}

    /**
     * Filters: filter[search|card_type|audience|is_active].
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('templates.view');

        $input = $request->validate([
            'filter' => ['sometimes', 'array:search,card_type,audience,is_active'],
            'filter.search' => ['nullable', 'string', 'max:100'],
            'filter.card_type' => ['nullable', 'string', 'size:26'],
            'filter.audience' => ['nullable', new Enum(TemplateAudience::class)],
            'filter.is_active' => ['nullable', 'in:0,1,true,false'],
        ]);

        return CardTemplateResource::collection($this->templates->list(CardTemplateFilter::fromArray($input)));
    }

    public function store(SaveCardTemplateRequest $request): JsonResponse
    {
        return (new CardTemplateResource($this->templates->create($request->validated(), $request->user())))->response()->setStatusCode(201);
    }

    public function show(CardTemplate $template): CardTemplateResource
    {
        Gate::authorize('templates.view');

        return new CardTemplateResource($template->load(['cardType', 'authorityType']));
    }

    public function update(SaveCardTemplateRequest $request, CardTemplate $template): CardTemplateResource
    {
        return new CardTemplateResource($this->templates->update($template, $request->validated()));
    }

    public function destroy(CardTemplate $template): JsonResponse
    {
        Gate::authorize('templates.delete');

        $this->templates->delete($template);

        return response()->json(status: 204);
    }

    public function duplicate(Request $request, CardTemplate $template): JsonResponse
    {
        Gate::authorize('templates.create');

        $name = $request->validate(['name' => ['required', 'string', 'max:255']])['name'];

        return (new CardTemplateResource($this->templates->duplicate($template, $name, $request->user())))->response()->setStatusCode(201);
    }
}
