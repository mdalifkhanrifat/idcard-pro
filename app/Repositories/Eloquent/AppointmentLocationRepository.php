<?php

namespace App\Repositories\Eloquent;

use App\Models\AppointmentLocation;
use App\Repositories\Contracts\AppointmentLocationRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<AppointmentLocation>
 */
class AppointmentLocationRepository extends EloquentRepository implements AppointmentLocationRepositoryInterface
{
    protected function model(): string
    {
        return AppointmentLocation::class;
    }

    public function list(?string $search = null, ?bool $isActive = null): Collection
    {
        return $this->query()
            ->when($search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('name', 'like', "%{$search}%")
                ->orWhere('name_local', 'like', "%{$search}%")
                ->orWhere('address', 'like', "%{$search}%")))
            ->when($isActive !== null, fn (Builder $q) => $q->where('is_active', $isActive))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    public function countAll(): int
    {
        return $this->query()->count();
    }

    public function lockForBooking(int $locationId): AppointmentLocation
    {
        return $this->query()->whereKey($locationId)->lockForUpdate()->firstOrFail();
    }

    public function counts(): array
    {
        return ['total' => $this->countAll(), 'active' => $this->query()->where('is_active', true)->count()];
    }
}
