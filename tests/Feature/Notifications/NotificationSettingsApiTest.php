<?php

use App\Models\Card;
use App\Models\Cardholder;
use App\Services\Notifications\Channels\MailChannel;
use App\Services\Notifications\NotificationComposer;
use App\Services\Notifications\NotificationSettings;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| The notification settings on their own (5.2e-1): GET /notifications/settings (notifications.view or
| settings.update) and PUT (settings.update) with {notifications: {...}} — checked and saved exactly as in the
| organization's settings, the rest of them untouched, every change in the audit log. With them each event's
| values for a made-up occurrence in every language (5.2e-2), for previews of the texts.
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
            'sms_languages' => ['all' => 'All languages, in order', 'first' => 'Only the first language (shorter, fewer SMS parts)'],
        ])
        ->and($this->getJson('/api/v1/notifications/settings', ['Accept-Language' => 'bn'])->json('data.options.labels.languages'))->toBe(['bn' => 'বাংলা', 'en' => 'ইংরেজি (English)']);

    expect($response->json('data.options.channel_addresses'))->toBe(['mail' => 'email', 'sms' => 'phone']);

    // A channel added to the registry appears by itself (named by its key until it has a name), with its kind of address
    config(['notifications.channels.office_mail' => ['address' => 'email', 'class' => MailChannel::class]]);
    $options = $this->getJson('/api/v1/notifications/settings')->json('data.options');
    expect($options['labels']['channels'])->toMatchArray(['office_mail' => 'office_mail'])
        ->and($options['channel_addresses'])->toMatchArray(['office_mail' => 'email']);
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

it('takes a partial change, as an integration sends it: what is not sent stays, an empty list is none', function () {
    Sanctum::actingAs($this->owner);
    ($this->put)([
        'enabled' => true, 'channels' => ['mail', 'sms'], 'languages' => ['bn', 'en'],
        'events' => ['card_ready' => ['enabled' => true, 'channels' => ['mail', 'sms'], 'templates' => ['bn' => ['subject' => 'কার্ড', 'sms' => '{holder.name}, কার্ড নিন।'], 'en' => ['sms' => 'Card for {holder.name}.']]]],
    ])->assertOk();
    $saved = fn () => $this->tenant->fresh()->notificationSettings();
    $audited = fn () => $this->getJson('/api/v1/audit/activities?filter[log_name]=tenant')->json('data.0.changes.attributes');

    // One value alone: nothing else moves, and only it is noted
    ($this->put)(['log_days' => 200])->assertOk()->assertJsonPath('data.notifications.log_days', 200);
    expect($saved())->toMatchArray(['enabled' => true, 'channels' => ['mail', 'sms'], 'languages' => ['bn', 'en']])
        ->and($audited())->toBe(['settings.notifications.log_days' => 200]);

    // Part of an event: its other channels, its on/off and its other texts stay
    ($this->put)(['events' => ['card_ready' => ['templates' => ['bn' => ['sms' => '{holder.name}: কার্ড তৈরি।']]]]])->assertOk();
    expect($saved()['events']['card_ready'])->toMatchArray(['enabled' => true, 'channels' => ['mail', 'sms']])
        ->and($saved()['events']['card_ready']['templates']['bn'])->toBe(['subject' => 'কার্ড', 'body' => null, 'sms' => '{holder.name}: কার্ড তৈরি।'])
        ->and($saved()['events']['card_ready']['templates']['en']['sms'])->toBe('Card for {holder.name}.');

    // An empty list is none; a text sent empty is the default again
    ($this->put)(['events' => ['card_ready' => ['channels' => [], 'templates' => ['bn' => ['subject' => null]]]]])->assertOk();
    expect($saved()['events']['card_ready']['channels'])->toBe([])
        ->and($saved()['events']['card_ready']['templates']['bn']['subject'])->toBeNull()
        ->and($saved()['channels'])->toBe(['mail', 'sms']);

    // Turned off and on again alone, the channels stay
    ($this->put)(['enabled' => false])->assertOk();
    ($this->put)(['enabled' => true])->assertOk();
    expect($saved())->toMatchArray(['enabled' => true, 'channels' => ['mail', 'sms']]);
});

