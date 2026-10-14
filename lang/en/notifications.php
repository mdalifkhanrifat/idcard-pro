<?php

return [

    'channels' => [
        'mail' => 'E-mail',
        'sms' => 'SMS',
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
    ],

    // A message sent by hand to check a channel (not an event of the registry)
    'test' => [
        'name' => 'Test message',
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

    'settings' => [
        'unknown_placeholders' => 'This text uses :names, which this message does not offer. Pick from the listed values.',
        'channel_needed' => 'Choose at least one channel to send messages on.',
    ],

];
