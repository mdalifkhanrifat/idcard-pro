<?php

namespace App\Services\Integrations;

use App\Data\Integrations\IntegrationFilter;
use App\Enums\UserKind;
use App\Enums\UserStatus;
use App\Models\User;
use App\Repositories\Contracts\IntegrationRepositoryInterface;
use App\Services\Roles\PermissionCeiling;
use App\Support\Audit\RecordedChanges;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An organization's accounts for other systems. Each is a user of its own kind, so it survives whoever made it, acts
 * only inside its organization, is limited by one role like anyone else and names itself in the audit log; it has no
 * e-mail and no password anyone knows, and turning one off stops its tokens until it is turned on again.
 */
class IntegrationService
{
    public function __construct(
        private IntegrationRepositoryInterface $integrations,
        private PermissionCeiling $ceiling,
    ) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function list(IntegrationFilter $filter): LengthAwarePaginator
    {
        return $this->integrations->list($filter);
    }

    /**
     * @param  array{name: string, description?: string|null, role: string, status?: string}  $data
     */
    public function create(array $data, User $actor): User
    {
        $this->ceiling->ensureRoles($actor, [$data['role']]);

        return DB::transaction(function () use ($data): User {
            /** @var User $account */
            $account = $this->integrations->create([
                'kind' => UserKind::Integration,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? UserStatus::Active->value,
                'password' => Str::password(64),
            ]);
            $this->giveRole($account, $data['role']);

            return $account->load('roles');
        });
    }

    /**
     * Whatever is not sent stays as it is. A name or a description is anyone's to correct; turning an account on or
     * off or changing its role is for those who could have given it that role.
     *
     * @param  array{name?: string, description?: string|null, role?: string, status?: string}  $data
     */
    public function update(User $account, array $data, User $actor): User
    {
        $before = $account->roles()->pluck('name')->all();
        $role = $data['role'] ?? null;
        $roleChanges = $role !== null && $before !== [$role];

        if ($roleChanges || (isset($data['status']) && $data['status'] !== $account->status->value)) {
            $this->ceiling->ensureRoles($actor, $before);
        }
        if ($roleChanges) {
            $this->ceiling->ensureRoles($actor, [$role]);
        }

        return DB::transaction(function () use ($account, $data, $role, $roleChanges): User {
            $this->integrations->update($account, collect($data)->only(['name', 'description', 'status'])->all());
            if ($roleChanges) {
                $this->giveRole($account, $role);
            }

            return $account->load('roles');
        });
    }

    /**
     * The account leaves the list and its tokens stop for good; the record stays, so the audit log keeps naming it.
     */
    public function delete(User $account, User $actor): void
    {
        $this->ceiling->ensureRoles($actor, $account->roles()->pluck('name')->all());

        DB::transaction(function () use ($account): void {
            $account->tokens()->delete();
            $this->integrations->delete($account);
        });
    }

    private function giveRole(User $account, string $role): void
    {
        $before = $account->roles()->pluck('name')->all();
        $account->syncRoles([$role]);
        RecordedChanges::log('user', $account, 'updated', ['roles' => $before], ['roles' => [$role]]);
    }
}
