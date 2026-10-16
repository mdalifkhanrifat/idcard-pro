<?php

use App\Models\Role;
use App\Services\Appointments\AppointmentSettings;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Tenancy\TenantContext;
use Laravel\Sanctum\Sanctum;

/*
| Appointment settings (5.3a): every organization decides whether it uses appointments at all (off by default)
| and how — who books, a time or a day, confirmation, family members, limits, changes, identity check, reminders
| and kinds of visit — through GET/PUT /appointments/settings, a partial change, checked, in the audit log.
*/

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->put = function (array $appointments, array $headers = []) {
        $response = $this->putJson('/api/v1/appointments/settings', ['appointments' => $appointments], $headers);
        $this->owner->unsetRelation('tenant');

        return $response;
    };
    $this->saved = fn () => $this->tenant->fresh()->appointmentSettings();
});

it('starts off, booked by staff at a time and confirmed at once, with every choice named', function () {
    Sanctum::actingAs($this->owner);

    $response = $this->getJson('/api/v1/appointments/settings')->assertOk();
    expect($response->json('data.appointments'))->toMatchArray([
        'enabled' => false,
        'booking' => ['staff'],
        'scheduling' => 'slot',
        'slot_minutes' => 15,
        'confirmation' => 'auto',
        'family_members' => true,
        'open_per_service' => 1,
        'notice_hours' => 2,
        'days_ahead' => 30,
        'changes' => ['cancel' => true, 'reschedule' => true, 'until_hours' => 2],
        'verification' => ['method' => 'code', 'code_channels' => ['mail', 'sms'], 'code_minutes' => 10, 'code_attempts' => 5],
        'reminders' => [24],
    ])
        ->and(array_keys($response->json('data.appointments.services')))->toBe(['new_card', 'renewal', 'biometrics', 'card_collection'])
        ->and($response->json('data.appointments.services.renewal'))->toBe(['enabled' => true, 'custom' => false, 'name' => ['bn' => null, 'en' => null], 'slots' => 1, 'public' => true])
        ->and($response->json('data.options.booking'))->toBe(['staff', 'public'])
        ->and($response->json('data.options.labels.scheduling'))->toBe(['slot' => 'A time of the day', 'day' => 'A day (served in turn)'])
        ->and($response->json('data.options.services.biometrics'))->toBe(['slots' => 1, 'names' => ['bn' => 'বায়োমেট্রিক নেওয়া', 'en' => 'Biometrics capture']]);

    // In Bangla, and in the organization as /auth/me gives it (so pages know whether to show appointments)
    $this->getJson('/api/v1/appointments/settings', ['Accept-Language' => 'bn'])->assertJsonPath('data.options.labels.confirmation.approval', 'প্রতিটা বুকিং কর্মী অনুমোদন করেন');
    $this->getJson('/api/v1/auth/me')->assertJsonPath('data.tenant.appointments.enabled', false);
});

it('shows them to those who see appointments or may change the settings, and lets only the latter save', function () {
    foreach (['Administrator' => [200, 403], 'Viewer' => [200, 403], 'Operator' => [200, 403]] as $role => [$show, $save]) {
        Sanctum::actingAs(tenantUser($this->tenant, $role));
        $this->getJson('/api/v1/appointments/settings')->assertStatus($show);
        $this->putJson('/api/v1/appointments/settings', ['appointments' => ['days_ahead' => 10]])->assertStatus($save);
    }
    Sanctum::actingAs($this->owner);
    ($this->put)(['days_ahead' => 10])->assertOk();
});

it('gives the default roles their part of appointments', function () {
    $permissions = fn (string $role): array => app(TenantContext::class)->run(
        $this->tenant,
        fn () => Role::findByName($role, PermissionRegistry::GUARD)->permissions->pluck('name')->filter(fn (string $name) => str_starts_with($name, 'appointments.'))->sort()->values()->all(),
    );

    expect($permissions('Owner'))->toBe(['appointments.book', 'appointments.manage', 'appointments.view'])
        ->and($permissions('Administrator'))->toBe(['appointments.book', 'appointments.manage', 'appointments.view'])
        ->and($permissions('Operator'))->toBe(['appointments.book', 'appointments.view'])
        ->and($permissions('Viewer'))->toBe(['appointments.view']);
});

