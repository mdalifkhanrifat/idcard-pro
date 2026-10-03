<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomFields\SaveCustomFieldRequest;
use App\Http\Resources\CustomFieldResource;
use App\Models\CustomField;
use App\Services\CustomFields\CustomFieldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class CustomFieldController extends Controller
{
    public function __construct(private CustomFieldService $fields) {}

    /**
     * entity=cardholder (default); active=1 returns only fields in use (for forms).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        abort_unless($request->user()->canAny(['cardholders.view', 'settings.view']), 403);

        $input = $request->validate([
            'entity' => ['sometimes', Rule::in(CustomField::ENTITIES)],
            'active' => ['sometimes', 'boolean'],
        ]);

        return CustomFieldResource::collection($this->fields->list($input['entity'] ?? 'cardholder', (bool) ($input['active'] ?? false)));
    }

    public function store(SaveCustomFieldRequest $request): JsonResponse
    {
        return (new CustomFieldResource($this->fields->create($request->validated())))->response()->setStatusCode(201);
    }

    public function update(SaveCustomFieldRequest $request, CustomField $customField): CustomFieldResource
    {
        return new CustomFieldResource($this->fields->update($customField, $request->validated()));
    }

    public function destroy(CustomField $customField): JsonResponse
    {
        Gate::authorize('settings.update');

        $this->fields->delete($customField);

        return response()->json(status: 204);
    }

    /**
     * Available presets: name => number of fields.
     */
    public function presets(): JsonResponse
    {
        Gate::authorize('settings.update');

        return response()->json(['data' => collect(config('custom_field_presets'))
            ->map(fn (array $preset, string $name) => ['name' => $name, 'group' => $preset['group'] ?? null, 'fields' => count($preset['fields'])])
            ->values()]);
    }

    public function applyPreset(string $preset): JsonResponse
    {
        Gate::authorize('settings.update');

        return response()->json(['created' => $this->fields->applyPreset($preset)]);
    }
}
