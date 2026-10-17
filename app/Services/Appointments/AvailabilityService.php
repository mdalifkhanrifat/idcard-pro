<?php

namespace App\Services\Appointments;

use App\Data\Appointments\Availability;
use App\Enums\BookingAudience;
use App\Models\AppointmentClosure;
use App\Models\AppointmentLocation;
use App\Repositories\Contracts\AppointmentClosureRepositoryInterface;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * What a place has free for a kind of visit, day by day, by the organization's settings (on, how booking works,
 * slot length, notice, days ahead, the kind's slots and whether it is public), the place's week, capacities
 * and kinds, its closures and what is already booked (AppointmentLoad). Only reads; days are the
 * organization's and times its wall clock, so a day of a daylight-saving change is right too. Staff, the API,
 * the public page (5.4–5.6) all ask this.
 */
class AvailabilityService
{
    public function __construct(
        private TenantContext $tenant,
        private AppointmentClosureRepositoryInterface $closures,
        private AppointmentLoad $load,
    ) {}

    /**
     * Days from one to another (Y-m-d, both whole) within what may be booked: from today (the organization's)
     * to `days_ahead` on; earlier and later days are left out. Staff may book up to now, the public link at
     * least `notice_hours` ahead and only kinds offered there. A preview shows it as it will be once
     * appointments and the place are turned on (setting up before turning them on); every other rule holds.
     * `except` (an appointment's id) leaves that booking out of what is taken: the one being moved.
     */
    public function for(AppointmentLocation $location, string $service, string $from, string $to, BookingAudience $audience = BookingAudience::Staff, ?CarbonImmutable $now = null, bool $preview = false, ?int $except = null): Availability
    {
        $tenant = $this->tenant->get();
        $settings = $tenant->appointmentSettings();
        $scheduling = $settings['scheduling'];
        $kind = $settings['services'][$service] ?? null;
        $named = ['key' => $service, 'name' => $kind === null ? $service : AppointmentSettings::serviceName($service, $kind), 'slots' => $kind['slots'] ?? null];
        $unavailable = $this->unavailable($settings, $location, $service, $audience, $preview);
        if ($unavailable !== null) {
            return new Availability($scheduling, $named, $unavailable, []);
        }

        $timezone = $tenant->timezone ?: (string) config('app.timezone');
        $now = ($now ?? CarbonImmutable::now())->setTimezone($timezone);
        $today = $now->startOfDay();
        $first = max($from, $today->toDateString());
        $last = min($to, $today->addDays($settings['days_ahead'])->toDateString());
        if ($first > $last) {
            return new Availability($scheduling, $named, null, [], $first, $last);
        }
        // Staff book up to now; the public link at least some hours ahead
        $earliest = $audience === BookingAudience::Public ? $now->addHours($settings['notice_hours']) : $now;
        $closures = $this->closures->affecting($location, $first, $last);
        $hours = WeeklyHours::normalize($location->hours);
        $weekdays = WeeklyHours::weekdays();

        $days = [];
        $load = null;
        for ($day = CarbonImmutable::parse($first, $timezone); $day->toDateString() <= $last; $day = $day->addDay()) {
            $date = $day->toDateString();
            $periods = $hours[$weekdays[$day->dayOfWeekIso - 1]] ?? [];
            $closure = $closures->first(fn (AppointmentClosure $closure) => $closure->covers($date));
            $entry = ['date' => $date, 'open' => $periods !== [] && $closure === null, 'closure' => $closure === null ? null : [
                'id' => $closure->public_id, 'reason' => $closure->reason, 'reason_local' => $closure->reason_local,
            ]];
            if ($scheduling === 'slot') {
                $load ??= $this->load->slots($location, CarbonImmutable::parse($first, $timezone)->utc(), CarbonImmutable::parse($last, $timezone)->addDay()->utc(), $settings['slot_minutes'], $except);
                $entry['slots'] = $entry['open'] ? $this->slots($location, $day, $periods, $settings['slot_minutes'], $settings['services'][$service]['slots'], $earliest, $load) : [];
            } else {
                $load ??= $this->load->days($location, $first, $last, $except);
                // Served in turn: the day can still be booked while there is time to come before it closes
                $closes = $periods === [] ? null : $this->at($day, (string) end($periods)[1]);
                $bookable = $entry['open'] && $closes !== null && $closes->greaterThan($earliest);
                $booked = (int) ($load[$date] ?? 0);
                $entry += ['capacity' => $location->day_capacity, 'booked' => $booked, 'available' => $bookable ? max(0, $location->day_capacity - $booked) : 0];
            }
            $days[] = $entry;
        }

        return new Availability($scheduling, $named, null, $days, $first, $last);
    }

