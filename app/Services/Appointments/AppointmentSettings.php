<?php

namespace App\Services\Appointments;

use App\Services\Notifications\NotificationSettings;

/**
 * An organization's appointment settings (tenants.settings.appointments): whether it uses appointments at all,
 * and how — who books, a time or a day, confirmed at once or after approval, family members, limits, what a
 * person may change through the public link, how they prove who they are, reminders, and the kinds of visit
 * (the registry's, turned on or off and renamed, and the organization's own).
 */
final class AppointmentSettings
{
    /**
     * @return array<int, string>
     */
    public static function bookingModes(): array
    {
        return array_values((array) config('appointments.booking'));
    }

    /**
     * @return array<int, string>
     */
    public static function schedulingModes(): array
    {
        return array_values((array) config('appointments.scheduling'));
    }

    /**
     * @return array<int, string>
     */
    public static function confirmationModes(): array
    {
        return array_values((array) config('appointments.confirmation'));
    }

    /**
     * @return array<int, string>
     */
    public static function verificationMethods(): array
    {
        return array_values((array) config('appointments.verification'));
    }

    /**
     * Channels a one-time code may go on: those configured for codes that notifications can send on.
     *
     * @return array<int, string>
     */
    public static function codeChannels(): array
    {
        return array_values(array_intersect((array) config('appointments.code_channels'), NotificationSettings::channels()));
    }

    /**
     * The registry's kinds of visit: key => slots one booking takes.
     *
     * @return array<string, int>
     */
    public static function registryServices(): array
    {
        return collect((array) config('appointments.services'))->map(fn (array $service): int => (int) ($service['slots'] ?? 1))->all();
    }

