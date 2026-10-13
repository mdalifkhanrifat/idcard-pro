<?php

namespace App\Repositories\Eloquent;

use App\Data\Reports\QualificationReportFilter;
use App\Models\Cardholder;
use App\Models\Qualification;
use App\Repositories\Contracts\QualificationRepositoryInterface;
use App\Repositories\Eloquent\Concerns\FiltersByCardholder;
use App\Support\ListPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * @extends EloquentRepository<Qualification>
 */
class QualificationRepository extends EloquentRepository implements QualificationRepositoryInterface
{
    use FiltersByCardholder;

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

    public function report(QualificationReportFilter $filter): LengthAwarePaginator
    {
        return $this->withReportRelations($this->reportQuery($filter))
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }

    public function reportRows(QualificationReportFilter $filter): LazyCollection
    {
        return $this->withReportRelations($this->reportQuery($filter))->lazy(500);
    }

    public function reportTotals(QualificationReportFilter $filter): array
    {
        $totals = $this->reportQuery($filter)->reorder()->toBase()
            ->selectRaw("count(*) as records, count(distinct cardholder_id) as cardholders, coalesce(sum(case when kind = 'education' then 1 else 0 end), 0) as education, coalesce(sum(case when kind = 'course' then 1 else 0 end), 0) as courses")
            ->first();

        return [
            'records' => (int) ($totals->records ?? 0),
            'cardholders' => (int) ($totals->cardholders ?? 0),
            'education' => (int) ($totals->education ?? 0),
            'courses' => (int) ($totals->courses ?? 0),
        ];
    }

    /**
     * @return Builder<Qualification>
     */
    private function reportQuery(QualificationReportFilter $filter): Builder
    {
        $query = $this->query()
            ->when($filter->search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('title', 'like', "%{$search}%")
                ->orWhere('institution', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")
                ->orWhere('degree', 'like', "%{$search}%")
                ->orWhere('result', 'like', "%{$search}%")
                ->orWhere('certificate_no', 'like', "%{$search}%")
                ->orWhereHas('cardholder', fn (Builder $h) => $h->where('full_name', 'like', "%{$search}%")
                    ->orWhere('full_name_local', 'like', "%{$search}%")
                    ->orWhere('employee_no', 'like', "%{$search}%"))))
            ->when($filter->kind, fn (Builder $q, string $kind) => $q->where('kind', $kind))
            ->when($filter->yearFrom !== null, fn (Builder $q) => $q->where('year', '>=', $filter->yearFrom))
            ->when($filter->yearTo !== null, fn (Builder $q) => $q->where('year', '<=', $filter->yearTo));
        $this->whereCardholderMatches($query, 'cardholder', $filter->lookups, $filter->custom);

        return $query->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')->orderBy('id', 'desc');
    }

    /**
     * @param  Builder<Qualification>  $query
     * @return Builder<Qualification>
     */
    private function withReportRelations(Builder $query): Builder
    {
        return $query->with(['cardholder', ...array_map(fn (string $relation) => "cardholder.{$relation}", Cardholder::lookupRelations())]);
    }
}
