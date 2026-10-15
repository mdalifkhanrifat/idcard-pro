<?php

namespace App\Services\Notifications;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
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
     * Every event's values for a made-up occurrence (NotificationEvent::sample()) in every language messages can
     * be written in, written as a real message's would be (that language's digits and dates, the organization's
     * name, today and card number format), for previews of the texts. Nothing is read from or saved to the
     * database.
     *
     * @return array<string, array<string, array<string, string>>> event => language => placeholder => value
     */
    public function samples(Tenant $tenant): array
    {
        return app(TenantContext::class)->run($tenant, fn (): array => collect(NotificationSettings::events())->map(
            fn (NotificationEvent $event): array => collect(NotificationSettings::languages())->mapWithKeys(
                fn (string $language): array => [$language => $this->inLanguage($language, fn (): array => $event->values($event->sample($tenant)))],
            )->all(),
        )->all());
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
        foreach ($languages as $language) {
            foreach ($this->inLanguage($language, fn (): array => $partsIn($language)) as $part => $text) {
                $parts[$part][] = trim($text);
            }
        }

        $separators = (array) config('notifications.separators');

        return [
            'languages' => array_values($languages),
            'subject' => implode((string) $separators['subject'], $parts['subject']),
            'body' => implode((string) $separators['body'], $parts['body']),
            'sms' => implode((string) $separators['sms'], $parts['sms']),
        ];
    }

    /**
     * Run the callback with the language as the app's (its digits, dates and texts), then go back.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function inLanguage(string $language, Closure $callback): mixed
    {
        $previous = app()->getLocale();
        app()->setLocale($language);
        try {
            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }
}
