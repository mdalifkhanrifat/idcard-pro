<?php

namespace App\Services\Reports\Definitions;

use App\Data\Reports\ReportColumn;
use App\Models\CardPrint;
use App\Services\Reports\Columns\CardholderColumns;
use App\Services\Reports\Report;
use App\Support\Tenancy\TenantContext;
use Closure;

/**
 * Card print history (legacy "Card Print History": Name, Card Type, Card ID, Remarks, Created at): one row per
 * printing, with the card, the holder and any cardholder field.
 */
class CardPrintReport implements Report
{
    public function __construct(
        private CardholderColumns $cardholderColumns,
        private TenantContext $tenant,
    ) {}

    public function key(): string
    {
        return 'card_prints';
    }

    public function columns(): array
    {
        $labels = (array) __('reports.columns');
        $timezone = $this->tenant->get()?->timezone ?: (string) config('app.timezone');
        $column = fn (string $key, string $type, string $group, Closure $value) => new ReportColumn($key, (string) ($labels[$key] ?? $key), $type, $group, $value);

        $columns = [
            $column('printed_at', ReportColumn::DATETIME, 'print', fn (CardPrint $row) => $row->printed_at->setTimezone($timezone)),
            $column('printed_by', ReportColumn::TEXT, 'print', fn (CardPrint $row) => $row->printer?->name),
            $column('method', ReportColumn::TEXT, 'print', fn (CardPrint $row) => (string) __("reports.methods.{$row->method}")),
            $column('copies', ReportColumn::NUMBER, 'print', fn (CardPrint $row) => $row->copies),
            $column('note', ReportColumn::TEXT, 'print', fn (CardPrint $row) => $row->note),
            $column('batch', ReportColumn::TEXT, 'print', fn (CardPrint $row) => $row->batch),
            $column('card.number', ReportColumn::TEXT, 'card', fn (CardPrint $row) => $row->card->number),
            $column('card.reason', ReportColumn::TEXT, 'card', fn (CardPrint $row) => (string) __("cards.reasons.{$row->card->reason}")),
            $column('card.status', ReportColumn::TEXT, 'card', fn (CardPrint $row) => (string) __("reports.statuses.{$row->card->status->value}")),
            $column('card.issue_date', ReportColumn::DATE, 'card', fn (CardPrint $row) => $row->card->issue_date),
            $column('card.expiry_date', ReportColumn::DATE, 'card', fn (CardPrint $row) => $row->card->expiry_date),
            $column('card.template', ReportColumn::TEXT, 'card', fn (CardPrint $row) => $row->card->template->name),
            // Who the card is for: the family member on a family card, else the cardholder
            $column('holder.name', ReportColumn::TEXT, 'holder', fn (CardPrint $row) => $row->card->familyMember->name ?? $row->card->cardholder->full_name),
            $column('family.relation', ReportColumn::TEXT, 'holder', fn (CardPrint $row) => ($relation = $row->card->familyMember?->relation) ? (string) __("cards.values.relation.{$relation->value}") : null),
        ];

        return [
            ...collect($columns)->keyBy('key')->all(),
            ...$this->cardholderColumns->for(fn (CardPrint $row) => $row->card->cardholder),
        ];
    }

    public function defaultColumns(): array
    {
        // Legacy columns in their order, then who printed and how many copies
        return ['holder.name', 'card.reason', 'card.number', 'note', 'printed_at', 'printed_by', 'copies'];
    }
}
