<?php

namespace App\Services\Webhooks;

/**
 * The events an organization can be told about (config/webhooks.php), named for the page.
 */
class WebhookEvents
{
    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return array_merge(...array_values((array) config('webhooks.events')));
    }

    /**
     * Grouped as the page shows them. The event names hold dots, so the list is read whole.
     *
     * @return array<int, array{key: string, name: string, events: array<int, array{key: string, name: string}>}>
     */
    public static function options(): array
    {
        $names = (array) __('webhooks.events');
        $groups = (array) __('webhooks.groups');

        return array_map(fn (string $group, array $events): array => [
            'key' => $group,
            'name' => (string) ($groups[$group] ?? $group),
            'events' => array_map(fn (string $event): array => ['key' => $event, 'name' => (string) ($names[$event] ?? $event)], $events),
        ], array_keys((array) config('webhooks.events')), array_values((array) config('webhooks.events')));
    }
}
