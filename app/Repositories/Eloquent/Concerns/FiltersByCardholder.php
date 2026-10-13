<?php

namespace App\Repositories\Eloquent\Concerns;

use App\Models\MasterDataItem;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrowing any record of a cardholder (cards, printings, family members, qualifications...) by the cardholder's
 * master data and custom fields, read one way everywhere.
 */
trait FiltersByCardholder
{
    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  string  $relation  the path to the cardholder, e.g. "cardholder" or "card.cardholder"
     * @param  array<string, string>  $lookups  cardholder column => master data public id
     * @param  array<string, string>  $custom  custom field key => exact value
     */
    protected function whereCardholderMatches(Builder $query, string $relation, array $lookups, array $custom): void
    {
        foreach ($lookups as $column => $publicId) {
            $query->whereHas($relation, fn (Builder $holder) => $holder->whereIn($column, MasterDataItem::query()->where('public_id', $publicId)->select('id')));
        }
        if ($custom !== []) {
            $query->whereHas($relation, fn (Builder $holder) => $holder->whereCustomFields($custom));
        }
    }
}
