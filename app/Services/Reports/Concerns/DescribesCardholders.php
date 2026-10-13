<?php

namespace App\Services\Reports\Concerns;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Enums\CustomFieldType;
use App\Models\Cardholder;
use App\Models\CustomField;
use App\Models\MasterDataItem;
use App\Repositories\Contracts\CustomFieldRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * What reports about cardholders share (the cardholders themselves, their cards, family or records): the
 * cardholder's shape in a row and the filters by any master data list and custom field of the cardholder.
 */
trait DescribesCardholders
{
    /**
     * A column labelled from reports.columns.<key>.
     */
    protected function column(string $key, string $type, string $group, Closure $value): ReportColumn
    {
        return new ReportColumn($key, $this->columnLabel($key), $type, $group, $value);
    }

    /**
     * reports.columns.<key>, read from the list: the keys hold dots (card.reason) that __() would take as nesting.
     */
    protected function columnLabel(string $key): string
    {
        $labels = (array) __('reports.columns');

        return (string) ($labels[$key] ?? $key);
    }

    /**
     * Whether the reader may see something (e.g. cards or biometrics), so a report shows only that to them.
     */
    protected function userCan(string $permission): bool
    {
        return (bool) Auth::user()?->can($permission);
    }

    protected function timezone(): string
    {
        return app(TenantContext::class)->get()?->timezone ?: (string) config('app.timezone');
    }

    /**
     * The cardholder with every master data reference (rank, branch, department...), null when not set: a
     * stable shape for any client.
     *
     * @return array<string, mixed>
     */
    protected function presentCardholder(Cardholder $holder): array
    {
        return [
            'id' => $holder->public_id,
            'full_name' => $holder->full_name,
            'full_name_local' => $holder->full_name_local,
            'employee_no' => $holder->employee_no,
            'lookups' => collect(Cardholder::LOOKUPS)->mapWithKeys(function (string $type) use ($holder) {
                /** @var MasterDataItem|null $item */
                $item = $holder->getRelationValue(Str::camel($type));

                return [$type => $item ? ['id' => $item->public_id, 'name' => $item->name, 'code' => $item->code] : null];
            })->all(),
        ];
    }

    /**
     * A filter for every master data list a cardholder refers to (a child list inside its parent's item).
     *
     * @return array<int, ReportFilterField>
     */
    protected function lookupFilters(): array
    {
        // Labelled like the columns of the same lists
        $labels = (array) __('reports.fields');

        return array_values(array_map(fn (string $type) => new ReportFilterField(
            $type,
            ReportFilterField::MASTER_DATA,
            (string) ($labels[$type] ?? $type),
            masterData: $type,
            parent: config("master_data.types.{$type}.parent"),
        ), Cardholder::LOOKUPS));
    }

    /**
     * A filter for every active drop-down and yes/no custom field of cardholders (`custom.<key>`, an exact value,
     * as the cardholder list filters them); a new such field becomes a filter without changing any report.
     *
     * @return array<int, ReportFilterField>
     */
    protected function customFieldFilters(): array
    {
        $local = app()->getLocale() !== 'en';
        $yesNo = [['value' => 'true', 'label' => (string) __('reports.values.yes')], ['value' => 'false', 'label' => (string) __('reports.values.no')]];

        return app(CustomFieldRepositoryInterface::class)->listFor('cardholder', true)
            ->filter(fn (CustomField $field) => in_array($field->type, [CustomFieldType::Select, CustomFieldType::Checkbox], true))
            ->map(fn (CustomField $field) => new ReportFilterField(
                "custom.{$field->key}",
                ReportFilterField::SELECT,
                $local && filled($field->label_local) ? (string) $field->label_local : $field->label,
                $field->type === CustomFieldType::Checkbox
                    ? $yesNo
                    : array_map(fn (string $option) => ['value' => $option, 'label' => $option], $field->options ?? []),
            ))
            ->values()
            ->all();
    }
}
