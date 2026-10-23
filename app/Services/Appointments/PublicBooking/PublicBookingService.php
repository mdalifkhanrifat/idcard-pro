<?php

namespace App\Services\Appointments\PublicBooking;

use App\Models\AppointmentLocation;
use App\Repositories\Contracts\AppointmentLocationRepositoryInterface;
use App\Services\Appointments\AppointmentSettings;
use App\Support\Tenancy\TenantContext;

/**
 * The public booking link (5.6): what a person sees before proving who they are — the organization, the kinds of
 * visit it offers on the link and the places that take them, how they prove who they are and what they may change
 * later — all from the organization's settings. Nothing about people is answered here.
 */
class PublicBookingService
{
    public function __construct(
        private TenantContext $tenant,
        private AppointmentLocationRepositoryInterface $locations,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function info(): array
    {
        $tenant = $this->tenant->get();
        $settings = $tenant->appointmentSettings();
        $services = collect($settings['services'])
            ->filter(fn (array $service) => $service['enabled'] && $service['public'])
            ->map(fn (array $service, string $key) => ['key' => $key, 'name' => AppointmentSettings::serviceName($key, $service)])
            ->values();
        $keys = $services->pluck('key')->all();
        $places = $this->locations->list(null, true)
            ->map(fn (AppointmentLocation $place) => [
                'id' => $place->public_id, 'name' => $place->name, 'name_local' => $place->name_local, 'address' => $place->address,
                'services' => array_values(array_intersect($place->services ?? $keys, $keys)),
            ])
            ->filter(fn (array $place) => $place['services'] !== [])
            ->values();

        return [
            'organization' => [
                'name' => $tenant->name,
                'logo_url' => $tenant->logo_path ? url('storage/'.$tenant->logo_path) : null,
                'color' => $tenant->primary_color,
                'timezone' => $tenant->timezone ?: (string) config('app.timezone'),
            ],
            'scheduling' => $settings['scheduling'],
            'days_ahead' => $settings['days_ahead'],
            'notice_hours' => $settings['notice_hours'],
            'family_members' => $settings['family_members'],
            'confirmation' => $settings['confirmation'],
            'verification' => ['method' => $settings['verification']['method'], 'channels' => $settings['verification']['code_channels']],
            'changes' => $settings['changes'],
            'session_minutes' => (int) config('appointments.public.session_minutes'),
            'services' => $services->all(),
            'places' => $places->all(),
        ];
    }
}
