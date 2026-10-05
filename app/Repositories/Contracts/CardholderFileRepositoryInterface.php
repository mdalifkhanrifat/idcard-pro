<?php

namespace App\Repositories\Contracts;

use App\Models\Cardholder;
use App\Models\CardholderFile;
use App\Models\FamilyMember;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<CardholderFile>
 */
interface CardholderFileRepositoryInterface extends RepositoryInterface
{
    /**
     * Files of the cardholder, or of one of their family members when given.
     *
     * @return Collection<int, CardholderFile>
     */
    public function listFor(Cardholder $cardholder, ?string $collection, bool $includeReplaced, ?FamilyMember $member = null): Collection;

    /**
     * Archive the current files of a collection, for the cardholder or one of their family members.
     *
     * @return int number of files archived
     */
    public function markReplaced(Cardholder $cardholder, string $collection, ?FamilyMember $member = null): int;

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

    /**
     * Stored locations of every file of a family member.
     *
     * @return Collection<int, array{disk: string, path: string}>
     */
    public function locationsForMember(FamilyMember $member): Collection;
}
