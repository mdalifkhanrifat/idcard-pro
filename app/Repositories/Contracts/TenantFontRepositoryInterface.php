<?php

namespace App\Repositories\Contracts;

use App\Models\TenantFont;
use Illuminate\Support\Collection;

/**
 * @extends RepositoryInterface<TenantFont>
 */
interface TenantFontRepositoryInterface extends RepositoryInterface
{
    /**
     * Fonts of the current organization by family, then weight and style.
     *
     * @return Collection<int, TenantFont>
     */
    public function list(): Collection;

    /**
     * Family names of the current organization.
     *
     * @return array<int, string>
     */
    public function families(): array;

    public function faceExists(string $family, int $weight, string $style): bool;

    /**
     * Other files of the same family (other weights or styles).
     */
    public function otherFacesExist(TenantFont $font): bool;

    /**
     * Find a font regardless of the current tenant (used by signed font links).
     */
    public function findAnyTenant(string $publicId): ?TenantFont;
}
