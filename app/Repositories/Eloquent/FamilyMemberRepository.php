<?php

namespace App\Repositories\Eloquent;

use App\Data\Reports\FamilyMemberReportFilter;
use App\Enums\BiometricStatus;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\FamilyMemberRepositoryInterface;
use App\Repositories\Eloquent\Concerns\FiltersByCardholder;
use App\Support\ListPage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * @extends EloquentRepository<FamilyMember>
 */
class FamilyMemberRepository extends EloquentRepository implements FamilyMemberRepositoryInterface
{
    use FiltersByCardholder;

    protected function model(): string
    {
        return FamilyMember::class;
    }

    public function listFor(Cardholder $cardholder): Collection
    {
        return $this->query()
            ->with(['bloodGroup', 'photo', 'signature'])
            ->where('cardholder_id', $cardholder->id)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function report(FamilyMemberReportFilter $filter): LengthAwarePaginator
    {
        return $this->withReportRelations($this->reportQuery($filter))
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }

    public function reportRows(FamilyMemberReportFilter $filter): LazyCollection
    {
        return $this->withReportRelations($this->reportQuery($filter))->lazy(500);
    }

    public function reportTotals(FamilyMemberReportFilter $filter): array
    {
        $totals = $this->reportQuery($filter)->reorder()->toBase()->selectRaw('count(*) as members, count(distinct cardholder_id) as cardholders')->first();

        return ['members' => (int) ($totals->members ?? 0), 'cardholders' => (int) ($totals->cardholders ?? 0)];
    }

    /**
     * @return Builder<FamilyMember>
     */
    private function reportQuery(FamilyMemberReportFilter $filter): Builder
    {
        $query = $this->query()
            ->when($filter->search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_local', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('national_id', 'like', "%{$search}%")
                ->orWhereHas('cardholder', fn (Builder $h) => $h->where('full_name', 'like', "%{$search}%")
                    ->orWhere('full_name_local', 'like', "%{$search}%")
                    ->orWhere('employee_no', 'like', "%{$search}%"))))
            ->when($filter->relation, fn (Builder $q, string $relation) => $q->where('relation', $relation))
            ->when($filter->gender, fn (Builder $q, string $gender) => $q->where('gender', $gender));
        $this->whereCardholderMatches($query, 'cardholder', $filter->lookups, $filter->custom);

        return $query->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')->orderBy('id', 'desc');
    }

    /**
     * @param  Builder<FamilyMember>  $query
     * @return Builder<FamilyMember>
     */
    private function withReportRelations(Builder $query): Builder
    {
        return $query->with([
            'bloodGroup',
            'currentCard:id,public_id,family_member_id,number,print_count,last_printed_at',
            'cardholder',
            ...array_map(fn (string $relation) => "cardholder.{$relation}", Cardholder::lookupRelations()),
        ]);
    }

    public function nextSortOrder(Cardholder $cardholder): int
    {
        return (int) $this->query()->where('cardholder_id', $cardholder->id)->max('sort_order') + 1;
    }

    public function usesMasterDataItem(MasterDataItem $item): bool
    {
        return $item->type === 'blood_group' && $this->query()->where('blood_group_id', $item->id)->exists();
    }

    public function setBiometricStatus(array $ids, BiometricStatus $status): void
    {
        if ($ids !== []) {
            $this->query()->whereKey($ids)->toBase()->update(['biometric_status' => $status->value]);
        }
    }
}
