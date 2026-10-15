<?php

use App\Services\Notifications\Channels\MailChannel;
use Laravel\Sanctum\Sanctum;

/*
| The notification settings on their own (5.2e-1): GET /notifications/settings (notifications.view or
| settings.update) and PUT (settings.update) with {notifications: {...}} — checked and saved exactly as in the
| organization's settings, the rest of them untouched, every change in the audit log.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->put = function (array $notifications, array $headers = []) {
        $response = $this->putJson('/api/v1/notifications/settings', ['notifications' => $notifications], $headers);
        $this->owner->unsetRelation('tenant');

        return $response;
    };
});

it('shows them to those who see the sent messages or may change the settings, and lets only the latter save', function () {
    foreach (['Administrator' => [200, 403], 'Viewer' => [200, 403], 'Operator' => [403, 403]] as $role => [$show, $save]) {
        Sanctum::actingAs(tenantUser($this->tenant, $role));
        $this->getJson('/api/v1/notifications/settings')->assertStatus($show);
        $this->putJson('/api/v1/notifications/settings', ['notifications' => ['enabled' => false]])->assertStatus($save);
    }
    Sanctum::actingAs($this->owner);
    $this->getJson('/api/v1/notifications/settings')->assertOk();
    ($this->put)(['enabled' => false])->assertOk();
});

it('gives the same settings and options as the organization\'s, with channels and languages named', function () {
    Sanctum::actingAs($this->owner);
    $organization = $this->getJson('/api/v1/settings')->json('data');

    $response = $this->getJson('/api/v1/notifications/settings')->assertOk();

    expect($response->json('data.notifications'))->toBe($organization['notifications'])
        ->and($response->json('data.options'))->toBe($organization['notification_options'])
        ->and($response->json('data.options.labels'))->toBe([
            'channels' => ['mail' => 'E-mail', 'sms' => 'SMS'],
            'languages' => ['bn' => 'Bangla (বাংলা)', 'en' => 'English'],
        ])
        ->and($this->getJson('/api/v1/notifications/settings', ['Accept-Language' => 'bn'])->json('data.options.labels.languages'))->toBe(['bn' => 'বাংলা', 'en' => 'ইংরেজি (English)']);

    // A channel added to the registry appears by itself (named by its key until it has a name)
    config(['notifications.channels.office_mail' => ['address' => 'email', 'class' => MailChannel::class]]);
    expect($this->getJson('/api/v1/notifications/settings')->json('data.options.labels.channels'))->toMatchArray(['office_mail' => 'office_mail']);
});

it('saves only what is sent, leaving the rest of the organization\'s settings, and notes each change in the audit log', function () {
    Sanctum::actingAs($this->owner);
    $this->tenant->forceFill(['settings' => ['card_number_on_reissue' => 'new']])->save();
    $before = $this->tenant->only(['name', 'timezone', 'locale', 'primary_color']);

    ($this->put)([
        'enabled' => true, 'channels' => ['sms', 'mail'], 'languages' => ['en', 'bn'],
        'events' => ['card_ready' => ['enabled' => true, 'channels' => ['sms'], 'templates' => ['bn' => ['sms' => '{holder.name}, কার্ড নিন।']]]],
    ])->assertOk()
        ->assertJsonPath('data.notifications.enabled', true)
        ->assertJsonPath('data.notifications.channels', ['sms', 'mail'])
        ->assertJsonPath('data.notifications.languages', ['en', 'bn'])
        ->assertJsonPath('data.notifications.events.card_ready.templates.bn.sms', '{holder.name}, কার্ড নিন।')
        ->assertJsonPath('data.options.events.card_ready.name', 'Card ready');

    // Another event alone: the first one stays
    ($this->put)(['enabled' => true, 'channels' => ['sms', 'mail'], 'events' => ['card_expiring' => ['enabled' => true, 'channels' => ['mail']]]])->assertOk()
        ->assertJsonPath('data.notifications.events.card_ready.channels', ['sms'])
        ->assertJsonPath('data.notifications.events.card_expiring.enabled', true);

    $tenant = $this->tenant->fresh();
    expect($tenant->only(['name', 'timezone', 'locale', 'primary_color']))->toBe($before)
        ->and($tenant->settings['card_number_on_reissue'])->toBe('new');

    $changes = $this->getJson('/api/v1/audit/activities?filter[log_name]=tenant')->json('data.1.changes');
    expect($changes['attributes'])->toMatchArray([
        'settings.notifications.enabled' => true,
        'settings.notifications.languages' => ['en', 'bn'],
        'settings.notifications.events.card_ready.templates.bn.sms' => '{holder.name}, কার্ড নিন।',
    ])->and($changes['attributes'])->not->toHaveKey('settings.card_number_on_reissue');
});

it('checks them as the organization\'s settings do, in the reader\'s language', function () {
    Sanctum::actingAs($this->owner);

    $this->putJson('/api/v1/notifications/settings', [])->assertUnprocessable()->assertJsonValidationErrors('notifications');
    ($this->put)(['enabled' => true, 'colour' => 'red'])->assertJsonValidationErrors('notifications');
    ($this->put)(['enabled' => true])->assertJsonValidationErrors(['notifications.channels' => 'Choose at least one channel to send messages on.']);
    ($this->put)(['enabled' => true, 'channels' => ['fax'], 'languages' => [], 'log_days' => 1])
        ->assertJsonValidationErrors(['notifications.channels.0', 'notifications.languages', 'notifications.log_days']);
    ($this->put)(['enabled' => true, 'channels' => ['mail'], 'events' => ['card_ready' => ['enabled' => true, 'templates' => ['bn' => ['sms' => '{x}']]]]], ['Accept-Language' => 'bn'])
        ->assertJsonPath('errors', ['notifications.events.card_ready.templates.bn.sms' => ['এই লেখায় {x} আছে, যা এই বার্তায় বসানো যায় না। তালিকার মান থেকে বেছে নিন।']]);

    expect($this->tenant->fresh()->notificationSettings()['enabled'])->toBeFalse();
});
