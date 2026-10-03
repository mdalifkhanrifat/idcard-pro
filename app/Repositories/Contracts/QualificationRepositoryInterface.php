<?php

namespace App\Repositories\Contracts;

use App\Models\Cardholder;
use App\Models\Qualification;
use Illuminate\Support\Collection;

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
}
