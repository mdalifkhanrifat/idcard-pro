<?php

namespace App\Repositories\Eloquent;

use App\Models\CardholderSync;
use App\Repositories\Contracts\CardholderSyncRepositoryInterface;

/**
 * @extends EloquentRepository<CardholderSync>
 */
class CardholderSyncRepository extends EloquentRepository implements CardholderSyncRepositoryInterface
{
    protected function model(): string
    {
        return CardholderSync::class;
    }
}
