<?php

namespace App\Repositories\Contracts;

use App\Data\Reports\QualificationReportFilter;
use App\Models\Cardholder;
use App\Models\Qualification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * @extends RepositoryInterface<Qualification>
 */
interface QualificationRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, Qualification>
     */
    public function listFor(Cardholder $cardholder, ?string $kind): Collection;

    public function nextSortOrder(Cardholder $cardholder, string $kind): int;

    /**
     * Education and training records of the report with their cardholders.
     *
     * @return LengthAwarePaginator<int, Qualification>
     */
    public function report(QualificationReportFilter $filter): LengthAwarePaginator;

    /**
     * @return LazyCollection<int, Qualification>
     */
    public function reportRows(QualificationReportFilter $filter): LazyCollection;

    /**
     * @return array{records: int, cardholders: int, education: int, courses: int}
     */
    public function reportTotals(QualificationReportFilter $filter): array;
}
