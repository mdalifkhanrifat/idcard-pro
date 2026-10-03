<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\QueryBuilder;

class UserController extends Controller
{
    /**
     * Filters: filter[search], filter[status], filter[role], filter[two_factor], filter[locked], filter[created_from], filter[created_to] (Y-m-d)
     * Sorts: name, email, last_login_at, created_at (prefix with - for descending)
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('users.view');

        $users = QueryBuilder::for(User::class)
            ->allowedFilters(
                AllowedFilter::callback('search', fn (Builder $query, string $value) => $query->where(fn (Builder $q) => $q
                    ->where('name', 'like', "%{$value}%")
                    ->orWhere('email', 'like', "%{$value}%")
                    ->orWhere('username', 'like', "%{$value}%")
                    ->orWhere('phone', 'like', "%{$value}%"))),
                AllowedFilter::exact('status'),
                AllowedFilter::callback('role', fn (Builder $query, string $value) => $query->whereHas('roles', fn (Builder $q) => $q->where('name', $value))),
                AllowedFilter::callback('two_factor', fn (Builder $query, mixed $value) => filter_var($value, FILTER_VALIDATE_BOOL)
                    ? $query->whereNotNull('two_factor_confirmed_at')
                    : $query->whereNull('two_factor_confirmed_at')),
                AllowedFilter::callback('locked', fn (Builder $query, mixed $value) => filter_var($value, FILTER_VALIDATE_BOOL)
                    ? $query->where('locked_until', '>', now())
                    : $query->where(fn (Builder $q) => $q->whereNull('locked_until')->orWhere('locked_until', '<=', now()))),
                AllowedFilter::callback('created_from', fn (Builder $query, string $value) => $query->whereDate('created_at', '>=', $value)),
                AllowedFilter::callback('created_to', fn (Builder $query, string $value) => $query->whereDate('created_at', '<=', $value)),
            )
            ->allowedSorts('name', 'email', 'last_login_at', 'created_at')
            ->defaultSort('-created_at')
            ->with('roles')
            ->paginate(min((int) $request->integer('per_page', 15), 100))
            ->withQueryString();

        return UserResource::collection($users);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::create(collect($data)->except(['roles', 'password_confirmation'])->all());
        $user->forceFill(['password_changed_at' => now()])->save();
        $user->syncRoles($data['roles'] ?? []);

        return (new UserResource($user->load('roles')))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('users.view');

        return new UserResource($user->load('roles'));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        $data = $request->validated();

        $this->guardLastOwner($request, $user, $data['roles'] ?? null, $data['status']);

        $user->fill(collect($data)->except(['roles', 'password', 'password_confirmation', 'unlock'])->all());

        if (filled($data['password'] ?? null)) {
            $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()]);
        }

        if ($request->boolean('unlock')) {
            $user->forceFill(['locked_until' => null, 'failed_login_attempts' => 0]);
        }

        $user->save();

        if (array_key_exists('roles', $data)) {
            $user->syncRoles($data['roles']);
        }

        return new UserResource($user->load('roles'));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('users.delete');

        abort_if($user->is($request->user()), 422, __('users.cannot_delete_self'));
        $this->guardLastOwner($request, $user, [], 'inactive');

        $user->tokens()->delete();
        $user->delete();

        return response()->json(status: 204);
    }

    /**
     * Prevent a tenant from losing its last active Owner.
     *
     * @param  array<int, string>|null  $newRoles
     */
    private function guardLastOwner(Request $request, User $user, ?array $newRoles, mixed $newStatus): void
    {
        if (! $user->hasRole('Owner')) {
            return;
        }

        $staysOwner = ($newRoles === null || in_array('Owner', $newRoles, true)) && (string) ($newStatus->value ?? $newStatus) === 'active';
        $otherOwners = User::role('Owner')->whereKeyNot($user->id)->where('status', 'active')->count();

        abort_if(! $staysOwner && $otherOwners === 0, 422, __('users.last_owner'));
    }
}
