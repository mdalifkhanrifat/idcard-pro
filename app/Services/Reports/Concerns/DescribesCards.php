<?php

namespace App\Services\Reports\Concerns;

use App\Data\Reports\ReportFilterField;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\MasterDataItem;
use Illuminate\Support\Str;

/**
 * What reports about cards share: the shape of a card, its holder and family member in a row, and the
 * filters by template, holder and any master data of the cardholder.
 */
trait DescribesCards
{
    /**
     * @return array<string, mixed>
     */
    protected function presentCard(Card $card): array
    {
        return [
            'id' => $card->public_id,
            'number' => $card->number,
            'status' => $card->status->value,
            'reason' => $card->reason,
            'issue_date' => $card->issue_date->toDateString(),
            'expiry_date' => $card->expiry_date?->toDateString(),
            'template' => ['id' => $card->template->public_id, 'name' => $card->template->name, 'code' => $card->template->code],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function presentFamilyMember(?FamilyMember $member): ?array
    {
        return $member ? ['id' => $member->public_id, 'name' => $member->name, 'relation' => $member->relation->value] : null;
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

    protected function templateFilter(): ReportFilterField
    {
        return new ReportFilterField('template', ReportFilterField::TEMPLATE, (string) __('reports.filters.template'));
    }

    protected function holderFilter(): ReportFilterField
    {
        return new ReportFilterField('holder', ReportFilterField::SELECT, (string) __('reports.filters.holder'), [
            ['value' => 'cardholder', 'label' => (string) __('reports.filters.holders.cardholder')],
            ['value' => 'family', 'label' => (string) __('reports.filters.holders.family')],
        ]);
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
}
