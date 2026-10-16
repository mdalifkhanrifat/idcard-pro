<?php

namespace App\Services\Appointments;

use App\Exceptions\BusinessRuleException;
use App\Models\AppointmentLocation;
use App\Repositories\Contracts\AppointmentLocationRepositoryInterface;
use App\Services\Reports\ReportValueText;
use Illuminate\Support\Collection;

/**
 * Places people come to for an appointment: a branch, an office or a counter, each with its own weekly hours,
 * how many are served at once and per day, and the kinds of visit it takes. Changes are in the audit log.
 */
class AppointmentLocationService
{
    public function __construct(private AppointmentLocationRepositoryInterface $locations) {}

    /**
     * @return Collection<int, AppointmentLocation>
     */
    public function list(?string $search = null, ?bool $isActive = null): Collection
    {
        return $this->locations->list($search, $isActive);
    }

    /**
     * A new place; what is not given starts from config('appointments.locations') (e.g. the week's hours).
     *
     * @param  array<string, mixed>  $data  validated input
     */
    public function create(array $data): AppointmentLocation
    {
        $max = (int) config('appointments.locations.max');
        if ($this->locations->countAll() >= $max) {
            throw new BusinessRuleException(__('appointments.locations.too_many', ['max' => app(ReportValueText::class)->number($max)]));
        }
        $defaults = (array) config('appointments.locations');

        return $this->locations->create($this->prepare([
            'slot_capacity' => $defaults['slot_capacity'],
            'day_capacity' => $defaults['day_capacity'],
            ...$data,
        ], (array) $defaults['hours']));
    }

    /**
     * @param  array<string, mixed>  $data  validated input; what is not sent stays, a weekday of the hours too
     */
    public function update(AppointmentLocation $location, array $data): AppointmentLocation
    {
        return $this->locations->update($location, $this->prepare($data, $location->hours));
    }

    public function delete(AppointmentLocation $location): void
    {
        // Booked places are kept from deletion once bookings exist (5.4); turning one off keeps its history
        $this->locations->delete($location);
    }

    /**
     * Hours with every weekday, periods in order — a weekday not sent keeps its hours (an empty list closes it);
     * kinds of visit without repeats (null stays: every kind).
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentHours  the place's hours (a new one's: the configured week)
     * @return array<string, mixed>
     */
    private function prepare(array $data, array $currentHours): array
    {
        $data['hours'] = WeeklyHours::normalize([...$currentHours, ...(array) ($data['hours'] ?? [])]);
        if (array_key_exists('services', $data) && $data['services'] !== null) {
            $data['services'] = array_values(array_unique((array) $data['services']));
        }

        return $data;
    }
}
