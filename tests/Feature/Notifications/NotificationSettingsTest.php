<?php

use App\Data\Notifications\NotificationRecipient;
use App\Models\Card;
use App\Services\Notifications\Events\CardExpiringEvent;
use App\Services\Notifications\NotificationEvent;
use App\Services\Notifications\NotificationSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake(config('cardholder_files.disk'));
    [$this->tenant, $this->owner] = tenantWithOwner();
    Sanctum::actingAs($this->owner);
    $this->save = function (array $notifications) {
        $response = $this->post('/api/v1/settings', ['name' => 'Acme', 'timezone' => 'Asia/Dhaka', 'locale' => 'en', 'primary_color' => '#112233', 'notifications' => $notifications], ['Accept' => 'application/json']);
        $this->owner->unsetRelation('tenant');

        return $response;
    };
});

it('keeps notifications off until the organization turns them on, and offers every event with its values and default texts', function () {
    $response = $this->getJson('/api/v1/settings')->assertOk();

    // The log is kept for good unless the organization chooses to have old messages deleted
    expect($response->json('data.notifications'))->toMatchArray(['enabled' => false, 'channels' => ['mail'], 'languages' => ['bn'], 'prune_log' => false, 'log_days' => 365])
        ->and($response->json('data.notifications.events.card_ready'))->toBe([
            'enabled' => false, 'channels' => ['mail', 'sms'],
            'templates' => ['bn' => ['subject' => null, 'body' => null, 'sms' => null], 'en' => ['subject' => null, 'body' => null, 'sms' => null]],
        ])
        ->and($response->json('data.notification_options.channels'))->toBe(['mail', 'sms'])
        ->and($response->json('data.notification_options.languages'))->toBe(['bn', 'en'])
        ->and($response->json('data.notification_options.log_days'))->toBe(['min' => 7, 'max' => 3650])
        ->and($response->json('data.notification_options.events.card_expiring.name'))->toBe('Card expiring')
        ->and($response->json('data.notification_options.events.card_expiring.placeholders'))->toContain(['key' => 'card.days_left', 'label' => 'Days until expiry'])
        ->and($response->json('data.notification_options.events.card_ready.placeholders.1'))->toBe(['key' => 'holder.name', 'label' => 'Card holder\'s name'])
        // Default texts in every language, whatever the reader's own
        ->and($response->json('data.notification_options.events.card_ready.default_templates.en.subject'))->toBe('Your ID card {card.number} is ready')
        ->and($response->json('data.notification_options.events.card_ready.default_templates.bn.subject'))->toBe('আপনার আইডি কার্ড {card.number} তৈরি হয়েছে');
});

it('saves the organization\'s choices and its own texts, one event without touching the others, kept in the audit log', function () {
    ($this->save)([
        'enabled' => '1', 'channels' => ['mail', 'sms'], 'languages' => ['en', 'bn'], 'log_days' => '90',
        'events' => ['card_ready' => ['enabled' => '1', 'channels' => ['sms'], 'templates' => ['bn' => ['sms' => '{holder.name}, কার্ড {card.number} নিন।', 'subject' => '  ']]]],
    ])->assertOk()
        ->assertJsonPath('data.notifications.enabled', true)
        ->assertJsonPath('data.notifications.languages', ['en', 'bn'])
        ->assertJsonPath('data.notifications.log_days', 90)
        ->assertJsonPath('data.notifications.events.card_ready.channels', ['sms'])
        ->assertJsonPath('data.notifications.events.card_ready.templates.bn', ['subject' => null, 'body' => null, 'sms' => '{holder.name}, কার্ড {card.number} নিন।']);

    // Another event alone: the first one stays as it was
    ($this->save)(['enabled' => '1', 'channels' => ['mail', 'sms'], 'events' => ['card_expiring' => ['enabled' => '1']]])->assertOk()
        ->assertJsonPath('data.notifications.events.card_ready.enabled', true)
        ->assertJsonPath('data.notifications.events.card_ready.templates.bn.sms', '{holder.name}, কার্ড {card.number} নিন।')
        ->assertJsonPath('data.notifications.events.card_expiring.enabled', true)
        // An event saved without channels goes on none (a form cannot send an empty list)
        ->assertJsonPath('data.notifications.events.card_expiring.channels', []);

    $changes = $this->getJson('/api/v1/audit/activities?filter[log_name]=tenant')->json('data.1.changes');
    expect($changes['attributes'])->toMatchArray([
        'settings.notifications.enabled' => true,
        'settings.notifications.log_days' => 90,
        'settings.notifications.events.card_ready.channels' => ['sms'],
        'settings.notifications.events.card_ready.templates.bn.sms' => '{holder.name}, কার্ড {card.number} নিন।',
    ])->and($changes['old'])->toMatchArray(['settings.notifications.enabled' => false, 'settings.notifications.log_days' => 365])
        ->and($changes['attributes'])->not->toHaveKey('settings.notifications.events.card_expiring.enabled');
});

it('deletes old messages only when the organization chooses so, keeping its number of days either way', function () {
    ($this->save)(['enabled' => '0', 'prune_log' => '1', 'log_days' => '90'])->assertOk()
        ->assertJsonPath('data.notifications.prune_log', true)
        ->assertJsonPath('data.notifications.log_days', 90);
    // Turned off again: kept for good, the 90 days remembered for the next time
    ($this->save)(['enabled' => '0', 'prune_log' => '0'])->assertOk()
        ->assertJsonPath('data.notifications.prune_log', false)
        ->assertJsonPath('data.notifications.log_days', 90);
    ($this->save)(['enabled' => '0', 'prune_log' => 'sometimes'])->assertJsonValidationErrors('notifications.prune_log');

    expect($this->getJson('/api/v1/audit/activities?filter[log_name]=tenant')->json('data.0.changes'))->toMatchArray([
        'attributes' => ['settings.notifications.prune_log' => false], 'old' => ['settings.notifications.prune_log' => true],
    ]);
});