it('checks them as the organization\'s settings do, in the reader\'s language', function () {
    Sanctum::actingAs($this->owner);

    $this->putJson('/api/v1/notifications/settings', [])->assertUnprocessable()->assertJsonValidationErrors('notifications');
    ($this->put)(['enabled' => true, 'colour' => 'red'])->assertJsonValidationErrors('notifications');
    ($this->put)(['enabled' => true, 'channels' => []])->assertJsonValidationErrors(['notifications.channels' => 'Choose at least one channel to send messages on.']);
    ($this->put)(['enabled' => true, 'channels' => ['fax'], 'languages' => [], 'log_days' => 1])
        ->assertJsonValidationErrors(['notifications.channels.0', 'notifications.languages', 'notifications.log_days']);
    ($this->put)(['enabled' => true, 'channels' => ['mail'], 'events' => ['card_ready' => ['enabled' => true, 'templates' => ['bn' => ['sms' => '{x}']]]]], ['Accept-Language' => 'bn'])
        ->assertJsonPath('errors', ['notifications.events.card_ready.templates.bn.sms' => ['এই লেখায় {x} আছে, যা এই বার্তায় বসানো যায় না। তালিকার মান থেকে বেছে নিন।']]);

    expect($this->tenant->fresh()->notificationSettings()['enabled'])->toBeFalse();
});

it('gives each event\'s values for a made-up card in every language, as a real message would write them', function () {
    Sanctum::actingAs($this->owner);
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $this->owner->unsetRelation('tenant');
    // 00:30 on 4 February in Dhaka is still 3 February in UTC: the sample card is issued on the 4th
    Date::setTestNow('2026-02-03 18:30:00');

    $response = $this->getJson('/api/v1/notifications/settings')->assertOk()
        ->assertJsonPath('data.options.separators', config('notifications.separators'));

    expect($response->json('data.samples.card_ready.en'))->toBe([
        'organization.name' => 'Acme', 'holder.name' => 'Rahim Uddin', 'holder.employee_no' => 'EMP-1024',
        'card.number' => 'EMP/26000123', 'card.template' => 'Employee card', 'card.issue_date' => '04/02/2026',
        'card.expiry_date' => '03/02/2031', 'card.validity' => 'It is valid until 03/02/2031.',
    ])->and($response->json('data.samples.card_ready.bn'))->toMatchArray([
        'holder.name' => 'রহিম উদ্দিন', 'holder.employee_no' => 'EMP-১০২৪', 'card.number' => 'EMP/26000123',
        'card.template' => 'কর্মী কার্ড', 'card.issue_date' => '০৪/০২/২০২৬', 'card.validity' => 'কার্ডটি ০৩/০২/২০৩১ পর্যন্ত বৈধ।',
    ])->and($response->json('data.samples.card_expiring.en'))->toMatchArray(['card.expiry_date' => '06/03/2026', 'card.days_left' => '30'])
        ->and($response->json('data.samples.card_expiring.bn'))->toMatchArray(['card.expiry_date' => '০৬/০৩/২০২৬', 'card.days_left' => '৩০'])
        // Every placeholder an event offers has a sample value, in every language
        ->and(array_keys($response->json('data.samples.card_expiring.bn')))->toBe(array_column($response->json('data.options.events.card_expiring.placeholders'), 'key'))
        // Still the reader's language afterwards
        ->and($response->json('data.options.events.card_ready.name'))->toBe('Card ready');

    // The organization's own card number format
    $this->tenant->update(['settings' => ['card_number_format' => '{YYYY}-{CODE}-{SEQ:4}']]);
    $this->owner->unsetRelation('tenant');
    // (placeholder names hold dots, so read as a list)
    expect($this->getJson('/api/v1/notifications/settings')->json('data.samples.card_ready.en')['card.number'])->toBe('2026-EMP-0123');
    Date::setTestNow();
});

