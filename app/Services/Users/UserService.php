<?php

namespace App\Services\Users;

use App\Data\Users\UserFilter;
use App\Enums\UserStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Support\Audit\RecordedChanges;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Manages the users of the current tenant.
 */
class UserService
{
    public function __construct(private UserRepositoryInterface $users) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function list(UserFilter $filter): LengthAwarePaginator
    {
        return $this->users->list($filter);
    }

    /**
     * @param  array<string, mixed>  $data  validated input (may contain `roles`)
     */
    public function create(array $data): User
    {
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
    public function update(User $user, array $data): User
    {
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
        $this->guardLastOwner($user, [], UserStatus::Inactive->value);

        $user->tokens()->delete();
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
