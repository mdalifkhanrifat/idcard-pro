<?php

namespace App\Repositories\Eloquent;

use App\Data\Cardholders\CardholderFilter;
use App\Models\Cardholder;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\CardholderRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

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
        $query = $this->query()
            ->with(['department', 'unit', 'rank', 'designation', 'bloodGroup', 'photo', 'currentCard:id,public_id,cardholder_id,number,print_count,last_printed_at'])
            // Any card issued to the person or their family: such records cannot be deleted (has_cards)
            ->withExists('cards')
            ->when($filter->search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('full_name', 'like', "%{$search}%")
                ->orWhere('full_name_local', 'like', "%{$search}%")
                ->orWhere('employee_no', 'like', "%{$search}%")
                ->orWhere('secondary_no', 'like', "%{$search}%")
                ->orWhere('national_id', 'like', "%{$search}%")
                ->orWhere('personal_phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")))
            ->when($filter->status, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filter->employmentType, fn (Builder $q, string $type) => $q->where('employment_type', $type))
            ->when($filter->joinedFrom, fn (Builder $q, string $date) => $q->whereDate('joining_date', '>=', $date))
            ->when($filter->joinedTo, fn (Builder $q, string $date) => $q->whereDate('joining_date', '<=', $date))
            ->when($filter->card === 'none', fn (Builder $q) => $q->whereDoesntHave('currentCard'))
            ->when($filter->card === 'pending', fn (Builder $q) => $q->whereHas('currentCard', fn (Builder $c) => $c->where('print_count', 0)))
            ->when($filter->card === 'printed', fn (Builder $q) => $q->whereHas('currentCard', fn (Builder $c) => $c->where('print_count', '>', 0)));

        foreach ($filter->custom as $key => $value) {
            in_array($value, ['true', 'false'], true)
                ? $query->where("custom_fields->{$key}", $value === 'true')
                : $query->where("custom_fields->{$key}", $value);
        }

        foreach ($filter->lookups as $column => $publicId) {
            $query->whereIn($column, MasterDataItem::query()->where('public_id', $publicId)->select('id'));
        }

        return $query
            ->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')
            ->orderBy('id', 'desc')
            ->paginate(min($filter->perPage, 100))
            ->withQueryString();
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
}
