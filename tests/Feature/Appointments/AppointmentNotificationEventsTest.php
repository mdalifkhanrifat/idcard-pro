<?php

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\AppointmentLocation;
use App\Models\Cardholder;
use App\Models\FamilyMember;
use App\Models\NotificationMessage;
use App\Services\Notifications\NotificationComposer;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\NotificationSettings;
use App\Support\Tenancy\TenantContext;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/*
| Appointment events in the notifications registry (5.4e; sending from 5.7): booked, moved, cancelled and a
| reminder — named, with their placeholders, made-up samples and default texts in both languages; their values
| for a real appointment (the organization's clock, the place in the reader's language, a day booked, a family
| member); messages linked to the appointment; and a kind of visit with bookings kept from removal.
*/

beforeEach(function () {
    $this->withoutMiddleware(ThrottleRequests::class);
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->forceFill(['name' => 'Acme Ltd', 'timezone' => 'Asia/Dhaka', 'locale' => 'en', 'settings' => ['appointments' => [
        'enabled' => true, 'slot_minutes' => 30, 'services' => ['vehicle_pass' => ['name' => ['en' => 'Vehicle pass', 'bn' => 'গাড়ির পাস']]],
    ]]])->save();
    $this->owner->unsetRelation('tenant');
    $this->inTenant = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant->fresh(), $callback);
    $this->events = ['appointment_booked', 'appointment_changed', 'appointment_cancelled', 'appointment_reminder'];
    ($this->inTenant)(function () {
        $this->place = AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Head office', 'name_local' => 'প্রধান কার্যালয়', 'address' => '12 Station Road']);
        $this->holder = Cardholder::factory()->create(['tenant_id' => $this->tenant->id, 'full_name' => 'Jane Doe', 'employee_no' => 'EMP-77', 'email' => 'jane@example.com', 'personal_phone' => '+8801711000000']);
        $this->member = FamilyMember::factory()->create(['tenant_id' => $this->tenant->id, 'cardholder_id' => $this->holder->id, 'name' => 'Ayesha Doe']);
        $this->appointment = Appointment::factory()->at('2026-10-18', '2026-10-18 04:00:00', 30)->create([
            'tenant_id' => $this->tenant->id, 'number' => 'A-26-00042', 'location_id' => $this->place->id, 'cardholder_id' => $this->holder->id, 'family_member_id' => $this->member->id,
            'service' => 'vehicle_pass', 'status' => AppointmentStatus::Cancelled, 'cancel_reason' => 'Travelling',
        ]);
    });
    $this->values = fn (string $event, Appointment $appointment, string $language) => ($this->inTenant)(function () use ($event, $appointment, $language) {
        app()->setLocale($language);

        return NotificationSettings::events()[$event]->values($appointment->fresh());
    });
    $this->travelTo(now()->parse('2026-10-16 03:00:00'));
});

it('adds the four appointment events to the registry, named, with samples and default texts in both languages', function () {
    Sanctum::actingAs($this->owner);
    $queries = 0;
    $data = $this->getJson('/api/v1/notifications/settings', ['Accept-Language' => 'bn'])->assertOk()->json('data');

    foreach ($this->events as $event) {
        $options = $data['options']['events'][$event];
        expect($options['name'])->not->toBe($event)
            ->and($options['channels'] ?? $options['default_channels'] ?? null)->not->toBeNull()
            ->and(array_keys($options['default_templates']))->toEqualCanonicalizing(['bn', 'en']);
        foreach (['bn', 'en'] as $language) {
            $sample = $data['samples'][$event][$language];
            // Every placeholder has a sample value, and every default text uses only known ones
            foreach (NotificationSettings::events()[$event]->placeholders() as $placeholder) {
                expect($sample)->toHaveKey($placeholder);
            }
            foreach ($options['default_templates'][$language] as $text) {
                expect(preg_match_all('/\{([a-z_.]+)\}/', $text, $found) ? array_diff($found[1], array_keys($sample)) : [])->toBe([]);
            }
        }
    }
    $bn = $data['samples']['appointment_booked']['bn'];
    expect($bn)->toMatchArray([
        'organization.name' => 'Acme Ltd', 'holder.name' => 'রহিম উদ্দিন', 'appointment.number' => 'A-26-00042',
        'appointment.date' => '১৮/১০/২০২৬', 'appointment.time' => '১০:৩০', 'appointment.when' => '১৮/১০/২০২৬, ১০:৩০',
        'appointment.service' => 'নতুন কার্ড', 'appointment.place' => 'প্রধান কার্যালয়', 'appointment.status' => 'নিশ্চিত',
    ])->and($data['samples']['appointment_cancelled']['en']['appointment.cancel_reason'])->toBe('Reason: The office is closed that day.')
        ->and($data['samples']['appointment_booked']['en']['appointment.when'])->toBe('18/10/2026 at 10:30');

    // Samples are built in memory: nothing read or written for them, and none of the organization's appointments
    $tenant = $this->tenant->fresh();
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    app(NotificationComposer::class)->samples($tenant);
    expect($queries)->toBe(0)->and(Appointment::query()->withoutGlobalScopes()->count())->toBe(1);

    // A day booked: the day alone; the organization's own number format
    $this->tenant->forceFill(['settings' => ['appointments' => ['scheduling' => 'day', 'number_format' => 'APT/{YYYY}/{SEQ:3}']]])->save();
    $this->owner->unsetRelation('tenant');
    $en = $this->getJson('/api/v1/notifications/settings')->json('data.samples.appointment_reminder.en');
    expect($en)->toMatchArray(['appointment.number' => 'APT/2026/042', 'appointment.time' => '', 'appointment.when' => '18/10/2026']);
});

