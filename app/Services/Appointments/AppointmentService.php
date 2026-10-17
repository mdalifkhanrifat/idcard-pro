<?php

namespace App\Services\Appointments;

use App\Data\Appointments\AppointmentFilter;
use App\Enums\AppointmentSource;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Repositories\Contracts\AppointmentRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Reading appointments (5.4c): the list, an appointment's details and what the list can be filtered by. Seen
 * while the module is off too — bookings are never deleted, so their history stays readable (booking and
 * changing are AppointmentBookingService's, and refused then).
 */
class AppointmentService
{
    public function __construct(private AppointmentRepositoryInterface $appointments) {}

    /**
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function list(AppointmentFilter $filter): LengthAwarePaginator
    {
        return $this->appointments->list($filter);
    }

    public function details(Appointment $appointment): Appointment
    {
        return $this->appointments->withDetails($appointment);
    }

    /**
     * What the list can be filtered by, named in the reader's language: the statuses, the sources, and the kinds
     * of visit — the organization's (new ones appear by themselves) plus any booked since removed from them.
     *
     * @return array{statuses: array<int, array{key: string, name: string}>, sources: array<int, array{key: string, name: string}>, services: array<int, array{key: string, name: string}>}
     */
    public function options(): array
    {
        $names = AppointmentSettings::serviceNames(app(TenantContext::class)->get());
        foreach ($this->appointments->usedServices() as $key) {
            $names[$key] ??= $key;
        }

        return [
            'statuses' => array_map(fn (AppointmentStatus $status): array => ['key' => $status->value, 'name' => (string) __("appointments.statuses.{$status->value}")], AppointmentStatus::cases()),
            'sources' => array_map(fn (AppointmentSource $source): array => ['key' => $source->value, 'name' => (string) __("appointments.sources.{$source->value}")], AppointmentSource::cases()),
            'services' => array_map(fn (string $key, string $name): array => ['key' => $key, 'name' => $name], array_keys($names), $names),
        ];
    }
}
