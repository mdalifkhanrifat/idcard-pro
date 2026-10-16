<?php

namespace App\Services\Appointments;

use App\Services\Reports\ReportValueText;
use Carbon\CarbonImmutable;

/**
 * The days of a closure, checked wherever a closure comes from: the last day not before the first, at most
 * config('appointments.closures.max_days') days in a row, and a yearly one shorter than a year (it comes back).
 */
final class ClosureDates
{
    /**
     * What is wrong with the days (Y-m-d, both whole), by field: messages in the reader's language.
     *
     * @return array<string, string>
     */
    public static function mistakes(string $from, string $to, bool $yearly): array
    {
        $first = CarbonImmutable::parse($from);
        $last = CarbonImmutable::parse($to);
        if ($last->lessThan($first)) {
            return ['date_to' => __('appointments.closures.order')];
        }
        if ($yearly && $last->greaterThanOrEqualTo($first->addYear())) {
            return ['date_to' => __('appointments.closures.yearly_too_long')];
        }
        $max = (int) config('appointments.closures.max_days');
        if ((int) $first->diffInDays($last) + 1 > $max) {
            return ['date_to' => __('appointments.closures.too_long', ['max' => app(ReportValueText::class)->number($max)])];
        }

        return [];
    }
}
