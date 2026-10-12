<?php

namespace App\Services\Reports;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The reports in config('reports.reports'), built from the container.
 */
class ReportRegistry
{
    /**
     * @return array<string, Report>
     */
    public function all(): array
    {
        $reports = [];
        foreach ((array) config('reports.reports') as $key => $class) {
            $report = app($class);
            assert($report instanceof Report && $report->key() === $key);
            $reports[$key] = $report;
        }

        return $reports;
    }

    /**
     * @throws NotFoundHttpException An unknown report
     */
    public function get(string $key): Report
    {
        $class = config("reports.reports.{$key}");
        if (! is_string($class)) {
            throw new NotFoundHttpException;
        }
        $report = app($class);
        assert($report instanceof Report);

        return $report;
    }
}
