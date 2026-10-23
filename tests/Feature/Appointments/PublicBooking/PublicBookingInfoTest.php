<?php

use App\Models\AppointmentLocation;
use App\Support\Tenancy\TenantContext;

/*
| The public booking link's front page (5.6a): only an active organization with appointments on that books through
| the public link answers — any other 404s alike; it shows the kinds of visit offered there and the places that take
| them, how a person proves who they are and what they may change, from the organization's settings; never cached
| nor indexed; rate limited per address.
*/

beforeEach(function () {
    [$this->tenant] = tenantWithOwner();
    $this->settings = fn (array $appointments) => $this->tenant->forceFill(['settings' => ['appointments' => ['enabled' => true, 'booking' => ['staff', 'public'], ...$appointments]]])->save();
    ($this->settings)([
        'services' => ['renewal' => ['public' => false], 'biometrics' => ['enabled' => false], 'passes' => ['name' => ['en' => 'Vehicle pass', 'bn' => 'গাড়ির পাস'], 'public' => true, 'position' => 1]],
        'verification' => ['method' => 'date_of_birth'],
    ]);
    $in = fn (Closure $callback) => app(TenantContext::class)->run($this->tenant, $callback);
    [$this->head, $this->renewals, $this->off] = $in(fn () => [
        AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Head office', 'services' => null]),
        // Takes only a kind not offered on the link
        AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Renewals desk', 'services' => ['renewal']]),
        AppointmentLocation::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Closed branch', 'is_active' => false]),
    ]);
});

it('shows what the link offers, from the organization\'s settings, and nothing about people', function () {
    $response = $this->getJson('/api/v1/public/acme/booking', ['Accept-Language' => 'bn'])->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    $data = $response->json('data');

    expect($data['organization'])->toMatchArray(['name' => 'Acme', 'logo_url' => null])
        ->and(array_column($data['services'], 'key'))->toBe(['new_card', 'card_collection', 'passes'])
        ->and(collect($data['services'])->firstWhere('key', 'passes')['name'])->toBe('গাড়ির পাস')
        ->and(array_column($data['places'], 'name'))->toBe(['Head office'])
        ->and($data['places'][0]['services'])->toBe(['new_card', 'card_collection', 'passes'])
        ->and($data['verification'])->toBe(['method' => 'date_of_birth', 'channels' => ['mail', 'sms']])
        ->and($data['changes'])->toBe(['cancel' => true, 'reschedule' => true, 'until_hours' => 2])
        ->and($data['session_minutes'])->toBe(30)
        ->and($data)->toHaveKeys(['scheduling', 'days_ahead', 'notice_hours', 'family_members', 'confirmation']);
});

it('answers 404 alike for an unknown organization, a suspended one, appointments off or staff-only booking', function () {
    $this->getJson('/api/v1/public/nobody/booking')->assertNotFound()->assertJsonPath('message', 'This booking page is not available.');

    ($this->settings)(['booking' => ['staff']]);
    $this->getJson('/api/v1/public/acme/booking')->assertNotFound();
    ($this->settings)(['enabled' => false]);
    $this->getJson('/api/v1/public/acme/booking')->assertNotFound();

    ($this->settings)([]);
    $this->getJson('/api/v1/public/acme/booking')->assertOk();
    $this->tenant->forceFill(['status' => 'suspended'])->save();
    $this->getJson('/api/v1/public/acme/booking', ['Accept-Language' => 'bn'])->assertNotFound()->assertJsonPath('message', 'এই বুকিংয়ের পাতা এখন খোলা নেই।');
});

it('limits requests per address', function () {
    config(['appointments.public.per_minute' => 2]);
    $this->getJson('/api/v1/public/acme/booking')->assertOk();
    $this->getJson('/api/v1/public/acme/booking')->assertOk();
    $this->getJson('/api/v1/public/acme/booking')->assertTooManyRequests();
});
