<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Repositories\Contracts\CardTemplateRepositoryInterface;
use App\Repositories\Contracts\MasterDataRepositoryInterface;
use App\Repositories\Contracts\UserRepositoryInterface;

/**
 * The criteria of a report in words ("Rank: Captain", "Printed on or after: 01/03/2026"), for documents people
 * read: ids become the names they stand for.
 */
class ReportFilterSummary
{
    public function __construct(
        private MasterDataRepositoryInterface $masterData,
        private CardTemplateRepositoryInterface $templates,
        private UserRepositoryInterface $users,
        private ReportValueText $text,
    ) {}

    /**
     * @return array<int, array{label: string, value: string}>
     */
    public function describe(Report $report, ReportQuery $query): array
    {
        $lines = [];
        foreach ($report->filters() as $field) {
            $value = $query->filter[$field->key] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $lines[] = ['label' => $field->label, 'value' => $this->valueOf($field, $value)];
        }

        return $lines;
    }

    private function valueOf(ReportFilterField $field, string $value): string
    {
        return match ($field->type) {
            ReportFilterField::SELECT => (string) (collect($field->options)->firstWhere('value', $value)['label'] ?? $value),
            ReportFilterField::DATE => $this->text->date($value),
            ReportFilterField::NUMBER => $this->text->number((int) $value),
            ReportFilterField::MASTER_DATA => $this->masterData->findByPublicId($value)?->getAttribute('name') ?? $value,
            ReportFilterField::TEMPLATE => ($template = $this->templates->findByPublicId($value)) ? "{$template->getAttribute('name')} ({$template->getAttribute('code')})" : $value,
            ReportFilterField::USER => $this->users->findByPublicId($value)?->getAttribute('name') ?? $value,
            default => $value,
        };
    }
}