it('takes a partial change: what is not sent stays, and only what changed is noted in the audit log', function () {
    Sanctum::actingAs($this->owner);

    ($this->put)(['enabled' => true, 'booking' => ['staff', 'public'], 'scheduling' => 'day', 'confirmation' => 'approval', 'changes' => ['until_hours' => 24], 'verification' => ['method' => 'date_of_birth']])->assertOk()
        ->assertJsonPath('data.appointments.enabled', true)
        ->assertJsonPath('data.appointments.changes', ['cancel' => true, 'reschedule' => true, 'until_hours' => 24])
        ->assertJsonPath('data.appointments.verification.code_channels', ['mail', 'sms']);

    ($this->put)(['days_ahead' => 60])->assertOk();
    expect(($this->saved)())->toMatchArray(['enabled' => true, 'booking' => ['staff', 'public'], 'scheduling' => 'day', 'confirmation' => 'approval', 'days_ahead' => 60]);
    expect($this->getJson('/api/v1/audit/activities?filter[log_name]=tenant')->json('data.0.changes'))
        ->toBe(['attributes' => ['settings.appointments.days_ahead' => 60], 'old' => ['settings.appointments.days_ahead' => 30]]);

    // An empty list is none; reminders can be none
    ($this->put)(['reminders' => []])->assertOk()->assertJsonPath('data.appointments.reminders', []);
    // A built-in kind of visit renamed in one language, turned off, the other language kept
    ($this->put)(['services' => ['renewal' => ['name' => ['en' => 'Renew a card']]]])->assertOk();
    ($this->put)(['services' => ['renewal' => ['enabled' => false, 'name' => ['bn' => 'নবায়ন']]]])->assertOk()
        ->assertJsonPath('data.appointments.services.renewal', ['enabled' => false, 'custom' => false, 'name' => ['bn' => 'নবায়ন', 'en' => 'Renew a card'], 'slots' => 1, 'public' => true]);
});

it('lets the organization add, change and remove kinds of visit of its own', function () {
    Sanctum::actingAs($this->owner);

    ($this->put)(['services' => ['vehicle_pass' => ['name' => ['en' => 'Vehicle pass', 'bn' => 'গাড়ির পাস'], 'slots' => 2, 'public' => false]]])->assertOk()
        ->assertJsonPath('data.appointments.services.vehicle_pass', ['enabled' => true, 'custom' => true, 'name' => ['bn' => 'গাড়ির পাস', 'en' => 'Vehicle pass'], 'slots' => 2, 'public' => false, 'position' => 1]);
    expect(array_keys(($this->saved)()['services']))->toBe(['new_card', 'renewal', 'biometrics', 'card_collection', 'vehicle_pass'])
        ->and(AppointmentSettings::serviceName('vehicle_pass', ($this->saved)()['services']['vehicle_pass']))->toBe('Vehicle pass')
        ->and(AppointmentSettings::serviceName('renewal', ($this->saved)()['services']['renewal']))->toBe('Card renewal');

    ($this->put)(['services' => ['vehicle_pass' => ['slots' => 3]]])->assertOk()->assertJsonPath('data.appointments.services.vehicle_pass.name.en', 'Vehicle pass');
    ($this->put)(['services' => ['vehicle_pass' => null]])->assertOk();
    expect(($this->saved)()['services'])->not->toHaveKey('vehicle_pass');
});

