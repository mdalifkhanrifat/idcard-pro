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
     * Where those who manage appointments may take an appointment from here (5.4d): approve or turn down, came in
     * or did not come, served — and back from each step taken by mistake (from cancelled it takes its place
     * again, so only while there is room).
     *
     * @return array<int, self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::CheckedIn, self::NoShow, self::Cancelled],
            self::CheckedIn => [self::Done, self::Confirmed],
            self::Done => [self::CheckedIn],
            self::NoShow => [self::Confirmed],
            self::Cancelled => [self::Confirmed, self::Pending],
        };
    }

    public function canBecome(self $status): bool
    {
        return in_array($status, $this->next(), true);
    }

    /**
     * Whether a step from here to `$status` goes back from one taken by mistake (5.5c — the page shows these
     * apart from the steps forward): came in → confirmed, and every step from done, did not come or cancelled.
     */
    public function isCorrection(self $status): bool
    {
        return $this->canBecome($status) && match ($this) {
            self::CheckedIn => $status === self::Confirmed,
            self::Done, self::NoShow, self::Cancelled => true,
            default => false,
        };
    }

    /**
     * Still waiting for its day: may be moved to another time or place, or cancelled by those who book.
     */
    public function isAhead(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed], true);
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
