<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\QualificationKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cardholders\SaveQualificationRequest;
use App\Http\Resources\QualificationResource;
use App\Models\Cardholder;
use App\Models\Qualification;
use App\Services\Cardholders\QualificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\Enum;

class QualificationController extends Controller
{
    public function __construct(private QualificationService $qualifications) {}

    /**
     * kind=education|course narrows the list.
     */
    public function index(Request $request, Cardholder $cardholder): AnonymousResourceCollection
    {
        Gate::authorize('cardholders.view');

        $kind = $request->validate(['kind' => ['sometimes', new Enum(QualificationKind::class)]])['kind'] ?? null;

        return QualificationResource::collection($this->qualifications->list($cardholder, $kind));
    }

    public function store(SaveQualificationRequest $request, Cardholder $cardholder): JsonResponse
    {
        return (new QualificationResource($this->qualifications->create($cardholder, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(SaveQualificationRequest $request, Cardholder $cardholder, Qualification $qualification): QualificationResource
    {
        return new QualificationResource($this->qualifications->update($qualification, $request->validated()));
    }

    public function destroy(Cardholder $cardholder, Qualification $qualification): JsonResponse
    {
        Gate::authorize('cardholders.update');

        $this->qualifications->delete($qualification);

        return response()->json(status: 204);
    }
}
