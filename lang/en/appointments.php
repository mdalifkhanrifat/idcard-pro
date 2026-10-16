<?php

return [
    // Kinds of visit of the registry (config appointments.services); an organization may rename them and add its own
    'services' => [
        'new_card' => 'New card',
        'renewal' => 'Card renewal',
        'biometrics' => 'Biometrics capture',
        'card_collection' => 'Card collection',
    ],
    'booking' => [
        'staff' => 'Staff book for people',
        'public' => 'People book through the public link',
    ],
    'scheduling' => [
        'slot' => 'A time of the day',
        'day' => 'A day (served in turn)',
    ],
    'confirmation' => [
        'auto' => 'Confirmed at once',
        'approval' => 'Staff approve each booking',
    ],
    'verification' => [
        'code' => 'Employee number and a one-time code',
        'date_of_birth' => 'Employee number and date of birth',
        'national_id' => 'Employee number and national ID',
    ],
    'locations' => [
        'period_order' => 'A period must end after it starts.',
        'periods_overlap' => 'Two periods of this day overlap; let one end before the next starts.',
        'too_many' => 'An organization may have at most :max places.',
    ],
    'availability' => [
        'too_many_days' => 'Ask for at most :max days at a time.',
        'unavailable' => [
            'off' => 'Appointments are turned off for the organization.',
            'not_staff' => 'The organization\'s staff do not book appointments.',
            'not_public' => 'Appointments are not booked through the public link.',
            'place_off' => 'This place is turned off.',
            'unknown_service' => 'There is no such kind of visit.',
            'service_off' => 'This kind of visit is turned off.',
            'service_not_public' => 'This kind of visit is not offered on the public link.',
            'not_at_place' => 'This place does not take this kind of visit.',
        ],
    ],
    'holidays' => [
        'BD' => [
            'language_martyrs_day' => 'Shaheed Day and International Mother Language Day',
            'independence_day' => 'Independence Day',
            'bengali_new_year' => 'Bengali New Year',
            'may_day' => 'May Day',
            'victory_day' => 'Victory Day',
            'christmas' => 'Christmas Day',
        ],
        'none' => 'There are no holidays to offer for the organization\'s country.',
    ],
    'number_format' => [
        'unknown_token' => 'Use only {YYYY}, {YY}, {MM} and {SEQ:n}.',
    ],
    'closures' => [
        'order' => 'The last day must be the first day or after it.',
        'too_long' => 'A closure may last at most :max days in a row.',
        'yearly_too_long' => 'A closure that comes every year must be shorter than a year.',
    ],
    'settings' => [
        'booking_needed' => 'Choose who books before turning appointments on.',
        'service_needed' => 'Turn on at least one kind of visit before turning appointments on.',
        'code_channel_needed' => 'Choose at least one way a one-time code can be sent.',
        'service_name' => 'Give this kind of visit a name.',
        'service_key' => 'A kind of visit\'s key is 2 to 40 small letters, digits or underscores, starting with a letter.',
        'registry_service_kept' => 'A built-in kind of visit cannot be removed; turn it off instead.',
        'too_many_services' => 'An organization may add at most :max kinds of visit of its own.',
    ],
];
