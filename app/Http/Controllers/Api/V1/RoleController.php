<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoleResource;
use App\Models\Role;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    public function __construct(private TenantContext $tenantContext) {}

    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('roles.view');

        return RoleResource::collection(
            $this->query()->with('permissions')->withCount('users')->orderBy('name')->get()
        );
    }

    public function store(Request $request, PermissionRegistry $registry): JsonResponse
    {
        Gate::authorize('roles.create');

        $data = $this->validated($request, $registry);
        $role = Role::create(['name' => $data['name'], 'guard_name' => PermissionRegistry::GUARD, 'tenant_id' => $this->tenantContext->id()]);
        $role->syncPermissions($data['permissions']);

        return (new RoleResource($role->load('permissions')))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $role, PermissionRegistry $registry): RoleResource
    {
        Gate::authorize('roles.update');

        $model = $this->query()->findOrFail($role);
        abort_if($model->name === 'Owner', 422, __('roles.owner_locked'));

        $data = $this->validated($request, $registry, $model->id);
        $model->update(['name' => $data['name']]);
        $model->syncPermissions($data['permissions']);

        return new RoleResource($model->load('permissions'));
    }

    public function destroy(int $role): JsonResponse
    {
        Gate::authorize('roles.delete');

        $model = $this->query()->withCount('users')->findOrFail($role);
        abort_if($model->name === 'Owner', 422, __('roles.owner_locked'));
        abort_if($model->users_count > 0, 422, __('roles.in_use'));

        $model->delete();

        return response()->json(status: 204);
    }

    /**
     * @return Builder<Role>
     */
    private function query()
    {
        return Role::query()->where('tenant_id', $this->tenantContext->id());
    }

    /**
     * @return array{name: string, permissions: array<int, string>}
     */
    private function validated(Request $request, PermissionRegistry $registry, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:64', Rule::unique('roles', 'name')->where('tenant_id', $this->tenantContext->id())->ignore($ignoreId)],
            'permissions' => ['array'],
            'permissions.*' => ['string', Rule::in($registry->all()->all())],
        ]) + ['permissions' => []];
    }
}
