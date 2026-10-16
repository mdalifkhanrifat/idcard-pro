<?php

/*
|--------------------------------------------------------------------------
| Appointments (Phase 5)
|--------------------------------------------------------------------------
|
| Times (or days) people book to come in: for a new card, a renewal, biometrics, collecting a card... Every
| organization decides whether it uses appointments at all and how (tenants.settings.appointments): off until
| it turns them on, booked by its staff, through a public link or both, at a time or on a day, confirmed at
| once or after approval. What can be chosen is listed here; a new choice is a line here and its names in
| lang/<language>/appointments.php.
|
*/

return [

    // Who books: the organization's staff (for a cardholder, at the counter or on the phone), and/or the people
    // themselves through the organization's public link
    'booking' => ['staff', 'public'],

    // What is booked: a time slot of the day (10:15–10:30), or only a day (served in turn, a number per day)
    'scheduling' => ['slot', 'day'],

    // A booking holds at once, or waits for staff to approve it
    'confirmation' => ['auto', 'approval'],

    /*
     * How a person proves who they are on the public link, after giving their employee number: a one-time code
     * sent to the e-mail or phone on record, their date of birth, or their national ID number.
     */
    'verification' => ['code', 'date_of_birth', 'national_id'],

    // Notification channels a one-time code may go on (keys of config('notifications.channels'))
    'code_channels' => ['mail', 'sms'],

    /*
     * Kinds of visit: key => [slots one booking takes, in slot scheduling]. Each organization turns them on or off,
     * renames them and adds its own. Names: lang/<language>/appointments.php, services.<key>.
     */
    'services' => [
        'new_card' => ['slots' => 1],
        'renewal' => ['slots' => 1],
        'biometrics' => ['slots' => 1],
        'card_collection' => ['slots' => 1],
    ],

    // An organization's own kinds of visit: how many at most, and the shape of their keys
    'custom_services' => ['max' => 20, 'key' => '/^[a-z][a-z0-9_]{1,39}$/'],

    /*
     * Places people come to (a branch, an office, a counter), each with its own weekly hours, how many are served
     * at once per time slot (counters side by side) and per day (day scheduling), and the kinds of visit it takes
     * (null: every kind, those added later too). Days are ISO keys, Monday first; hours "HH:MM" from–to.
     */
    'weekdays' => ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'],
    'locations' => [
        // A new place's hours: Sunday to Thursday, 9 to 5 (a working week of Bangladesh; change it for another)
        'hours' => [
            'mon' => [['09:00', '17:00']], 'tue' => [['09:00', '17:00']], 'wed' => [['09:00', '17:00']], 'thu' => [['09:00', '17:00']],
            'fri' => [], 'sat' => [], 'sun' => [['09:00', '17:00']],
        ],
        'slot_capacity' => 1,
        'day_capacity' => 50,
        // Places one organization may have
        'max' => 100,
        // Opening periods in one day (e.g. 9–1 and 2–5)
        'periods_per_day' => 4,
    ],
    'closures' => [
        // Days in a row one closure may last (both days whole); a yearly one is shorter than a year
        'max_days' => 366,
    ],
    'availability' => [
        // Days one question about free times may cover (a month's calendar with its edges)
        'max_days' => 62,
    ],

    // Limits of what an organization may set: [min, max]
    'limits' => [
        'slot_capacity' => [1, 100],
        'day_capacity' => [1, 5000],
        'slot_minutes' => [5, 240],
        'service_slots' => [1, 8],
        'open_per_service' => [1, 10],
        'notice_hours' => [0, 168],
        'days_ahead' => [1, 365],
        'change_until_hours' => [0, 168],
        'code_minutes' => [2, 60],
        'code_attempts' => [1, 10],
        'reminder_hours' => [1, 168],
        'reminders' => [0, 3],
        'name' => [1, 80],
    ],

    // What an organization starts with: appointments off; once on, booked by staff at a time, confirmed at once
    'defaults' => [
        'enabled' => false,
        'booking' => ['staff'],
        'scheduling' => 'slot',
        'slot_minutes' => 15,
        'confirmation' => 'auto',
        // Family members (who have cards and biometrics of their own) may have appointments too
        'family_members' => true,
        // Open bookings one person may hold for one kind of visit
        'open_per_service' => 1,
        // Booked at least this many hours ahead, and at most this many days ahead
        'notice_hours' => 2,
        'days_ahead' => 30,
        // What a person may do themselves through the public link, until some hours before
        'changes' => ['cancel' => true, 'reschedule' => true, 'until_hours' => 2],
        'verification' => ['method' => 'code', 'code_channels' => ['mail', 'sms'], 'code_minutes' => 10, 'code_attempts' => 5],
        // Reminders this many hours before (sent as notifications, when those are on)
        'reminders' => [24],
    ],

];
