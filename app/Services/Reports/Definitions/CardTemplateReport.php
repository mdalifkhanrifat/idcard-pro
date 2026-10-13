<?php

namespace App\Services\Reports\Definitions;

use App\Data\CardTemplates\CardTemplateFilter;
use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Enums\TemplateAudience;
use App\Models\CardTemplate;
use App\Models\User;
use App\Repositories\Contracts\CardTemplateRepositoryInterface;
use App\Services\Reports\Concerns\DescribesColumns;
use App\Services\Reports\Report;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * Card templates (legacy "Template Setup" list: width, height, status) with their kind, audience, sides,
 * validity, authority type and how many cards were issued from each.
 *
 * @implements Report<CardTemplate>
 */
class CardTemplateReport implements Report
{
    use DescribesColumns;

    public function __construct(private CardTemplateRepositoryInterface $templates) {}

    public function key(): string
    {
        return 'card_templates';
    }

    public function allows(User $user): bool
    {
        return $user->can('templates.view');
    }

    public function columns(): array
    {
        $timezone = $this->timezone();
        $yesNo = fn (bool $value) => (string) __('reports.values.'.($value ? 'yes' : 'no'));
        $column = fn (string $key, string $type, \Closure $value) => [$key => $this->column($key, $type, 'template', $value)];
        // 85.60 => 85.6, 54.00 => 54, 100 => 100
        $measure = fn (string $value) => rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');

        return [
            ...$column('template.name', ReportColumn::TEXT, fn (CardTemplate $template) => $template->name),
            ...$column('template.code', ReportColumn::TEXT, fn (CardTemplate $template) => $template->code),
            ...$column('template.card_type', ReportColumn::TEXT, fn (CardTemplate $template) => $template->cardType?->name),
            ...$column('template.audience', ReportColumn::TEXT, fn (CardTemplate $template) => (string) __("reports.values.audience.{$template->audience->value}")),
            ...$column('template.orientation', ReportColumn::TEXT, fn (CardTemplate $template) => (string) __("reports.values.orientation.{$template->orientation->value}")),
            // Legacy "Width" and "Height", as one size
            ...$column('template.size', ReportColumn::TEXT, fn (CardTemplate $template) => $measure($template->width).' × '.$measure($template->height).' '.$template->size_unit),
            ...$column('template.double_sided', ReportColumn::TEXT, fn (CardTemplate $template) => $yesNo($template->is_double_sided)),
            ...$column('template.validity_months', ReportColumn::NUMBER, fn (CardTemplate $template) => $template->validity_months),
            ...$column('template.authority_type', ReportColumn::TEXT, fn (CardTemplate $template) => $template->authorityType?->name),
            ...$column('template.cards', ReportColumn::NUMBER, fn (CardTemplate $template) => (int) $template->getAttribute('cards_count')),
            ...$column('template.version', ReportColumn::NUMBER, fn (CardTemplate $template) => $template->version),
            ...$column('template.status', ReportColumn::TEXT, fn (CardTemplate $template) => (string) __('reports.values.user_status.'.($template->is_active ? 'active' : 'inactive'))),
            ...$column('template.created_at', ReportColumn::DATETIME, fn (CardTemplate $template) => $template->created_at?->setTimezone($timezone)),
            ...$column('template.updated_at', ReportColumn::DATETIME, fn (CardTemplate $template) => $template->updated_at?->setTimezone($timezone)),
        ];
    }

    public function defaultColumns(): array
    {
        return ['template.name', 'template.code', 'template.card_type', 'template.audience', 'template.size', 'template.double_sided', 'template.cards', 'template.status', 'template.created_at'];
    }

    public function filters(): array
    {
        return [
            new ReportFilterField('search', ReportFilterField::SEARCH, (string) __('reports.filters.search_templates')),
            new ReportFilterField('card_type', ReportFilterField::MASTER_DATA, $this->columnLabel('template.card_type'), masterData: 'card_type'),
            new ReportFilterField('audience', ReportFilterField::SELECT, $this->columnLabel('template.audience'), array_map(
                fn (TemplateAudience $audience) => ['value' => $audience->value, 'label' => (string) __("reports.values.audience.{$audience->value}")],
                TemplateAudience::cases(),
            )),
            new ReportFilterField('is_active', ReportFilterField::SELECT, $this->columnLabel('template.status'), [
                ['value' => '1', 'label' => (string) __('reports.values.user_status.active')],
                ['value' => '0', 'label' => (string) __('reports.values.user_status.inactive')],
            ]),
        ];
    }

    public function sorts(): array
    {
        return ['template.name' => 'name', 'template.code' => 'code', 'template.created_at' => 'created_at', 'template.updated_at' => 'updated_at'];
    }

    public function defaultSort(): string
    {
        return 'name';
    }

    public function totalKeys(): array
    {
        return ['templates', 'active'];
    }

    public function paginate(ReportQuery $query): LengthAwarePaginator
    {
        return $this->templates->report(CardTemplateFilter::fromArray($query->input()));
    }

    public function rows(ReportQuery $query): LazyCollection
    {
        return $this->templates->reportRows(CardTemplateFilter::fromArray($query->input()));
    }

    public function count(ReportQuery $query): int
    {
        return $this->totals($query)['templates'];
    }

    public function totals(ReportQuery $query): array
    {
        return $this->templates->reportTotals(CardTemplateFilter::fromArray($query->input()));
    }

    /**
     * @param  CardTemplate  $row
     */
    public function present(Model $row): array
    {
        return ['id' => $row->public_id, 'name' => $row->name, 'code' => $row->code];
    }
}
