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

    // Made-up details for previews of the texts (never a real person's)
    'samples' => [
        'holder_name' => 'Rahim Uddin',
        'employee_no' => 'EMP-1024',
        'template' => 'Employee card',
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
