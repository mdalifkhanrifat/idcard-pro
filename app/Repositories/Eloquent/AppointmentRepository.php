<?php

namespace App\Repositories\Eloquent;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Repositories\Contracts\AppointmentRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * @extends EloquentRepository<Appointment>
 */
class AppointmentRepository extends EloquentRepository implements AppointmentRepositoryInterface
{
    protected function model(): string
    {
        return Appointment::class;
    }

    public function countOpen(int $cardholderId, ?int $familyMemberId, string $service): int
    {
        return $this->query()
            ->where('cardholder_id', $cardholderId)
            ->where('family_member_id', $familyMemberId)
            ->where('service', $service)
            ->whereIn('status', AppointmentStatus::open())
            ->count();
    }

    public function heldTimes(int $locationId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return $this->query()
            ->select(['id', 'starts_at', 'ends_at'])
            ->where('location_id', $locationId)
            ->whereIn('status', AppointmentStatus::holdingPlace())
            ->whereNotNull('starts_at')
            ->where('starts_at', '<', $to->utc()->toDateTimeString())
            ->where('ends_at', '>', $from->utc()->toDateTimeString())
            ->get();
    }

    public function existsFor(?int $cardholderId = null, ?int $familyMemberId = null, ?int $locationId = null): bool
    {
        return $this->query()
            ->when($cardholderId !== null, fn ($q) => $q->where('cardholder_id', $cardholderId))
            ->when($familyMemberId !== null, fn ($q) => $q->where('family_member_id', $familyMemberId))
            ->when($locationId !== null, fn ($q) => $q->where('location_id', $locationId))
            ->exists();
    }

    public function heldDays(int $locationId, string $from, string $to): array
    {
        return $this->query()
            ->where('location_id', $locationId)
            ->whereIn('status', AppointmentStatus::holdingPlace())
            ->whereBetween('date', [$from, $to])
            ->selectRaw('date, count(*) as people')
            ->groupBy('date')
            ->pluck('people', 'date')
            ->mapWithKeys(fn (mixed $people, mixed $date) => [substr((string) $date, 0, 10) => (int) $people])
            ->all();
    }
}
