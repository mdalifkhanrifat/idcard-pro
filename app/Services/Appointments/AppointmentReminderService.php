<?php

namespace App\Services\Appointments;

use App\Models\Appointment;
use App\Models\Tenant;
use App\Repositories\Contracts\AppointmentReminderRepositoryInterface;
use App\Repositories\Contracts\AppointmentRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use App\Services\Notifications\NotificationService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * Reminders before appointments (5.7c), run every few minutes: for each organization with appointments on, its
 * reminder hours set and the reminder event sent on some channel, every confirmed appointment still ahead whose
 * start less those hours has come — once (AppointmentReminderRepository::claim), not when found later than the
 * grace (config appointments.reminder_grace_minutes), and not when the person was told of it after that moment
 * (booked, moved or confirmed since: they just heard). A whole day's booking starts when its place opens that day.
 * Several due at once for one appointment send the nearest only; the others count as done.
 */
class AppointmentReminderService
{
    public function __construct(
        private TenantRepositoryInterface $tenants,
        private AppointmentRepositoryInterface $appointments,
        private AppointmentReminderRepositoryInterface $reminders,
        private AppointmentNotifications $notifications,
        private TenantContext $tenantContext,
    ) {}

    /**
     * @return array<string, int> organization slug => reminders sent, for organizations that send them
     */
    public function send(?Tenant $only = null, ?CarbonImmutable $now = null): array
    {
        $now = ($now ?? CarbonImmutable::now())->utc();
        $sent = [];
        $each = function (Tenant $tenant) use ($now, &$sent): void {
            $count = $this->tenantContext->run($tenant, fn (): ?int => $this->sendFor($tenant, $now));
            if ($count !== null) {
                $sent[$tenant->slug] = $count;
            }
        };
        $only === null ? $this->tenants->each($each) : $each($only);

        return $sent;
    }

    /**
     * The organization's reminders due now, or null when it sends none.
     */
    private function sendFor(Tenant $tenant, CarbonImmutable $now): ?int
    {
        $settings = $tenant->appointmentSettings();
        $hours = array_values(array_unique($settings['reminders']));
        if (! $settings['enabled'] || $hours === [] || NotificationService::channelsFor($tenant->notificationSettings(), 'appointment_reminder') === []) {
            return null;
        }
        sort($hours);
        $timezone = $tenant->timezone ?: (string) config('app.timezone');
        $oldest = $now->subMinutes((int) config('appointments.reminder_grace_minutes'));
        $local = $now->setTimezone($timezone);

        $count = 0;
        foreach ($this->appointments->confirmedBetween($local->subDay()->toDateString(), $local->addHours((int) max($hours))->addDay()->toDateString()) as $appointment) {
            $start = $this->start($appointment, $timezone);
            if ($start->lte($now)) {
                continue;
            }
            $sentOne = false;
            // The nearest first: with several due at once, the others are only marked done
            foreach ($hours as $before) {
                $due = $start->subHours($before);
                $toldSince = $appointment->updated_at !== null && $appointment->updated_at->gt($due);
                if ($due->gt($now) || $due->lt($oldest) || $toldSince) {
                    continue;
                }
                if ($this->reminders->claim($appointment, $before, $due) && ! $sentOne) {
                    $this->notifications->reminded($appointment);
                    $sentOne = true;
                    $count++;
                }
            }
        }

        return $count;
    }

    /**
     * When the appointment starts (UTC): its time, or for a whole day when its place opens that day (the day's
     * start when it has no hours then).
     */
    private function start(Appointment $appointment, string $timezone): CarbonImmutable
    {
        if ($appointment->starts_at !== null) {
            return $appointment->starts_at->toImmutable()->utc();
        }
        $day = $appointment->date->toDateString();
        $weekday = strtolower(CarbonImmutable::parse($day)->format('D'));
        $opens = WeeklyHours::normalize((array) $appointment->location->hours)[$weekday][0][0] ?? '00:00';

        return CarbonImmutable::parse("{$day} {$opens}", $timezone)->utc();
    }
}
