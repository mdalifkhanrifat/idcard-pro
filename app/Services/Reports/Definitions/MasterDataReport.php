<?php

namespace App\Services\Reports\Definitions;

use App\Data\MasterData\MasterDataFilter;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Models\MasterDataItem;
use App\Models\User;
use App\Repositories\Contracts\MasterDataRepositoryInterface;
use App\Services\Reports\Concerns\DescribesColumns;
use App\Services\Reports\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * Master data (legacy separate lists of ranks, branches, units, designations... each with Print / PDF / Excel):
 * one list or every list, with the list page's criteria. The lists come from config('master_data.types'), so a
 * new list is in the report without changing it; a child list (unit) narrows by its parent (department).
 *
 * @implements Report<MasterDataItem>
 */
class MasterDataReport implements Report
{
    use DescribesColumns;

    public function __construct(private MasterDataRepositoryInterface $items) {}

    public function key(): string
    {
        return 'master_data';
    }

    public function allows(User $user): bool
    {
        return $user->can('master_data.view');
    }

    public function columns(): array
    {
        $timezone = $this->timezone();
        $column = fn (string $key, string $type, \Closure $value) => [$key => $this->column($key, $type, 'master_data', $value)];

        return [
            ...$column('master_data.type', ReportColumn::TEXT, fn (MasterDataItem $item) => $this->typeLabel($item->type)),
            ...$column('master_data.name', ReportColumn::TEXT, fn (MasterDataItem $item) => $item->name),
            ...$column('master_data.name_local', ReportColumn::TEXT, fn (MasterDataItem $item) => $item->name_local),
            ...$column('master_data.code', ReportColumn::TEXT, fn (MasterDataItem $item) => $item->code),
            ...$column('master_data.parent', ReportColumn::TEXT, fn (MasterDataItem $item) => $item->parent?->name),
            ...$column('master_data.status', ReportColumn::TEXT, fn (MasterDataItem $item) => (string) __('reports.values.user_status.'.($item->is_active ? 'active' : 'inactive'))),
            ...$column('master_data.children', ReportColumn::NUMBER, fn (MasterDataItem $item) => (int) $item->getAttribute('children_count')),
            ...$column('master_data.sort_order', ReportColumn::NUMBER, fn (MasterDataItem $item) => $item->sort_order),
            ...$column('master_data.created_at', ReportColumn::DATETIME, fn (MasterDataItem $item) => $item->created_at?->setTimezone($timezone)),
            ...$column('master_data.updated_at', ReportColumn::DATETIME, fn (MasterDataItem $item) => $item->updated_at?->setTimezone($timezone)),
        ];
    }

    public function defaultColumns(): array
    {
        return ['master_data.type', 'master_data.name', 'master_data.name_local', 'master_data.code', 'master_data.parent', 'master_data.status'];
    }

    public function filters(): array
    {
        $types = (array) config('master_data.types');
        $children = array_filter($types, fn (array $definition) => filled($definition['parent'] ?? null));

        return [
            new ReportFilterField('type', ReportFilterField::SELECT, $this->columnLabel('master_data.type'), array_map(
                fn (string $type) => ['value' => $type, 'label' => $this->typeLabel($type)],
                array_keys($types),
            )),
            new ReportFilterField('search', ReportFilterField::SEARCH, (string) __('reports.filters.search_master_data')),
            new ReportFilterField('is_active', ReportFilterField::SELECT, $this->columnLabel('master_data.status'), [
                ['value' => '1', 'label' => (string) __('reports.values.user_status.active')],
                ['value' => '0', 'label' => (string) __('reports.values.user_status.inactive')],
            ]),
            // A child list's items inside one item of its parent list, while that child list is chosen
            ...array_map(fn (string $child) => new ReportFilterField(
                (string) $types[$child]['parent'],
                ReportFilterField::MASTER_DATA,
                $this->typeLabel((string) $types[$child]['parent']),
                masterData: (string) $types[$child]['parent'],
                when: ['type' => $child],
            ), array_keys($children)),
        ];
    }

    public function sorts(): array
    {
        return [
            'master_data.name' => 'name',
            'master_data.code' => 'code',
            'master_data.sort_order' => 'sort_order',
            'master_data.updated_at' => 'updated_at',
        ];
    }

    public function defaultSort(): string
    {
        return 'sort_order';
    }

    public function totalKeys(): array
    {
        return ['items', 'active'];
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        return $this->items->report($this->type($query), $this->filter($query));
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->items->reportRows($this->type($query), $this->filter($query));
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['items'];
    }

    public function totals(ReportQuery $query): array
    {
        return $this->items->reportTotals($this->type($query), $this->filter($query));
    }

    /**
     * @param  MasterDataItem  $row
     */
    public function present(Model $row): array
    {
        return ['id' => $row->public_id, 'type' => $row->type, 'name' => $row->name, 'code' => $row->code];
    }

    private function type(ReportQuery $query): ?string
    {
        return $query->filter['type'] ?? null;
    }

    /**
     * The list page's criteria; the parent is the chosen list's own parent filter.
     */
    private function filter(ReportQuery $query): MasterDataFilter
    {
        $type = $this->type($query);
        $parent = $type ? config("master_data.types.{$type}.parent") : null;
        $input = $query->input();
        $input['filter']['parent'] = $parent ? ($query->filter[$parent] ?? null) : null;

        return MasterDataFilter::fromArray($input);
    }

    private function typeLabel(string $type): string
    {
        return (string) (((array) __('reports.master_data_types'))[$type] ?? $type);
    }
}
