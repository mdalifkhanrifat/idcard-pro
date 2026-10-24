<?php

use App\Support\Calendar\CalendarEvent;
use App\Support\Calendar\IcsWriter;
use Carbon\CarbonImmutable;

/*
| The calendar file of an appointment message (5.7b): RFC 5545 lines (CRLF, folded at 75 bytes without splitting
| a character), text escaped, times in UTC, a whole day as dates, and the same UID with a higher sequence for a
| change.
*/

$now = CarbonImmutable::parse('2026-10-16 03:00:00', 'UTC');
$unfold = fn (string $ics) => str_replace("\r\n ", '', $ics);

it('writes an appointment at a time, in UTC, escaped and folded', function () use ($now, $unfold) {
    $ics = IcsWriter::write(new CalendarEvent(
        uid: '01abc@acme.example', sequence: 3, summary: 'নতুন কার্ড — A-26-00001', date: '2026-10-18',
        start: CarbonImmutable::parse('2026-10-18 10:00', 'Asia/Dhaka'), end: CarbonImmutable::parse('2026-10-18 10:30', 'Asia/Dhaka'),
        location: 'প্রধান কার্যালয়, ১২ স্টেশন রোড; ঢাকা', description: "Number A-26-00001\nBring the old card, please",
    ), $now);

    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n")->toEndWith("END:VEVENT\r\nEND:VCALENDAR\r\n")
        ->and(preg_match("/[^\r]\n/", $ics))->toBe(0);
    // Every physical line is at most 75 bytes and still valid UTF-8
    foreach (explode("\r\n", rtrim($ics, "\r\n")) as $line) {
        expect(strlen($line))->toBeLessThanOrEqual(75)->and(mb_check_encoding($line, 'UTF-8'))->toBeTrue();
    }
    expect($unfold($ics))->toContain(
        "UID:01abc@acme.example\r\nSEQUENCE:3\r\nDTSTAMP:20261016T030000Z\r\n",
        "DTSTART:20261018T040000Z\r\nDTEND:20261018T043000Z\r\n",
        "SUMMARY:নতুন কার্ড — A-26-00001\r\n",
        "LOCATION:প্রধান কার্যালয়\\, ১২ স্টেশন রোড\\; ঢাকা\r\n",
        "DESCRIPTION:Number A-26-00001\\nBring the old card\\, please\r\n",
        "STATUS:CONFIRMED\r\n",
    );
});

it('writes a whole day as dates, and a cancelled one as cancelled', function () use ($now, $unfold) {
    $ics = $unfold(IcsWriter::write(new CalendarEvent(uid: 'x@y', sequence: 0, summary: 'Renewal', date: '2026-12-31', status: CalendarEvent::CANCELLED), $now));

    expect($ics)->toContain("DTSTART;VALUE=DATE:20261231\r\nDTEND;VALUE=DATE:20270101\r\n", "STATUS:CANCELLED\r\n")
        ->not->toContain('LOCATION', 'DESCRIPTION');
});
