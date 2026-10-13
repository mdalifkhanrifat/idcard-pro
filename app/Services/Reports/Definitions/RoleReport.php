<?php

namespace App\Services\Reports\Definitions;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Models\Role;
use App\Models\User;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Services\Reports\Concerns\DescribesColumns;
use App\Services\Reports\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * The organization's roles (legacy "Role" list with Print / PDF / Excel): how many users have each and what each
 * may do (permission names as the API knows them, for comparing with another system).
 *
 * @implements Report<Role>
 */
class RoleReport implements Report
{
    use DescribesColumns;

    public function __construct(private RoleRepositoryInterface $roles) {}

    public function key(): string
    {
        return 'roles';
    }

    public function allows(User $user): bool
    {
        return $user->can('roles.view');
    }

    public function columns(): array
    {
        $timezone = $this->timezone();
        $column = fn (string $key, string $type, \Closure $value) => [$key => $this->column($key, $type, 'role', $value)];

        return [
            ...$column('role.name', ReportColumn::TEXT, fn (Role $role) => $role->name),
            ...$column('role.users', ReportColumn::NUMBER, fn (Role $role) => (int) $role->getAttribute('users_count')),
            ...$column('role.permission_count', ReportColumn::NUMBER, fn (Role $role) => (int) $role->getAttribute('permissions_count')),
            ...$column('role.permissions', ReportColumn::TEXT, fn (Role $role) => $role->permissions->pluck('name')->sort()->implode(', ') ?: null),
            ...$column('role.created_at', ReportColumn::DATETIME, fn (Role $role) => $role->created_at?->setTimezone($timezone)),
        ];
    }

    public function defaultColumns(): array
    {
        return ['role.name', 'role.users', 'role.permission_count', 'role.created_at'];
    }

    public function filters(): array
    {
        return [new ReportFilterField('search', ReportFilterField::SEARCH, $this->columnLabel('role.name'))];
    }

    public function sorts(): array
    {
        return ['role.name' => 'name', 'role.users' => 'users_count', 'role.permission_count' => 'permissions_count', 'role.created_at' => 'created_at'];
    }

    public function defaultSort(): string
    {
        return 'name';
    }

    public function totalKeys(): array
    {
        return ['roles'];
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        return $this->roles->report($query->filter['search'] ?? null, ltrim($query->sort, '-'), str_starts_with($query->sort, '-'), $query->perPage);
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->roles->reportRows($query->filter['search'] ?? null, ltrim($query->sort, '-'), str_starts_with($query->sort, '-'));
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['roles'];
    }

    public function totals(ReportQuery $query): array
    {
        return ['roles' => $this->roles->report($query->filter['search'] ?? null, 'name', false, 1)->total()];
    }

    /**
     * @param  Role  $row
     */
    public function present(Model $row): array
    {
        return ['id' => $row->getKey(), 'name' => $row->name];
    }
}
