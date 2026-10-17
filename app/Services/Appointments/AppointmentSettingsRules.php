<?php

namespace App\Services\Appointments;

use App\Repositories\Contracts\AppointmentRepositoryInterface;
use App\Services\Reports\ReportValueText;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * How a change to an organization's appointment settings is checked, wherever it comes from (the appointments
 * settings page, an integration): the shape, built from the registry, as a partial change (any part may be sent
 * alone), then checks across fields on the settings as they will be saved.
 */
final class AppointmentSettingsRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        $between = function (string $limit): string {
            [$min, $max] = AppointmentSettings::limit($limit);

            return "between:{$min},{$max}";
        };
        [, $maxReminders] = AppointmentSettings::limit('reminders');
        [, $maxName] = AppointmentSettings::limit('name');
        $languages = implode(',', AppointmentSettings::languages());

        return [
            'appointments' => ['required', 'array:enabled,booking,scheduling,slot_minutes,confirmation,family_members,open_per_service,notice_hours,days_ahead,changes,verification,reminders,number_format,services'],
            'appointments.enabled' => ['sometimes', 'boolean'],
            'appointments.booking' => ['sometimes', 'array'],
            'appointments.booking.*' => ['distinct', Rule::in(AppointmentSettings::bookingModes())],
            'appointments.scheduling' => ['sometimes', Rule::in(AppointmentSettings::schedulingModes())],
            'appointments.slot_minutes' => ['sometimes', 'integer', $between('slot_minutes')],
            'appointments.confirmation' => ['sometimes', Rule::in(AppointmentSettings::confirmationModes())],
            'appointments.family_members' => ['sometimes', 'boolean'],
            'appointments.open_per_service' => ['sometimes', 'integer', $between('open_per_service')],
            'appointments.notice_hours' => ['sometimes', 'integer', $between('notice_hours')],
            'appointments.days_ahead' => ['sometimes', 'integer', $between('days_ahead')],
            'appointments.changes' => ['sometimes', 'array:cancel,reschedule,until_hours'],
            'appointments.changes.cancel' => ['sometimes', 'boolean'],
            'appointments.changes.reschedule' => ['sometimes', 'boolean'],
            'appointments.changes.until_hours' => ['sometimes', 'integer', $between('change_until_hours')],
            'appointments.verification' => ['sometimes', 'array:method,code_channels,code_minutes,code_attempts'],
            'appointments.verification.method' => ['sometimes', Rule::in(AppointmentSettings::verificationMethods())],
            'appointments.verification.code_channels' => ['sometimes', 'array'],
            'appointments.verification.code_channels.*' => ['distinct', Rule::in(AppointmentSettings::codeChannels())],
            'appointments.verification.code_minutes' => ['sometimes', 'integer', $between('code_minutes')],
            'appointments.verification.code_attempts' => ['sometimes', 'integer', $between('code_attempts')],
            'appointments.reminders' => ['sometimes', 'array', "max:{$maxReminders}"],
            'appointments.reminders.*' => ['integer', 'distinct', $between('reminder_hours')],
            'appointments.number_format' => ['sometimes', 'string', 'max:40'],
            'appointments.services' => ['sometimes', 'array'],
            // null removes an organization's own kind of visit
            // `custom` and `position` are what GET answers (the registry's or the organization's own, in which order):
            // taken back, never stored from what is sent
            'appointments.services.*' => ['nullable', 'array:enabled,custom,position,name,slots,public'],
            'appointments.services.*.custom' => ['sometimes', 'boolean'],
            'appointments.services.*.position' => ['sometimes', 'integer'],
            'appointments.services.*.enabled' => ['sometimes', 'boolean'],
            'appointments.services.*.name' => ['sometimes', 'array:'.$languages],
            'appointments.services.*.name.*' => ['nullable', 'string', "max:{$maxName}"],
            'appointments.services.*.slots' => ['sometimes', 'integer', $between('service_slots')],
            'appointments.services.*.public' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Checks across fields: the registry's kinds of visit can be turned off, not removed; an organization's own
     * has a key of the allowed shape, a name, and there are not too many; turned on, appointments need someone
     * to book and a kind of visit; a one-time code needs a channel to go on.
     *
     * @param  mixed  $change  the `appointments` input
     * @param  array<string, mixed>  $current  the organization's saved settings.appointments
     */
    public static function check(Validator $validator, mixed $change, array $current): void
    {
        if (! is_array($change) || $validator->errors()->has('appointments')) {
            return;
        }
        // The kinds of visit's keys are checked beside the shape, so every mistake shows at once
        $registry = AppointmentSettings::registryServices();
        foreach (is_array($change['services'] ?? null) ? $change['services'] : [] as $key => $service) {
            $field = "appointments.services.{$key}";
            if (isset($registry[$key])) {
                if ($service === null) {
                    $validator->errors()->add($field, __('appointments.settings.registry_service_kept'));
                }
            } elseif (! preg_match((string) config('appointments.custom_services.key'), (string) $key)) {
                $validator->errors()->add($field, __('appointments.settings.service_key'));
            } elseif ($service === null && in_array((string) $key, $booked ??= app(AppointmentRepositoryInterface::class)->usedServices(), true)) {
                // Its bookings are kept, so the kind stays (named) too: turned off instead
                $validator->errors()->add($field, __('appointments.settings.service_in_use'));
            }
        }
        if (is_string($change['number_format'] ?? null) && ($problem = AppointmentNumbers::problem($change['number_format'])) !== null) {
            $validator->errors()->add('appointments.number_format', $problem);
        }
        if ($validator->errors()->has('appointments*')) {
            return;
        }

        $patched = AppointmentSettings::patch($current, $change);
        $settings = AppointmentSettings::normalize($patched);
        $custom = array_filter((array) ($patched['services'] ?? []), fn (mixed $service, int|string $key) => ! isset($registry[$key]), ARRAY_FILTER_USE_BOTH);
        if (count($custom) > (int) config('appointments.custom_services.max')) {
            $validator->errors()->add('appointments.services', __('appointments.settings.too_many_services', ['max' => app(ReportValueText::class)->number((int) config('appointments.custom_services.max'))]));
        }
        foreach ($settings['services'] as $key => $service) {
            if ($service['custom'] && array_filter($service['name']) === []) {
                $validator->errors()->add("appointments.services.{$key}.name", __('appointments.settings.service_name'));
            }
        }
        if ($settings['enabled'] && $settings['booking'] === []) {
            $validator->errors()->add('appointments.booking', __('appointments.settings.booking_needed'));
        }
        if ($settings['enabled'] && ! collect($settings['services'])->contains('enabled', true)) {
            $validator->errors()->add('appointments.services', __('appointments.settings.service_needed'));
        }
        if ($settings['verification']['method'] === 'code' && $settings['verification']['code_channels'] === []) {
            $validator->errors()->add('appointments.verification.code_channels', __('appointments.settings.code_channel_needed'));
        }
    }
}
