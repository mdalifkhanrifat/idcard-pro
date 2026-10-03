<?php

namespace App\Repositories\Eloquent;

use App\Models\CustomField;
use App\Repositories\Contracts\CustomFieldRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<CustomField>
 */
class CustomFieldRepository extends EloquentRepository implements CustomFieldRepositoryInterface
{
    protected function model(): string
    {
        return CustomField::class;
    }

    public function listFor(string $entity, bool $activeOnly): Collection
    {
        return $this->query()
            ->where('entity', $entity)
            ->when($activeOnly, fn (Builder $q) => $q->where('is_active', true))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function keyExists(string $entity, string $key): bool
    {
        return $this->query()->where('entity', $entity)->where('key', $key)->exists();
    }

    public function nextSortOrder(string $entity): int
    {
        return (int) $this->query()->where('entity', $entity)->max('sort_order') + 1;
    }
}
