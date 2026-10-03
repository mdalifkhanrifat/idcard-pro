<?php

namespace App\Repositories\Eloquent;

use App\Models\Cardholder;
use App\Models\CardholderFile;
use App\Repositories\Contracts\CardholderFileRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<CardholderFile>
 */
class CardholderFileRepository extends EloquentRepository implements CardholderFileRepositoryInterface
{
    protected function model(): string
    {
        return CardholderFile::class;
    }

    public function listFor(Cardholder $cardholder, ?string $collection, bool $includeReplaced): Collection
    {
        return $this->query()
            ->with('uploader')
            ->where('cardholder_id', $cardholder->id)
            ->when($collection, fn (Builder $q, string $name) => $q->where('collection', $name))
            ->when(! $includeReplaced, fn (Builder $q) => $q->whereNull('replaced_at'))
            ->orderBy('collection')
            ->orderByDesc('id')
            ->get();
    }

    public function markReplaced(Cardholder $cardholder, string $collection): int
    {
        return $this->query()
            ->where('cardholder_id', $cardholder->id)
            ->where('collection', $collection)
            ->whereNull('replaced_at')
            ->update(['replaced_at' => now()]);
    }

    public function findAnyTenant(string $publicId): ?CardholderFile
    {
        return CardholderFile::query()->withoutGlobalScopes()->where('public_id', $publicId)->first();
    }

    public function locationsFor(Cardholder $cardholder): Collection
    {
        return $this->query()
            ->where('cardholder_id', $cardholder->id)
            ->get(['disk', 'path'])
            ->map(fn (CardholderFile $file) => ['disk' => $file->disk, 'path' => $file->path]);
    }
}