it('checks a change in the reader\'s language, and saves nothing that fails', function () {
    Sanctum::actingAs($this->owner);
    $before = $this->tenant->fresh()->settings;
    $errors = fn (array $change) => ($this->put)($change, ['Accept-Language' => 'bn'])->assertUnprocessable()->json('errors');

    $this->putJson('/api/v1/appointments/settings', [])->assertUnprocessable()->assertJsonValidationErrors('appointments');
    expect($errors(['enabled' => true, 'booking' => []]))->toBe(['appointments.booking' => ['অ্যাপয়েন্টমেন্ট চালুর আগে কে বুক করবেন তা বেছে নিন।']])
        ->and(array_keys($errors(['scheduling' => 'hour', 'slot_minutes' => 1, 'days_ahead' => 400, 'booking' => ['fax'], 'reminders' => [0, 500, 1, 2], 'colour' => 'red'])))
        ->toContain('appointments', 'appointments.scheduling', 'appointments.slot_minutes', 'appointments.days_ahead', 'appointments.booking.0', 'appointments.reminders')
        ->and($errors(['verification' => ['code_channels' => []]]))->toBe(['appointments.verification.code_channels' => ['এককালীন কোড কোন পথে যাবে, অন্তত একটা বেছে নিন।']])
        ->and($errors(['services' => ['renewal' => null]]))->toBe(['appointments.services.renewal' => ['সিস্টেমের দেওয়া কাজের ধরন সরানো যায় না; বন্ধ করে দিন।']])
        ->and($errors(['services' => ['Bad Key' => ['name' => ['en' => 'X']]]]))->toHaveKey('appointments.services.Bad Key')
        ->and($errors(['services' => ['vehicle_pass' => ['slots' => 1]]]))->toBe(['appointments.services.vehicle_pass.name' => ['এই কাজের ধরনের একটা নাম দিন।']])
        ->and($errors(['enabled' => true, 'services' => collect(AppointmentSettings::registryServices())->map(fn () => ['enabled' => false])->all()]))
        ->toBe(['appointments.services' => ['অ্যাপয়েন্টমেন্ট চালুর আগে অন্তত একটা কাজের ধরন চালু করুন।']]);

    // Every mistake at once: the shape and the kinds of visit's keys
    expect(array_keys($errors(['slot_minutes' => 1, 'services' => ['renewal' => null, 'Bad Key' => ['name' => ['en' => 'X']]]])))
        ->toEqualCanonicalizing(['appointments.slot_minutes', 'appointments.services.renewal', 'appointments.services.Bad Key']);

    config(['appointments.custom_services.max' => 1]);
    expect($errors(['services' => ['one' => ['name' => ['en' => 'One']], 'two' => ['name' => ['en' => 'Two']]]]))->toBe(['appointments.services' => ['প্রতিষ্ঠান নিজের সর্বোচ্চ ১টা কাজের ধরন যোগ করতে পারে।']]);

    expect($this->tenant->fresh()->settings)->toBe($before);
});

it('reads saved settings complete and within limits, whatever is saved', function () {
    $this->tenant->forceFill(['settings' => ['appointments' => [
        'enabled' => true, 'booking' => ['public', 'robot'], 'scheduling' => 'hour', 'slot_minutes' => 9999, 'days_ahead' => -3,
        'reminders' => [500, 48, 48, 2, 1, 'x'], 'verification' => ['code_channels' => ['fax', 'sms']],
        'services' => ['renewal' => ['slots' => 50], 'Bad Key' => ['name' => ['en' => 'X']], 'vehicle_pass' => ['name' => ['en' => '  Vehicle pass  ', 'bn' => '']]],
    ]]])->save();

    expect(($this->saved)())->toMatchArray([
        'enabled' => true, 'booking' => ['public'], 'scheduling' => 'slot', 'slot_minutes' => 240, 'days_ahead' => 1,
        'reminders' => [48, 2, 1],
    ])
        ->and(($this->saved)()['verification']['code_channels'])->toBe(['sms'])
        ->and(($this->saved)()['services']['renewal']['slots'])->toBe(8)
        ->and(($this->saved)()['services'])->not->toHaveKey('Bad Key')
        ->and(($this->saved)()['services']['vehicle_pass']['name'])->toBe(['bn' => null, 'en' => 'Vehicle pass']);
});

