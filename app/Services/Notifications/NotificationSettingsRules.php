<?php

namespace App\Services\Notifications;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * How a change to an organization's notification settings is checked, wherever it comes from (the organization's
 * settings, PUT /notifications/settings, later an integration): the shape, built from the registry (channels,
 * languages, events, parts, limits), and the checks across fields. Lists that are missing mean none.
 */
final class NotificationSettingsRules
{
    /**
     * On or off, channels, languages (and whether an SMS takes them all or only the first), whether old messages leave the log and after how many days, and per event
     * whether it goes out, on which channels, in whose words (an empty text = the event's default) and its own
     * settings, if it has any.
     * Partial (JSON clients): any part may be sent alone, the rest stays; otherwise (a form, which cannot send an
     * empty list) a section is sent whole, its on/off included.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(bool $partial = false): array
    {
        $whole = fn (string $key): string => $partial ? 'sometimes' : "required_with:{$key}";
        $channels = NotificationSettings::channels();
        $languages = NotificationSettings::languages();
        $days = (array) config('notifications.log_days');
        $length = (array) config('notifications.max_length');
        $rules = [
            'notifications' => ['sometimes', 'array:enabled,channels,languages,sms_languages,prune_log,log_days,events'],
            'notifications.enabled' => [$whole('notifications'), 'boolean'],
            'notifications.channels' => ['sometimes', 'array'],
            'notifications.channels.*' => ['distinct', Rule::in($channels)],
            'notifications.languages' => ['sometimes', 'array', 'min:1'],
            'notifications.languages.*' => ['distinct', Rule::in($languages)],
            'notifications.sms_languages' => ['sometimes', Rule::in(NotificationSettings::smsLanguageModes())],
            'notifications.prune_log' => ['sometimes', 'boolean'],
            'notifications.log_days' => ['sometimes', 'integer', 'between:'.(int) $days['min'].','.(int) $days['max']],
            'notifications.events' => ['sometimes', 'array:'.implode(',', array_keys(NotificationSettings::events()))],
        ];
        foreach (NotificationSettings::events() as $event => $definition) {
            $key = "notifications.events.{$event}";
            $options = NotificationSettings::optionsOf($definition);
            $rules += [
                $key => ['sometimes', 'array:enabled,channels,templates'.($options !== [] ? ',options' : '')],
                "{$key}.enabled" => [$whole($key), 'boolean'],
                "{$key}.channels" => ['sometimes', 'array'],
                "{$key}.channels.*" => ['distinct', Rule::in($channels)],
                "{$key}.templates" => ['sometimes', 'array:'.implode(',', $languages)],
            ];
            foreach ($languages as $language) {
                $rules["{$key}.templates.{$language}"] = ['sometimes', 'array:'.implode(',', NotificationSettings::PARTS)];
                foreach (NotificationSettings::PARTS as $part) {
                    $rules["{$key}.templates.{$language}.{$part}"] = ['nullable', 'string', 'max:'.(int) $length[$part]];
                }
            }
            // The event's own settings (5.7d), as it declares them
            if ($options !== []) {
                $rules["{$key}.options"] = ['sometimes', 'array:'.implode(',', array_keys($options))];
                foreach ($options as $option) {
                    $rules += $option->rules("{$key}.options.{$option->key}");
                }
            }
        }

        return $rules;
    }

    /**
     * Checks across fields: a text may only use its event's placeholders (a misspelt one would reach people as
     * is); turned on, notifications need a channel to go on — checked as it will be saved, the change applied to
     * the current settings.
     *
     * @param  mixed  $change  the `notifications` input
     * @param  array<string, mixed>  $current  the organization's saved settings.notifications
     * @param  bool  $partial  the change is partial (see rules())
     */
    public static function check(Validator $validator, mixed $change, array $current, bool $partial = false): void
    {
        if (! is_array($change) || $validator->errors()->has('notifications*')) {
            return;
        }
        $events = NotificationSettings::events();
        foreach ((array) ($change['events'] ?? []) as $key => $event) {
            foreach ((array) ($event['templates'] ?? []) as $language => $parts) {
                foreach ((array) $parts as $part => $text) {
                    $unknown = is_string($text) ? NotificationSettings::unknownPlaceholders($text, $events[$key]) : [];
                    if ($unknown !== []) {
                        $validator->errors()->add("notifications.events.{$key}.templates.{$language}.{$part}", __('notifications.settings.unknown_placeholders', [
                            'names' => implode(', ', array_map(fn (string $name) => '{'.$name.'}', $unknown)),
                        ]));
                    }
                }
            }
        }
        $settings = NotificationSettings::normalize($partial ? NotificationSettings::patch($current, $change) : NotificationSettings::merge($current, $change));
        if ($settings['enabled'] && $settings['channels'] === []) {
            $validator->errors()->add('notifications.channels', __('notifications.settings.channel_needed'));
        }
    }
}
