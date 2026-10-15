<?php

namespace App\Services\Notifications;

use Closure;

/**
 * The words of an event's message as the organization chose them: in each of its languages, in its order, its
 * own text where it wrote one, else the event's default, with the occurrence's values filled in (dates and
 * digits in that language). Several languages make one message, each in turn.
 */
class NotificationComposer
{
    /**
     * @param  NotificationEvent<object>  $event
     * @param  array{languages: array<int, string>, events: array<string, array{templates: array<string, array{subject: string|null, body: string|null, sms: string|null}>}>}  $settings  normalized
     * @return array{languages: array<int, string>, subject: string, body: string, sms: string}
     */
    public function compose(NotificationEvent $event, object $subject, array $settings): array
    {
        return $this->assemble($settings['languages'], function (string $language) use ($event, $subject, $settings): array {
            $values = $event->values($subject);

            return array_map(
                fn (string $part): string => NotificationSettings::fill(
                    $settings['events'][$event->key()]['templates'][$language][$part]
                        ?? (string) __("notifications.events.{$event->key()}.{$part}", locale: $language),
                    $values,
                ),
                array_combine(NotificationSettings::PARTS, NotificationSettings::PARTS),
            );
        });
    }

    /**
     * Words that are not an event's (e.g. the test message): the texts under a lang key (`<key>.subject|body|sms`)
     * in each of the given languages, in their order, with the values filled in.
     *
     * @param  string  $key  e.g. "notifications.test"
     * @param  array<string, string>  $values  placeholder => value
     * @param  array<int, string>  $languages
     * @return array{languages: array<int, string>, subject: string, body: string, sms: string}
     */
    public function composeText(string $key, array $values, array $languages): array
    {
        return $this->assemble($languages, fn (string $language): array => array_map(
            fn (string $part): string => NotificationSettings::fill((string) __("{$key}.{$part}", locale: $language), $values),
            array_combine(NotificationSettings::PARTS, NotificationSettings::PARTS),
        ));
    }

    /**
     * One message of each language's parts in turn (config('notifications.separators') between them), each
     * language's written with it as the app's language (its digits and dates).
     *
     * @param  array<int, string>  $languages
     * @param  Closure(string): array<string, string>  $partsIn  language => its subject, body and sms
     * @return array{languages: array<int, string>, subject: string, body: string, sms: string}
     */
    private function assemble(array $languages, Closure $partsIn): array
    {
        $parts = ['subject' => [], 'body' => [], 'sms' => []];
        $previous = app()->getLocale();
        try {
            foreach ($languages as $language) {
                app()->setLocale($language);
                foreach ($partsIn($language) as $part => $text) {
                    $parts[$part][] = trim($text);
                }
            }
        } finally {
            app()->setLocale($previous);
        }

        $separators = (array) config('notifications.separators');

        return [
            'languages' => array_values($languages),
            'subject' => implode((string) $separators['subject'], $parts['subject']),
            'body' => implode((string) $separators['body'], $parts['body']),
            'sms' => implode((string) $separators['sms'], $parts['sms']),
        ];
    }
}