it('makes the samples up in memory: nothing is read from or saved to the database', function () {
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $samples = app(NotificationComposer::class)->samples($this->tenant);

    expect($queries)->toBe(0)
        ->and(array_keys($samples))->toBe(array_keys(config('notifications.events')))
        ->and(array_keys($samples['card_ready']))->toBe(['bn', 'en'])
        ->and(Card::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Cardholder::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(app()->getLocale())->toBe('en');
});

it('keeps each default SMS to one part with the sample values, in every language', function () {
    Sanctum::actingAs($this->owner);
    $this->tenant->update(['name' => 'Acme Corporation']);
    $this->owner->unsetRelation('tenant');
    $data = $this->getJson('/api/v1/notifications/settings')->json('data');

    foreach ($data['samples'] as $event => $languages) {
        foreach ($languages as $language => $values) {
            $sms = NotificationSettings::fill($data['options']['events'][$event]['default_templates'][$language]['sms'], $values);
            // Plain ASCII goes as GSM-7 (160 a part), anything else as Unicode (70 UTF-16 units a part)
            $oneSms = preg_match('/^[\x20-\x7E]*$/', $sms) === 1 ? strlen($sms) <= 160 : strlen((string) mb_convert_encoding($sms, 'UTF-16LE', 'UTF-8')) / 2 <= 70;
            expect($oneSms)->toBeTrue("{$event} ({$language}) takes more than one SMS: {$sms}");
        }
    }
});

it('lets an SMS take all the organization\'s languages or only the first, all by default, named and audited', function () {
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/notifications/settings', ['Accept-Language' => 'bn'])
        ->assertJsonPath('data.notifications.sms_languages', 'all')
        ->assertJsonPath('data.options.sms_languages', ['all', 'first'])
        ->assertJsonPath('data.options.labels.sms_languages.first', 'শুধু প্রথম ভাষা (ছোট, কম SMS ভাগ)');

    ($this->put)(['enabled' => false, 'sms_languages' => 'first'])->assertOk()->assertJsonPath('data.notifications.sms_languages', 'first');
    // Not sent: kept
    ($this->put)(['enabled' => false])->assertOk()->assertJsonPath('data.notifications.sms_languages', 'first');
    ($this->put)(['enabled' => false, 'sms_languages' => 'some'])->assertJsonValidationErrors('notifications.sms_languages');

    $changes = $this->getJson('/api/v1/audit/activities?filter[log_name]=tenant')->json('data.0.changes');
    expect($changes['old'])->toMatchArray(['settings.notifications.sms_languages' => 'all'])
        ->and($changes['attributes'])->toMatchArray(['settings.notifications.sms_languages' => 'first']);
});

it('keeps an event\'s own settings, as the event declares them: named, checked, partial and audited', function () {
    Sanctum::actingAs($this->owner);
    $saved = fn () => $this->tenant->fresh()->notificationSettings()['events'];

    // Offered with names, choices and limits in the reader's language; none for an event without settings
    $this->getJson('/api/v1/notifications/settings', ['Accept-Language' => 'bn'])
        ->assertJsonPath('data.options.events.card_ready.options.0.key', 'send_on')
        ->assertJsonPath('data.options.events.card_ready.options.0.kind', 'choice')
        ->assertJsonPath('data.options.events.card_ready.options.0.label', 'কখন পাঠাবেন')
        ->assertJsonPath('data.options.events.card_ready.options.0.default', 'print')
        ->assertJsonPath('data.options.events.card_ready.options.0.choices', [['key' => 'print', 'name' => 'কার্ড প্রথমবার ছাপার পরে'], ['key' => 'issue', 'name' => 'কার্ড ইস্যুর সাথে সাথে']])
        ->assertJsonPath('data.options.events.card_expiring.options.0', [
            'key' => 'days', 'kind' => 'numbers', 'label' => 'মেয়াদ শেষের কত দিন আগে',
            'hint' => 'সর্বোচ্চ তিনটা রিমাইন্ডার, যেমন ৬০, ৩০ আর ৭ দিন আগে। শুধু কার্ডধারীর এখনকার কার্ডে — বদলানো বা বাতিল কার্ডে নয়।',
            'default' => [30], 'min' => 1, 'max' => 365, 'max_items' => 3, 'unit' => 'দিন আগে',
        ])
        ->assertJsonPath('data.options.events.appointment_booked.options', []);

    // Days kept once each, largest first; the event's other parts stay
    ($this->put)(['events' => ['card_expiring' => ['enabled' => true, 'options' => ['days' => [7, '60', 30]]]]])->assertOk()
        ->assertJsonPath('data.notifications.events.card_expiring.options.days', [60, 30, 7]);
    ($this->put)(['events' => ['card_ready' => ['options' => ['send_on' => 'issue']]]])->assertOk();
    expect($saved()['card_ready']['options'])->toBe(['send_on' => 'issue'])
        ->and($saved()['card_expiring'])->toMatchArray(['enabled' => true, 'options' => ['days' => [60, 30, 7]]]);
    $changes = $this->getJson('/api/v1/audit/activities?filter[log_name]=tenant')->json('data.0.changes');
    expect($changes['attributes'])->toBe(['settings.notifications.events.card_ready.options.send_on' => 'issue'])
        ->and($changes['old'] ?? [])->not->toHaveKey('settings.notifications.events.card_expiring.options.days');

    // A change to another part of the event keeps its settings
    ($this->put)(['events' => ['card_expiring' => ['channels' => ['mail']]]])->assertOk();
    expect($saved()['card_expiring']['options']['days'])->toBe([60, 30, 7]);

    // Checked as declared, named in the reader's language
    ($this->put)(['events' => ['card_ready' => ['options' => ['send_on' => 'later', 'colour' => 'red']]]])
        ->assertJsonValidationErrors(['notifications.events.card_ready.options']);
    ($this->put)(['events' => ['card_ready' => ['options' => ['send_on' => 'later']]]])
        ->assertJsonValidationErrors(['notifications.events.card_ready.options.send_on']);
    ($this->put)(['events' => ['card_expiring' => ['options' => ['days' => [400, 30, 30, 1, 2]]]]])
        ->assertJsonValidationErrors(['notifications.events.card_expiring.options.days', 'notifications.events.card_expiring.options.days.0', 'notifications.events.card_expiring.options.days.2']);
    ($this->put)(['events' => ['card_expiring' => ['options' => ['days' => []]]]], ['Accept-Language' => 'bn'])
        ->assertJsonPath('errors', ['notifications.events.card_expiring.options.days' => ['মেয়াদ শেষের কত দিন আগে-এ কমপক্ষে ১ টি আইটেম থাকতে হবে।']]);
    // Settings only for events that have them
    ($this->put)(['events' => ['appointment_booked' => ['options' => ['send_on' => 'issue']]]])
        ->assertJsonValidationErrors(['notifications.events.appointment_booked']);
    expect($saved()['card_expiring']['options']['days'])->toBe([60, 30, 7]);

    // What GET gives, PUT takes back unchanged
    $settings = $this->getJson('/api/v1/notifications/settings')->json('data.notifications');
    ($this->put)($settings)->assertOk()->assertJsonPath('data.notifications', $settings);
});

it('completes saved settings of an event: a choice it no longer offers or days out of range take the default', function () {
    $this->tenant->update(['settings' => ['notifications' => ['events' => [
        'card_ready' => ['options' => ['send_on' => 'never']],
        'card_expiring' => ['options' => ['days' => [0, 500, 'x']]],
    ]]]]);

    expect($this->tenant->fresh()->notificationSettings()['events']['card_ready']['options'])->toBe(['send_on' => 'print'])
        ->and($this->tenant->fresh()->notificationSettings()['events']['card_expiring']['options'])->toBe(['days' => [30]])
        ->and(NotificationSettings::option(['events' => ['card_expiring' => ['options' => ['days' => [7]]]]], 'card_expiring', 'days'))->toBe([7]);
});
