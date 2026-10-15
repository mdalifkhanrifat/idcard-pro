<?php

namespace App\Services\Notifications;

/**
 * An organization's notification settings (tenants.settings.notifications), always complete: anything not saved
 * takes the configured default, and channels, languages or events the registry no longer offers are dropped.
 * Texts are kept only where the organization wrote its own (null = the event's default text).
 */
class NotificationSettings
{
    /** Parts of a message an organization may write: a mail's subject and body, an SMS. */
    public const PARTS = ['subject', 'body', 'sms'];

    /** {holder.name}, {card.number}... */
    private const PLACEHOLDER = '/\{([a-z0-9_]+(?:\.[a-z0-9_]+)*)\}/';

    /**
     * @return array<int, string>
     */
    public static function channels(): array
    {
        return array_keys((array) config('notifications.channels'));
    }

    /**
     * @return array<int, string>
     */
    public static function languages(): array
    {
        return array_values((array) config('notifications.languages'));
    }

    /**
     * Every event of the registry, by key.
     *
     * @return array<string, NotificationEvent<object>>
     */
    public static function events(): array
    {
        $events = [];
        foreach ((array) config('notifications.events') as $key => $class) {
            $event = app($class);
            assert($event instanceof NotificationEvent);
            $events[(string) $key] = $event;
        }

        return $events;
    }

    /**
     * The saved settings with a change applied: top-level values replace, an event replaces that event only.
     * A channel list that is missing means none (a form cannot send an empty list); the languages, which are
     * never empty, stay as they were.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $change
     * @return array<string, mixed>
     */
    public static function merge(array $current, array $change): array
    {
        $events = (array) ($current['events'] ?? []);
        foreach ((array) ($change['events'] ?? []) as $key => $event) {
            $events[$key] = ['channels' => [], ...(array) $event];
        }

        return [...$current, 'channels' => [], ...$change, 'events' => $events];
    }

    /**
     * @param  array<string, mixed>  $saved
     * @return array{enabled: bool, channels: array<int, string>, languages: array<int, string>, prune_log: bool, log_days: int, events: array<string, array{enabled: bool, channels: array<int, string>, templates: array<string, array{subject: string|null, body: string|null, sms: string|null}>}>}
     */
    public static function normalize(array $saved): array
    {
        $defaults = (array) config('notifications.defaults');
        $channels = self::channels();
        $languages = self::languages();
        $days = (array) config('notifications.log_days');
        $list = fn (mixed $value, array $allowed, array $default): array => array_values(array_intersect(array_unique(is_array($value) ? $value : $default), $allowed));

        $events = [];
        foreach (self::events() as $key => $event) {
            $savedEvent = (array) ($saved['events'][$key] ?? []);
            $templates = [];
            foreach ($languages as $language) {
                foreach (self::PARTS as $part) {
                    $text = $savedEvent['templates'][$language][$part] ?? null;
                    $templates[$language][$part] = is_string($text) && trim($text) !== '' ? $text : null;
                }
            }
            $events[$key] = [
                'enabled' => (bool) ($savedEvent['enabled'] ?? false),
                'channels' => $list($savedEvent['channels'] ?? null, $channels, $event->defaultChannels()),
                'templates' => $templates,
            ];
        }

        $chosenLanguages = $list($saved['languages'] ?? null, $languages, (array) $defaults['languages']);

        return [
            'enabled' => (bool) ($saved['enabled'] ?? $defaults['enabled']),
            'channels' => $list($saved['channels'] ?? null, $channels, (array) $defaults['channels']),
            // In the organization's order; at least one
            'languages' => $chosenLanguages !== [] ? $chosenLanguages : [$languages[0] ?? 'en'],
            // Old messages are deleted only when the organization chose so; the days are kept either way
            'prune_log' => (bool) ($saved['prune_log'] ?? $defaults['prune_log']),
            'log_days' => max((int) $days['min'], min((int) $days['max'], (int) ($saved['log_days'] ?? $defaults['log_days']))),
            'events' => $events,
        ];
    }

    /**
     * What this installation can send and about what, for a settings form, named in the reader's language: the
     * channels and languages (keys, with their names under `labels`), limits, and every event with the
     * placeholders its texts may use (named), its default channels and its default texts in every language.
     *
     * @return array<string, mixed>
     */
    public static function options(): array
    {
        $languages = self::languages();

        return [
            'channels' => self::channels(),
            'languages' => $languages,
            'labels' => [
                'channels' => collect(self::channels())->mapWithKeys(fn (string $key): array => [$key => NotificationLogService::label('channels', $key)])->all(),
                'languages' => collect($languages)->mapWithKeys(fn (string $key): array => [$key => NotificationLogService::label('languages', $key)])->all(),
            ],
            'log_days' => config('notifications.log_days'),
            'max_length' => config('notifications.max_length'),
            'events' => collect(self::events())->map(fn (NotificationEvent $event, string $key): array => [
                'name' => (string) __("notifications.events.{$key}.name"),
                'description' => (string) __("notifications.events.{$key}.description"),
                // Read as a list: placeholder names hold dots, which __() would take as nesting
                'placeholders' => array_map(fn (string $placeholder): array => ['key' => $placeholder, 'label' => (string) (((array) __('notifications.placeholders'))[$placeholder] ?? $placeholder)], $event->placeholders()),
                'default_channels' => $event->defaultChannels(),
                'default_templates' => collect($languages)->mapWithKeys(fn (string $language): array => [$language => collect(self::PARTS)->mapWithKeys(
                    fn (string $part): array => [$part => (string) __("notifications.events.{$key}.{$part}", locale: $language)],
                )->all()])->all(),
            ])->all(),
        ];
    }

    /**
     * Placeholders a text uses that its event does not offer (e.g. a misspelt {card.numbr}).
     *
     * @return array<int, string>
     */
    public static function unknownPlaceholders(string $text, NotificationEvent $event): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $matches);

        return array_values(array_diff(array_unique($matches[1]), $event->placeholders()));
    }

    /**
     * A text with its placeholders filled in; values are plain text, never markup or code.
     *
     * @param  array<string, string>  $values
     */
    public static function fill(string $text, array $values): string
    {
        return (string) preg_replace_callback(self::PLACEHOLDER, fn (array $match): string => $values[$match[1]] ?? $match[0], $text);
    }
}
