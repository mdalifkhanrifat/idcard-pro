<?php

namespace App\Repositories\Contracts;

use App\Models\CustomField;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<CustomField>
 */
interface CustomFieldRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, CustomField>
     */
    public function listFor(string $entity, bool $activeOnly): Collection;

    public function keyExists(string $entity, string $key): bool;

    public function nextSortOrder(string $entity): int;
}
