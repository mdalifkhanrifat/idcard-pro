<?php

namespace App\Repositories\Eloquent;

use App\Data\Reports\CardPrintReportFilter;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\CardPrint;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\CardPrintRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<CardPrint>
 */
class CardPrintRepository extends EloquentRepository implements CardPrintRepositoryInterface
{
    protected function model(): string
    {
        return CardPrint::class;
    }

    public function listFor(Card $card): Collection
    {
        return $this->query()
            ->with('printer')
            ->where('card_id', $card->id)
            ->latest('printed_at')
            ->latest('id')
            ->get();
    }

    public function report(CardPrintReportFilter $filter): LengthAwarePaginator
    {
        return $this->reportQuery($filter)
            ->with([
                'printer:id,public_id,name',
                'card:id,public_id,number,status,reason,issue_date,expiry_date,card_template_id,cardholder_id,family_member_id',
                'card.template:id,public_id,name,code',
                'card.familyMember:id,public_id,name,relation',
                'card.cardholder',
                ...array_map(fn (string $relation) => "card.cardholder.{$relation}", Cardholder::lookupRelations()),
            ])
            ->orderBy($filter->sort, $filter->descending ? 'desc' : 'asc')
            ->orderBy('id', $filter->descending ? 'desc' : 'asc')
            ->paginate(min($filter->perPage, 100))
            ->withQueryString();
    }

    public function reportTotals(CardPrintReportFilter $filter): array
    {
        $totals = $this->reportQuery($filter)
            ->toBase()
            ->selectRaw('count(*) as prints, coalesce(sum(copies), 0) as copies, count(distinct card_id) as cards')
            ->first();

        return [
            'prints' => (int) ($totals->prints ?? 0),
            'copies' => (int) ($totals->copies ?? 0),
            'cards' => (int) ($totals->cards ?? 0),
        ];
    }

    /**
     * The report's criteria, without order, shared by the rows and the totals.
     *
     * @return Builder<CardPrint>
     */
    private function reportQuery(CardPrintReportFilter $filter): Builder
    {
        $tenant = app(TenantContext::class);
        $query = $this->query()
            ->when($filter->printedFrom, fn (Builder $q, string $date) => $q->where('printed_at', '>=', $tenant->dayStart($date)))
            ->when($filter->printedTo, fn (Builder $q, string $date) => $q->where('printed_at', '<', $tenant->dayStart($date)->addDay()))
            ->when($filter->method, fn (Builder $q, string $method) => $q->where('method', $method))
            ->when($filter->batch, fn (Builder $q, string $batch) => $q->where('batch', $batch))
            ->when($filter->printedBy, fn (Builder $q, string $id) => $q->whereHas('printer', fn (Builder $u) => $u->where('public_id', $id)))
            ->when($filter->search, fn (Builder $q, string $search) => $q->whereHas('card', fn (Builder $c) => $c->where(fn (Builder $w) => $w
                ->where('number', 'like', "%{$search}%")
                ->orWhere('rf_card_no', 'like', "%{$search}%")
                ->orWhereHas('cardholder', fn (Builder $h) => $h->where('full_name', 'like', "%{$search}%")
                    ->orWhere('full_name_local', 'like', "%{$search}%")
                    ->orWhere('employee_no', 'like', "%{$search}%"))
                ->orWhereHas('familyMember', fn (Builder $f) => $f->where('name', 'like', "%{$search}%")))))
            ->when($filter->templatePublicId, fn (Builder $q, string $id) => $q->whereHas('card.template', fn (Builder $t) => $t->where('public_id', $id)))
            ->when($filter->holder === 'family', fn (Builder $q) => $q->whereHas('card', fn (Builder $c) => $c->whereNotNull('family_member_id')))
            ->when($filter->holder === 'cardholder', fn (Builder $q) => $q->whereHas('card', fn (Builder $c) => $c->whereNull('family_member_id')));

        foreach ($filter->lookups as $column => $publicId) {
            $query->whereHas('card.cardholder', fn (Builder $c) => $c->whereIn($column, MasterDataItem::query()->where('public_id', $publicId)->select('id')));
        }

        return $query;
    }
}
