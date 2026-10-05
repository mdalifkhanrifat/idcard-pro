<?php

namespace App\Repositories\Eloquent;

use App\Models\TenantFont;
use App\Repositories\Contracts\TenantFontRepositoryInterface;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<TenantFont>
 */
class TenantFontRepository extends EloquentRepository implements TenantFontRepositoryInterface
{
    protected function model(): string
    {
        return TenantFont::class;
    }

    public function list(): Collection
    {
        return $this->query()->orderBy('family')->orderBy('weight')->orderBy('style')->get();
    }

    public function families(): array
    {
        return $this->query()->distinct()->orderBy('family')->pluck('family')->all();
    }

    public function faceExists(string $family, int $weight, string $style): bool
    {
        return $this->query()->where(['family' => $family, 'weight' => $weight, 'style' => $style])->exists();
    }

    public function otherFacesExist(TenantFont $font): bool
    {
        return $this->query()->where('family', $font->family)->whereKeyNot($font->getKey())->exists();
    }

    public function findAnyTenant(string $publicId): ?TenantFont
    {
        return TenantFont::query()->withoutGlobalScopes()->where('public_id', $publicId)->first();
    }
}
