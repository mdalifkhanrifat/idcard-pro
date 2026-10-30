<?php

namespace App\Repositories\Eloquent;

use App\Data\Integrations\IntegrationFilter;
use App\Models\User;
use App\Repositories\Contracts\IntegrationRepositoryInterface;
use App\Support\ListPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * @extends EloquentRepository<User>
 */
class IntegrationRepository extends EloquentRepository implements IntegrationRepositoryInterface
{
    protected function model(): string
    {
        return User::class;
    }

    protected function query(): Builder
    {
        return parent::query()->integrations();
    }

    public function list(IntegrationFilter $filter): LengthAwarePaginator
    {
        return $this->query()
            ->with('roles')
            ->when($filter->search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")))
            ->when($filter->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filter->role, fn (Builder $q, string $role) => $q->whereHas('roles', fn (Builder $r) => $r->where('name', $role)))
            ->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')
            ->orderBy('id', 'desc')
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }
}