it('refuses texts with values the message does not offer, unknown choices, and sending on no channel', function () {
    ($this->save)(['enabled' => '1', 'channels' => ['mail'], 'events' => ['card_ready' => ['enabled' => '1', 'templates' => ['en' => ['body' => 'Hi {holder.name}, card {card.numbr} ({card.days_left})']]]]])
        ->assertStatus(422)
        ->assertJsonPath('errors', ['notifications.events.card_ready.templates.en.body' => ['This text uses {card.numbr}, {card.days_left}, which this message does not offer. Pick from the listed values.']]);

    ($this->save)(['enabled' => '1', 'channels' => ['fax'], 'languages' => ['fr'], 'log_days' => 1, 'events' => ['card_lost' => ['enabled' => '1']]])
        ->assertJsonValidationErrors(['notifications.channels.0', 'notifications.languages.0', 'notifications.log_days', 'notifications.events']);
    ($this->save)(['enabled' => '1'])->assertJsonValidationErrors(['notifications.channels' => 'Choose at least one channel to send messages on.']);
    ($this->save)(['enabled' => '1', 'channels' => ['mail'], 'events' => ['card_ready' => ['enabled' => '1', 'templates' => ['en' => ['sms' => str_repeat('x', 481)]]]]])
        ->assertJsonValidationErrors('notifications.events.card_ready.templates.en.sms');
    // Turned off, no channel is needed
    ($this->save)(['enabled' => '0'])->assertOk();

    $this->owner->update(['locale' => 'bn']);
    expect(($this->save)(['enabled' => '1', 'channels' => ['mail'], 'events' => ['card_ready' => ['enabled' => '1', 'templates' => ['bn' => ['sms' => '{x}']]]]])->json('errors')['notifications.events.card_ready.templates.bn.sms'][0])
        ->toBe('এই লেখায় {x} আছে, যা এই বার্তায় বসানো যায় না। তালিকার মান থেকে বেছে নিন।');
});

it('takes a new event from the registry alone: settings, rules and options follow', function () {
    $event = new class implements NotificationEvent
    {
        public function key(): string
        {
            return 'badge_lost';
        }

        public function placeholders(): array
        {
            return ['holder.name'];
        }

        public function defaultChannels(): array
        {
            return ['sms'];
        }

        public function values(object $subject): array
        {
            return ['holder.name' => 'X'];
        }

        public function recipient(object $subject): NotificationRecipient
        {
            return new NotificationRecipient('X');
        }

        public function links(object $subject): array
        {
            return [];
        }
    };
    app()->instance('test.badge_lost', $event);
    config(['notifications.events.badge_lost' => 'test.badge_lost']);

    $this->getJson('/api/v1/settings')
        ->assertJsonPath('data.notifications.events.badge_lost.channels', ['sms'])
        ->assertJsonPath('data.notification_options.events.badge_lost.placeholders', [['key' => 'holder.name', 'label' => 'Card holder\'s name']]);
    ($this->save)(['enabled' => '1', 'channels' => ['sms'], 'events' => ['badge_lost' => ['enabled' => '1', 'channels' => ['sms'], 'templates' => ['en' => ['sms' => '{holder.name} {card.number}']]]]])
        ->assertJsonValidationErrors('notifications.events.badge_lost.templates.en.sms');
});

it('fills a card\'s values in the reader\'s language, days left counted in the organization\'s days', function () {
    $this->tenant->update(['timezone' => 'Asia/Dhaka']);
    $template = cardTemplate($this->tenant, ['code' => 'EMP', 'name' => 'Staff card']);
    $jane = cardholder($this->tenant, ['full_name' => 'Jane Doe', 'employee_no' => 'E-17']);
    $card = $this->postJson("/api/v1/cardholders/{$jane->public_id}/cards", ['template' => $template->public_id, 'reason' => 'new', 'issue_date' => '2026-01-05'])->json('data');
    $card = app(TenantContext::class)->run($this->tenant, fn () => Card::query()->where('public_id', $card['id'])->firstOrFail());
    $card->update(['expiry_date' => '2026-02-14']);
    // 00:30 on 4 February in Dhaka is still 3 February in UTC: ten days left, not eleven
    Date::setTestNow('2026-02-03 18:30:00');

    $values = function (string $locale) use ($card): array {
        app()->setLocale($locale);
        try {
            return app(TenantContext::class)->run($this->tenant, fn () => app(CardExpiringEvent::class)->values($card->fresh()));
        } finally {
            app()->setLocale('en');
        }
    };
    expect($values('en'))->toMatchArray([
        'organization.name' => 'Acme', 'holder.name' => 'Jane Doe', 'holder.employee_no' => 'E-17', 'card.template' => 'Staff card',
        'card.issue_date' => '05/01/2026', 'card.expiry_date' => '14/02/2026', 'card.days_left' => '10',
    ])->and($values('bn'))->toMatchArray(['card.issue_date' => '০৫/০১/২০২৬', 'card.days_left' => '১০', 'holder.employee_no' => 'E-১৭']);

    // Filled as plain text: unknown placeholders stay as written, values are never read as placeholders again
    expect(NotificationSettings::fill('Hi {holder.name}, {card.number} {nope}', ['holder.name' => '{card.number}', 'card.number' => 'C-1']))
        ->toBe('Hi {card.number}, C-1 {nope}');
    Date::setTestNow();
});
