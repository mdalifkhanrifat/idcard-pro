<?php

namespace App\Services\Appointments;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * What a user may do with an appointment now (5.5c), so a page only draws the buttons: move it and cancel it
 * (appointments.book), and the steps it can take (appointments.manage) — each allowed or with the reason why
 * not, by the very checks the change itself makes (AppointmentStatusService::problem(), AppointmentBookingService::
 * moveProblem()). What the user's role never allows is left out (null, or no step), so nothing is offered that
 * could not be; cancelling is offered once — as `cancel` to those who book, else as a step to those who manage.
 * Each step is named for what it does ("Approve", "Back to confirmed") in the reader's language and marked when
 * it goes back from a mistake.
 */
class AppointmentActions
{
    public function __construct(
        private AppointmentStatusService $statuses,
        private AppointmentBookingService $booking,
    ) {}

    /**
     * @return array{move: array{allowed: bool, reason: string|null}|null, cancel: array{allowed: bool, reason: string|null}|null, steps: array<int, array{status: array{key: string, name: string}, label: string, correction: bool, allowed: bool, reason: string|null}>}
     */
    public function for(Appointment $appointment, User $user, ?CarbonImmutable $now = null): array
    {
        $books = $user->can('appointments.book');
        $steps = [];
        if ($user->can('appointments.manage')) {
            $from = $appointment->status;
            foreach ($from->next() as $to) {
                if ($to === AppointmentStatus::Cancelled && $books) {
                    continue;
                }
                $steps[] = [
                    'status' => ['key' => $to->value, 'name' => (string) __("appointments.statuses.{$to->value}")],
                    'label' => (string) __("appointments.steps.{$from->value}.{$to->value}"),
                    'correction' => $from->isCorrection($to),
                    ...$this->verdict($this->statuses->problem($appointment, $to, $now)),
                ];
            }
        }

        return [
            'move' => $books ? $this->verdict($this->booking->moveProblem($appointment)) : null,
            'cancel' => $books ? $this->verdict($this->statuses->cancelProblem($appointment)) : null,
            'steps' => $steps,
        ];
    }

    /**
     * @return array{allowed: bool, reason: string|null}
     */
    private function verdict(?string $problem): array
    {
        return ['allowed' => $problem === null, 'reason' => $problem];
    }
}
