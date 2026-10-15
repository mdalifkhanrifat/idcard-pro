<?php

namespace App\Services\Notifications;

use App\Data\Notifications\NotificationRecipient;
use App\Models\Tenant;

/**
 * Something that happened which people may be told about (config('notifications.events')): the values its
 * texts may use, the channels it goes on unless the organization chose others, and for one occurrence those
 * values, whom to tell and what the message is about. Its default texts are in
 * lang/<language>/notifications.php under events.<key>.
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

    /**
     * A made-up occurrence for previews of the texts, e.g. a card of an invented holder: built in memory and
     * never saved, with no real person's details, its names in the current language. Its values are those a
     * real one would have (the organization's name, today in its days, its card number format).
     *
     * @return TSubject
     */
    public function sample(Tenant $tenant): object;

    /**
     * Whom to tell about this occurrence.
     *
     * @param  TSubject  $subject
     */
    public function recipient(object $subject): NotificationRecipient;

    /**
     * What the message is about, as columns of the message log (e.g. ["card_id" => 12]).
     *
     * @param  TSubject  $subject
     * @return array<string, int>
     */
    public function links(object $subject): array;
}
