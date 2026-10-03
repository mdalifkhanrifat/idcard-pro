<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Cardholders\SaveFamilyMemberRequest;
use App\Http\Resources\FamilyMemberResource;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Services\Cardholders\FamilyMemberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class FamilyMemberController extends Controller
{
    public function __construct(private FamilyMemberService $members) {}

    public function index(Cardholder $cardholder): AnonymousResourceCollection
    {
        Gate::authorize('cardholders.view');

        return FamilyMemberResource::collection($this->members->list($cardholder));
    }

    public function store(SaveFamilyMemberRequest $request, Cardholder $cardholder): JsonResponse
    {
        return (new FamilyMemberResource($this->members->create($cardholder, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(SaveFamilyMemberRequest $request, Cardholder $cardholder, FamilyMember $familyMember): FamilyMemberResource
    {
        return new FamilyMemberResource($this->members->update($familyMember, $request->validated()));
    }

    public function destroy(Cardholder $cardholder, FamilyMember $familyMember): JsonResponse
    {
        Gate::authorize('cardholders.update');

        $this->members->delete($familyMember);

        return response()->json(status: 204);
    }
}
