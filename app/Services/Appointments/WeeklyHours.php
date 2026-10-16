<?php

namespace App\Services\Appointments;

/**
 * A place's weekly opening hours: every weekday (ISO keys, Monday first) with its opening periods, each
 * ["HH:MM", "HH:MM"] from–to ("24:00" ends at midnight), in order and apart.
 */
final class WeeklyHours
{
    /** A time of day; "24:00" may end the last period. */
    public const TIME = '/^(?:[01]\d|2[0-3]):[0-5]\d$|^24:00$/';

    /**
     * @return array<int, string>
     */
    public static function weekdays(): array
    {
        return array_values((array) config('appointments.weekdays'));
    }

    /**
     * Every weekday present (closed days empty), periods in order.
     *
     * @param  array<string, mixed>  $hours
     * @return array<string, array<int, array{0: string, 1: string}>>
     */
    public static function normalize(array $hours): array
    {
        $week = [];
        foreach (self::weekdays() as $day) {
            $periods = array_values(array_filter((array) ($hours[$day] ?? []), fn (mixed $period) => is_array($period) && count($period) === 2));
            $periods = array_map(fn (array $period): array => [(string) array_values($period)[0], (string) array_values($period)[1]], $periods);
            usort($periods, fn (array $a, array $b) => strcmp($a[0], $b[0]));
            $week[$day] = $periods;
        }

        return $week;
    }

    /**
     * Mistakes in a week of hours, by field ("hours.mon.1" => message key): a period that ends before it
     * starts, or one that begins before the previous one ends. The shape is checked by the request's rules.
     *
     * @param  array<string, mixed>  $hours
     * @return array<string, string>
     */
    public static function mistakes(array $hours): array
    {
        $mistakes = [];
        foreach (self::weekdays() as $day) {
            $periods = array_values((array) ($hours[$day] ?? []));
            $ordered = $periods;
            usort($ordered, fn (array $a, array $b) => strcmp((string) $a[0], (string) $b[0]));
            foreach ($periods as $index => [$from, $to]) {
                if (strcmp((string) $from, (string) $to) >= 0) {
                    $mistakes["hours.{$day}.{$index}"] = 'appointments.locations.period_order';
                }
            }
            for ($i = 1; $i < count($ordered); $i++) {
                if (strcmp((string) $ordered[$i][0], (string) $ordered[$i - 1][1]) < 0) {
                    $mistakes["hours.{$day}"] = 'appointments.locations.periods_overlap';
                }
            }
        }

        return $mistakes;
    }
}
