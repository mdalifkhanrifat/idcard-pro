<?php

namespace App\Services\Webhooks;

use App\Models\User;

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
     * Those of the events holding data this person may not see themselves.
     *
     * @param  array<int, string>  $events
     * @return array<int, string>
     */
    public static function beyond(User $user, array $events): array
    {
        $groups = (array) config('webhooks.events');
        $requires = (array) config('webhooks.requires');
        $blocked = array_keys(array_filter($requires, fn (string $permission): bool => ! $user->can($permission)));
        $hidden = array_merge([], ...array_map(fn (string $group): array => (array) ($groups[$group] ?? []), $blocked));

        return array_values(array_intersect($events, $hidden));
    }

    /**
     * An event's name in the reader's language; its key once it has none (no longer in the list).
     */
    public static function name(string $event): string
    {
        return (string) ([...(array) __('webhooks.events'), ...(array) __('webhooks.other_events')][$event] ?? $event);
    }

    /**
     * Grouped as the page shows them. The event names hold dots, so the list is read whole.
     *
     * @return array<int, array{key: string, name: string, allowed: bool, events: array<int, array{key: string, name: string}>}>
     */
    public static function options(User $user): array
    {
        $names = (array) __('webhooks.events');
        $groups = (array) __('webhooks.groups');

        return array_map(fn (string $group, array $events): array => [
            'key' => $group,
            'name' => (string) ($groups[$group] ?? $group),
            // Whether this person may send it somewhere: beyond() says the same
            'allowed' => self::beyond($user, $events) === [],
            'events' => array_map(fn (string $event): array => ['key' => $event, 'name' => (string) ($names[$event] ?? $event)], $events),
        ], array_keys((array) config('webhooks.events')), array_values((array) config('webhooks.events')));
    }
}
