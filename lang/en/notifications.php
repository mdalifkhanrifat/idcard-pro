<?php

return [

    'channels' => [
        'mail' => 'E-mail',
        'sms' => 'SMS',
    ],

    // Languages a message can be written in (config notifications.languages)
    'languages' => [
        'bn' => 'Bangla (বাংলা)',
        'en' => 'English',
    ],

    // Which languages an SMS is written in (config notifications.sms_languages)
    'sms_languages' => [
        'all' => 'All languages, in order',
        'first' => 'Only the first language (shorter, fewer SMS parts)',
    ],

    // Values a message's text may use: {holder.name} and so on
    'placeholders' => [
        'organization.name' => 'Organization name',
        'holder.name' => 'Card holder\'s name',
        'holder.employee_no' => 'Employee no',
        'card.number' => 'Card number',
        'card.template' => 'Card template',
        'card.issue_date' => 'Issue date',
        'card.expiry_date' => 'Expiry date',
        'card.days_left' => 'Days until expiry',
        'card.validity' => 'Validity (a sentence; also for cards without an expiry date)',
        'appointment.number' => 'Appointment number',
        'appointment.service' => 'Kind of visit',
        'appointment.place' => 'Place',
        'appointment.address' => 'Place\'s address',
        'appointment.date' => 'Appointment date',
        'appointment.time' => 'Appointment time (empty when a day is booked)',
        'appointment.when' => 'Date and time (the day alone when a day is booked)',
        'appointment.status' => 'Appointment status',
        'appointment.cancel_reason' => 'Reason for cancelling (a sentence; empty when none was given)',
    ],

    // Made-up details for previews of the texts (never a real person's)
    'samples' => [
        'holder_name' => 'Rahim Uddin',
        'employee_no' => 'EMP-1024',
        'template' => 'Employee card',
        'place' => 'Head office',
        'address' => '12 Station Road',
        'cancel_reason' => 'The office is closed that day',
    ],

    // Appointment values written as sentences
    'appointment' => [
        'when' => ':date at :time',
        'reason' => 'Reason: :reason.',
    ],

    'validity' => [
        'until' => 'It is valid until :date.',
        'none' => 'It has no expiry date.',
    ],

    // Each event: its name and description for the settings, and its default texts
    'events' => [
        'card_ready' => [
            'name' => 'Card ready',
            'description' => 'A card was issued and can be collected.',
            'subject' => 'Your ID card {card.number} is ready',
            'body' => "Dear {holder.name},\n\nYour ID card {card.number} from {organization.name} is ready. {card.validity}\n\nThank you.",
            'sms' => '{organization.name}: your ID card {card.number} is ready. {card.validity}',
        ],
        'card_expiring' => [
            'name' => 'Card expiring',
            'description' => 'A card\'s last day is near: time to renew it.',
            'subject' => 'Your ID card {card.number} expires on {card.expiry_date}',
            'body' => "Dear {holder.name},\n\nYour ID card {card.number} from {organization.name} expires on {card.expiry_date} ({card.days_left} days from today). Please arrange its renewal in time.\n\nThank you.",
            'sms' => '{organization.name}: your ID card {card.number} expires on {card.expiry_date}. Please arrange its renewal.',
        ],
        'appointment_booked' => [
            'name' => 'Appointment booked',
            'description' => 'An appointment was booked (by staff, through the public link or by another system).',
            'subject' => 'Your appointment {appointment.number} on {appointment.when}',
            'body' => "Dear {holder.name},\n\nYour appointment for {appointment.service} at {appointment.place} is booked for {appointment.when} ({appointment.status}). Its number is {appointment.number}; please have it with you.\n\n{organization.name}",
            'sms' => '{organization.name}: appointment {appointment.number} on {appointment.when} at {appointment.place}.',
        ],
        'appointment_changed' => [
            'name' => 'Appointment moved',
            'description' => 'An appointment was moved to another time, day or place.',
            'subject' => 'Your appointment {appointment.number} is now on {appointment.when}',
            'body' => "Dear {holder.name},\n\nYour appointment {appointment.number} for {appointment.service} has been moved. It is now on {appointment.when} at {appointment.place}.\n\n{organization.name}",
            'sms' => '{organization.name}: appointment {appointment.number} moved to {appointment.when} at {appointment.place}.',
        ],
        'appointment_cancelled' => [
            'name' => 'Appointment cancelled',
            'description' => 'An appointment was cancelled, or turned down while waiting for approval.',
            'subject' => 'Your appointment {appointment.number} is cancelled',
            'body' => "Dear {holder.name},\n\nYour appointment {appointment.number} for {appointment.service} on {appointment.when} has been cancelled. {appointment.cancel_reason}\n\nYou are welcome to book another time.\n\n{organization.name}",
            'sms' => '{organization.name}: appointment {appointment.number} on {appointment.when} is cancelled.',
        ],
        'appointment_reminder' => [
            'name' => 'Appointment reminder',
            'description' => 'An appointment is near (the organization\'s reminder hours before it).',
            'subject' => 'Reminder: your appointment on {appointment.when}',
            'body' => "Dear {holder.name},\n\nA reminder of your appointment {appointment.number} for {appointment.service} on {appointment.when} at {appointment.place}. Please have its number with you.\n\n{organization.name}",
            'sms' => '{organization.name}: reminder, appointment {appointment.number} on {appointment.when} at {appointment.place}.',
        ],
    ],

    // A message sent by hand to check a channel (not an event of the registry)
    'test' => [
        'name' => 'Test message',
        'subject' => '{organization.name}: test message',
        'body' => "This is a test message from {organization.name}.\n\nIt reached you, so messages on this channel are getting through. No reply is needed.",
        'sms' => '{organization.name}: this is a test message. Messages on this channel are getting through.',
        'address_needed' => 'Type the address to send the test message to; your own profile has none for this channel.',
    ],

    // A typed address that does not look like its kind (config notifications.addresses, checked by pattern)
    'addresses' => [
        'phone' => 'Type a phone number: 6 to 15 digits, optionally starting with +.',
    ],

    // The log of messages: how each went, and why one was not sent
    'statuses' => [
        'queued' => 'Waiting',
        'sent' => 'Sent',
        'failed' => 'Failed',
        'skipped' => 'Not sent',
    ],
    'reasons' => [
        'no_address' => 'No address on record',
        'turned_off' => 'Turned off before it went',
    ],

    // Sending a message again by hand: why it cannot go
    'resend' => [
        'not_failed' => 'Only a failed message, or one that was not sent, can be sent again.',
        'already' => 'This message was already sent again; send the newer one again if needed.',
        'unknown_channel' => 'This channel is no longer available.',
        'no_recipient' => 'The person it went to is no longer on record.',
        'no_address' => 'There is still no address on record for this channel. Add it to the person\'s record first.',
    ],

    'settings' => [
        'unknown_placeholders' => 'This text uses :names, which this message does not offer. Pick from the listed values.',
        'channel_needed' => 'Choose at least one channel to send messages on.',
    ],

];
