<?php

namespace App\Services\Reports;

/**
 * A report that names its own totals (e.g. one per status, named as the status is), instead of
 * reports.totals.<key> — so totals that follow a registry need no label written by hand.
 */
interface NamesTotals
{
    /**
     * The total's name in the reader's language, or null for reports.totals.<key>.
     */
    public function totalLabel(string $key): ?string;
}
