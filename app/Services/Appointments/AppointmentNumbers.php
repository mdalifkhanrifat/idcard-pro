<?php

namespace App\Services\Appointments;

use App\Models\Tenant;
use App\Repositories\Contracts\CardSequenceRepositoryInterface;
use App\Services\Cards\CardNumberFormat;
use Carbon\CarbonInterface;

/**
 * An appointment's short number in the organization's own format (settings appointments.number_format, e.g.
 * "A-{YY}-{SEQ:5}" → A-26-00042): the card numbers' tokens but {CODE} — {YYYY}, {YY}, {MM} of the day it was
 * booked and one running {SEQ:n}, counted per prefix (so per year with {YY}) on the organization's counters,
 * under a row lock: two bookings at once never share a number.
 */
class AppointmentNumbers
{
    /** Counters of appointment numbers are kept apart from the cards' on the same table. */
    private const KEY = 'appointment:';

    public function __construct(private CardSequenceRepositoryInterface $sequences) {}

    /**
     * What is wrong with a format, in the reader's language, or null.
     */
    public static function problem(string $pattern): ?string
    {
        if (str_contains($pattern, '{CODE}')) {
            return __('appointments.number_format.unknown_token');
        }
        $problem = CardNumberFormat::problem($pattern);

        return $problem === __('cards.number_format.unknown_token') ? __('appointments.number_format.unknown_token') : $problem;
    }

    /**
     * The next number for a booking made on a day of the organization. Call inside a database transaction.
     */
    public function next(Tenant $tenant, CarbonInterface $bookedOn): string
    {
        $format = new CardNumberFormat($tenant->appointmentSettings()['number_format']);
        $prefix = $format->prefix('', $bookedOn);

        return $format->render('', $bookedOn, $this->sequences->increment(self::KEY.$prefix));
    }

    /**
     * What the next number would be, changing nothing (e.g. to show the format at work).
     */
    public function peek(Tenant $tenant, CarbonInterface $bookedOn): string
    {
        $format = new CardNumberFormat($tenant->appointmentSettings()['number_format']);

        return $format->render('', $bookedOn, $this->sequences->peekNext(self::KEY.$format->prefix('', $bookedOn)));
    }
}