    /**
     * Why nothing can be booked at all, or null.
     *
     * @param  array<string, mixed>  $settings  the organization's appointment settings
     */
    private function unavailable(array $settings, AppointmentLocation $location, string $service, BookingAudience $audience, bool $preview): ?string
    {
        $kind = $settings['services'][$service] ?? null;

        return match (true) {
            ! $preview && ! $settings['enabled'] => 'off',
            ! in_array($audience->value, $settings['booking'], true) => "not_{$audience->value}",
            ! $preview && ! $location->is_active => 'place_off',
            $kind === null => 'unknown_service',
            ! $kind['enabled'] => 'service_off',
            $audience === BookingAudience::Public && ! $kind['public'] => 'service_not_public',
            $location->services !== null && ! in_array($service, $location->services, true) => 'not_at_place',
            default => null,
        };
    }

    /**
     * A day's starts for a visit of `$take` slots: the run of slots must lie in one opening period, start no
     * earlier than `$earliest`, and each slot's people count against the place's capacity (the fullest slot
     * of the run decides).
     *
     * @param  array<int, array{0: string, 1: string}>  $periods
     * @param  array<int, int>  $load  people booked by a slot's start (Unix timestamp)
     * @return array<int, array{start: string, end: string, capacity: int, booked: int, available: int}>
     */
    private function slots(AppointmentLocation $location, CarbonImmutable $day, array $periods, int $minutes, int $take, CarbonImmutable $earliest, array $load): array
    {
        $slots = [];
        foreach ($periods as [$open, $close]) {
            $end = $this->minutes($close);
            for ($start = $this->minutes($open); $start + $take * $minutes <= $end; $start += $minutes) {
                $run = [];
                for ($i = 0; $i < $take; $i++) {
                    $run[] = $this->at($day, $start + $i * $minutes);
                }
                // A wall-clock time skipped by a daylight-saving change does not exist
                if (in_array(null, $run, true) || $run[0]->lessThan($earliest)) {
                    continue;
                }
                $booked = max(array_map(fn (CarbonImmutable $slot) => (int) ($load[$slot->getTimestamp()] ?? 0), $run));
                $slots[] = [
                    'start' => $run[0]->toIso8601String(),
                    'end' => $run[0]->addMinutes($take * $minutes)->toIso8601String(),
                    'capacity' => $location->slot_capacity,
                    'booked' => $booked,
                    'available' => max(0, $location->slot_capacity - $booked),
                ];
            }
        }

        return $slots;
    }

    /**
     * A day's wall-clock time ("HH:MM", or minutes into the day; 24:00 is the next midnight) in the
     * organization's time zone; null when that time does not exist on the day.
     */
    private function at(CarbonImmutable $day, string|int $time): ?CarbonImmutable
    {
        $minutes = is_int($time) ? $time : $this->minutes($time);
        if ($minutes >= 1440) {
            return $day->addDay()->startOfDay();
        }
        $wall = sprintf('%s %02d:%02d', $day->toDateString(), intdiv($minutes, 60), $minutes % 60);
        $moment = CarbonImmutable::parse($wall, $day->getTimezone());

        return $moment->format('Y-m-d H:i') === $wall ? $moment : null;
    }

    private function minutes(string $time): int
    {
        [$hours, $minutes] = array_map(intval(...), explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
