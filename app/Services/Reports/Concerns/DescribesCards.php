<?php

namespace App\Services\Reports\Concerns;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Models\Card;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\MasterDataItem;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Str;

/**
 * What reports about cards share: the shape of a card, its holder and family member in a row, and the
 * filters by template, holder and any master data of the cardholder.
 */
trait DescribesCards
{
    /**
     * A column labelled from reports.columns.<key>.
     */
    protected function column(string $key, string $type, string $group, Closure $value): ReportColumn
    {
        $labels = (array) __('reports.columns');

        return new ReportColumn($key, (string) ($labels[$key] ?? $key), $type, $group, $value);
    }

    protected function timezone(): string
    {
        return app(TenantContext::class)->get()?->timezone ?: (string) config('app.timezone');
    }

    /**
     * Columns of a card and of who it is for, read through the row's card.
     *
     * @param  Closure(object): Card  $cardOf
     * @return array<int, ReportColumn>
     */
    protected function cardColumns(Closure $cardOf): array
    {
        $timezone = $this->timezone();
        // Whole days between calendar dates: both read as plain dates in one time zone
        $today = CarbonImmutable::parse(app(TenantContext::class)->today()->toDateString());

        return [
            $this->column('card.number', ReportColumn::TEXT, 'card', fn (object $row) => $cardOf($row)->number),
            $this->column('card.rf_card_no', ReportColumn::TEXT, 'card', fn (object $row) => $cardOf($row)->rf_card_no),
            $this->column('card.reason', ReportColumn::TEXT, 'card', fn (object $row) => (string) __("cards.reasons.{$cardOf($row)->reason}")),
            $this->column('card.status', ReportColumn::TEXT, 'card', fn (object $row) => (string) __("reports.statuses.{$cardOf($row)->status->value}")),
            $this->column('card.template', ReportColumn::TEXT, 'card', fn (object $row) => $cardOf($row)->template->name),
            $this->column('card.issue_date', ReportColumn::DATE, 'card', fn (object $row) => $cardOf($row)->issue_date),
            $this->column('card.expiry_date', ReportColumn::DATE, 'card', fn (object $row) => $cardOf($row)->expiry_date),
            // Days since the last valid day, in the organization's days (negative: days still left)
            $this->column('card.days_overdue', ReportColumn::NUMBER, 'card', fn (object $row) => ($expiry = $cardOf($row)->expiry_date) ? (int) round(CarbonImmutable::parse($expiry->toDateString())->diffInDays($today)) : null),
            $this->column('card.print_count', ReportColumn::NUMBER, 'card', fn (object $row) => $cardOf($row)->print_count),
            $this->column('card.last_printed_at', ReportColumn::DATETIME, 'card', fn (object $row) => $cardOf($row)->last_printed_at?->setTimezone($timezone)),
            $this->column('card.issued_by', ReportColumn::TEXT, 'card', fn (object $row) => $cardOf($row)->issuer?->name),
            // Legacy "Create Date": when the card was recorded here
            $this->column('card.created_at', ReportColumn::DATETIME, 'card', fn (object $row) => $cardOf($row)->created_at?->setTimezone($timezone)),
            // Who the card is for: the family member on a family card, else the cardholder
            $this->column('holder.name', ReportColumn::TEXT, 'holder', fn (object $row) => $cardOf($row)->familyMember->name ?? $cardOf($row)->cardholder->full_name),
            $this->column('family.relation', ReportColumn::TEXT, 'holder', fn (object $row) => ($relation = $cardOf($row)->familyMember?->relation) ? (string) __("cards.values.relation.{$relation->value}") : null),
        ];
    }

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
