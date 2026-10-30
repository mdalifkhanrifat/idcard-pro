<?php

namespace App\Services\Users;

use App\Data\Users\UserFilter;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Integrations\ApiTokenService;
use App\Services\Roles\PermissionCeiling;
use App\Support\Audit\RecordedChanges;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Manages the users of the current tenant.
 */
class UserService
{
    public function __construct(
        private UserRepositoryInterface $users,
        private PermissionCeiling $ceiling,
        private ApiTokenService $tokens,
    ) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function list(UserFilter $filter): LengthAwarePaginator
    {
        return $this->users->list($filter);
    }

    /**
     * The roles this person can give, for the form to offer only those.
     *
     * @return array<int, string>
     */
    public function assignableRoles(User $actor): array
    {
        return $this->ceiling->assignableRoles($actor);
    }

    /**
     * @param  array<string, mixed>  $data  validated input (may contain `roles`)
     */
    public function create(array $data, User $actor): User
    {
        $this->ceiling->ensureRoles($actor, $data['roles'] ?? []);

        return DB::transaction(function () use ($data): User {
            $user = $this->users->create([...$this->attributes($data), 'password' => $data['password']]);
            $user->forceFill(['password_changed_at' => now()])->save();
            $user->syncRoles($data['roles'] ?? []);
            $this->noteRoles($user, []);

            return $user->load('roles');
        });
    }

    /**
     * @param  array<string, mixed>  $data  validated input; `password` and `unlock` are optional
     */
    public function update(User $user, array $data, User $actor): User
    {
        $this->guardReach($user, $data, $actor);
        $this->guardLastOwner($user, $data['roles'] ?? null, (string) $data['status']);

        return DB::transaction(function () use ($user, $data): User {
            $this->users->update($user, $this->attributes($data));

            if (filled($data['password'] ?? null)) {
                $user->forceFill(['password' => $data['password'], 'password_changed_at' => now()]);
            }
            if (! empty($data['unlock'])) {
                $user->forceFill(['locked_until' => null, 'failed_login_attempts' => 0]);
            }
            $user->save();

            if (array_key_exists('roles', $data)) {
                $before = $user->roles()->pluck('name')->all();
                $user->syncRoles($data['roles']);
                $this->noteRoles($user, $before);
            }

            return $user->load('roles');
        });
    }

    /**
     * Roles given must be within the actor's own, and a password, a status or roles can be changed only for someone
     * the actor could have made themselves. Name, e-mail and phone stay correctable for anyone.
     *
     * @param  array<string, mixed>  $data
     */
    private function guardReach(User $user, array $data, User $actor): void
    {
        $before = $user->roles()->pluck('name')->all();
        $after = $data['roles'] ?? $before;

        $this->ceiling->ensureRoles($actor, array_values(array_diff($after, $before)));

        if (filled($data['password'] ?? null) || array_diff($before, $after) !== [] || $data['status'] !== $user->status->value) {
            $this->ceiling->ensureRoles($actor, $before);
        }
    }

    /**
     * A user's roles given or taken, in the audit log (roles live in a pivot table the model's own logging does
     * not see).
     *
     * @param  array<int, string>  $before
     */
    private function noteRoles(User $user, array $before): void
    {
        RecordedChanges::log('user', $user, 'updated', ['roles' => $before], ['roles' => $user->roles()->pluck('name')->all()]);
    }

    public function delete(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw new BusinessRuleException(__('users.cannot_delete_self'));
        }
        $this->ceiling->ensureRoles($actor, $user->roles()->pluck('name')->all());
        $this->guardLastOwner($user, [], UserStatus::Inactive->value);

        $this->tokens->revokeAll($user);
        $this->users->delete($user);
    }

    /**
     * An organization must always keep at least one active Owner.
     *
     * @param  array<int, string>|null  $newRoles  null when roles are not being changed
     */
    private function guardLastOwner(User $user, ?array $newRoles, string $newStatus): void
    {
        if (! $user->hasRole(PermissionRegistry::OWNER_ROLE)) {
            return;
        }

        $staysOwner = ($newRoles === null || in_array(PermissionRegistry::OWNER_ROLE, $newRoles, true))
            && $newStatus === UserStatus::Active->value;

        if (! $staysOwner && $this->users->countActiveWithRole(PermissionRegistry::OWNER_ROLE, $user) === 0) {
            throw new BusinessRuleException(__('users.last_owner'));
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        return collect($data)->only(['name', 'email', 'username', 'phone', 'locale', 'status'])->all();
    }
}
