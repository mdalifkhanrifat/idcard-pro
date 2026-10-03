<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Users\ListUsersRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class UserController extends Controller
{
    public function __construct(private UserService $users) {}

    /**
     * Filters: filter[search|status|role|two_factor|locked|created_from|created_to].
     * Sorts: name, email, last_login_at, created_at (prefix "-" for descending).
     */
    public function index(ListUsersRequest $request): AnonymousResourceCollection
    {
        return UserResource::collection($this->users->list($request->toFilter()));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        return (new UserResource($this->users->create($request->validated())))->response()->setStatusCode(201);
    }

    public function show(User $user): UserResource
    {
        Gate::authorize('users.view');

        return new UserResource($user->load('roles'));
    }

    public function update(UpdateUserRequest $request, User $user): UserResource
    {
        return new UserResource($this->users->update($user, $request->validated()));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        Gate::authorize('users.delete');

        $this->users->delete($user, $request->user());

        return response()->json(status: 204);
    }
}