it('fills an appointment\'s values on the organization\'s clock, the place in the reader\'s language', function () {
    $en = ($this->values)('appointment_cancelled', $this->appointment, 'en');
    expect($en)->toBe([
        'organization.name' => 'Acme Ltd', 'holder.name' => 'Ayesha Doe', 'holder.employee_no' => 'EMP-77',
        'appointment.number' => 'A-26-00042', 'appointment.service' => 'Vehicle pass', 'appointment.place' => 'Head office',
        'appointment.address' => '12 Station Road', 'appointment.date' => '18/10/2026', 'appointment.time' => '10:00',
        'appointment.when' => '18/10/2026 at 10:00', 'appointment.status' => 'Cancelled', 'appointment.cancel_reason' => 'Reason: Travelling.',
    ]);
    $bn = ($this->values)('appointment_cancelled', $this->appointment, 'bn');
    expect($bn)->toMatchArray([
        'holder.employee_no' => 'EMP-৭৭', 'appointment.service' => 'গাড়ির পাস', 'appointment.place' => 'প্রধান কার্যালয়',
        'appointment.when' => '১৮/১০/২০২৬, ১০:০০', 'appointment.status' => 'বাতিল', 'appointment.cancel_reason' => 'কারণ: Travelling।',
    ]);

    // A day booked, the cardholder's own, no reason, a place without another name
    ($this->inTenant)(function () {
        $this->appointment->forceFill(['starts_at' => null, 'ends_at' => null, 'family_member_id' => null, 'cancel_reason' => null, 'status' => AppointmentStatus::Confirmed])->save();
        $this->place->forceFill(['name_local' => null])->save();
    });
    expect(($this->values)('appointment_cancelled', $this->appointment, 'bn'))->toMatchArray([
        'holder.name' => 'Jane Doe', 'appointment.time' => '', 'appointment.when' => '১৮/১০/২০২৬', 'appointment.place' => 'Head office', 'appointment.cancel_reason' => '',
    ])->and(($this->values)('appointment_booked', $this->appointment, 'en'))->not->toHaveKey('appointment.cancel_reason');
});

it('logs an appointment\'s messages to the cardholder, linked to it, also when sent again', function () {
    $this->tenant->forceFill(['settings' => [...$this->tenant->fresh()->settings, 'notifications' => [
        'enabled' => true, 'channels' => ['mail', 'sms'], 'languages' => ['en'], 'events' => ['appointment_cancelled' => ['enabled' => true, 'channels' => ['mail', 'sms']]],
    ]]])->save();
    $messages = ($this->inTenant)(fn () => app(NotificationService::class)->notify('appointment_cancelled', $this->appointment));

    expect($messages)->toHaveCount(2)
        ->and($messages->pluck('appointment_id')->unique()->all())->toBe([$this->appointment->id])
        ->and($messages->pluck('cardholder_id')->unique()->all())->toBe([$this->holder->id])
        ->and($messages->firstWhere('channel', 'mail')->subject)->toBe('Your appointment A-26-00042 is cancelled')
        ->and($messages->firstWhere('channel', 'sms')->body)->toBe('Acme Ltd: appointment A-26-00042 on 18/10/2026 at 10:00 is cancelled.');

    Sanctum::actingAs($this->owner);
    $mail = $messages->firstWhere('channel', 'mail');
    $this->getJson("/api/v1/notifications/messages/{$mail->public_id}")->assertOk()->assertJsonPath('data.appointment', ['id' => $this->appointment->public_id, 'number' => 'A-26-00042']);
    ($this->inTenant)(fn () => $mail->forceFill(['status' => 'failed'])->save());
    $this->postJson("/api/v1/notifications/messages/{$mail->public_id}/resend")->assertCreated()->assertJsonPath('data.appointment.number', 'A-26-00042');
    expect(NotificationMessage::query()->withoutGlobalScopes()->whereNotNull('resend_of_id')->value('appointment_id'))->toBe($this->appointment->id);
});

it('keeps a kind of visit with bookings from removal: turned off instead', function () {
    Sanctum::actingAs($this->owner);
    $this->tenant->forceFill(['settings' => ['appointments' => ['services' => [
        'vehicle_pass' => ['name' => ['en' => 'Vehicle pass'], 'position' => 1], 'visitor_pass' => ['name' => ['en' => 'Visitor pass'], 'position' => 2],
    ]]]])->save();
    $this->owner->unsetRelation('tenant');

    $this->putJson('/api/v1/appointments/settings', ['appointments' => ['services' => ['vehicle_pass' => null, 'visitor_pass' => null]]], ['Accept-Language' => 'bn'])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['appointments.services.vehicle_pass' => ['এই কাজের ধরনে বুকিং আছে, সেগুলো রেকর্ড হিসেবে থাকে; সরানোর বদলে বন্ধ করুন।']]);
    // Without bookings it goes; with them it is turned off
    $this->putJson('/api/v1/appointments/settings', ['appointments' => ['services' => ['visitor_pass' => null]]])->assertOk();
    $this->putJson('/api/v1/appointments/settings', ['appointments' => ['services' => ['vehicle_pass' => ['enabled' => false]]]])->assertOk()
        ->assertJsonPath('data.appointments.services.vehicle_pass.enabled', false);
    expect(array_keys($this->tenant->fresh()->appointmentSettings()['services']))->toContain('vehicle_pass')->not->toContain('visitor_pass');
});
