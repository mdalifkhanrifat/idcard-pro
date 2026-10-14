<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportDocument;
use App\Data\Reports\ReportFile;
use App\Data\Reports\ReportQuery;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use App\Services\Reports\Export\ReportExporter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Downloads of any report in any registered format (config('reports.exporters')). A download carries personal
 * data out of the system, so each one is kept in the audit log: who, which report, format, columns, filter,
 * how many rows and whether it was opened to print.
 */
class ReportExportService
{
    /** Largest logo drawn into a document (bigger ones are left out rather than slowing every PDF). */
    private const MAX_LOGO_BYTES = 2 * 1024 * 1024;

    public function __construct(
        private TenantContext $tenant,
        private ReportFilterSummary $summary,
        private ReportValueText $text,
    ) {}

    /**
     * Formats a report can be downloaded in here.
     *
     * @return array<int, string>
     */
    public function formats(): array
    {
        return array_keys(array_filter($this->exporters(), fn (ReportExporter $exporter) => $exporter->available()));
    }

    public function maxRows(): int
    {
        return (int) config('reports.max_export_rows');
    }

    /**
     * Rows one file may hold, per format.
     *
     * @return array<string, int>
     */
    public function limits(): array
    {
        $limits = [];
        foreach ($this->formats() as $format) {
            $limits[$format] = $this->limit($format);
        }

        return $limits;
    }

    /**
     * Columns one file may show, per format that has a limit (a PDF must stay readable on paper).
     *
     * @return array<string, int>
     */
    public function columnLimits(): array
    {
        $limits = [];
        foreach ($this->formats() as $format) {
            $max = $this->exporters()[$format]->maxColumns();
            if ($max !== null) {
                $limits[$format] = $max;
            }
        }

        return $limits;
    }

    /**
     * @param  array<int, ReportColumn>  $columns
     *
     * @throws BusinessRuleException More rows, or columns, than one file of this format may hold
     */
    public function export(Report $report, ReportQuery $query, array $columns, string $format, User $actor, bool $inline = false): ReportFile
    {
        $count = $report->count($query);
        $max = $this->limit($format);
        if ($count > $max) {
            // Numbers in the reader's digits, grouped (Bangla digits for Bangla readers)
            throw new BusinessRuleException(__('reports.export.too_many', ['count' => $this->text->number($count), 'max' => $this->text->number($max)]));
        }

        $exporter = $this->exporters()[$format];
        $maxColumns = $exporter->maxColumns();
        if ($maxColumns !== null && count($columns) > $maxColumns) {
            throw new BusinessRuleException(__('reports.export.too_many_columns', ['count' => $this->text->number(count($columns)), 'max' => $this->text->number($maxColumns), 'format' => strtoupper($format)]));
        }
        $tenant = $this->tenant->get();
        $timezone = $tenant?->timezone ?: (string) config('app.timezone');
        $now = now()->setTimezone($timezone);
        $totals = $report->totals($query);
        $document = new ReportDocument(
            title: (string) __("reports.names.{$report->key()}"),
            organization: (string) $tenant?->name,
            author: $actor->name,
            generatedAt: $now,
            description: (string) __("reports.descriptions.{$report->key()}"),
            logo: $this->logo($tenant?->logo_path),
            filters: $this->summary->describe($report, $query),
            totals: array_map(fn (string $key) => ['label' => (string) __("reports.totals.{$key}"), 'value' => (int) ($totals[$key] ?? 0)], $report->totalKeys()),
            locale: app()->getLocale(),
            timezone: $timezone,
            color: $tenant?->primary_color ?: '#0f766e',
        );

        $path = (string) tempnam(sys_get_temp_dir(), 'report');
        try {
            $exporter->write($path, $document, $columns, $report->rows($query));
        } catch (Throwable $exception) {
            @unlink($path);

            throw $exception;
        }

        activity('reports')
            ->causedBy($actor)
            ->event('exported')
            ->withProperties([
                'report' => $report->key(),
                'format' => $format,
                'rows' => $count,
                'columns' => array_map(fn (ReportColumn $column) => $column->key, $columns),
                'filter' => $query->filter,
                ...($inline ? ['print' => true] : []),
            ])
            ->log('exported');

        return new ReportFile($path, Str::slug($report->key()).'-'.$now->format('Y-m-d-His').'.'.$exporter->extension(), $exporter->contentType(), $count);
    }

    private function limit(string $format): int
    {
        $own = $this->exporters()[$format]->maxRows();

        return $own === null ? $this->maxRows() : min($own, $this->maxRows());
    }

    /**
     * @return array<string, ReportExporter>
     */
    private function exporters(): array
    {
        $exporters = [];
        foreach ((array) config('reports.exporters') as $format => $class) {
            $exporter = app($class);
            assert($exporter instanceof ReportExporter);
            $exporters[$format] = $exporter;
        }

        return $exporters;
    }

    /**
     * The organization's logo as a data: URI, or null when there is none (or it is too large).
     */
    private function logo(?string $path): ?string
    {
        $disk = Storage::disk('public');
        if ($path === null || ! $disk->exists($path) || $disk->size($path) > self::MAX_LOGO_BYTES) {
            return null;
        }

        return 'data:'.($disk->mimeType($path) ?: 'image/png').';base64,'.base64_encode((string) $disk->get($path));
    }
}
