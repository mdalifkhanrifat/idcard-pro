<?php

namespace App\Services\Notifications;

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
        $parts = ['subject' => [], 'body' => [], 'sms' => []];
        $previous = app()->getLocale();
        try {
            foreach ($settings['languages'] as $language) {
                app()->setLocale($language);
                $values = $event->values($subject);
                foreach (NotificationSettings::PARTS as $part) {
                    $text = $settings['events'][$event->key()]['templates'][$language][$part]
                        ?? (string) __("notifications.events.{$event->key()}.{$part}", locale: $language);
                    $parts[$part][] = trim(NotificationSettings::fill($text, $values));
                }
            }
        } finally {
            app()->setLocale($previous);
        }

        $separators = (array) config('notifications.separators');

        return [
            'languages' => array_values($settings['languages']),
            'subject' => implode((string) $separators['subject'], $parts['subject']),
            'body' => implode((string) $separators['body'], $parts['body']),
            'sms' => implode((string) $separators['sms'], $parts['sms']),
        ];
    }
}
