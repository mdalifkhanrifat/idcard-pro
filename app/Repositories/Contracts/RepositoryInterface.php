<?php

namespace App\Repositories\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Basic persistence operations shared by every repository.
 *
 * @template TModel of Model
 */
interface RepositoryInterface
{
    /**
     * @return TModel|null
     */
    public function findByPublicId(string $publicId): ?Model;

    /**
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function create(array $attributes): Model;

    /**
     * @param  TModel  $model
     * @param  array<string, mixed>  $attributes
     * @return TModel
     */
    public function update(Model $model, array $attributes): Model;

    /**
     * @param  TModel  $model
     */
    public function delete(Model $model): void;
}