it('takes a new kind of visit or choice from the registry alone', function () {
    config(['appointments.services.passport_photo' => ['slots' => 2], 'appointments.scheduling' => ['slot', 'day', 'week']]);
    Sanctum::actingAs($this->owner);

    $this->getJson('/api/v1/appointments/settings')->assertOk()
        ->assertJsonPath('data.appointments.services.passport_photo', ['enabled' => true, 'custom' => false, 'name' => ['bn' => null, 'en' => null], 'slots' => 2, 'public' => true])
        ->assertJsonPath('data.options.scheduling', ['slot', 'day', 'week'])
        // No name yet: its key
        ->assertJsonPath('data.options.labels.scheduling.week', 'week');
    ($this->put)(['scheduling' => 'week'])->assertOk()->assertJsonPath('data.appointments.scheduling', 'week');
});

it('takes back the whole settings as it answered them, changing nothing, a wrong `custom` mark not taken', function () {
    Sanctum::actingAs($this->owner);
    ($this->put)(['services' => ['vehicle_pass' => ['name' => ['en' => 'Vehicle pass']]]])->assertOk();
    $answered = $this->getJson('/api/v1/appointments/settings')->assertOk()->json('data.appointments');
    $audit = $this->tenant->fresh()->settings;

    // A client sending back what it read (a settings page, an integration) — even with a wrong `custom` mark
    $answered['services']['renewal']['custom'] = true;
    ($this->put)($answered)->assertOk()->assertJsonPath('data.appointments.services.renewal.custom', false)->assertJsonPath('data.appointments.services.vehicle_pass.custom', true);

    expect($this->tenant->fresh()->settings)->toBe($audit)
        ->and($this->tenant->fresh()->settings['appointments']['services']['renewal']['custom'] ?? false)->toBeFalse();
});

it('keeps the organization\'s own kinds of visit in the order they were added, whatever order the keys are stored in', function () {
    Sanctum::actingAs($this->owner);
    ($this->put)(['services' => ['vehicle_pass' => ['name' => ['en' => 'Vehicle pass']], 'visit' => ['name' => ['bn' => 'অতিথি পাস']]]])->assertOk();
    ($this->put)(['services' => ['canteen' => ['name' => ['en' => 'Canteen card']]]])->assertOk();
    $order = fn () => array_slice(array_keys($this->getJson('/api/v1/appointments/settings')->json('data.appointments.services')), 4);
    expect($order())->toBe(['vehicle_pass', 'visit', 'canteen'])
        ->and($this->getJson('/api/v1/appointments/settings')->json('data.appointments.services.canteen.position'))->toBe(3);

    // A JSON column (MySQL's) stores an object's keys shortest first: the order still holds
    $settings = $this->tenant->fresh()->settings;
    $services = $settings['appointments']['services'];
    uksort($services, fn (string $a, string $b) => [strlen($a), $a] <=> [strlen($b), $b]);
    $this->tenant->forceFill(['settings' => [...$settings, 'appointments' => [...$settings['appointments'], 'services' => $services]]])->save();
    $this->owner->unsetRelation('tenant');
    expect($order())->toBe(['vehicle_pass', 'visit', 'canteen']);

    // Sent back with other positions, or one removed: the order is kept, then numbered again
    $answered = $this->getJson('/api/v1/appointments/settings')->json('data.appointments.services');
    $answered['canteen']['position'] = 1;
    $answered['vehicle_pass'] = null;
    ($this->put)(['services' => $answered])->assertOk();
    expect($order())->toBe(['visit', 'canteen'])
        ->and($this->getJson('/api/v1/appointments/settings')->json('data.appointments.services.canteen.position'))->toBe(2);
});
