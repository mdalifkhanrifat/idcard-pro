<?php

// Event keys hold dots: read `events` whole (__('webhooks.events')), never one by path.
return [
    'groups' => [
        'cardholder' => 'Cardholders',
        'card' => 'Cards',
        'appointment' => 'Appointments',
    ],
    'events' => [
        'cardholder.created' => 'Cardholder added',
        'cardholder.updated' => 'Cardholder changed',
        'cardholder.deleted' => 'Cardholder deleted',
        'card.issued' => 'Card issued',
        'card.printed' => 'Card printed',
        'card.updated' => 'Card changed',
        'card.revoked' => 'Card revoked',
        'appointment.booked' => 'Appointment booked',
        'appointment.confirmed' => 'Appointment confirmed',
        'appointment.moved' => 'Appointment moved',
        'appointment.cancelled' => 'Appointment cancelled',
        'appointment.status_changed' => 'Appointment status changed',
    ],
    'other_events' => ['webhook.test' => 'Test'],
    'statuses' => ['queued' => 'Waiting', 'delivered' => 'Delivered', 'failed' => 'Failed'],
    'too_many' => 'An organization can have at most :max webhooks.',
    'address' => [
        'invalid' => 'This is not a web address (like https://hr.example.com/hooks).',
        'credentials' => 'Put no name or password in the address; deliveries are signed instead.',
        'https' => 'Use https, so what is sent cannot be read on the way.',
        'inner' => 'This address leads inside a private network. Only a public address can be used.',
        'only_hosts' => 'This installation sends only to its own test receiver.',
    ],
    'failures' => [
        'refused' => 'Nothing was sent: this address may not be used.',
        'unresolved' => 'This address could not be found.',
        'timeout' => 'The address did not answer in time.',
        'unreachable' => 'Could not connect to the address.',
        'certificate' => 'The security certificate of the address could not be trusted.',
        'redirected' => 'The address answered with a move elsewhere. Moves are not followed: save the final address.',
        'rejected' => 'The address answered with an error.',
        'disabled' => 'Nothing was sent: the webhook was turned off or deleted first.',
    ],
];