    /**
     * Languages a kind of visit is named in (those messages are written in).
     *
     * @return array<int, string>
     */
    public static function languages(): array
    {
        return NotificationSettings::languages();
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function limit(string $key): array
    {
        $limit = (array) config("appointments.limits.{$key}");

        return [(int) $limit[0], (int) $limit[1]];
    }

    /**
     * The saved settings with a change applied: what is not sent stays, a value or list that is sent replaces the
     * old one (an empty list is none), groups (changes, verification) change only in what is sent, a kind of visit
     * only in what is sent for it (its name by language), and an organization's own kind sent as null is removed.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    public static function patch(array $current, array $change): array
    {
        $settings = [...$current, ...array_diff_key($change, array_flip(['changes', 'verification', 'services']))];
        foreach (['changes', 'verification'] as $group) {
            if (array_key_exists($group, $change)) {
                $settings[$group] = [...(array) ($current[$group] ?? []), ...(array) $change[$group]];
            }
        }
        $services = (array) ($current['services'] ?? []);
        // An organization's own kinds keep the order they were added in (a JSON column may store keys in its own)
        $next = 1 + (int) collect($services)->max(fn (mixed $service) => (int) (is_array($service) ? ($service['position'] ?? 0) : 0));
        foreach ((array) ($change['services'] ?? []) as $key => $serviceChange) {
            if ($serviceChange === null) {
                unset($services[$key]);

                continue;
            }
            // Whether a kind is the organization's own follows from the registry, not from what is sent
            $service = [...(array) ($services[$key] ?? []), ...array_diff_key((array) $serviceChange, ['name' => true, 'custom' => true, 'position' => true])];
            if (! isset(self::registryServices()[$key]) && ! isset($service['position'])) {
                $service['position'] = $next++;
            }
            if (array_key_exists('name', (array) $serviceChange)) {
                $service['name'] = [...(array) ($services[$key]['name'] ?? []), ...(array) $serviceChange['name']];
            }
            $services[$key] = $service;
        }
        $settings['services'] = $services;

        return $settings;
    }

    /**
     * The settings, complete and within their limits, whatever is saved.
     *
     * @param  array<string, mixed>  $saved
     * @return array{enabled: bool, booking: array<int, string>, scheduling: string, slot_minutes: int, confirmation: string, family_members: bool, open_per_service: int, notice_hours: int, days_ahead: int, changes: array{cancel: bool, reschedule: bool, until_hours: int}, verification: array{method: string, code_channels: array<int, string>, code_minutes: int, code_attempts: int}, reminders: array<int, int>, number_format: string, services: array<string, array{enabled: bool, custom: bool, name: array<string, string|null>, slots: int, public: bool, position?: int}>}
     */
    public static function normalize(array $saved): array
    {
        $defaults = (array) config('appointments.defaults');
        $number = function (mixed $value, string $limit, int $default): int {
            [$min, $max] = self::limit($limit);

            return max($min, min($max, is_numeric($value) ? (int) $value : $default));
        };
        $list = fn (mixed $value, array $allowed, array $default): array => array_values(array_intersect(array_unique(is_array($value) ? $value : $default), $allowed));
        $oneOf = fn (mixed $value, array $allowed, string $default): string => in_array($value, $allowed, true) ? $value : $default;
        $changes = (array) ($saved['changes'] ?? []);
        $verification = (array) ($saved['verification'] ?? []);
        [$minHours, $maxHours] = self::limit('reminder_hours');
        $reminders = collect(is_array($saved['reminders'] ?? null) ? $saved['reminders'] : $defaults['reminders'])
            ->filter(fn (mixed $hours) => is_numeric($hours) && (int) $hours >= $minHours && (int) $hours <= $maxHours)
            ->map(fn (mixed $hours) => (int) $hours)->unique()->sortDesc()->take(self::limit('reminders')[1])->values()->all();

        return [
            'enabled' => (bool) ($saved['enabled'] ?? $defaults['enabled']),
            'booking' => $list($saved['booking'] ?? null, self::bookingModes(), (array) $defaults['booking']),
            'scheduling' => $oneOf($saved['scheduling'] ?? null, self::schedulingModes(), (string) $defaults['scheduling']),
            'slot_minutes' => $number($saved['slot_minutes'] ?? null, 'slot_minutes', (int) $defaults['slot_minutes']),
            'confirmation' => $oneOf($saved['confirmation'] ?? null, self::confirmationModes(), (string) $defaults['confirmation']),
            'family_members' => (bool) ($saved['family_members'] ?? $defaults['family_members']),
            'open_per_service' => $number($saved['open_per_service'] ?? null, 'open_per_service', (int) $defaults['open_per_service']),
            'notice_hours' => $number($saved['notice_hours'] ?? null, 'notice_hours', (int) $defaults['notice_hours']),
            'days_ahead' => $number($saved['days_ahead'] ?? null, 'days_ahead', (int) $defaults['days_ahead']),
            'changes' => [
                'cancel' => (bool) ($changes['cancel'] ?? $defaults['changes']['cancel']),
                'reschedule' => (bool) ($changes['reschedule'] ?? $defaults['changes']['reschedule']),
                'until_hours' => $number($changes['until_hours'] ?? null, 'change_until_hours', (int) $defaults['changes']['until_hours']),
            ],
            'verification' => [
                'method' => $oneOf($verification['method'] ?? null, self::verificationMethods(), (string) $defaults['verification']['method']),
                'code_channels' => $list($verification['code_channels'] ?? null, self::codeChannels(), (array) $defaults['verification']['code_channels']),
                'code_minutes' => $number($verification['code_minutes'] ?? null, 'code_minutes', (int) $defaults['verification']['code_minutes']),
                'code_attempts' => $number($verification['code_attempts'] ?? null, 'code_attempts', (int) $defaults['verification']['code_attempts']),
            ],
            'reminders' => $reminders,
            'number_format' => is_string($saved['number_format'] ?? null) && AppointmentNumbers::problem($saved['number_format']) === null ? $saved['number_format'] : (string) $defaults['number_format'],
            'services' => self::normalizeServices((array) ($saved['services'] ?? [])),
        ];
    }

    /**
     * The registry's kinds of visit first, in its order (on unless turned off), then the organization's own, in
     * the order they were added (`position`, 1 on — kept apart from the keys' order, which a JSON column may
     * change); names null where the default name stands.
     *
     * @param  array<string, mixed>  $saved
     * @return array<string, array{enabled: bool, custom: bool, name: array<string, string|null>, slots: int, public: bool, position?: int}>
     */
    private static function normalizeServices(array $saved): array
    {
        [$minSlots, $maxSlots] = self::limit('service_slots');
        $service = function (array $values, int $slots, bool $custom): array {
            $names = [];
            foreach (self::languages() as $language) {
                $name = $values['name'][$language] ?? null;
                $names[$language] = is_string($name) && trim($name) !== '' ? trim($name) : null;
            }

            return [
                'enabled' => (bool) ($values['enabled'] ?? true),
                'custom' => $custom,
                'name' => $names,
                'slots' => $slots,
                // Offered on the public link too (staff can always book it)
                'public' => (bool) ($values['public'] ?? true),
            ];
        };
        $clampSlots = fn (mixed $value, int $default): int => max($minSlots, min($maxSlots, is_numeric($value) ? (int) $value : $default));

        $services = [];
        foreach (self::registryServices() as $key => $slots) {
            $values = (array) ($saved[$key] ?? []);
            $services[$key] = $service($values, $clampSlots($values['slots'] ?? null, $slots), false);
        }
        $custom = array_filter($saved, fn (mixed $values, int|string $key) => is_array($values) && ! isset($services[$key]) && preg_match((string) config('appointments.custom_services.key'), (string) $key), ARRAY_FILTER_USE_BOTH);
        // In the order they were added (stable for those saved before positions)
        uksort($custom, fn (int|string $a, int|string $b) => ((int) ($custom[$a]['position'] ?? 0)) <=> ((int) ($custom[$b]['position'] ?? 0)));
        $position = 0;
        foreach (array_slice($custom, 0, (int) config('appointments.custom_services.max'), true) as $key => $values) {
            $services[(string) $key] = [...$service($values, $clampSlots($values['slots'] ?? null, 1), true), 'position' => ++$position];
        }

        return $services;
    }

    /**
     * A kind of visit's name in the current language: the organization's own, else the registry's, else the
     * organization's in another language, else its key.
     *
     * @param  array{name: array<string, string|null>}  $service
     */
    public static function serviceName(string $key, array $service): string
    {
        $locale = app()->getLocale();
        $default = "appointments.services.{$key}";

        return $service['name'][$locale]
            ?? (trans()->has($default) ? (string) __($default) : null)
            ?? collect($service['name'])->filter()->first()
            ?? $key;
    }

    /**
     * What the settings can be set to, for a settings page, named in the reader's language: every choice with its
     * name, the limits, the languages a kind of visit is named in, and the registry's kinds of visit with their
     * default names in every language.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        $named = fn (string $group, array $keys): array => collect($keys)->mapWithKeys(fn (string $key): array => [$key => self::label($group, $key)])->all();

        return [
            'booking' => self::bookingModes(),
            'scheduling' => self::schedulingModes(),
            'confirmation' => self::confirmationModes(),
            'verification' => self::verificationMethods(),
            'code_channels' => self::codeChannels(),
            'languages' => self::languages(),
            'labels' => [
                'booking' => $named('booking', self::bookingModes()),
                'scheduling' => $named('scheduling', self::schedulingModes()),
                'confirmation' => $named('confirmation', self::confirmationModes()),
                'verification' => $named('verification', self::verificationMethods()),
                'code_channels' => collect(self::codeChannels())->mapWithKeys(fn (string $key): array => [$key => (string) (((array) __('notifications.channels'))[$key] ?? $key)])->all(),
                'languages' => collect(self::languages())->mapWithKeys(fn (string $key): array => [$key => (string) (((array) __('notifications.languages'))[$key] ?? $key)])->all(),
            ],
            'limits' => config('appointments.limits'),
            'custom_services' => ['max' => (int) config('appointments.custom_services.max'), 'key' => trim((string) config('appointments.custom_services.key'), '/')],
            // Places (their own API): a new one's week and capacities, how many opening periods a day, how many places
            'locations' => [
                'weekdays' => WeeklyHours::weekdays(),
                'hours' => WeeklyHours::normalize((array) config('appointments.locations.hours')),
                'slot_capacity' => (int) config('appointments.locations.slot_capacity'),
                'day_capacity' => (int) config('appointments.locations.day_capacity'),
                'periods_per_day' => (int) config('appointments.locations.periods_per_day'),
                'max' => (int) config('appointments.locations.max'),
            ],
            'services' => collect(self::registryServices())->map(fn (int $slots, string $key): array => [
                'slots' => $slots,
                'names' => collect(self::languages())->mapWithKeys(fn (string $language): array => [$language => (string) __("appointments.services.{$key}", locale: $language)])->all(),
            ])->all(),
        ];
    }

    /**
     * A name from lang/<language>/appointments.php (group.key), or the key when there is none.
     */
    public static function label(string $group, string $key): string
    {
        $name = "appointments.{$group}.{$key}";

        return trans()->has($name) ? (string) __($name) : $key;
    }
}
