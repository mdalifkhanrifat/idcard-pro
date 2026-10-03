<?php

namespace App\Repositories\Eloquent;

use App\Models\Cardholder;
use App\Models\Qualification;
use App\Repositories\Contracts\QualificationRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<Qualification>
 */
class QualificationRepository extends EloquentRepository implements QualificationRepositoryInterface
{
    protected function model(): string
    {
        return Qualification::class;
    }

    public function listFor(Cardholder $cardholder, ?string $kind): Collection
    {
        return $this->query()
            ->where('cardholder_id', $cardholder->id)
            ->when($kind, fn (Builder $q, string $value) => $q->where('kind', $value))
            ->orderBy('kind')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function nextSortOrder(Cardholder $cardholder, string $kind): int
    {
        return (int) $this->query()->where('cardholder_id', $cardholder->id)->where('kind', $kind)->max('sort_order') + 1;
    }
}
