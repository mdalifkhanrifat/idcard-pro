<?php

namespace App\Enums;

/**
 * Where an appointment stands: waiting for staff approval (when the organization approves bookings), confirmed,
 * the person came (checked in), served (done), did not come (no show), or cancelled. Names in
 * lang appointments.statuses.
 */
enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case CheckedIn = 'checked_in';
    case Done = 'done';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    /**
     * Still ahead: counts toward the open bookings a person may hold for a kind of visit.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::CheckedIn], true);
    }

    /**
     * Holds its place in a slot or a day (all but a cancelled one: a past one keeps what it took).
     */
    public function holdsPlace(): bool
    {
        return $this !== self::Cancelled;
    }

    /**
     * @return array<int, string>
     */
    public static function holdingPlace(): array
    {
        return array_values(array_map(fn (self $status) => $status->value, array_filter(self::cases(), fn (self $status) => $status->holdsPlace())));
    }

    /**
     * @return array<int, string>
     */
    public static function open(): array
    {
        return array_values(array_map(fn (self $status) => $status->value, array_filter(self::cases(), fn (self $status) => $status->isOpen())));
    }
}
