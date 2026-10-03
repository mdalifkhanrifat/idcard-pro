<?php

namespace App\Repositories\Contracts;

use App\Data\Cardholders\CardholderFilter;
use App\Models\Cardholder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<Cardholder>
 */
interface CardholderRepositoryInterface extends RepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, Cardholder>
     */
    public function list(CardholderFilter $filter): LengthAwarePaginator;
}
