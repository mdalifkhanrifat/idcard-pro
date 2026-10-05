<?php

namespace App\Repositories\Eloquent;

use App\Models\Cardholder;
use App\Models\CardholderFile;
use App\Models\FamilyMember;
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

    public function listFor(Cardholder $cardholder, ?string $collection, bool $includeReplaced, ?FamilyMember $member = null): Collection
    {
        return $this->ownedBy($cardholder, $member)
            ->with(['uploader', 'familyMember'])
            ->when($collection, fn (Builder $q, string $name) => $q->where('collection', $name))
            ->when(! $includeReplaced, fn (Builder $q) => $q->whereNull('replaced_at'))
            ->orderBy('collection')
            ->orderByDesc('id')
            ->get();
    }

    public function markReplaced(Cardholder $cardholder, string $collection, ?FamilyMember $member = null): int
    {
        return $this->ownedBy($cardholder, $member)
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

    public function locationsForMember(FamilyMember $member): Collection
    {
        return $this->query()
            ->where('family_member_id', $member->id)
            ->get(['disk', 'path'])
            ->map(fn (CardholderFile $file) => ['disk' => $file->disk, 'path' => $file->path]);
    }

    /**
     * Files of the cardholder themselves, or of one of their family members.
     *
     * @return Builder<CardholderFile>
     */
    private function ownedBy(Cardholder $cardholder, ?FamilyMember $member): Builder
    {
        return $this->query()
            ->where('cardholder_id', $cardholder->id)
            ->when(
                $member,
                fn (Builder $q, FamilyMember $m) => $q->where('family_member_id', $m->id),
                fn (Builder $q) => $q->whereNull('family_member_id'),
            );
    }
}
