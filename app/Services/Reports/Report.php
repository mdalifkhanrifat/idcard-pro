<?php

namespace App\Services\Reports;

use App\Data\Reports\ReportColumn;
use App\Data\Reports\ReportFilterField;
use App\Data\Reports\ReportQuery;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;

/**
 * A report in the registry (config/reports.php). Adding a report means adding a class like this and a line
 * there: the list of reports, the request rules, the rows, the downloads and the client's page all follow
 * from what it declares. Its data comes from repositories, as everywhere else.
 *
 * @template TRow of Model
 */
interface Report
{
    /**
     * The registry key, e.g. "card_prints".
     */
    public function key(): string;

    /**
     * Whether the user may see this report (beyond reports.view, e.g. a report of biometric data).
     */
    public function allows(User $user): bool;

    /**
     * Every column this report can show, in their natural order, by key.
     *
     * @return array<string, ReportColumn>
     */
    public function columns(): array;

    /**
     * Columns shown when nobody chose any (legacy report's columns first).
     *
     * @return array<int, string>
     */
    public function defaultColumns(): array;

    /**
     * How the report can be filtered.
     *
     * @return array<int, ReportFilterField>
     */
    public function filters(): array;

    /**
     * Columns the report can be sorted by: column key => sort field.
     *
     * @return array<string, string>
     */
    public function sorts(): array;

    /**
     * The order when none is asked for (a sort field, "-" first for descending).
     */
    public function defaultSort(): string;

    /**
     * Keys of the totals the report counts over every page (labels: reports.totals.<key>).
     *
     * @return array<int, string>
     */
    public function totalKeys(): array;

    /**
     * @return LengthAwarePaginator<int, TRow>
     */
    public function paginate(ReportQuery $query): LengthAwarePaginator;

    /**
     * Every row, read a chunk at a time (downloads).
     *
     * @return LazyCollection<int, TRow>
     */
    public function rows(ReportQuery $query): LazyCollection;

    /**
     * How many rows the query finds.
     */
    public function count(ReportQuery $query): int;

    /**
     * @return array<string, int>
     */
    public function totals(ReportQuery $query): array;

    /**
     * A row's fixed shape for API clients (ids and the main facts), next to the chosen columns' values.
     *
     * @param  TRow  $row
     * @return array<string, mixed>
     */
    public function present(Model $row): array;
}
