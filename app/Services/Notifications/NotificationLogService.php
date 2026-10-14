<?php

namespace App\Services\Notifications;

use App\Data\Notifications\NotificationMessageFilter;
use App\Enums\NotificationStatus;
use App\Models\NotificationMessage;
use App\Models\Tenant;
use App\Repositories\Contracts\NotificationMessageRepositoryInterface;
use App\Repositories\Contracts\TenantRepositoryInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The log of sent messages: reading it, and keeping it — old messages leave it only for organizations that chose
 * so (settings.notifications.prune_log), after their own number of days; each deletion is noted in the audit log.
 */
class NotificationLogService
{
    /**
     * The event of a message that is not of the registry: one sent by hand to check a channel.
     */
    public const TEST_EVENT = 'test';

    public function __construct(
        private NotificationMessageRepositoryInterface $messages,
        private TenantRepositoryInterface $tenants,
    ) {}

    /**
     * @return LengthAwarePaginator<int, NotificationMessage>
     */
    public function list(NotificationMessageFilter $filter): LengthAwarePaginator
    {
        return $this->messages->list($filter);
    }

    public function details(NotificationMessage $message): NotificationMessage
    {
        return $this->messages->withDetails($message);
    }

    /**
     * What the log can be filtered by, named in the reader's language: the registry's events and channels (new
     * ones appear by themselves) plus any in the log since removed from it, the test message, and the statuses.
     *
     * @return array{events: array<int, array{key: string, name: string}>, channels: array<int, array{key: string, name: string}>, statuses: array<int, array{key: string, name: string}>}
     */
    public function options(): array
    {
        $events = array_values(array_unique([...array_keys(NotificationSettings::events()), self::TEST_EVENT, ...$this->messages->loggedValues('event')]));
        $channels = array_values(array_unique([...NotificationSettings::channels(), ...$this->messages->loggedValues('channel')]));

        return [
            'events' => array_map(fn (string $key): array => ['key' => $key, 'name' => self::eventName($key)], $events),
            'channels' => array_map(fn (string $key): array => ['key' => $key, 'name' => self::label('channels', $key)], $channels),
            'statuses' => array_map(fn (NotificationStatus $status): array => ['key' => $status->value, 'name' => self::label('statuses', $status->value)], NotificationStatus::cases()),
        ];
    }

    /**
     * An event's name in the current language; the key itself for one no longer known.
     */
    public static function eventName(string $key): string
    {
        return self::label($key === self::TEST_EVENT ? 'test' : "events.{$key}", 'name', $key);
    }

    /**
     * A name from lang/<language>/notifications.php (group.key), or the fallback (the key) when there is none.
     */
    public static function label(string $group, string $key, ?string $fallback = null): string
    {
        $name = "notifications.{$group}.{$key}";

        return trans()->has($name) ? (string) __($name) : ($fallback ?? $key);
    }

    /**
     * Delete old messages for every organization that has them deleted (or one); returns how many per
     * organization (slug => count), organizations that keep their log left out.
     *
     * @return array<string, int>
     */
    public function prune(?Tenant $only = null): array
    {
        $pruned = [];
        $prune = function (Tenant $tenant) use (&$pruned): void {
            $settings = $tenant->notificationSettings();
            if (! $settings['prune_log']) {
                return;
            }
            $count = $this->messages->deleteOlderThan($tenant->id, now()->subDays($settings['log_days']));
            $pruned[$tenant->slug] = $count;
            if ($count > 0) {
                // By the system, about the organization itself (its audit page shows such entries)
                activity('notifications')
                    ->performedOn($tenant)
                    ->causedByAnonymous()
                    ->event('pruned')
                    ->withProperties(['messages' => $count, 'days' => $settings['log_days']])
                    ->log('pruned');
            }
        };

        $only === null ? $this->tenants->each($prune) : $prune($only);

        return $pruned;
    }
}
