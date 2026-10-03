<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\SaveRoleRequest;
use App\Http\Resources\RoleResource;
use App\Services\Roles\RoleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class RoleController extends Controller
{
    public function __construct(private RoleService $roles) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('roles.view');

        return RoleResource::collection($this->roles->list());
    }

    public function store(SaveRoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($request->validated('name'), $request->permissions());

        return (new RoleResource($role))->response()->setStatusCode(201);
    }

    public function update(SaveRoleRequest $request, int $role): RoleResource
    {
        $model = $this->roles->findOrFail($role);

        return new RoleResource($this->roles->update($model, $request->validated('name'), $request->permissions()));
    }

    public function destroy(int $role): JsonResponse
    {
        Gate::authorize('roles.delete');

        $this->roles->delete($this->roles->findOrFail($role));

        return response()->json(status: 204);
    }
}
