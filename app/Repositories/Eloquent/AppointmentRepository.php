<?php

namespace App\Repositories\Eloquent;

use App\Data\Appointments\AppointmentFilter;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Repositories\Contracts\AppointmentRepositoryInterface;
use App\Support\ListPage;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * What an appointment is shown with in a list.
     */
    public const LIST_RELATIONS = ['location', 'cardholder', 'familyMember', 'bookedBy'];

    public function list(AppointmentFilter $filter): LengthAwarePaginator
    {
        return $this->ordered($filter)
            ->with(self::LIST_RELATIONS)
            ->paginate(ListPage::clamp($filter->perPage))
            ->withQueryString();
    }

    public function withDetails(Appointment $appointment): Appointment
    {
        return $appointment->loadMissing([...self::LIST_RELATIONS, 'statusChangedBy']);
    }

    public function lockForChange(Appointment $appointment): Appointment
    {
        return $this->query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();
    }

    public function usedServices(): array
    {
        return $this->query()->distinct()->orderBy('service')->pluck('service')->all();
    }

    public function ordered(AppointmentFilter $filter): Builder
    {
        $direction = $filter->descending ? 'desc' : 'asc';

        return $this->criteria($filter)
            ->when($filter->sort === 'date', fn (Builder $q) => $q->orderBy('date', $direction)->orderBy('starts_at', $direction))
            ->when($filter->sort === 'number', fn (Builder $q) => $q->orderBy('number', $direction))
            ->when($filter->sort === 'created_at', fn (Builder $q) => $q->orderBy('created_at', $direction))
            ->orderBy('id', $direction);
    }

    public function criteria(AppointmentFilter $filter): Builder
    {
        $ranged = $filter->from !== null || $filter->to !== null;
        $ids = fn (string $model, string $publicId) => $model::query()->select('id')->where('public_id', $publicId);

        return $this->query()
            ->when($filter->search, fn (Builder $q, string $search) => $q->where(fn (Builder $w) => $w
                ->where('number', 'like', "%{$search}%")
                ->orWhereHas('cardholder', fn (Builder $c) => $c->where('full_name', 'like', "%{$search}%")
                    ->orWhere('full_name_local', 'like', "%{$search}%")
                    ->orWhere('employee_no', 'like', "%{$search}%"))
                ->orWhereHas('familyMember', fn (Builder $f) => $f->where('name', 'like', "%{$search}%"))))
            // Dates are the organization's days, so compared as they are
            ->when($filter->from, fn (Builder $q, string $from) => $q->where('date', '>=', $from))
            ->when($filter->to, fn (Builder $q, string $to) => $q->where('date', '<=', $to))
            ->when(! $ranged && ! $filter->past, fn (Builder $q) => $q->where('date', '>=', app(TenantContext::class)->today()->toDateString()))
            ->when($filter->locationPublicId, fn (Builder $q, string $id) => $q->whereIn('location_id', $ids(AppointmentLocation::class, $id)))
            ->when($filter->statuses !== [], fn (Builder $q) => $q->whereIn('status', $filter->statuses))
            ->when($filter->service, fn (Builder $q, string $service) => $q->where('service', $service))
            ->when($filter->source, fn (Builder $q, string $source) => $q->where('source', $source))
            // A family member's booking is the cardholder's too (cardholder_id is always the cardholder)
            ->when($filter->cardholderPublicId, fn (Builder $q, string $id) => $q->whereIn('cardholder_id', $ids(Cardholder::class, $id)))
            ->when($filter->familyMemberPublicId, fn (Builder $q, string $id) => $q->whereIn('family_member_id', $ids(FamilyMember::class, $id)));
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

    public function heldTimes(int $locationId, CarbonImmutable $from, CarbonImmutable $to, ?int $exceptId = null): Collection
    {
        return $this->query()
            ->select(['id', 'starts_at', 'ends_at'])
            ->where('location_id', $locationId)
            ->whereIn('status', AppointmentStatus::holdingPlace())
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
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

    public function heldDays(int $locationId, string $from, string $to, ?int $exceptId = null): array
    {
        return $this->query()
            ->where('location_id', $locationId)
            ->whereIn('status', AppointmentStatus::holdingPlace())
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->whereBetween('date', [$from, $to])
            ->selectRaw('date, count(*) as people')
            ->groupBy('date')
            ->pluck('people', 'date')
            ->mapWithKeys(fn (mixed $people, mixed $date) => [substr((string) $date, 0, 10) => (int) $people])
            ->all();
    }
}
