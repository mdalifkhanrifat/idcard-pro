<?php

namespace App\Repositories\Contracts;

use App\Models\Cardholder;
use App\Models\CardholderFile;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<CardholderFile>
 */
interface CardholderFileRepositoryInterface extends RepositoryInterface
{
    /**
     * @return Collection<int, CardholderFile>
     */
    public function listFor(Cardholder $cardholder, ?string $collection, bool $includeReplaced): Collection;

    /**
     * Archive the current files of a collection.
     *
     * @return int number of files archived
     */
    public function markReplaced(Cardholder $cardholder, string $collection): int;

    /**
     * Find a file regardless of the current tenant (used by signed download links).
     */
    public function findAnyTenant(string $publicId): ?CardholderFile;

    /**
     * Stored locations of every file of a cardholder.
     *
     * @return Collection<int, array{disk: string, path: string}>
     */
    public function locationsFor(Cardholder $cardholder): Collection;
}
