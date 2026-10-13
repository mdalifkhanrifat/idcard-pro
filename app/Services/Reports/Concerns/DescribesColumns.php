<?php

namespace App\Services\Reports\Concerns;

use App\Data\Reports\ReportColumn;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Support\Facades\Auth;

/**
 * What every report shares when it names its columns: labels from reports.columns, the organization's time
 * zone for moments, and whether the reader may see something.
 */
trait DescribesColumns
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
}
