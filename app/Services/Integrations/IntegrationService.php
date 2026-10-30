<?php

namespace App\Services\Integrations;

use App\Data\Integrations\IntegrationFilter;
use App\Enums\UserKind;
use App\Enums\UserStatus;
use App\Models\User;
use App\Repositories\Contracts\IntegrationRepositoryInterface;
use App\Services\Roles\PermissionCeiling;
use App\Support\Audit\RecordedChanges;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

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
        private ApiTokenService $tokens,
        private TenantContext $tenant,
    ) {}

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function list(IntegrationFilter $filter): LengthAwarePaginator
    {
        return $this->integrations->list($filter);
    }

    /**
     * @param  array{name: string, description?: string|null, role: string, status?: string, allowed_ips?: array<int, string>|null}  $data
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
                'allowed_ips' => ($data['allowed_ips'] ?? []) ?: null,
                'password' => Str::password(64),
            ]);
            $this->giveRole($account, $data['role']);

            return $this->integrations->withSummary($account);
        });
    }

    /**
     * Whatever is not sent stays as it is. A name or a description is anyone's to correct; turning an account on or
     * off, changing its role or where it works from is for those who could have given it that role.
     *
     * @param  array{name?: string, description?: string|null, role?: string, status?: string, allowed_ips?: array<int, string>|null}  $data
     */
    public function update(User $account, array $data, User $actor): User
    {
        $before = $account->roles()->pluck('name')->all();
        $role = $data['role'] ?? null;
        $roleChanges = $role !== null && $before !== [$role];
        if (array_key_exists('allowed_ips', $data)) {
            $data['allowed_ips'] = $data['allowed_ips'] ?: null;
        }
        $addressChanges = array_key_exists('allowed_ips', $data) && $data['allowed_ips'] !== $account->allowed_ips;

        if ($roleChanges || $addressChanges || (isset($data['status']) && $data['status'] !== $account->status->value)) {
            $this->ceiling->ensureRoles($actor, $before);
        }
        if ($roleChanges) {
            $this->ceiling->ensureRoles($actor, [$role]);
        }

        return DB::transaction(function () use ($account, $data, $role, $roleChanges): User {
            $this->integrations->update($account, collect($data)->only(['name', 'description', 'status', 'allowed_ips'])->all());
            if ($roleChanges) {
                $this->giveRole($account, $role);
            }

            return $this->integrations->withSummary($account);
        });
    }

    public function find(User $account): User
    {
        return $this->integrations->withSummary($account);
    }

    /**
     * @return Collection<int, PersonalAccessToken>
     */
    public function tokens(User $account): Collection
    {
        return $this->tokens->list($account);
    }

    /**
     * A token acts with the account's role, so making one takes the same reach as giving that role: no one gets a
     * way in stronger than their own.
     *
     * @param  array{name: string, expires_in_days?: int}  $data
     */
    public function issueToken(User $account, array $data, User $actor): NewAccessToken
    {
        $this->ceiling->ensureRoles($actor, $account->roles()->pluck('name')->all());
        $days = $data['expires_in_days'] ?? $this->tenant->get()->integrationSettings()['token_days'];

        return $this->tokens->issue($account, $data['name'], now()->addDays($days));
    }

    /**
     * Anyone who manages integrations can stop a token, whoever's it is: stopping gives no one anything.
     */
    public function revokeToken(User $account, int $tokenId): void
    {
        $this->tokens->revoke($account, $tokenId);
    }

    /**
     * The account leaves the list and its tokens stop for good; the record stays, so the audit log keeps naming it.
     */
    public function delete(User $account, User $actor): void
    {
        $this->ceiling->ensureRoles($actor, $account->roles()->pluck('name')->all());

        DB::transaction(function () use ($account): void {
            $this->tokens->revokeAll($account);
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
