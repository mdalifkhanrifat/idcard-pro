<?php

namespace App\Services\Notifications;

/**
 * Something that happened which people may be told about (config('notifications.events')): the values its
 * texts may use, the channels it goes on unless the organization chose others, and those values for one
 * occurrence. Its default texts are in lang/<language>/notifications.php under events.<key>.
 *
 * @template TSubject of object
 */
interface NotificationEvent
{
    public function key(): string;

    /**
     * Placeholders the texts may use, e.g. ["holder.name", "card.number"] ({holder.name} in a text).
     *
     * @return array<int, string>
     */
    public function placeholders(): array;

    /**
     * Channels it goes on by default (config('notifications.channels') keys).
     *
     * @return array<int, string>
     */
    public function defaultChannels(): array;

    /**
     * The placeholders' values for one occurrence, written for the current language (dates and digits).
     *
     * @param  TSubject  $subject  what it happened to, e.g. a card
     * @return array<string, string>
     */
    public function values(object $subject): array;
}
