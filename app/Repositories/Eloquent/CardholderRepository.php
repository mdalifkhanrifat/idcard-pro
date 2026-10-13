<?php

namespace App\Repositories\Eloquent;

use App\Data\Cardholders\CardholderFilter;
use App\Enums\BiometricStatus;
use App\Models\Cardholder;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\CardholderRepositoryInterface;
use App\Support\ListPage;
use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * @extends EloquentRepository<Cardholder>
 */
class CardholderRepository extends EloquentRepository implements CardholderRepositoryInterface
{
    protected function model(): string
    {
        return Cardholder::class;
    }

    public function list(CardholderFilter $filter): LengthAwarePaginator
    {
        return $this->filtered($filter)
            ->with(['department', 'unit', 'rank', 'designation', 'bloodGroup', 'photo', 'currentCard:id,public_id,cardholder_id,number,print_count,last_printed_at'])
            // Any card issued to the person or their family: such records cannot be deleted (has_cards)
            ->withExists('cards')
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }

    public function report(CardholderFilter $filter): LengthAwarePaginator
    {
        return $this->withReportRelations($this->filtered($filter))
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }

    public function reportRows(CardholderFilter $filter): LazyCollection
    {
        return $this->withReportRelations($this->filtered($filter))->lazy(500);
    }

    public function reportTotals(CardholderFilter $filter): array
    {
        $count = function (?Closure $card = null) use ($filter): int {
            $query = $this->filtered($filter)->reorder();
            // Not through when(): it would call the closure as its condition
            if ($card !== null) {
                $query->whereHas('currentCard', $card);
            }

            return $query->count();
        };
        $cardholders = $count();
        $pending = $count(fn (Builder $c) => $c->where('print_count', 0));
        $printed = $count(fn (Builder $c) => $c->where('print_count', '>', 0));

        return ['cardholders' => $cardholders, 'no_card' => $cardholders - $pending - $printed, 'pending' => $pending, 'printed' => $printed];
    }

    /**
     * The list's criteria and order, shared by the list and the report.
     *
     * @return Builder<Cardholder>
     */
    private function filtered(CardholderFilter $filter): Builder
    {
        $query = $this->query()
            ->when($filter->search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('full_name_local', 'like', "%{$search}%")
                ->orWhere('employee_no', 'like', "%{$search}%")
                ->orWhere('secondary_no', 'like', "%{$search}%")
                ->orWhere('national_id', 'like', "%{$search}%")
                ->orWhere('personal_phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                // Any card of the person or their family, current or earlier
                ->when($filter->searchCards, fn (Builder $c) => $c->orWhereHas('cards', fn (Builder $card) => $card
                    ->where('number', 'like', "%{$search}%")
                    ->orWhere('rf_card_no', 'like', "%{$search}%")))))
            ->when($filter->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filter->employmentType, fn (Builder $q, string $type) => $q->where('employment_type', $type))
            ->when($filter->joinedFrom, fn (Builder $q, string $date) => $q->whereDate('joining_date', '>=', $date))
            ->when($filter->joinedTo, fn (Builder $q, string $date) => $q->whereDate('joining_date', '<=', $date))
            ->when($filter->card === 'none', fn (Builder $q) => $q->whereDoesntHave('currentCard'))
            ->when($filter->card === 'pending', fn (Builder $q) => $q->whereHas('currentCard', fn (Builder $c) => $c->where('print_count', 0)))
            ->when($filter->card === 'printed', fn (Builder $q) => $q->whereHas('currentCard', fn (Builder $c) => $c->where('print_count', '>', 0)))
            ->when($filter->biometrics !== null, fn (Builder $q) => $q->where('biometric_status', $filter->biometrics));

        $query->whereCustomFields($filter->custom);

        foreach ($filter->lookups as $column => $publicId) {
            $query->whereIn($column, MasterDataItem::query()->where('public_id', $publicId)->select('id'));
        }

        return $query
            ->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')
            ->orderBy('id', 'desc');
    }

    /**
     * Everything a report row reads: every master data list, the current card, who recorded the person.
     *
     * @param  Builder<Cardholder>  $query
     * @return Builder<Cardholder>
     */
    private function withReportRelations(Builder $query): Builder
    {
        return $query->with([
            ...Cardholder::lookupRelations(),
            'currentCard:id,public_id,cardholder_id,number,print_count,last_printed_at',
            'creator:id,name',
        ]);
    }

    public function usesMasterDataItem(MasterDataItem $item): bool
    {
        $column = array_search($item->type, Cardholder::LOOKUPS, true);

        return $column !== false && $this->query()->where($column, $item->id)->exists();
    }

    public function findByEmployeeNo(string $employeeNo): ?Cardholder
    {
        return $this->query()->where('employee_no', $employeeNo)->first();
    }

    public function setBiometricStatus(array $ids, BiometricStatus $status): void
    {
        if ($ids !== []) {
            $this->query()->whereKey($ids)->toBase()->update(['biometric_status' => $status->value]);
        }
    }

    public function eachBatch(int $size, callable $callback): void
    {
        $this->query()->select(['id', 'tenant_id'])->with('familyMembers:id,cardholder_id')->chunkById($size, $callback);
    }
}
