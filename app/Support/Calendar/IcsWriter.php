<?php

namespace App\Support\Calendar;

use Carbon\CarbonImmutable;

/**
 * A calendar file (iCalendar, RFC 5545) for one event, as calendar apps open it from an e-mail: published (no
 * invitation to answer), times in UTC, a whole day as dates, text escaped and long lines folded at 75 bytes
 * without splitting a character, lines ending in CRLF.
 */
final class IcsWriter
{
    public const MIME = 'text/calendar; charset=utf-8; method=PUBLISH';

    public static function write(CalendarEvent $event, ?CarbonImmutable $now = null): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//IDCard Pro//Appointments//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.self::text($event->uid),
            'SEQUENCE:'.$event->sequence,
            'DTSTAMP:'.self::utc($now ?? CarbonImmutable::now()),
            ...self::when($event),
            'SUMMARY:'.self::text($event->summary),
            ...($event->location !== null && $event->location !== '' ? ['LOCATION:'.self::text($event->location)] : []),
            ...($event->description !== null && $event->description !== '' ? ['DESCRIPTION:'.self::text($event->description)] : []),
            'STATUS:'.$event->status,
            'TRANSP:OPAQUE',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /**
     * @return array<int, string>
     */
    private static function when(CalendarEvent $event): array
    {
        if ($event->start === null) {
            $day = CarbonImmutable::parse($event->date);

            return ['DTSTART;VALUE=DATE:'.$day->format('Ymd'), 'DTEND;VALUE=DATE:'.$day->addDay()->format('Ymd')];
        }

        return ['DTSTART:'.self::utc($event->start), 'DTEND:'.self::utc($event->end ?? $event->start)];
    }

    private static function utc(CarbonImmutable $moment): string
    {
        return $moment->utc()->format('Ymd\THis\Z');
    }

    /**
     * TEXT values: backslash, semicolon and comma escaped, line breaks as \n.
     */
    private static function text(string $value): string
    {
        return str_replace(['\\', ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $value);
    }

    /**
     * A line longer than 75 bytes continues on lines starting with a space, never inside a UTF-8 character.
     */
    private static function fold(string $line): string
    {
        $parts = [];
        $current = '';
        foreach (mb_str_split($line) as $character) {
            $limit = $parts === [] ? 75 : 74;
            if (strlen($current) + strlen($character) > $limit) {
                $parts[] = $current;
                $current = '';
            }
            $current .= $character;
        }
        $parts[] = $current;

        return implode("\r\n ", $parts);
    }
}
