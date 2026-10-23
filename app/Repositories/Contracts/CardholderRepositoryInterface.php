<?php

namespace App\Repositories\Contracts;

use App\Data\Cardholders\CardholderFilter;
use App\Enums\BiometricStatus;
use App\Models\Cardholder;
use App\Models\MasterDataItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * @extends RepositoryInterface<Cardholder>
 */
interface CardholderRepositoryInterface extends RepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Cardholder>
     */
    public function list(CardholderFilter $filter): LengthAwarePaginator;

    /**
     * Cardholders of the report (the list's criteria) with everything a row reads.
     *
     * @return LengthAwarePaginator<int, Cardholder>
     */
    public function report(CardholderFilter $filter): LengthAwarePaginator;

    /**
     * Every cardholder of the report, read in batches (downloads).
     *
     * @return LazyCollection<int, Cardholder>
     */
    public function reportRows(CardholderFilter $filter): LazyCollection;

    /**
     * Counts over every page: cardholders, and how many have no card, a card not printed yet, a printed card.
     *
     * @return array{cardholders: int, no_card: int, pending: int, printed: int}
     */
    public function reportTotals(CardholderFilter $filter): array;

    /**
     * Whether any cardholder references the master data item.
     */
    public function usesMasterDataItem(MasterDataItem $item): bool;

    public function findByEmployeeNo(string $employeeNo): ?Cardholder;

    /**
     * A cardholder of the current organization by its own key (e.g. one a public booking session stands for).
     */
    public function findById(int $id): ?Cardholder;

    /**
     * People to book an appointment for (5.5b): the list's own search, an exact employee number first, then by
     * name; with their family members (id, name, relation only).
     *
     * @return Collection<int, Cardholder>
     */
    public function findForBooking(string $search, int $limit): Collection;

    /**
     * Keep the biometric status of cardholders; not an edit of the person (no audit entry, no updated_at).
     *
     * @param  array<int, int>  $ids
     */
    public function setBiometricStatus(array $ids, BiometricStatus $status): void;

    /**
     * Every cardholder of the organization in batches, with their family members' ids.
     *
     * @param  callable(Collection<int, Cardholder>): void  $callback
     */
    public function eachBatch(int $size, callable $callback): void;
}
