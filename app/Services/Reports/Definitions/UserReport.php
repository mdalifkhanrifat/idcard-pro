<?php

namespace App\Services\Reports\Definitions;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Data\Users\UserFilter;
use App\Enums\UserStatus;
use App\Models\Role;
use App\Models\User;
use App\Repositories\Contracts\RoleRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;
use App\Services\Reports\Concerns\DescribesColumns;
use App\Services\Reports\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * The organization's users (legacy "User" and "Admin" lists with Print / PDF / Excel) with the user list's own
 * criteria: who they are, their roles, status, two-step sign-in, lock, last sign-in.
 *
 * @implements Report<User>
 */
class UserReport implements Report
{
    use DescribesColumns;

    public function __construct(
        private UserRepositoryInterface $users,
        private RoleRepositoryInterface $roles,
    ) {}

    public function key(): string
    {
        return 'users';
    }

    public function allows(User $user): bool
    {
        return $user->can('users.view');
    }

    public function columns(): array
    {
        $timezone = $this->timezone();
        $yesNo = fn (bool $value) => (string) __('reports.values.'.($value ? 'yes' : 'no'));
        $column = fn (string $key, string $type, \Closure $value) => [$key => $this->column($key, $type, 'user', $value)];

        return [
            ...$column('user.name', ReportColumn::TEXT, fn (User $user) => $user->name),
            ...$column('user.email', ReportColumn::TEXT, fn (User $user) => $user->email),
            ...$column('user.username', ReportColumn::TEXT, fn (User $user) => $user->username),
            ...$column('user.phone', ReportColumn::TEXT, fn (User $user) => $user->phone),
            ...$column('user.roles', ReportColumn::TEXT, fn (User $user) => $user->roles->pluck('name')->sort()->implode(', ') ?: null),
            ...$column('user.status', ReportColumn::TEXT, fn (User $user) => (string) __("reports.values.user_status.{$user->status->value}")),
            ...$column('user.two_factor', ReportColumn::TEXT, fn (User $user) => $yesNo($user->hasTwoFactorEnabled())),
            ...$column('user.locked', ReportColumn::TEXT, fn (User $user) => $yesNo($user->isLocked())),
            ...$column('user.locale', ReportColumn::TEXT, fn (User $user) => $user->locale),
            ...$column('user.last_login_at', ReportColumn::DATETIME, fn (User $user) => $user->last_login_at?->setTimezone($timezone)),
            ...$column('user.created_at', ReportColumn::DATETIME, fn (User $user) => $user->created_at?->setTimezone($timezone)),
        ];
    }

    public function defaultColumns(): array
    {
        // Legacy "Admin" list order (name, email, role, mobile, created), then status and last sign-in
        return ['user.name', 'user.email', 'user.roles', 'user.phone', 'user.created_at', 'user.status', 'user.last_login_at'];
    }

    public function filters(): array
    {
        $yesNo = [['value' => '1', 'label' => (string) __('reports.values.yes')], ['value' => '0', 'label' => (string) __('reports.values.no')]];

        return [
            new ReportFilterField('search', ReportFilterField::SEARCH, (string) __('reports.filters.search_users')),
            new ReportFilterField('status', ReportFilterField::SELECT, $this->columnLabel('user.status'), array_map(
                fn (UserStatus $status) => ['value' => $status->value, 'label' => (string) __("reports.values.user_status.{$status->value}")],
                UserStatus::cases(),
            )),
            // The organization's own roles
            new ReportFilterField('role', ReportFilterField::SELECT, $this->columnLabel('user.roles'), $this->roles->all()
                ->map(fn (Role $role) => ['value' => $role->name, 'label' => $role->name])->values()->all()),
            new ReportFilterField('two_factor', ReportFilterField::SELECT, $this->columnLabel('user.two_factor'), $yesNo),
            new ReportFilterField('locked', ReportFilterField::SELECT, $this->columnLabel('user.locked'), $yesNo),
            new ReportFilterField('created_from', ReportFilterField::DATE, (string) __('reports.filters.added_from')),
            new ReportFilterField('created_to', ReportFilterField::DATE, (string) __('reports.filters.added_to'), after: 'created_from'),
        ];
    }

    public function sorts(): array
    {
        return [
            'user.name' => 'name',
            'user.email' => 'email',
            'user.last_login_at' => 'last_login_at',
            'user.created_at' => 'created_at',
        ];
    }

    public function defaultSort(): string
    {
        return '-created_at';
    }

    public function totalKeys(): array
    {
        return ['users', 'active', 'two_factor'];
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        return $this->users->report($this->filter($query));
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->users->reportRows($this->filter($query));
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['users'];
    }

    public function totals(ReportQuery $query): array
    {
        return $this->users->reportTotals($this->filter($query));
    }

    /**
     * @param  User  $row
     */
    public function present(Model $row): array
    {
        return ['id' => $row->public_id, 'name' => $row->name, 'email' => $row->email];
    }

    /**
     * The user list's own reading of the criteria.
     */
    private function filter(ReportQuery $query): UserFilter
    {
        return UserFilter::fromArray($query->input());
    }
}
