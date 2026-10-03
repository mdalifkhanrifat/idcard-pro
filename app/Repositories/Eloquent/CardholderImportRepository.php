<?php

namespace App\Repositories\Eloquent;

use App\Models\CardholderImport;
use App\Repositories\Contracts\CardholderImportRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends EloquentRepository<CardholderImport>
 */
class CardholderImportRepository extends EloquentRepository implements CardholderImportRepositoryInterface
{
    protected function model(): string
    {
        return CardholderImport::class;
    }

    public function latest(int $perPage): LengthAwarePaginator
    {
        return $this->query()->with('user')->latest('id')->paginate(min($perPage, 50));
    }

    public function findAnyTenant(int $id): ?CardholderImport
    {
        return CardholderImport::query()->withoutGlobalScopes()->with(['tenant', 'user'])->find($id);
    }
}
